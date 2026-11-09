<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Gateway\GatewayConfig;
use School\Accounting\Gateway\GatewayHttpClient;
use School\Accounting\Gateway\GatewayResult;
use School\Accounting\Gateway\NagadGateway;
use School\Accounting\Support\Money;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$masked = GatewayHttpClient::mask([
    'app_key' => 'secret-key',
    'password' => '',
    'Authorization' => 'id-token-value',
    'payerAccount' => '01712345678',
    'nested' => ['refresh_token' => 'abc', 'amount' => '10.00'],
    'bkashURL' => 'https://pay.example/checkout?paymentId=1&hash=deadbeef',
    'note' => 'plain',
]);
$check($masked['app_key'] === '***', 'app key is masked');
$check($masked['password'] === '', 'empty secret stays empty');
$check($masked['Authorization'] === '***', 'authorization header is masked');
$check($masked['payerAccount'] === '017*****678', 'wallet number is masked');
$check($masked['nested']['refresh_token'] === '***' && $masked['nested']['amount'] === '10.00', 'nested secrets are masked and amounts kept');
$check($masked['bkashURL'] === 'https://pay.example/checkout?***', 'callback hash is stripped');
$check($masked['note'] === 'plain', 'ordinary fields are kept');
$check(GatewayHttpClient::maskWallet('01770618575') === '017*****575', 'documented wallet mask');
$check(GatewayHttpClient::maskWallet('123456') === '******', 'short wallet is fully masked');
$check(GatewayHttpClient::maskWallet('') === '', 'empty wallet stays empty');

$check(GatewayConfig::validBaseUrl('https://tokenized.sandbox.bka.sh/v2/tokenized-checkout'), 'sandbox https is valid');
$check(GatewayConfig::validBaseUrl('http://127.0.0.1:8099/mock'), 'loopback http is valid');
$check(GatewayConfig::validBaseUrl('http://localhost/mock'), 'localhost http is valid');
$check(!GatewayConfig::validBaseUrl('http://tokenized.pay.bka.sh/v2'), 'public http is rejected');
$check(!GatewayConfig::validBaseUrl('ftp://127.0.0.1/x'), 'non-http scheme is rejected');
$check(!GatewayConfig::validBaseUrl('not a url'), 'garbage url is rejected');
$check(GatewayConfig::isLoopback('http://127.0.0.1:9/x') && !GatewayConfig::isLoopback('https://bka.sh'), 'loopback detection');

$check(Money::percentOf(10000, 150) === 150, '1.50% of 100 taka is 1.50');
$check(Money::percentOf(100, 150) === 2, 'half-up rounding of a charge');
$check(Money::parsePercent('1.50') === 150, 'percent parses to basis points');
$check(Money::parsePercent('0') === 0, 'zero percent');
$check(Money::parsePercent('10.01') === 1001, '10.01% parses; the 10% ceiling is applied when gateway settings are saved');
$over = false;
try {
    Money::parsePercent('101');
} catch (RuntimeException) {
    $over = true;
}
$check($over, 'percent above 100 is rejected');

$nagad = new NagadGateway();
$check($nagad->code() === 'nagad' && $nagad->isReady() === false, 'Nagad adapter is not ready without documentation');
$result = $nagad->createPayment(1, 'OLP-2026-000001', 10000, 'SID1', 'https://example.test/cb');
$check($result->is(GatewayResult::ERROR) && $result->errorCode === 'not_implemented', 'Nagad create does not invent an API');
$check($nagad->refundHistory(1, 'p', 't') === null, 'Nagad refund history is unavailable');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
