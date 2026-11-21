<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$teacher = getAuthenticatedTeacher();
$basePath = '../';
$pageTitle = 'বিষয় পছন্দ | School Admin';
$manageAll = canManageModule('subjects', $teacher) || canManageModule('results', $teacher);
$sessionYear = trim((string) ($_REQUEST['session_year'] ?? date('Y')));
if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
    $sessionYear = (string) date('Y');
}
$classes = $manageAll ? schoolClasses($db) : classesVisibleToPanel($db, $teacher, $sessionYear);
$classId = (int) ($_REQUEST['cls'] ?? 0);
$sections = [];
if ($classId > 0) {
    $sections = $manageAll
        ? schoolSections($db, $classId)
        : sectionsVisibleToPanel($db, $classId, $teacher, $sessionYear);
}
$sectionId = studentFilterSectionId($sections, $classId, (int) ($_REQUEST['sections'] ?? 0));
$filterNotice = $classId > 0 ? studentFilterSelectionNotice($sections, $classId, $sectionId) : '';
$allowedClass = false;
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $allowedClass = true;
        break;
    }
}
if ($classId > 0 && !$allowedClass) {
    $classId = 0;
    $sectionId = 0;
    $filterNotice = '';
}
$scope = classTeacherScope($db, $teacher, $sessionYear);
$canEdit = $classId > 0 && $filterNotice === '' && (
    $manageAll || classTeacherScopeAllows($scope, $classId, $sectionId)
);

$subjects = ($classId > 0 && $filterNotice === '') ? classSubjects($db, $classId, $sectionId) : [];
$groups = [];
foreach ($subjects as $subject) {
    $group = subjectChoiceGroup($subject['choice_group'] ?? '');
    if ($group === '') {
        continue;
    }
    $groups[$group][] = $subject;
}
$students = ($classId > 0 && $filterNotice === '')
    ? classSectionStudents($db, $classId, $sectionId, $sessionYear)
    : [];
