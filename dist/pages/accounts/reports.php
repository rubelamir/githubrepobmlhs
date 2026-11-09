<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Report\ReportPdf;
use School\Accounting\Report\ReportService;
use School\Accounting\Report\Workbook;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
if (!$access->can(Capability::REPORTS_VIEW_ALL) && !$access->can(Capability::REPORTS_VIEW_CLASS)) {
    redirectWithMessage('dashboard.php', 'danger', 'রিপোর্ট দেখার অনুমতি আপনার নেই।');
}
$reports = accReports();
$basePath = '../../';
$pageTitle = 'রিপোর্ট | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$today = accToday();
$validDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
    && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
$type = (string) ($_GET['report'] ?? '');
if (!$reports->allows($access, $type)) {
    $type = $access->can(Capability::REPORTS_VIEW_ALL) ? ReportService::TRIAL : ReportService::COLLECTION;
}
$from = $validDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : $today->modify('first day of this month')->format('Y-m-d');
$to = $validDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today->format('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$sessionYear = (string) ($_GET['session_year'] ?? $today->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = $today->format('Y');
}
$rangeError = $from < $today->modify('-3 years')->format('Y-m-d') || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366
    ? 'তারিখের পরিসর এক বছরের মধ্যে রাখুন।'
    : '';

$db = getDatabaseConnection();
$classNames = [];
foreach (schoolClasses($db) as $class) {
    $classNames[(int) $class['id']] = accClassLabel($class);
}
$sectionNames = [];
foreach (schoolSections($db) as $section) {
    $sectionNames[(int) $section['id']] = accSectionLabel($section);
}
$place = static function (int $classId, int $sectionId) use ($classNames, $sectionNames): string {
    $class = $classNames[$classId] ?? ('শ্রেণী #' . $classId);
    $section = $sectionId > 0 ? ($sectionNames[$sectionId] ?? ('শাখা #' . $sectionId)) : 'শাখা নেই';

    return $class . ' — ' . $section;
};

