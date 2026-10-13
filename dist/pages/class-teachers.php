<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Class Teachers | School Admin';
$sessionYear = trim((string) ($_GET['session_year'] ?? $_POST['session_year'] ?? date('Y')));
$classes = schoolClasses($db);
$staff = schoolTeachers($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionYear = trim((string) ($_POST['session_year'] ?? date('Y')));
    $posted = $_POST['teacher'] ?? [];
    try {
        if (!is_array($posted)) {
            throw new RuntimeException('Invalid assignment data.');
        }
        foreach ($classes as $class) {
            $classId = (int) $class['id'];
            $classPosted = $posted[$classId] ?? [];
            if (!is_array($classPosted)) {
                $classPosted = [];
            }
            saveClassTeacherAssignment(
                $db,
                $classId,
                0,
                $sessionYear,
                trim((string) ($classPosted[0] ?? ''))
            );
            foreach (schoolSections($db, $classId) as $section) {
                $sectionId = (int) $section['id'];
                saveClassTeacherAssignment(
                    $db,
                    $classId,
                    $sectionId,
                    $sessionYear,
                    trim((string) ($classPosted[$sectionId] ?? ''))
                );
            }
        }
        redirectWithMessage(
            'class-teachers.php?session_year=' . rawurlencode($sessionYear),
            'success',
            'Class teacher assignments saved for session ' . $sessionYear . '.'
        );
    } catch (Throwable $exception) {
        redirectWithMessage(
            'class-teachers.php?session_year=' . rawurlencode($sessionYear),
            'danger',
            userFacingError($exception)
        );
    }
}

$assigned = classTeacherMap($db, $sessionYear);

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">শ্রেণী শিক্ষক নিয়োগ / Class teachers</h3>
            <p class="text-secondary mb-0">Assign a class teacher per class or section. That teacher can take attendance only for those students, along with their usual mark entry.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label">Session</label>
                  <input class="form-control" name="session_year" value="<?= h($sessionYear) ?>">
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary">Load</button></div>
              </div>
            </form>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
              <?php foreach ($classes as $class): ?>
                <?php
                  $classId = (int) $class['id'];
                  $sections = schoolSections($db, $classId);
                ?>
                <div class="card stat-card mb-3">
                  <div class="card-header">
                    <strong><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></strong>
                  </div>
                  <div class="card-body">
                    <div class="row g-3">
                      <div class="col-md-6 col-xl-4">
                        <label class="form-label">Entire class</label>
                        <select class="form-select" name="teacher[<?= $classId ?>][0]">
                          <option value="">— Not assigned —</option>
                          <?php foreach ($staff as $item): ?>
                            <?php $mobile = (string) $item['teacher_mobile']; ?>
                            <option value="<?= h($mobile) ?>" <?= (($assigned[$classId . ':0']['teacher_mobile'] ?? '') === $mobile) ? 'selected' : '' ?>>
                              <?= h((string) $item['teacher_name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">Can take attendance for every section of this class.</div>
                      </div>
                      <?php foreach ($sections as $section): ?>
                        <?php $sectionId = (int) $section['id']; ?>
                        <div class="col-md-6 col-xl-4">
                          <label class="form-label"><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></label>
                          <select class="form-select" name="teacher[<?= $classId ?>][<?= $sectionId ?>]">
                            <option value="">— Not assigned —</option>
                            <?php foreach ($staff as $item): ?>
                              <?php $mobile = (string) $item['teacher_mobile']; ?>
                              <option value="<?= h($mobile) ?>" <?= (($assigned[$classId . ':' . $sectionId]['teacher_mobile'] ?? '') === $mobile) ? 'selected' : '' ?>>
                                <?= h((string) $item['teacher_name']) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if ($classes === []): ?>
                <div class="alert alert-warning">Add classes first under Academic structure → Classes.</div>
              <?php else: ?>
                <button class="btn btn-success mb-4">Save class teachers</button>
              <?php endif; ?>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
