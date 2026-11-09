<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

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
$check($result['lines'] === [7 => 45000, 9 => 5000] && $result['remainder'] === 0, 'oldest due first, settled lines skipped');
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
