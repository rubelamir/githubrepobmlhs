<?php
declare(strict_types=1);

/**
 * End-to-end check of online payments against the local database and a mock
 * bKash server (tests/fixtures/bkash-mock-server.php). Creates one throwaway
 * student and removes every row it creates.
 *
 *   ADMINLTE_DB_HOST / ADMINLTE_DB_NAME / ADMINLTE_DB_USER / ADMINLTE_DB_PASSWORD
 */

putenv('ADMINLTE_APP_SECRET=phase5-gateway-test-secret-key');

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/schema.php';
require_once __DIR__ . '/../../shared/security.php';
require_once __DIR__ . '/../../shared/sms.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Access\AccessContext;
use School\Accounting\Access\Role;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Gateway\OnlinePaymentService;
use School\Accounting\Gateway\RefundService;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Support\Money;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "  ok  {$label}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$label}\n");
};

$dbHost = getenv('ADMINLTE_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('ADMINLTE_DB_NAME') ?: 'bmlhs';
$dbUser = getenv('ADMINLTE_DB_USER') ?: 'root';
$dbPass = getenv('ADMINLTE_DB_PASSWORD') ?: '';
$db = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);

$port = 8099;
$stateFile = sys_get_temp_dir() . '/bkash-mock-state.json';
$modeFile = sys_get_temp_dir() . '/bkash-mock-mode.json';
file_put_contents($stateFile, '{}');
$mode = static function (array $next) use ($modeFile): void {
    file_put_contents($modeFile, json_encode($next));
};
$mode(['execute' => 'completed', 'query' => 'initiated', 'refund' => 'completed', 'query_verified' => false]);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/bkash-mock-server.php'],
    [1 => ['file', sys_get_temp_dir() . '/bkash-mock.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/bkash-mock.log', 'a']],
    $pipes,
    null,
    ['BKASH_MOCK_STATE' => $stateFile, 'BKASH_MOCK_MODE' => $modeFile, 'BKASH_MOCK_PORT' => (string) $port]
);
$up = false;
for ($i = 0; $i < 40; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $up = true;
        break;
    }
    usleep(100000);
}
if (!$up) {
    fwrite(STDERR, "mock server did not start\n" . (string) @file_get_contents(sys_get_temp_dir() . '/bkash-mock.log'));
    exit(1);
}

putenv('BMLHS_BKASH_BASE_URL=http://127.0.0.1:' . $port . '/v2/tokenized-checkout');
putenv('BMLHS_BKASH_APP_KEY=test-app-key');
putenv('BMLHS_BKASH_APP_SECRET=test-app-secret');
putenv('BMLHS_BKASH_USERNAME=sandbox-user');
putenv('BMLHS_BKASH_PASSWORD=sandbox-pass');

$services = accStandaloneServices($db, 'গেটওয়ে টেস্ট');
$online = $services['online'];
$refunds = $services['refunds'];
$chart = new ChartOfAccounts($db);
$teacherId = (int) $db->query('SELECT id FROM teacher_info ORDER BY id LIMIT 1')->fetchColumn();
$head = new AccessContext($teacherId, 'টেস্ট প্রধান', [Role::HEAD], ['entire' => [], 'sections' => []], '2026');
$officer = new AccessContext($teacherId, 'টেস্ট অফিসার', [Role::OFFICER], ['entire' => [], 'sections' => []], '2026');
$headAudit = new AuditTrail($db, $head);
$officerAudit = new AuditTrail($db, $officer);
$originalSettings = $online->settings('bkash');
$bkashBefore = $chart->balance($chart->idOf(ChartOfAccounts::BKASH));
$chargeBefore = $chart->balance($chart->idOf(ChartOfAccounts::GATEWAY_CHARGE));

$studentId = 0;
$invoiceId = 0;
$itemId = 0;
$bankId = 0;
$createdBank = false;
$paymentIds = [];
$txnIds = [];
$voucherIds = [];

$trackTxn = static function (int $txnId) use (&$txnIds, $db, &$paymentIds): array {
    $txnIds[] = $txnId;
    $txn = fetchRows($db, 'SELECT * FROM acc_gateway_transactions WHERE id = :id', ['id' => $txnId])[0];
    if ($txn['payment_id']) {
        $paymentIds[] = (int) $txn['payment_id'];
    }

    return $txn;
};

