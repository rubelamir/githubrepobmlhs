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
$check(BillingPeriod::nextOpenMonth('2026', []) === '2026-01', 'monthly bill starts at January');
$check(BillingPeriod::nextOpenMonth('2026', ['M:2026-01']) === '2026-02', 'February follows a created January bill');
$check(BillingPeriod::nextOpenMonth('2026', ['M:2026-01', 'M:2026-02', 'O:2026-03', 'S:2026']) === '2026-03', 'other and session keys do not fill a month');
$check(BillingPeriod::nextOpenMonth('2026', ['M:2026-02']) === '2026-01', 'a later month does not skip an open January');
$check(BillingPeriod::nextOpenMonth('2026', array_map(static fn (int $month): string => sprintf('M:2026-%02d', $month), range(1, 11))) === '2026-12', 'December is next after November');
$check(BillingPeriod::nextOpenMonth('2026', array_map(static fn (int $month): string => sprintf('M:2026-%02d', $month), range(1, 12))) === '2026-12', 'December stays selected when every month exists');
$check($throws(static fn () => BillingPeriod::nextOpenMonth('20', [])), 'next month rejects a bad session');
$check($january->referenceDate()->format('Y-m-d') === '2026-01-01', 'monthly reference date');
$check(BillingPeriod::session('2026')->key() === 'S:2026', 'session key');
$admissionPeriod = BillingPeriod::admission('2026');
$check($admissionPeriod->key() === 'A:2026' && $admissionPeriod->kind() === BillingPeriod::SESSION, 'admission has its own yearly key and is still billed from session heads');
$check($admissionPeriod->label() !== BillingPeriod::session('2026')->label(), 'admission invoice has its own label');
$billKeys = ['M:2026-03', 'A:2026', 'S:2026', 'M:2026-01'];
usort($billKeys, [BillingPeriod::class, 'compareKeys']);
$check($billKeys === ['S:2026', 'A:2026', 'M:2026-03', 'M:2026-01'], 'bill filter lists session, then admission, then later months');
$sessionHead = ['billing_kind' => 'session', 'code' => 'SESSION', 'name_bn' => 'সেশন ফি'];
$admissionHead = ['billing_kind' => 'session', 'code' => 'ADMISSION', 'name_bn' => 'ভর্তি ফি'];
$check(BillingPeriod::headMatchesInvoiceKind($sessionHead, BillingPeriod::SESSION) && !BillingPeriod::headMatchesInvoiceKind($sessionHead, BillingPeriod::ADMISSION), 'session fee stays on the session bill');
$check(BillingPeriod::headMatchesInvoiceKind($admissionHead, BillingPeriod::ADMISSION) && !BillingPeriod::headMatchesInvoiceKind($admissionHead, BillingPeriod::SESSION), 'admission fee stays on the admission bill');
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
$check(BillingPeriod::coversMonthlyRate('M:2026-03', '2026-03-15'), '15 March sets the March collection rate');
$check(!BillingPeriod::coversMonthlyRate('M:2026-02', '2026-03-15'), 'February stays before a March effective date');
$check(BillingPeriod::coversMonthlyRate('M:2026-04', '2026-03-15'), 'April follows a March effective date');
$check(BillingPeriod::coversMonthlyRate('M:2026-03', '2026-01-01', '2026-03-20'), 'a rate ending mid-March still covers March');
$check(!BillingPeriod::coversMonthlyRate('M:2026-04', '2026-01-01', '2026-03-20'), 'a rate ending in March does not cover April');
$check(!BillingPeriod::coversMonthlyRate('S:2026', '2026-03-15'), 'session bills are outside the monthly rate');
$check(BillingPeriod::monthly('2026-03')->collectionDate()->format('Y-m-d') === '2026-03-31', 'March rate is chosen through the end of March');
$check(BillingPeriod::monthly('2026-02')->collectionDate()->format('Y-m-d') === '2026-02-28', 'February rate is chosen through the end of February');
$check(BillingPeriod::referenceDateForKey('M:2026-01', '2026-01-31') === '2026-01-01', 'January bill is covered from the 1st');
$check(BillingPeriod::referenceDateForKey('S:2026', '2026-03-01') === '2026-01-01', 'session bill is covered from 1 January');
$check(BillingPeriod::referenceDateForKey('A:2026', '2026-02-01') === '2026-01-01', 'admission bill is covered from 1 January');
$check(BillingPeriod::referenceDateForKey('E:2026:4', '2026-06-15') === '2026-06-15', 'exam bill uses its issue date');
$half = ['kind' => ConcessionService::PERCENT, 'value' => '50.00'];
$open = InvoiceService::concessionForExistingLine($half, 100000, 0, 0, 0, 100000);
$check($open['changed'] && $open['discount'] === 50000 && $open['net'] === 50000 && $open['advance'] === 0, 'full concession reduces an unpaid existing line');
$partlyPaid = InvoiceService::concessionForExistingLine($half, 100000, 0, 0, 80000, 100000);
$check($partlyPaid['changed'] && $partlyPaid['discount'] === 50000 && $partlyPaid['net'] === 50000 && $partlyPaid['advance'] === 30000, 'concession already collected above the new net becomes advance');
$smaller = InvoiceService::concessionForExistingLine(['kind' => ConcessionService::PERCENT, 'value' => '10.00'], 100000, 40000, 0, 0, 60000);
$check(!$smaller['changed'] && $smaller['net'] === 60000, 'a smaller concession does not raise an existing bill');

