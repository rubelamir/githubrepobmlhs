<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Public Result Publication | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ((string) ($_POST['action'] ?? '') !== 'toggle') {
            throw new RuntimeException('Invalid publication action.');
        }
        $examId = postedRecordId('exam');
        if ($examId < 1) {
            throw new RuntimeException('Invalid exam.');
        }
        $current = fetchRows($db, 'SELECT public_results_enabled, exam_name_bangla FROM exam_details WHERE id = :id LIMIT 1', ['id' => $examId]);
        if ($current === []) {
            throw new RuntimeException('Exam not found.');
        }
        $publish = (int) ($current[0]['public_results_enabled'] ?? 0) !== 1;
        $statement = $db->prepare('UPDATE exam_details SET public_results_enabled = :published WHERE id = :id');
        $statement->execute(['published' => $publish ? 1 : 0, 'id' => $examId]);
        $message = $publish ? 'Results published on the website.' : 'Result publication stopped.';
        writeAuditLog($db, $publish ? 'publish' : 'hide', 'exam_result', $examId, $message);
        redirectWithMessage('result-publication.php', 'success', $message);
    } catch (Throwable $exception) {
        redirectWithMessage('result-publication.php', 'danger', userFacingError($exception));
    }
}

$exams = schoolExams($db);

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Public result publication</h3>
            <p class="text-secondary mb-0">Choose which exams students and guardians can view on the public website. New exams are hidden until published.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Exam</th><th>Exam status</th><th>Public result</th><th></th></tr></thead>
                  <tbody>
                    <?php if ($exams === []): ?>
                      <tr><td colspan="4" class="text-secondary">No exams have been added yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($exams as $exam): ?>
                      <?php $published = (int) ($exam['public_results_enabled'] ?? 0) === 1; ?>
                      <tr>
                        <td>
                          <?= h((string) $exam['exam_name_bangla']) ?>
                          <?php if (!empty($exam['exam_name_english'])): ?>
                            <div class="small text-secondary"><?= h((string) $exam['exam_name_english']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= (int) ($exam['is_active'] ?? 1) === 1 ? 'Active' : 'Inactive' ?></td>
                        <td>
                          <span class="badge text-bg-<?= $published ? 'success' : 'secondary' ?>">
                            <?= $published ? 'Published' : 'Hidden' ?>
                          </span>
                        </td>
                        <td>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $exam['id'], 'exam') ?>
                            <button class="btn btn-sm <?= $published ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                              <?= $published ? 'Stop publication' : 'Publish results' ?>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