$headers = [];
$htmlRows = [];
$excelRows = [];
$subtitle = '';
$built = $rangeError === '';
if ($built) {
    if ($type === ReportService::TRIAL) {
        $trial = $reports->trial($to);
        $subtitle = 'তারিখ পর্যন্ত ' . bnDigits($to);
        $headers = ['কোড', 'হিসাব', 'ডেবিট', 'ক্রেডিট'];
        foreach ($trial['rows'] as $row) {
            $htmlRows[] = [h($row['code']), h($row['name']), h(Money::format($row['debit'])), h(Money::format($row['credit']))];
            $excelRows[] = [$row['code'], $row['name'], Money::toDb($row['debit']), Money::toDb($row['credit'])];
        }
        $htmlRows[] = ['', 'মোট', h(Money::format($trial['debit'])), h(Money::format($trial['credit']))];
        $excelRows[] = ['', 'মোট', Money::toDb($trial['debit']), Money::toDb($trial['credit'])];
    } elseif ($type === ReportService::INCOME) {
        $income = $reports->income($from, $to);
        $subtitle = bnDigits($from) . ' থেকে ' . bnDigits($to);
        $headers = ['ধরন', 'কোড', 'হিসাব', 'টাকা'];
        foreach ($income['income'] as $row) {
            $htmlRows[] = ['আয়', h($row['code']), h($row['name']), h(Money::format($row['amount']))];
            $excelRows[] = ['আয়', $row['code'], $row['name'], Money::toDb($row['amount'])];
        }
        $htmlRows[] = ['', '', 'মোট আয়', h(Money::format($income['income_total']))];
        $excelRows[] = ['', '', 'মোট আয়', Money::toDb($income['income_total'])];
        foreach ($income['expense'] as $row) {
            $htmlRows[] = ['ব্যয়', h($row['code']), h($row['name']), h(Money::format($row['amount']))];
            $excelRows[] = ['ব্যয়', $row['code'], $row['name'], Money::toDb($row['amount'])];
        }
        $htmlRows[] = ['', '', 'মোট ব্যয়', h(Money::format($income['expense_total']))];
        $excelRows[] = ['', '', 'মোট ব্যয়', Money::toDb($income['expense_total'])];
        $surplusLabel = $income['surplus'] >= 0 ? 'উদ্বৃত্ত' : 'ঘাটতি';
        $htmlRows[] = ['', '', $surplusLabel, h(Money::format($income['surplus']))];
        $excelRows[] = ['', '', $surplusLabel, Money::toDb($income['surplus'])];
    } elseif ($type === ReportService::CASH) {
        $cash = $reports->cash($from, $to);
        $subtitle = bnDigits($from) . ' থেকে ' . bnDigits($to);
        $headers = ['কোড', 'হিসাব', 'প্রারম্ভিক', 'জমা', 'খরচ', 'সমাপনী'];
        foreach ($cash['rows'] as $row) {
            $htmlRows[] = [h($row['code']), h($row['name']), h(Money::format($row['opening'])), h(Money::format($row['debit'])), h(Money::format($row['credit'])), h(Money::format($row['closing']))];
            $excelRows[] = [$row['code'], $row['name'], Money::toDb($row['opening']), Money::toDb($row['debit']), Money::toDb($row['credit']), Money::toDb($row['closing'])];
        }
        $htmlRows[] = ['', 'মোট', h(Money::format($cash['opening'])), h(Money::format($cash['debit'])), h(Money::format($cash['credit'])), h(Money::format($cash['closing']))];
        $excelRows[] = ['', 'মোট', Money::toDb($cash['opening']), Money::toDb($cash['debit']), Money::toDb($cash['credit']), Money::toDb($cash['closing'])];
    } elseif ($type === ReportService::COLLECTION) {
        $subtitle = bnDigits($from) . ' থেকে ' . bnDigits($to);
        $headers = ['শ্রেণী / শাখা', 'মাধ্যম', 'রসিদ', 'টাকা'];
        $total = 0;
        $count = 0;
        foreach ($reports->collection($access, $from, $to) as $row) {
            $total += $row['amount'];
            $count += $row['count'];
            $method = PaymentService::METHOD_LABELS[$row['method']] ?? $row['method'];
            $htmlRows[] = [h($place($row['class_id'], $row['section_id'])), h($method), h(bnDigits((string) $row['count'])), h(Money::format($row['amount']))];
            $excelRows[] = [$place($row['class_id'], $row['section_id']), $method, $row['count'], Money::toDb($row['amount'])];
        }
        $htmlRows[] = ['মোট', '', h(bnDigits((string) $count)), h(Money::format($total))];
        $excelRows[] = ['মোট', '', $count, Money::toDb($total)];
    } elseif ($type === ReportService::DUES) {
        $subtitle = 'সেশন ' . bnDigits($sessionYear);
        $headers = ['শ্রেণী / শাখা', 'ইনভয়েস', 'বকেয়াদার শিক্ষার্থী', 'প্রাপ্য', 'আদায়', 'বকেয়া'];
        $sums = ['invoices' => 0, 'students' => 0, 'net' => 0, 'paid' => 0, 'due' => 0];
        foreach ($reports->dues($access, $sessionYear) as $row) {
            $sums['invoices'] += $row['invoices'];
            $sums['students'] += $row['students_due'];
            $sums['net'] += $row['net'];
            $sums['paid'] += $row['paid'];
            $sums['due'] += $row['due'];
            $htmlRows[] = [h($place($row['class_id'], $row['section_id'])), h(bnDigits((string) $row['invoices'])), h(bnDigits((string) $row['students_due'])), h(Money::format($row['net'])), h(Money::format($row['paid'])), h(Money::format($row['due']))];
            $excelRows[] = [$place($row['class_id'], $row['section_id']), $row['invoices'], $row['students_due'], Money::toDb($row['net']), Money::toDb($row['paid']), Money::toDb($row['due'])];
        }
        $htmlRows[] = ['মোট', h(bnDigits((string) $sums['invoices'])), h(bnDigits((string) $sums['students'])), h(Money::format($sums['net'])), h(Money::format($sums['paid'])), h(Money::format($sums['due']))];
        $excelRows[] = ['মোট', $sums['invoices'], $sums['students'], Money::toDb($sums['net']), Money::toDb($sums['paid']), Money::toDb($sums['due'])];
    } else {
        $subtitle = 'সেশন ' . bnDigits($sessionYear) . ' — ফি-শ্রেণীর ছাড় আয়ের খাতায় যায় না, শুধু এই বিবরণীতে।';
        $headers = ['ফি-শ্রেণী', 'ছাড়', 'নেট বিল'];
        $concession = 0;
        $net = 0;
        foreach ($reports->concession($access, $sessionYear) as $row) {
            $concession += $row['concession'];
            $net += $row['net'];
            $htmlRows[] = [h($row['category']), h(Money::format($row['concession'])), h(Money::format($row['net']))];
            $excelRows[] = [$row['category'], Money::toDb($row['concession']), Money::toDb($row['net'])];
        }
        $htmlRows[] = ['মোট', h(Money::format($concession)), h(Money::format($net))];
        $excelRows[] = ['মোট', Money::toDb($concession), Money::toDb($net)];
    }
}

