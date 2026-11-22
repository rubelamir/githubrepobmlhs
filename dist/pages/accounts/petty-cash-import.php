<?php
declare(strict_types=1);

/**
 * One-shot importer: Jan-21…May-26 Excel cashbook → petty_cash_book.
 * Safe to re-open; skips when the migration flag is already recorded unless ?force=1.
 */

use School\Accounting\Access\Capability;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';
require_once dirname(__DIR__, 3) . '/shared/petty-cash.php';

$access = accBootPage([Capability::REPORTS_VIEW_ALL, Capability::VOUCHER_CREATE]);
if (!$access->can(Capability::REPORTS_VIEW_ALL) && !$access->can(Capability::VOUCHER_CREATE)) {
    redirectWithMessage('dashboard.php', 'danger', 'ইমপোর্টের অনুমতি নেই।');
}

$db = getDatabaseConnection();
$force = (string) ($_GET['force'] ?? '') === '1' || (string) ($_POST['force'] ?? '') === '1';
$run = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || (string) ($_GET['run'] ?? '') === '1';

$result = null;
$error = '';
if ($run) {
    try {
        set_time_limit(180);
        $result = pettyCashImportFromBundledJson($db, $access->teacherId(), $force);
        redirectWithMessage('petty-cash.php?month=2021-01', $result['skipped'] ? 'info' : 'success', $result['message']);
    } catch (Throwable $exception) {
        $error = userFacingError($exception);
    }
}

$basePath = '../../';
$pageTitle = 'Petty Cash ইমপোর্ট | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$already = function_exists('schemaMigrationApplied') && schemaMigrationApplied($db, pettyCashImportMigrationName());
$jsonExists = is_file(pettyCashImportJsonPath());

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">Petty Cash Book ইমপোর্ট</h3>
            <p class="text-secondary mb-0">এক্সেলের Jan-21 থেকে May-26 শীটগুলো তারিখ অনুসারে আলাদা Petty Cash খাতায় তোলা হবে।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <?php if ($error !== ''): ?>
              <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>
            <div class="card card-body">
              <ul class="mb-3">
                <li>উৎস: <code>casssssssss.xlsx</code> → bundled JSON</li>
                <li>পরিসর: <strong>Jan-21</strong> … <strong>May-26</strong></li>
                <li>JSON ফাইল: <?= $jsonExists ? 'পাওয়া গেছে' : 'নেই' ?></li>
                <li>স্ট্যাটাস: <?= $already ? 'আগেই ইমপোর্ট সম্পন্ন' : 'এখনো ইমপোর্ট হয়নি' ?></li>
              </ul>
              <form method="post" class="d-flex flex-wrap gap-2">
                <?php if ($already): ?>
                  <input type="hidden" name="force" value="1">
                  <button class="btn btn-warning" type="submit">আবার ইমপোর্ট (পুরনো পরিসর মুছে)</button>
                <?php else: ?>
                  <button class="btn btn-primary" type="submit">ইমপোর্ট চালান</button>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="petty-cash.php">Petty Cash Book</a>
                <a class="btn btn-outline-secondary" href="dashboard.php">ড্যাশবোর্ড</a>
              </form>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