// A stipend or scholarship monthly rate replaces the issued monthly amount from its effective month.
$halfPaid = ['kind' => ConcessionService::PERCENT, 'value' => '50.00', 'id' => 9];
$scholarship = InvoiceService::monthlyCategoryForExistingLine(0, 100000, 3, null, 100000, 100000, 1, 0, 0, null, 0, 0, 100000);
$check($scholarship['changed'] && $scholarship['amount'] === 0 && $scholarship['net'] === 0 && $scholarship['category_concession'] === 100000 && $scholarship['advance'] === 0 && !$scholarship['raised'], 'unpaid monthly bill takes a zero scholarship rate');
$collected = InvoiceService::monthlyCategoryForExistingLine(0, 100000, 3, null, 100000, 100000, 1, 0, 0, null, 0, 80000, 100000);
$check($collected['changed'] && $collected['amount'] === 0 && $collected['net'] === 0 && $collected['advance'] === 80000 && !$collected['raised'], 'scholarship money already collected becomes advance');
$backToGeneral = InvoiceService::monthlyCategoryForExistingLine(100000, 100000, 1, null, 40000, 100000, 2, 60000, 0, null, 0, 0, 40000);
$check($backToGeneral['changed'] && $backToGeneral['net'] === 100000 && $backToGeneral['raised'] && $backToGeneral['advance'] === 0, 'returning to the general rate raises an unpaid monthly bill');
$sameRate = InvoiceService::monthlyCategoryForExistingLine(40000, 100000, 2, null, 40000, 100000, 2, 60000, 0, null, 0, 0, 40000);
$check(!$sameRate['changed'], 'the same monthly rate leaves the bill alone');
$withConcession = InvoiceService::monthlyCategoryForExistingLine(40000, 100000, 2, $halfPaid, 100000, 100000, 1, 0, 0, null, 0, 0, 100000);
$check($withConcession['changed'] && $withConcession['discount'] === 20000 && $withConcession['net'] === 20000 && $withConcession['concession_id'] === 9, 'personal concession is recalculated on the new monthly rate');

// A student's bills: session fee, admission fee, then each month's tuition,
// with exam and other fees after the tuition of the month they were created.
$bill = static function (int $id, string $key, string $issued, array $heads = []) {
    return [
        'id' => $id,
        'billing_key' => $key,
        'issue_date' => $issued,
        'session_year' => '2026',
        'heads' => $heads,
    ];
};
$sessionHead = ['code' => 'SESSION', 'name_bn' => 'সেশন ফি'];
$admissionHead = ['code' => 'ADMISSION', 'name_bn' => 'ভর্তি ফি'];
$bills = [
    $bill(9, 'E:2026:3', '2026-03-12', [['code' => 'EXAM', 'name_bn' => 'পরীক্ষার ফি']]),
    $bill(4, 'M:2026-03', '2026-03-01'),
    $bill(2, 'A:2026', '2026-01-05', [$admissionHead]),
    $bill(7, 'O:2026-01', '2026-01-20', [['code' => 'LAB', 'name_bn' => 'ল্যাব ফি']]),
    $bill(1, 'S:2026', '2026-01-04', [$sessionHead]),
    $bill(5, 'M:2026-01', '2026-03-20'),
    $bill(8, 'E:2026:1', '2026-01-18', [['code' => 'EXAM', 'name_bn' => 'পরীক্ষার ফি']]),
    $bill(6, 'M:2026-02', '2026-02-01'),
];
usort($bills, static fn (array $a, array $b): int => InvoiceService::displaySortKey($a) <=> InvoiceService::displaySortKey($b));
$check(array_column($bills, 'id') === [1, 2, 5, 8, 7, 6, 4, 9], 'student bills follow session, admission, then each month');

$lines = [
    ['id' => 3, 'line_kind' => 'fee', 'billing_kind' => 'session', 'code' => 'ADMISSION', 'name_bn' => 'ভর্তি ফি', 'sort_order' => 20],
    ['id' => 1, 'line_kind' => 'fee', 'billing_kind' => 'session', 'code' => 'SESSION', 'name_bn' => 'সেশন ফি', 'sort_order' => 30],
    ['id' => 2, 'line_kind' => 'late_fine', 'billing_kind' => 'session', 'code' => 'SESSION', 'name_bn' => 'সেশন ফি', 'sort_order' => 30],
];
usort($lines, static fn (array $a, array $b): int => InvoiceService::lineDisplaySortKey($a) <=> InvoiceService::lineDisplaySortKey($b));
$check(array_column($lines, 'id') === [1, 3, 2], 'session fee line comes before admission, then any fine');

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