$format = (string) ($_GET['format'] ?? '');
if ($built && ($format === 'pdf' || $format === 'xls')) {
    $title = ReportService::LABELS[$type];
    $stamp = $type . '-' . ($type === ReportService::DUES || $type === ReportService::CONCESSION ? $sessionYear : $to);
    if ($format === 'pdf') {
        ReportPdf::download($stamp . '.pdf', $title, $subtitle, array_map('h', $headers), $htmlRows);
    }
    Workbook::download($stamp . '.xls', $title, $headers, $excelRows);
    exit;
}

$query = $_GET;
unset($query['format']);
$pdfUrl = 'reports.php?' . http_build_query($query + ['format' => 'pdf']);
$xlsUrl = 'reports.php?' . http_build_query($query + ['format' => 'xls']);

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">রিপোর্ট</h3>
            <p class="text-secondary mb-0">ট্রায়াল ব্যালেন্স, আয়-ব্যয়, নগদ ও ব্যাংক, ফি আদায়, বকেয়া এবং বৃত্তি/উপবৃত্তির ছাড়। শ্রেণিশিক্ষক শুধু নিজ শ্রেণীর আদায়, বকেয়া ও ছাড় দেখেন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <form method="get" class="card card-body mb-3">
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label" for="rp-type">রিপোর্ট</label>
                  <select class="form-select" id="rp-type" name="report">
                    <?php foreach (ReportService::LABELS as $key => $label): ?>
                      <?php if (!$reports->allows($access, $key)) { continue; } ?>
                      <option value="<?= h($key) ?>"<?= $key === $type ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php if ($type === ReportService::DUES || $type === ReportService::CONCESSION): ?>
                  <div class="col-md-2">
                    <label class="form-label" for="rp-session">সেশন</label>
                    <select class="form-select" id="rp-session" name="session_year">
                      <?php foreach (accSessionYears() as $year): ?><option value="<?= h($year) ?>"<?= $year === $sessionYear ? ' selected' : '' ?>><?= h(bnDigits($year)) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                <?php else: ?>
                  <?php if ($type !== ReportService::TRIAL): ?>
                    <div class="col-md-2"><label class="form-label" for="rp-from">শুরু</label><input class="form-control" type="date" id="rp-from" name="from" value="<?= h($from) ?>"></div>
                  <?php endif; ?>
                  <div class="col-md-2"><label class="form-label" for="rp-to"><?= $type === ReportService::TRIAL ? 'তারিখ পর্যন্ত' : 'শেষ' ?></label><input class="form-control" type="date" id="rp-to" name="to" value="<?= h($to) ?>"></div>
                <?php endif; ?>
                <div class="col-md-3 d-flex flex-wrap gap-1">
                  <button class="btn btn-primary" type="submit">দেখুন</button>
                  <?php if ($built): ?>
                    <a class="btn btn-outline-secondary" href="<?= h($pdfUrl) ?>">PDF</a>
                    <a class="btn btn-outline-secondary" href="<?= h($xlsUrl) ?>">Excel</a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
            <?php if ($rangeError !== ''): ?>
              <div class="alert alert-warning"><?= h($rangeError) ?></div>
            <?php else: ?>
              <div class="card">
                <div class="card-header"><h3 class="card-title mb-0"><?= h(ReportService::LABELS[$type]) ?> <span class="small text-secondary"><?= h($subtitle) ?></span></h3></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><?php foreach ($headers as $header): ?><th><?= h($header) ?></th><?php endforeach; ?></tr></thead>
                      <tbody>
                        <?php if (count($htmlRows) <= 1): ?><tr><td colspan="<?= count($headers) ?>" class="text-secondary p-3">এই পরিসরে কিছু নেই।</td></tr><?php endif; ?>
                        <?php foreach ($htmlRows as $index => $row): ?>
                          <tr class="<?= $index === count($htmlRows) - 1 ? 'fw-semibold' : '' ?>"><?php foreach ($row as $cell): ?><td><?= $cell ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
