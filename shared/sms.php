<?php
declare(strict_types=1);

function normalizeBdMobile(string $raw): string
{
    $value = function_exists('asciiDigits') ? asciiDigits($raw) : $raw;
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '880') && strlen($digits) === 13) {
        return $digits;
    }
    if (str_starts_with($digits, '01') && strlen($digits) === 11) {
        return '88' . $digits;
    }
    if (str_starts_with($digits, '1') && strlen($digits) === 10) {
        return '880' . $digits;
    }

    return '';
}

function smsGatewaySettings(PDO $db): array
{
    $settings = function_exists('schoolSettings') ? schoolSettings($db) : [];
    $provider = strtolower(trim((string) ($settings['sms_provider'] ?? '')));
    $webhook = trim((string) ($settings['notify_sms_url'] ?? ''));
    if (!in_array($provider, ['sslwireless', 'bulksmsbd', 'bdbulksms', 'webhook'], true)) {
        $provider = $webhook !== '' ? 'webhook' : '';
    }

    return [
        'provider' => $provider,
        'token' => trim((string) ($settings['sms_api_token'] ?? '')),
        'sid' => trim((string) ($settings['sms_sid'] ?? '')),
        'webhook' => $webhook,
    ];
}

function smsGatewayReady(PDO $db): bool
{
    $settings = smsGatewaySettings($db);
    if ($settings['provider'] === 'webhook') {
        return $settings['webhook'] !== '' && str_contains($settings['webhook'], '{mobile}') && str_contains($settings['webhook'], '{text}');
    }
    if ($settings['provider'] === 'bdbulksms') {
        return $settings['token'] !== '';
    }
    if (in_array($settings['provider'], ['sslwireless', 'bulksmsbd'], true)) {
        return $settings['token'] !== '' && $settings['sid'] !== '';
    }

    return false;
}

function sendSchoolSms(PDO $db, string $mobile, string $text, string $context = ''): array
{
    $normalized = normalizeBdMobile($mobile);
    if ($normalized === '') {
        return ['ok' => false, 'detail' => 'invalid_mobile'];
    }
    $batch = sendSchoolSmsBatch($db, [$normalized], $text, $context);

    return [
        'ok' => (int) $batch['sent'] === 1,
        'detail' => (string) ($batch['detail'] ?? ''),
    ];
}

function sendSchoolSmsBatch(PDO $db, array $mobiles, string $text, string $context = ''): array
{
    $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'detail' => ''];
    $text = trim($text);
    if ($text === '') {
        $summary['detail'] = 'empty_message';
        $summary['skipped'] = count($mobiles);

        return $summary;
    }
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 480, 'UTF-8');
    }
    $unique = [];
    foreach ($mobiles as $mobile) {
        $normalized = normalizeBdMobile((string) $mobile);
        if ($normalized !== '') {
            $unique[$normalized] = $normalized;
        }
    }
    $numbers = array_values($unique);
    if ($numbers === []) {
        $summary['detail'] = 'no_mobiles';

        return $summary;
    }
    if (!smsGatewayReady($db)) {
        $summary['skipped'] = count($numbers);
        $summary['detail'] = 'not_configured';

        return $summary;
    }

    $settings = smsGatewaySettings($db);
    $chunkSize = in_array($settings['provider'], ['webhook', 'bdbulksms'], true) ? 1 : 50;
    foreach (array_chunk($numbers, $chunkSize) as $chunk) {
        $result = dispatchSchoolSms($settings, $chunk, $text);
        $status = $result['ok'] ? 'sent' : 'failed';
        $summary[$status] += count($chunk);
        $summary['detail'] = $result['detail'];
        logSchoolSms($db, $context, $chunk, $status, $result['detail']);
    }

    return $summary;
}

function dispatchSchoolSms(array $settings, array $numbers, string $text): array
{
    $provider = (string) ($settings['provider'] ?? '');
    if ($provider === 'webhook') {
        return dispatchWebhookSms(
            (string) $settings['webhook'],
            $numbers,
            $text,
            (string) ($settings['token'] ?? '')
        );
    }
    if ($provider === 'sslwireless') {
        return dispatchSslWirelessSms((string) $settings['token'], (string) $settings['sid'], $numbers, $text);
    }
    if ($provider === 'bulksmsbd') {
        return dispatchBulkSmsBd((string) $settings['token'], (string) $settings['sid'], $numbers, $text);
    }
    if ($provider === 'bdbulksms') {
        return dispatchBdBulkSmsNet((string) $settings['token'], $numbers, $text);
    }

    return ['ok' => false, 'detail' => 'unknown_provider'];
}

function smsLocalBdMobile(string $normalized880): string
{
    if (str_starts_with($normalized880, '880') && strlen($normalized880) === 13) {
        return substr($normalized880, 2);
    }

    return $normalized880;
}

function smsWebhookLooksFailed(string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }
    if (preg_match('/\b(error|invalid|failed|denied|unauthorized)\b/i', $body) === 1) {
        return true;
    }

    return str_contains(strtolower($body), 'have not supplied');
}

