<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Billing\BillingPeriod;
use School\Accounting\Billing\ConcessionService;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Billing\InvoiceService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
    } catch (RuntimeException) {
        return true;
    }

    return false;
};

// Money: integer paisa, never float.
$check(Money::parse('1250') === 125000, 'parse whole taka');
$check(Money::parse('১২৫০.৫') === 125050, 'parse Bengali digits and one decimal');
$check(Money::parse('৳ 1,25,000.05') === 12500005, 'parse symbol and lakh commas');
$check(Money::parse('0.1') + Money::parse('0.2') === Money::parse('0.3'), 'no float drift');
foreach (['', '-5', '12.345', 'abc', '1e3', '12345678901'] as $bad) {
    $check($throws(static fn () => Money::parse($bad)), "reject money input '{$bad}'");
}
$check(Money::parseOptional('  ') === null, 'blank optional amount is null');
$check(Money::fromDb('-12.30') === -1230 && Money::fromDb('5') === 500 && Money::fromDb(null) === 0, 'fromDb exact');
$check(Money::toDb(-1230) === '-12.30' && Money::toDb(5) === '0.05', 'toDb exact');
$check(Money::toInput(125000) === '1250' && Money::toInput(125050) === '1250.50', 'toInput');
$check(Money::format(123456700) === '৳ ১২,৩৪,৫৬৭.০০', 'format lakh grouping in Bengali');
$check(Money::format(99, false, false) === '0.99', 'format plain');
$check(Money::percentOf(90000, 2500) === 22500, '25% of 900');
$check(Money::percentOf(333, 5000) === 167, 'percent rounds half up');
$check(Money::parsePercent('12.5') === 1250, 'percent to basis points');
$check($throws(static fn () => Money::parsePercent('100.01')), 'percent over 100 rejected');

// Invoice numbers.
$check(Sequence::format('INV', '2026', 42) === 'INV-2026-000042', 'invoice number format');

// Billing periods: the key is what prevents double billing.
$january = BillingPeriod::monthly('2026-01');
$check($january->key() === 'M:2026-01' && $january->kind() === BillingPeriod::MONTHLY, 'monthly key');
$check($january->referenceDate()->format('Y-m-d') === '2026-01-01', 'monthly reference date');
$check(BillingPeriod::session('2026')->key() === 'S:2026', 'session key');
$exam = BillingPeriod::exam(3, 'বার্ষিক পরীক্ষা', '2026', new DateTimeImmutable('2026-11-01'));
$check($exam->key() === 'E:2026:3', 'exam key includes the session');
$check($exam->key() !== BillingPeriod::exam(3, 'বার্ষিক পরীক্ষা', '2027', new DateTimeImmutable('2027-11-01'))->key(), 'same exam next session bills separately');
$check(BillingPeriod::other('2026-03')->key() === 'O:2026-03', 'other key');
$check($throws(static fn () => BillingPeriod::monthly('2026-13')), 'invalid month rejected');
$february = BillingPeriod::monthly('2026-02');
$check($february->dueDate(31, new DateTimeImmutable('2026-02-01'))->format('Y-m-d') === '2026-02-28', 'due day clamps to month end');
$check($january->dueDate(10, new DateTimeImmutable('2026-01-20'))->format('Y-m-d') === '2026-01-20', 'due date never before issue date');
$check(BillingPeriod::session('2026')->dueDate(15, new DateTimeImmutable('2026-02-01'))->format('Y-m-d') === '2026-02-16', 'session due date counts days from issue');
$check(InvoiceService::uniqKey(7, 1, 'M:2026-01', InvoiceService::LINE_FEE) === '7:1:M:2026-01:fee', 'line unique key');

// Rate resolution: own category > general; section > class-wide; latest effective date.
$general = 1;
$stipend = 2;
$scholarship = 3;
$row = static fn (int $section, int $category, string $from, string $amount): array => [
    'fee_head_id' => 1, 'section_id' => $section, 'fee_category_id' => $category, 'effective_from' => $from,
    'amount' => $amount, 'due_day' => 10, 'late_fine_amount' => '50.00', 'grace_days' => 5, 'is_active' => 1,
];
$rows = [
    $row(0, $general, '2026-01-01', '800.00'),
    $row(0, $stipend, '2026-01-01', '400.00'),
    $row(13, $general, '2026-01-01', '900.00'),
    $row(0, $general, '2026-07-01', '850.00'),
    $row(0, $scholarship, '2026-01-01', '0.00'),
];
$resolve = static fn (int $section, int $category, string $date) => FeeStructureRepository::resolve($rows, 1, $section, $category, $general, $date);
$check($resolve(14, $general, '2026-03-01')['amount'] === 80000, 'class-wide general rate');
$check($resolve(13, $general, '2026-03-01')['amount'] === 90000, 'section rate overrides class-wide');
$check($resolve(13, $general, '2026-08-01')['amount'] === 90000, 'section rate still wins over a newer class-wide rate');
$check($resolve(14, $general, '2026-08-01')['amount'] === 85000, 'latest effective date applies');
$stipendRate = $resolve(13, $stipend, '2026-03-01');
$check($stipendRate['amount'] === 40000 && $stipendRate['standard'] === 90000 && $stipendRate['category_id'] === $stipend, 'stipend rate differs from the regular rate');
$check($resolve(14, $scholarship, '2026-03-01')['amount'] === 0, 'zero rate is a valid scholarship rate');
$check($resolve(14, 4, '2026-03-01')['amount'] === 80000 && $resolve(14, 4, '2026-03-01')['category_id'] === $general, 'category without its own rate pays the general rate');
$check($resolve(14, $general, '2025-12-31') === null, 'no rate before the first effective date');
$inactive = $rows;
$inactive[2]['is_active'] = 0;
$check(FeeStructureRepository::resolve($inactive, 1, 13, $general, $general, '2026-03-01')['amount'] === 80000, 'inactive rows ignored');

// Concessions never exceed the line amount.
$check(ConcessionService::discountFor(['kind' => ConcessionService::PERCENT, 'value' => '25.00'], 90000) === 22500, '25% concession');
$check(ConcessionService::discountFor(['kind' => ConcessionService::FIXED, 'value' => '1000.00'], 40000) === 40000, 'fixed concession capped at amount');
$check(ConcessionService::describe(['kind' => ConcessionService::PERCENT, 'value' => '12.50']) === '১২.৫%', 'describe percent');

// Invoice status follows the numbers.
$check(InvoiceService::statusFor(90000, 0) === InvoiceService::UNPAID, 'unpaid');
$check(InvoiceService::statusFor(90000, 100) === InvoiceService::PARTIAL, 'partial');
$check(InvoiceService::statusFor(90000, 90000) === InvoiceService::PAID, 'paid');
$check(InvoiceService::statusFor(0, 0) === InvoiceService::PAID, 'fully concessioned invoice is settled');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