$choiceMap = studentSubjectChoiceMap(
    $db,
    array_map(static fn (array $student): int => (int) $student['id'], $students),
    $sessionYear
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canEdit) {
            throw new RuntimeException('এই শাখার বিষয় পছন্দ বদলানোর অনুমতি নেই।');
        }
        if ($groups === []) {
            throw new RuntimeException('এই শাখায় পছন্দের দল নেই।');
        }
        $posted = $_POST['choice'] ?? [];
        if (!is_array($posted)) {
            throw new RuntimeException('পছন্দ পাওয়া যায়নি।');
        }
        $studentIds = [];
        foreach ($students as $student) {
            $studentIds[(int) $student['id']] = true;
        }
        $allowedSubjects = [];
        foreach ($groups as $group => $groupSubjects) {
            foreach ($groupSubjects as $subject) {
                $allowedSubjects[$group][(int) $subject['id']] = true;
            }
        }
        $db->beginTransaction();
        $save = $db->prepare(
            'INSERT INTO student_subject_choices (student_id, session_year, choice_group, class_subject_id)
             VALUES (:student_id, :session_year, :choice_group, :class_subject_id)
             ON DUPLICATE KEY UPDATE class_subject_id = VALUES(class_subject_id)'
        );
        $clear = $db->prepare(
            'DELETE FROM student_subject_choices
             WHERE student_id = :student_id AND session_year = :session_year AND choice_group = :choice_group'
        );
        $groupNames = array_keys($groups);
        foreach ($studentIds as $studentId => $unused) {
            $row = $posted[$studentId] ?? $posted[(string) $studentId] ?? [];
            if (!is_array($row)) {
                continue;
            }
            foreach ($groupNames as $index => $group) {
                $subjectId = (int) ($row[$index] ?? $row[(string) $index] ?? 0);
                if ($subjectId < 1) {
                    $clear->execute([
                        'student_id' => $studentId,
                        'session_year' => $sessionYear,
                        'choice_group' => $group,
                    ]);
                    continue;
                }
                if (!isset($allowedSubjects[$group][$subjectId])) {
                    throw new RuntimeException('একটি পছন্দ এই শাখার বিষয়ের মধ্যে নেই।');
                }
                $save->execute([
                    'student_id' => $studentId,
                    'session_year' => $sessionYear,
                    'choice_group' => $group,
                    'class_subject_id' => $subjectId,
                ]);
            }
        }
        $db->commit();
        redirectWithMessage(
            'subject-choices.php?' . http_build_query([
                'cls' => $classId,
                'sections' => $sectionId,
                'session_year' => $sessionYear,
            ]),
            'success',
            'বিষয় পছন্দ সেভ হয়েছে।'
        );
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirectWithMessage(
            'subject-choices.php?' . http_build_query([
                'cls' => $classId,
                'sections' => $sectionId,
                'session_year' => $sessionYear,
            ]),
            'danger',
            userFacingError($exception)
        );
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('change', (event) => {
  const source = event.target;
  if (!(source instanceof HTMLSelectElement) || !source.dataset.fillGroup || source.value === '') {
    return;
  }
  document.querySelectorAll('select[data-choice-group]').forEach((select) => {
    if (select instanceof HTMLSelectElement && select.dataset.choiceGroup === source.dataset.fillGroup) {
      select.value = source.value;
    }
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">বিষয় পছন্দ</h3>
            <p class="text-secondary mb-0">কৃষি শিক্ষা ও গার্হস্থ্য বিজ্ঞানের মতো বিকল্প বিষয়ে প্রতিটি শিক্ষার্থীর একটি পছন্দ। ধর্মের বিষয় এখানে নয়; সেটি শিক্ষার্থীর ধর্ম থেকে মিলে।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label">শিক্ষাবর্ষ</label>
                  <input class="form-control" name="session_year" value="<?= h($sessionYear) ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">শ্রেণী</label>
                  <select class="form-select" name="cls" onchange="this.form.submit()">
                    <option value="">নির্বাচন করুন</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">শাখা</label>
                  <select class="form-select" name="sections">
                    <?= studentFilterSectionOptionsHtml($sections, $classId, $sectionId > 0 ? (string) $sectionId : '') ?>
                  </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100">দেখুন</button></div>
              </div>
            </form>

            <?php if ($filterNotice !== ''): ?>
              <div class="alert alert-warning"><?= h($filterNotice) ?></div>
            <?php elseif ($classId < 1): ?>
              <div class="alert alert-info">শ্রেণী ও শাখা বেছে নিন।</div>
            <?php elseif ($groups === []): ?>
              <div class="alert alert-info">
                এই শাখার কোনো বিষয়ে পছন্দের দল নেই।
                <?php if (canManageModule('subjects', $teacher)): ?>
                  <a href="subjects.php?class_id=<?= (int) $classId ?>&amp;section_id=<?= (int) $sectionId ?>">Subjects</a> পাতায় কৃষি ও গার্হস্থ্য দুটোতে একই পছন্দের দলের নাম দিন।
                <?php endif; ?>
              </div>
            <?php else: ?>
              <form method="post" class="card stat-card">
                <?= csrfField() ?>
                <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                <div class="card-body">
                  <?php if ($canEdit): ?>
                    <div class="row g-2 mb-3">
                      <?php $groupIndex = 0; ?>
                      <?php foreach ($groups as $group => $groupSubjects): ?>
                        <div class="col-md-4">
                          <label class="form-label">সবাইকে: <?= h((string) $group) ?></label>
                          <select class="form-select" data-fill-group="<?= (int) $groupIndex ?>">
                            <option value="">একসাথে বসান</option>
                            <?php foreach ($groupSubjects as $subject): ?>
                              <option value="<?= (int) $subject['id'] ?>"><?= h((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php $groupIndex++; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead>
                        <tr>
                          <th>রোল</th>
                          <th>নাম</th>
                          <?php foreach ($groups as $group => $groupSubjects): ?>
                            <th><?= h($group) ?></th>
                          <?php endforeach; ?>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if ($students === []): ?>
                          <tr><td colspan="<?= 2 + count($groups) ?>" class="text-secondary">এই শাখায় ভর্তি শিক্ষার্থী নেই।</td></tr>
                        <?php endif; ?>
                        <?php foreach ($students as $student): ?>
                          <?php $studentId = (int) $student['id']; ?>
                          <tr>
                            <td><?= h((string) $student['cls_roll']) ?></td>
                            <td><?= h((string) ($student['name_ban'] ?: $student['name_eng'])) ?></td>
                            <?php $groupIndex = 0; ?>
                            <?php foreach ($groups as $group => $groupSubjects): ?>
                              <?php $selected = (int) ($choiceMap[$studentId][$group] ?? 0); ?>
                              <td>
                                <?php if ($canEdit): ?>
                                  <select class="form-select" name="choice[<?= $studentId ?>][<?= (int) $groupIndex ?>]" data-choice-group="<?= (int) $groupIndex ?>">
                                    <option value="">নির্বাচন করুন</option>
                                    <?php foreach ($groupSubjects as $subject): ?>
                                      <option value="<?= (int) $subject['id'] ?>" <?= $selected === (int) $subject['id'] ? 'selected' : '' ?>><?= h((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                <?php else: ?>
                                  <?php
                                    $label = '—';
                                    foreach ($groupSubjects as $subject) {
                                        if ((int) $subject['id'] === $selected) {
                                            $label = (string) ($subject['subject_name_ban'] ?: $subject['subject_name']);
                                        }
                                    }
                                  ?>
                                  <?= h($label) ?>
                                <?php endif; ?>
                              </td>
                              <?php $groupIndex++; ?>
                            <?php endforeach; ?>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?php if ($canEdit && $students !== []): ?>
                    <button class="btn btn-primary mt-3">সেভ</button>
                  <?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