function dispatchWebhookSms(string $template, array $numbers, string $text, string $token = ''): array
{
    $mobile = $numbers[0] ?? '';
    $url = strtr($template, [
        '{mobile}' => rawurlencode($mobile),
        '{text}' => rawurlencode($text),
        '{token}' => rawurlencode($token),
    ]);
    $response = smsHttpRequest($url, '', [], 'GET');
    $ok = $response['ok'] && !smsWebhookLooksFailed((string) $response['body']);

    return [
        'ok' => $ok,
        'detail' => $ok ? 'webhook' : smsResponseDetail($response, 'webhook_failed'),
    ];
}

function dispatchBdBulkSmsNet(string $token, array $numbers, string $text): array
{
    $mobile = smsLocalBdMobile((string) ($numbers[0] ?? ''));
    $body = http_build_query([
        'token' => $token,
        'to' => $mobile,
        'message' => $text,
    ]);
    $response = smsHttpRequest(
        'https://api.bdbulksms.net/api.php?json',
        $body,
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $decoded = json_decode($response['body'], true);
    $ok = $response['ok'] && !smsWebhookLooksFailed((string) $response['body']);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? $row['response'] ?? '')));
            if ($status !== '' && !in_array($status, ['success', 'sent', 'ok'], true) && !str_contains($status, 'success')) {
                $ok = false;
            }
        }
    }

    return ['ok' => $ok, 'detail' => $ok ? 'bdbulksms' : smsResponseDetail($response, 'bdbulksms_failed')];
}

function dispatchSslWirelessSms(string $token, string $sid, array $numbers, string $text): array
{
    $messages = [];
    foreach ($numbers as $number) {
        $messages[] = [
            'msisdn' => $number,
            'text' => $text,
            'csms_id' => substr(bin2hex(random_bytes(8)), 0, 20),
        ];
    }
    $single = count($messages) === 1;
    $url = $single
        ? 'https://smsplus.sslwireless.com/api/v3/send-sms'
        : 'https://smsplus.sslwireless.com/api/v3/send-sms/bulk';
    $payload = $single
        ? [
            'api_token' => $token,
            'sid' => $sid,
            'msisdn' => $messages[0]['msisdn'],
            'sms' => $text,
            'csms_id' => $messages[0]['csms_id'],
        ]
        : [
            'api_token' => $token,
            'sid' => $sid,
            'sms' => $messages,
        ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $response = smsHttpRequest($url, $body === false ? '' : $body, ['Content-Type: application/json']);
    $decoded = json_decode($response['body'], true);
    $statusCode = is_array($decoded) ? (string) ($decoded['status_code'] ?? '') : '';
    $ok = $response['ok'] && ($statusCode === '' || in_array($statusCode, ['200', '202'], true));

    return ['ok' => $ok, 'detail' => $ok ? 'sslwireless' : smsResponseDetail($response, 'sslwireless_failed')];
}

function dispatchBulkSmsBd(string $token, string $sid, array $numbers, string $text): array
{
    $body = http_build_query([
        'api_key' => $token,
        'senderid' => $sid,
        'number' => implode(',', $numbers),
        'message' => $text,
    ]);
    $response = smsHttpRequest('https://bulksmsbd.net/api/smsapi', $body, ['Content-Type: application/x-www-form-urlencoded']);
    $decoded = json_decode($response['body'], true);
    $code = is_array($decoded) ? (string) ($decoded['response_code'] ?? '') : '';
    $ok = $response['ok'] && ($code === '' || $code === '202');

    return ['ok' => $ok, 'detail' => $ok ? 'bulksmsbd' : smsResponseDetail($response, 'bulksmsbd_failed')];
}

function smsHttpRequest(string $url, string $body, array $headers, string $method = 'POST'): array
{
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'body' => '', 'error' => 'invalid_url', 'code' => 0];
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'error' => 'curl_init_failed', 'code' => 0];
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $raw !== false && $code >= 200 && $code < 300,
            'body' => is_string($raw) ? $raw : '',
            'error' => $error,
            'code' => $code,
        ];
    }

    $headerText = $headers === [] ? '' : implode("\r\n", $headers) . "\r\n";
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'content' => $method === 'POST' ? $body : '',
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $code = 0;
    if (preg_match('/\s(\d{3})\s/', (string) $statusLine, $match) === 1) {
        $code = (int) $match[1];
    }

    return [
        'ok' => $raw !== false && $code >= 200 && $code < 300,
        'body' => is_string($raw) ? $raw : '',
        'error' => $raw === false ? 'request_failed' : '',
        'code' => $code,
    ];
}

function smsResponseDetail(array $response, string $fallback): string
{
    $body = trim((string) ($response['body'] ?? ''));
    if ($body !== '') {
        $body = preg_replace('/\s+/', ' ', $body) ?? $body;

        return function_exists('mb_substr') ? mb_substr($body, 0, 180, 'UTF-8') : substr($body, 0, 180);
    }
    $error = trim((string) ($response['error'] ?? ''));

    return $error !== '' ? $error : $fallback;
}