try {
    $over = false;
    try {
        $online->saveSettings($head, $headAudit, 'bkash', ['enabled' => '1', 'charge_bearer' => 'payer', 'charge_rate' => '10.01']);
    } catch (RuntimeException) {
        $over = true;
    }
    $check($over, 'charge rate above 10% is rejected');
    $online->saveSettings($head, $headAudit, 'bkash', ['enabled' => '1', 'charge_bearer' => 'payer', 'charge_rate' => '1.5']);
    $quote = $online->quote('bkash', 10000);
    $check($quote === ['fee' => 10000, 'charge' => 150, 'payable' => 10150, 'bearer' => 'payer'], 'payer bears 1.50% on top of the fee');
    $online->saveSettings($head, $headAudit, 'bkash', ['enabled' => '1', 'charge_bearer' => 'school', 'charge_rate' => '0']);
    $check($online->isAvailable('bkash'), 'bKash is available against the mock');
    $check($online->isAvailable('nagad') === false, 'Nagad stays unavailable');

    $now = AuditTrail::now()->format('Y-m-d H:i:s');
    $today = AuditTrail::now()->format('Y-m-d');
    $blankColumns = [
        'fname_eng', 'fname_ban', 'father_nid_no', 'mname_eng', 'mname_ban', 'mother_nid_no', 'gender', 'religion',
        'birth_date', 'birth_reg_no', 'post_office', 'village', 'house_name', 'reg_no', 'exam_roll', 'passing_year',
        'scholarship_stipend_info', 'last_school_name', 'pass', 'student_pic', 'birth_reg_pic',
    ];
    $studentParams = [
        'token' => 'gwtest-' . bin2hex(random_bytes(6)),
        'section' => '',
        'year' => '2026',
        'name_eng' => 'Gateway Test',
        'name_ban' => 'গেটওয়ে টেস্ট',
        'mobile' => '01700000901',
    ];
    $studentSql = [];
    foreach ($blankColumns as $index => $column) {
        $studentParams['b' . $index] = '';
        $studentSql[] = ':' . 'b' . $index;
    }
    $db->prepare(
        'INSERT INTO students_info
            (admission_token, cls_roll, cls, sections, session_year, name_eng, name_ban, ' . implode(', ', $blankColumns) . ',
             division, district, upazilla, mobile, status)
         VALUES (:token, 99001, 1, :section, :year, :name_eng, :name_ban, ' . implode(', ', $studentSql) . ', 1, 1, 1, :mobile, 1)'
    )->execute($studentParams);
    $studentId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO acc_invoices
            (invoice_no, student_id, session_year, class_id, section_id, roll_snapshot, name_snapshot, billing_key, billing_label,
             issue_date, due_date, gross, net, status, generated_by, created_at)
         VALUES (:no, :student, :year, 1, 0, :roll, :name, :key, :label, :issue, :due, 100.00, 100.00, :status, :actor, :now)'
    )->execute([
        'no' => 'INV-GWTEST-' . $studentId, 'student' => $studentId, 'year' => '2026', 'roll' => '99001', 'name' => 'গেটওয়ে টেস্ট',
        'key' => 'gwtest', 'label' => 'টেস্ট মাস', 'issue' => $today, 'due' => $today, 'status' => 'unpaid', 'actor' => $teacherId, 'now' => $now,
    ]);
    $invoiceId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO acc_invoice_items
            (invoice_id, student_id, billing_key, fee_head_id, line_kind, uniq_key, description, amount, net, due_date, created_at)
         VALUES (:invoice, :student, :key, 1, :kind, :uniq, :desc, 100.00, 100.00, :due, :now)'
    )->execute([
        'invoice' => $invoiceId, 'student' => $studentId, 'key' => 'gwtest', 'kind' => 'fee', 'uniq' => 'gwtest-' . $studentId,
        'desc' => 'টেস্ট বেতন', 'due' => $today, 'now' => $now,
    ]);
    $itemId = (int) $db->lastInsertId();

    $start = static function () use ($online, $studentId, $invoiceId): array {
        return $online->start('bkash', $studentId, [$invoiceId], bin2hex(random_bytes(16)), '127.0.0.1', 'https://bmlhs.site/bmlhs/fee-payment-callback.php');
    };

    $mode(['execute' => 'mismatch', 'query' => 'initiated', 'refund' => 'completed', 'query_verified' => false]);
    $mismatch = $start();
    $online->handleCallback('bkash', paymentRef($db, $mismatch['txn_id']), 'success');
    $txn = $trackTxn($mismatch['txn_id']);
    $check($txn['status'] === OnlinePaymentService::REVIEW && $txn['payment_id'] === null, 'amount mismatch becomes review and records no receipt');

    $mode(['execute' => 'completed', 'query' => 'initiated', 'refund' => 'completed', 'query_verified' => false]);
    $cancel = $start();
    $online->handleCallback('bkash', paymentRef($db, $cancel['txn_id']), 'cancel');
    $txn = $trackTxn($cancel['txn_id']);
    $check($txn['status'] === OnlinePaymentService::CANCELLED && $txn['payment_id'] === null, 'cancel is confirmed by query, not by the callback alone');

    $failed = $start();
    $online->handleCallback('bkash', paymentRef($db, $failed['txn_id']), 'failure');
    $txn = $trackTxn($failed['txn_id']);
    $check($txn['status'] === OnlinePaymentService::FAILED, 'failure hint closes the payment after query');

    $mode(['execute' => 'timeout', 'query' => 'completed', 'refund' => 'completed', 'query_verified' => false]);
    $recovered = $start();
    $online->handleCallback('bkash', paymentRef($db, $recovered['txn_id']), 'success');
    $txn = $trackTxn($recovered['txn_id']);
    $check($txn['status'] === OnlinePaymentService::SUCCESS && $txn['trx_id'] === 'TRX' . $txn['gateway_payment_id'], 'execute timeout is resolved by query');
    $check($txn['payer_account_masked'] === '017*****678', 'payer wallet stored masked');
    $receipts = (int) $db->query('SELECT COUNT(*) FROM acc_payments WHERE student_id = ' . $studentId)->fetchColumn();
    $online->handleCallback('bkash', (string) $txn['gateway_payment_id'], 'success');
    $receiptsAfter = (int) $db->query('SELECT COUNT(*) FROM acc_payments WHERE student_id = ' . $studentId)->fetchColumn();
    $check($receiptsAfter === $receipts, 'a second callback does not record the fee again');
    $paid = Money::fromDb(fetchRows($db, 'SELECT paid FROM acc_invoice_items WHERE id = :id', ['id' => $itemId])[0]['paid']);
    $check($paid === 10000, 'invoice line shows the fee paid');

    $log = fetchRows($db, 'SELECT request_masked FROM acc_gateway_logs WHERE gateway_txn_id = :id ORDER BY id', ['id' => $recovered['txn_id']])[0]['request_masked'] ?? '';
    $check(!str_contains((string) $log, 'test-app-secret') && !str_contains((string) $log, 'sandbox-id-token') && str_contains((string) $log, '***'), 'gateway log masks the secret and the token');

    $asked = $refunds->request($officer, $officerAudit, (int) $txn['id'], '100', 'টেস্ট রিফান্ড', bin2hex(random_bytes(16)));
    $mode(['execute' => 'completed', 'query' => 'completed', 'refund' => 'error', 'query_verified' => false]);
    $status = $refunds->decide($head, $headAudit, $asked['refund_id'], true, '');
    $check($status === RefundService::FAILED, 'a rejected gateway refund stays failed and does not touch the books');
    $paid = Money::fromDb(fetchRows($db, 'SELECT paid FROM acc_invoice_items WHERE id = :id', ['id' => $itemId])[0]['paid']);
    $check($paid === 10000, 'failed refund leaves the receipt intact');
    $mode(['execute' => 'completed', 'query' => 'completed', 'refund' => 'completed', 'query_verified' => false]);
    $status = $refunds->decide($head, $headAudit, $asked['refund_id'], true, 'আবার');
    $check($status === RefundService::COMPLETED, 'retrying a failed refund completes it');
    $paid = Money::fromDb(fetchRows($db, 'SELECT paid FROM acc_invoice_items WHERE id = :id', ['id' => $itemId])[0]['paid']);
    $check($paid === 0, 'completed refund returns the fee to outstanding');

    $mode(['execute' => 'completed', 'query' => 'initiated', 'refund' => 'completed', 'query_verified' => true]);
    $pending = $start();
    $db->prepare('UPDATE acc_gateway_transactions SET created_at = :t WHERE id = :id')->execute([
        't' => AuditTrail::now()->modify('-5 minutes')->format('Y-m-d H:i:s'),
        'id' => $pending['txn_id'],
    ]);
    $counts = $online->reconcilePending(20);
    $txn = $trackTxn($pending['txn_id']);
    $check($txn['status'] === OnlinePaymentService::SUCCESS && ($counts[OnlinePaymentService::SUCCESS] ?? 0) >= 1, 'cron executes a verified payment the browser never confirmed');
    $refundId = $refunds->request($officer, $officerAudit, (int) $txn['id'], '100', 'ক্রন রিফান্ড', bin2hex(random_bytes(16)))['refund_id'];
    $mode(['execute' => 'completed', 'query' => 'completed', 'refund' => 'silent', 'query_verified' => false]);
    $status = $refunds->decide($head, $headAudit, $refundId, true, '');
    $check($status === RefundService::PROCESSING, 'a refund with no gateway answer stays processing');
    $state = json_decode((string) file_get_contents($stateFile), true);
    $state['refunds'][(string) $txn['gateway_payment_id']][] = ['trx' => 'RFDHIST' . $txn['id'], 'amount' => '100.00'];
    file_put_contents($stateFile, json_encode($state));
    $db->prepare('UPDATE acc_refunds SET last_attempt_at = :t WHERE id = :id')->execute([
        't' => AuditTrail::now()->modify('-3 minutes')->format('Y-m-d H:i:s'),
        'id' => $refundId,
    ]);
    $again = $refunds->reconcileProcessing($headAudit, 20);
    $check(($again[RefundService::COMPLETED] ?? 0) >= 1, 'cron matches the refund from Refund Status');
    $paid = Money::fromDb(fetchRows($db, 'SELECT paid FROM acc_invoice_items WHERE id = :id', ['id' => $itemId])[0]['paid']);
    $check($paid === 0, 'history-confirmed refund restores the invoice');

    $grants = (int) (json_decode((string) file_get_contents($stateFile), true)['grants'] ?? 0);
    $check($grants === 1, 'the id_token is cached; Grant Token ran once');
    $db->prepare("UPDATE acc_gateway_tokens SET expires_at = :past, calls_json = :calls WHERE gateway = 'bkash'")->execute([
        'past' => AuditTrail::now()->modify('-1 hour')->format('Y-m-d H:i:s'),
        'calls' => json_encode([time() - 10, time() - 20]),
    ]);
    $limited = false;
    try {
        $start();
    } catch (RuntimeException $exception) {
        $limited = str_contains($exception->getMessage(), 'সীমা');
    }
    $check($limited, 'a third Grant Token within the hour is refused');

    $sms = [];
    $portal = new School\Accounting\Gateway\GuardianPortal($db, static function (string $mobile, string $text) use (&$sms): bool {
        $sms[] = [$mobile, $text];

        return true;
    });
    $sent = $portal->requestOtp(1, 0, '99001', '01700000901', '203.0.113.9');
    $check($sent !== null && $sent['student_id'] === $studentId && $sms !== [], 'matching guardian details send one code');
    $check($portal->requestOtp(1, 0, '99001', '01700000000', '203.0.113.10') === null, 'a wrong mobile gets the same kind of stored attempt and no code');
    preg_match('/(\d{6})/u', $sms[0][1], $codeMatch);
    $check($portal->verifyOtp((int) $sent['otp_id'], $studentId, '000000') === 0, 'a wrong code is rejected');
    $check($portal->verifyOtp((int) $sent['otp_id'], $studentId, $codeMatch[1] ?? '') === $studentId, 'the right code signs the guardian in');
    $blocked = false;
    try {
        $portal->requestOtp(1, 0, '99001', '01700000901', '203.0.113.11');
        $portal->requestOtp(1, 0, '99001', '01700000901', '203.0.113.11');
        $portal->requestOtp(1, 0, '99001', '01700000901', '203.0.113.11');
    } catch (RuntimeException) {
        $blocked = true;
    }
    $check($blocked, 'a fourth code for the same student inside 30 minutes is refused');

    $db->prepare("DELETE FROM acc_gateway_tokens WHERE gateway = 'bkash'")->execute();
    $bankId = (int) $db->query("SELECT id FROM acc_accounts WHERE money_kind = 'bank' AND is_group = 0 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($bankId < 1) {
        $db->prepare(
            "INSERT INTO acc_accounts (code, name_bn, account_type, money_kind, parent_id, is_group, is_system, is_active, created_at, updated_at)
             SELECT '1151', 'টেস্ট ব্যাংক', 'asset', 'bank', id, 0, 0, 1, :now, :now2 FROM acc_accounts WHERE code = '1150'"
        )->execute(['now' => $now, 'now2' => $now]);
        $bankId = (int) $db->lastInsertId();
        $createdBank = true;
    }
    $db->beginTransaction();
    $voucherIds[] = (new VoucherService($db, $chart, new School\Accounting\Ledger\PeriodClosing($db, $chart)))->postSystem(
        VoucherService::JOURNAL,
        $today,
        'টেস্ট গেটওয়ে স্থিতি',
        [
            ['account_id' => $chart->idOf(ChartOfAccounts::BKASH), 'debit' => 5000, 'credit' => 0, 'memo' => 'টেস্ট'],
            ['account_id' => $chart->idOf('3100'), 'debit' => 0, 'credit' => 5000, 'memo' => 'টেস্ট'],
        ],
        'gwtest:seed:' . $studentId,
        $teacherId
    );
    $db->commit();
    $submitted = (new School\Accounting\Gateway\SettlementService($db, $chart, new VoucherService($db, $chart, new School\Accounting\Ledger\PeriodClosing($db, $chart)), new School\Accounting\Ledger\PeriodClosing($db, $chart)))
        ->submit($officer, $officerAudit, bin2hex(random_bytes(16)), [
            'gateway' => 'bkash',
            'settlement_date' => $today,
            'gross' => '50',
            'charge' => '2',
            'bank_account_id' => $bankId,
            'reference' => 'GWTEST-' . $studentId,
            'note' => 'টেস্ট সেটেলমেন্ট',
        ]);
    $settlements = new School\Accounting\Gateway\SettlementService($db, $chart, new VoucherService($db, $chart, new School\Accounting\Ledger\PeriodClosing($db, $chart)), new School\Accounting\Ledger\PeriodClosing($db, $chart));
    $settlements->decide($head, $headAudit, $submitted['settlement_id'], true, '');
    $row = fetchRows($db, 'SELECT * FROM acc_settlements WHERE id = :id', ['id' => $submitted['settlement_id']])[0];
    $check($row['status'] === 'verified' && $row['voucher_id'] !== null, 'the head verifying a settlement posts the voucher');
    $check($chart->balance($chart->idOf(ChartOfAccounts::BKASH)) === $bkashBefore, 'settled bKash balance is back to where the test started');
    $check($chart->balance($chart->idOf(ChartOfAccounts::GATEWAY_CHARGE)) === $chargeBefore + 200, 'the gateway charge is booked on its own account');
    $voucherIds[] = (int) $row['voucher_id'];
    $db->beginTransaction();
    $voucherIds[] = (new VoucherService($db, $chart, new School\Accounting\Ledger\PeriodClosing($db, $chart)))->postSystem(
        VoucherService::JOURNAL,
        $today,
        'টেস্ট সেটেলমেন্ট উল্টানো',
        [
            ['account_id' => $bankId, 'debit' => 0, 'credit' => 4800, 'memo' => 'টেস্ট'],
            ['account_id' => $chart->idOf(ChartOfAccounts::GATEWAY_CHARGE), 'debit' => 0, 'credit' => 200, 'memo' => 'টেস্ট'],
            ['account_id' => $chart->idOf('3100'), 'debit' => 5000, 'credit' => 0, 'memo' => 'টেস্ট'],
        ],
        'gwtest:undo:' . $studentId,
        $teacherId
    );
    $db->commit();
    $check($chart->balance($chart->idOf(ChartOfAccounts::GATEWAY_CHARGE)) === $chargeBefore, 'undoing the test settlement clears the charge');
    $settlementId = $submitted['settlement_id'];
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'EXCEPTION ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    $settlementId = $settlementId ?? 0;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($studentId > 0) {
        foreach (fetchRows($db, 'SELECT id, payment_id FROM acc_gateway_transactions WHERE student_id = :id', ['id' => $studentId]) as $row) {
            $txnIds[] = (int) $row['id'];
            if ($row['payment_id']) {
                $paymentIds[] = (int) $row['payment_id'];
            }
        }
    }
    $online->saveSettings($head, $headAudit, 'bkash', [
        'enabled' => $originalSettings['enabled'] ? '1' : '',
        'charge_bearer' => $originalSettings['charge_bearer'],
        'charge_rate' => Money::toInput($originalSettings['charge_rate_bp']),
    ]);
    if ($txnIds !== []) {
        $in = implode(',', array_map('intval', $txnIds));
        $db->exec('DELETE FROM acc_refunds WHERE gateway_txn_id IN (' . $in . ')');
        $db->exec('DELETE FROM acc_gateway_logs WHERE gateway_txn_id IN (' . $in . ')');
        $db->exec('DELETE FROM acc_gateway_transactions WHERE id IN (' . $in . ')');
    }
    if ($paymentIds !== []) {
        $in = implode(',', array_map('intval', $paymentIds));
        $extra = fetchRows($db, 'SELECT id, source_key FROM acc_vouchers WHERE source_key LIKE :k', ['k' => 'payment:%']);
        foreach ($extra as $voucher) {
            if (preg_match('/^payment:(\d+)/', (string) $voucher['source_key'], $match) === 1 && in_array((int) $match[1], $paymentIds, true)) {
                $voucherIds[] = (int) $voucher['id'];
            }
        }
        $db->exec('DELETE FROM acc_payment_allocations WHERE payment_id IN (' . $in . ')');
        $db->exec('DELETE FROM acc_payments WHERE id IN (' . $in . ')');
    }
    if (isset($settlementId) && $settlementId > 0) {
        $db->prepare('DELETE FROM acc_settlements WHERE id = :id')->execute(['id' => $settlementId]);
    }
    $voucherIds = array_values(array_unique(array_filter($voucherIds)));
    if ($voucherIds !== []) {
        $in = implode(',', array_map('intval', $voucherIds));
        $db->exec('DELETE FROM acc_voucher_lines WHERE voucher_id IN (' . $in . ')');
        $db->exec('DELETE FROM acc_vouchers WHERE id IN (' . $in . ')');
    }
    if ($studentId > 0) {
        $db->prepare('DELETE FROM acc_guardian_otps WHERE student_id = :id')->execute(['id' => $studentId]);
        $db->prepare("DELETE FROM acc_gateway_logs WHERE request_masked LIKE '%SID" . $studentId . "%'")->execute();
    }
    if ($itemId > 0) {
        $db->prepare('DELETE FROM acc_invoice_items WHERE id = :id')->execute(['id' => $itemId]);
    }
    if ($invoiceId > 0) {
        $db->prepare('DELETE FROM acc_invoices WHERE id = :id')->execute(['id' => $invoiceId]);
    }
    if ($studentId > 0) {
        $db->prepare('DELETE FROM students_info WHERE id = :id AND name_ban = :name')->execute(['id' => $studentId, 'name' => 'গেটওয়ে টেস্ট']);
    }
    if ($createdBank && $bankId > 0) {
        $db->prepare('DELETE FROM acc_accounts WHERE id = :id AND code = :code')->execute(['id' => $bankId, 'code' => '1151']);
    }
    $db->prepare("DELETE FROM acc_gateway_tokens WHERE gateway = 'bkash'")->execute();
    proc_terminate($server);
    proc_close($server);
    @unlink($stateFile);
    @unlink($modeFile);
}

$bkashAfter = (new ChartOfAccounts($db))->balance((new ChartOfAccounts($db))->idOf(ChartOfAccounts::BKASH));
$check($bkashAfter === $bkashBefore, 'bKash ledger balance is unchanged after cleanup');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failure(s)\n");
    exit(1);
}
echo "PASS\n";

function paymentRef(PDO $db, int $txnId): string
{
    return (string) fetchRows($db, 'SELECT gateway_payment_id FROM acc_gateway_transactions WHERE id = :id', ['id' => $txnId])[0]['gateway_payment_id'];
}
