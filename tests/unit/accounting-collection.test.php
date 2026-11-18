<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Billing\InvoiceService;
use School\Accounting\Collection\Allocator;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\AmountInWords;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$lines = [['id' => 7, 'outstanding' => 45000], ['id' => 8, 'outstanding' => 0], ['id' => 9, 'outstanding' => 90000]];
$result = Allocator::allocate($lines, 50000);
$check($result['lines'] === [7 => 45000, 9 => 5000] && $result['remainder'] === 0, 'lines settle in the order they are given');
$due = static function (int $id, string $key, string $issue = '2026-01-01', string $kind = 'fee', int $sort = 0): array {
    return ['id' => $id, 'invoice_id' => $id, 'billing_key' => $key, 'issue_date' => $issue, 'line_kind' => $kind, 'sort_order' => $sort];
};
$dues = [
    $due(4, 'E:2026:2', '2026-06-01'),
    $due(2, 'A:2026'),
    $due(5, 'O:2026-01', '2026-01-20'),
    $due(3, 'M:2026-03', '2026-03-31'),
    $due(1, 'S:2026'),
    $due(6, 'M:2026-01', '2026-03-20'),
    $due(7, 'E:2026:1', '2026-03-01'),
];
usort($dues, static fn (array $left, array $right): int => InvoiceService::paymentSortKey($left) <=> InvoiceService::paymentSortKey($right));
$check(array_column($dues, 'id') === [1, 2, 6, 3, 7, 4, 5], 'collection pays session, admission, earlier months, exam, then other');
$fine = $due(9, 'S:2026', '2026-01-01', 'late_fine');
$fine['invoice_id'] = 1;
$pair = [$fine, $due(1, 'S:2026')];
$pair[1]['invoice_id'] = 1;
usort($pair, static fn (array $left, array $right): int => InvoiceService::paymentSortKey($left) <=> InvoiceService::paymentSortKey($right));
$check(array_column($pair, 'id') === [1, 9], 'a fine is settled after its own fee');
$result = Allocator::allocate($lines, 200000);
$check($result['lines'] === [7 => 45000, 9 => 90000] && $result['remainder'] === 65000, 'overpayment leaves a remainder for advance');
$result = Allocator::allocate([], 1000);
$check($result['lines'] === [] && $result['remainder'] === 1000, 'no dues: whole amount is remainder');
$check(array_sum(Allocator::allocate($lines, 123457)['lines']) + Allocator::allocate($lines, 123457)['remainder'] === 123457, 'allocation conserves every paisa');
$threw = false;
try {
    Allocator::allocate($lines, -1);
} catch (RuntimeException) {
    $threw = true;
}
$check($threw, 'negative amount rejected');

$kept = PaymentService::resolveInvoiceSelection([4, 0, 4], false);
$check($kept['ids'] === [4] && $kept['settle_none'] === false, 'callers without a bill list still settle the given invoices');
$all = PaymentService::resolveInvoiceSelection([], false);
$check($all['ids'] === [] && $all['settle_none'] === false, 'an empty list without a bill list still means every outstanding bill');
$unticked = PaymentService::resolveInvoiceSelection([], true);
$check($unticked['ids'] === [] && $unticked['settle_none'] === true, 'unticked bills are not applied until a bill is chosen');

$check(AmountInWords::taka(0) === 'শূন্য টাকা মাত্র', 'zero');
$check(AmountInWords::taka(200000) === 'দুই হাজার টাকা মাত্র', 'two thousand');
$check(AmountInWords::taka(125050) === 'এক হাজার দুইশত পঞ্চাশ টাকা পঞ্চাশ পয়সা মাত্র', 'taka and paisa');
$check(AmountInWords::taka(9900) === 'নিরানব্বই টাকা মাত্র', 'ninety-nine');
$check(AmountInWords::taka(12345678900) === 'বারো কোটি চৌত্রিশ লক্ষ ছাপ্পান্ন হাজার সাতশত ঊননব্বই টাকা মাত্র', 'crore and lakh scale');
$check(AmountInWords::taka(10000001) === 'এক লক্ষ টাকা এক পয়সা মাত্র', 'round lakh with paisa');

$keyA = PaymentService::newIdempotencyKey();
$keyB = PaymentService::newIdempotencyKey();
$check(preg_match('/^[a-f0-9]{32}$/', $keyA) === 1 && $keyA !== $keyB, 'idempotency keys are random 32-hex');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