function logSchoolSms(PDO $db, string $context, array $numbers, string $status, string $detail): void
{
    if (!function_exists('tableExists') || !tableExists($db, 'sms_log')) {
        return;
    }
    $statement = $db->prepare(
        'INSERT INTO sms_log (context_key, recipient, status, detail) VALUES (:context_key, :recipient, :status, :detail)'
    );
    $detail = function_exists('mb_substr') ? mb_substr($detail, 0, 180, 'UTF-8') : substr($detail, 0, 180);
    foreach ($numbers as $number) {
        $statement->execute([
            'context_key' => substr($context, 0, 40),
            'recipient' => $number,
            'status' => $status,
            'detail' => $detail,
        ]);
    }
}

function smsPublicUrl(string $path): string
{
    if (function_exists('publicAbsoluteUrl')) {
        return publicAbsoluteUrl($path);
    }
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    $relative = function_exists('publicSiteUrl') ? publicSiteUrl($path) : $path;
    if ($host === '') {
        return $relative;
    }
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($secure ? 'https' : 'http') . '://' . $host . $relative;
}

function admissionTokenSmsText(PDO $db, string $token): string
{
    $school = schoolSettings($db);
    $schoolName = trim((string) ($school['school_name_bn'] ?? 'বিদ্যালয়'));
    $link = smsPublicUrl('online_admission');

    return $schoolName . ' ভর্তি টোকেন: ' . $token . '। আবেদন: ' . $link;
}

function parseBdBulkSmsApiParts(string $body): array
{
    $cleaned = str_ireplace(['</br>', '<br>', '<br/>', '<br />'], "\n", $body);
    $parts = preg_split('/\R+/', trim($cleaned)) ?: [];
    $values = [];
    foreach ($parts as $part) {
        $part = trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($part !== '') {
            $values[] = $part;
        }
    }

    return $values;
}

function fetchBdBulkSmsAccountInfo(string $token): array
{
    $empty = [
        'ok' => false,
        'balance' => null,
        'rate' => null,
        'sms_count' => null,
        'expire_date' => '',
        'days_remaining' => null,
        'detail' => '',
    ];
    $token = trim($token);
    if ($token === '') {
        $empty['detail'] = 'missing_token';

        return $empty;
    }

    $url = 'https://api.bdbulksms.net/g_api.php?token=' . rawurlencode($token) . '&balance&expiry&rate';
    $response = smsHttpRequest($url, '', [], 'GET');
    if (!$response['ok']) {
        $empty['detail'] = smsResponseDetail($response, 'account_lookup_failed');

        return $empty;
    }
    $parts = parseBdBulkSmsApiParts((string) $response['body']);
    if (count($parts) < 3) {
        $empty['detail'] = trim((string) $response['body']) !== ''
            ? smsResponseDetail($response, 'account_lookup_failed')
            : 'account_lookup_failed';

        return $empty;
    }

    $balance = is_numeric($parts[0]) ? (float) $parts[0] : null;
    $expireDate = (string) ($parts[1] ?? '');
    $rate = is_numeric($parts[2] ?? null) ? (float) $parts[2] : null;
    $smsCount = null;
    if ($balance !== null && $rate !== null && $rate > 0) {
        $smsCount = round($balance / $rate, 2);
    }
    $daysRemaining = null;
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $expireDate, $match) === 1) {
        $expireTs = strtotime($match[3] . '-' . $match[2] . '-' . $match[1] . ' 23:59:59');
        if ($expireTs !== false) {
            $daysRemaining = (int) max(0, floor(($expireTs - time()) / 86400));
        }
    }

    return [
        'ok' => true,
        'balance' => $balance,
        'rate' => $rate,
        'sms_count' => $smsCount,
        'expire_date' => $expireDate,
        'days_remaining' => $daysRemaining,
        'detail' => '',
    ];
}

function smsAccountOverview(PDO $db): array
{
    $settings = smsGatewaySettings($db);
    $token = (string) ($settings['token'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'detail' => 'missing_token'];
    }
    $provider = (string) ($settings['provider'] ?? '');
    $webhook = (string) ($settings['webhook'] ?? '');
    $isBdBulk = $provider === 'bdbulksms'
        || ($provider === 'webhook' && str_contains(strtolower($webhook), 'bdbulksms.net'));
    if (!$isBdBulk) {
        return ['ok' => false, 'detail' => 'provider_unsupported'];
    }

    $cacheKey = 'sms_account_overview_' . hash('sha256', $token);
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['expires_at'], $cached['data']) && (int) $cached['expires_at'] > time()) {
        return is_array($cached['data']) ? $cached['data'] : ['ok' => false, 'detail' => 'cache_error'];
    }

    $info = fetchBdBulkSmsAccountInfo($token);
    $_SESSION[$cacheKey] = [
        'expires_at' => time() + 120,
        'data' => $info,
    ];

    return $info;
}
