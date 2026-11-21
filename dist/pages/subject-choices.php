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

$pageStyles = <<<'CSS'
.choice-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem; }
.choice-search { flex: 1 1 16rem; }
.choice-panel { margin-bottom: 1.25rem; }
.choice-panel h4 { font-size: 1rem; margin: 0 0 .75rem; }
.choice-stats { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .85rem; }
.choice-stat {
  border: 1px solid var(--bs-border-color);
  background: var(--bs-body-bg);
  color: var(--bs-body-color);
  border-radius: 999px;
  padding: .3rem .85rem .3rem .35rem;
  display: inline-flex;
  align-items: center;
  gap: .55rem;
  font-weight: 600;
}
button.choice-stat { cursor: pointer; }
button.choice-stat:hover { border-color: var(--pick, var(--bs-primary)); }
.choice-stat strong, .choice-pending strong {
  min-width: 1.8rem;
  height: 1.8rem;
  padding: 0 .4rem;
  border-radius: 999px;
  display: inline-grid;
  place-items: center;
  background: var(--pick, var(--bs-secondary));
  color: #fff;
  font-size: .85rem;
}
.choice-pending strong { background: #6c757d; }
.choice-row {
  display: grid;
  grid-template-columns: 4.25rem minmax(0, 1fr) auto;
  gap: .75rem;
  align-items: center;
  padding: .7rem .85rem;
  border: 1px solid var(--bs-border-color);
  border-radius: .95rem;
  background: var(--bs-body-bg);
  margin-bottom: .5rem;
}
.choice-row.is-open { background: rgba(232, 163, 23, .16); }
.choice-roll { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--bs-secondary-color); }
.choice-name { font-weight: 600; min-width: 0; }
.choice-picks { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: flex-end; }
.choice-pick {
  border: 1px solid var(--bs-border-color);
  background: transparent;
  color: var(--bs-body-color);
  border-radius: 999px;
  padding: .45rem .9rem;
  font-weight: 600;
  min-height: 2.6rem;
}
.choice-pick.is-on { background: var(--pick, var(--bs-primary)); border-color: var(--pick, var(--bs-primary)); color: #fff; }
.choice-savebar {
  position: sticky;
  bottom: .75rem;
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: .75rem;
  padding: .75rem 1rem;
  border-radius: 1rem;
  background: var(--bs-body-bg);
  border: 1px solid var(--bs-border-color);
  box-shadow: 0 10px 28px rgba(0, 0, 0, .08);
}
@media (max-width: 700px) {
  .choice-row { grid-template-columns: 3rem minmax(0, 1fr); }
  .choice-picks { grid-column: 1 / -1; }
  .choice-pick { flex: 1 1 8rem; }
}
CSS;

$pageScripts = [<<<'JS'
function paintChoiceGroup(group) {
  const inputs = document.querySelectorAll('input[data-choice-group="' + group + '"]');
  const counts = {};
  let pending = 0;
  inputs.forEach((input) => {
    const value = input.value;
    const row = input.closest('[data-choice-row]');
    row?.querySelectorAll('[data-pick][data-choice-group="' + group + '"]').forEach((button) => {
      const on = value !== '' && button.dataset.pick === value;
      button.classList.toggle('is-on', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (value === '') pending += 1;
    else counts[value] = (counts[value] || 0) + 1;
    const open = Array.from(row?.querySelectorAll('input[data-choice-group]') || []).some((field) => field.value === '');
    row?.classList.toggle('is-open', open);
  });
  document.querySelectorAll('[data-choice-panel][data-group="' + group + '"] [data-count-for]').forEach((node) => {
    node.textContent = String(counts[node.dataset.countFor] || 0);
  });
  const pendingNode = document.querySelector('[data-pending-for="' + group + '"]');
  if (pendingNode) pendingNode.textContent = String(pending);
}

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const pick = target.closest('[data-pick]');
  if (pick instanceof HTMLButtonElement) {
    const row = pick.closest('[data-choice-row]');
    const input = row?.querySelector('input[data-choice-group="' + pick.dataset.choiceGroup + '"]');
    if (!(input instanceof HTMLInputElement) || !pick.dataset.choiceGroup) return;
    input.value = input.value === pick.dataset.pick ? '' : (pick.dataset.pick || '');
    paintChoiceGroup(pick.dataset.choiceGroup);
    return;
  }
  const fill = target.closest('[data-fill-value]');
  if (!(fill instanceof HTMLButtonElement) || !fill.dataset.fillGroup) return;
  document.querySelectorAll('input[data-choice-group="' + fill.dataset.fillGroup + '"]').forEach((input) => {
    if (input instanceof HTMLInputElement) input.value = fill.dataset.fillValue || '';
  });
  paintChoiceGroup(fill.dataset.fillGroup);
});

document.addEventListener('input', (event) => {
  const search = event.target;
  if (!(search instanceof HTMLInputElement) || search.dataset.choiceSearch === undefined) return;
  const query = search.value.trim().toLowerCase();
  document.querySelectorAll('[data-choice-row]').forEach((row) => {
    if (!(row instanceof HTMLElement)) return;
    row.hidden = query !== '' && !(row.dataset.search || '').toLowerCase().includes(query);
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">কৃষি ও গার্হস্থ্য</h3>
            <p class="text-secondary mb-0">শিক্ষার্থীর নামের পাশে বিষয়টি চাপুন। ওপরের সংখ্যায় চাপলে পুরো শাখা একসাথে বসে। আবার চাপলে পছন্দ উঠে যায়।</p>
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
                এই শাখায় কৃষি শিক্ষা ও গার্হস্থ্য বিজ্ঞান একসাথে নেই।
                <?php if (canManageModule('subjects', $teacher)): ?>
                  দুটো বিষয়ের নামেই ওই শব্দ থাকলে এখানে নিজে থেকে আসবে। অন্য বিকল্পের জন্য <a href="subjects.php?class_id=<?= (int) $classId ?>&amp;section_id=<?= (int) $sectionId ?>">Subjects</a> পাতায় একই পছন্দের দলের নাম দিন।
                <?php endif; ?>
              </div>
            <?php else: ?>
              <form method="post" class="card stat-card">
                <?= csrfField() ?>
                <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                <div class="card-body">
                  <div class="choice-toolbar">
                    <input class="form-control choice-search" type="search" placeholder="রোল বা নাম" data-choice-search>
                  </div>
                  <?php if ($students === []): ?>
                    <p class="text-secondary mb-0">এই শাখায় ভর্তি শিক্ষার্থী নেই।</p>
                  <?php endif; ?>
                  <?php $groupIndex = 0; ?>
                  <?php foreach ($groups as $group => $groupSubjects): ?>
                    <?php
                      $labels = [];
                      $colors = [];
                      foreach ($groupSubjects as $pickIndex => $subject) {
                          $labels[(int) $subject['id']] = trim((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])) ?: 'বিষয়';
                          if (subjectIsAgriculturePaper($subject)) {
                              $colors[(int) $subject['id']] = '#1f8a4c';
                          } elseif (subjectIsHomeSciencePaper($subject)) {
                              $colors[(int) $subject['id']] = '#c46a2a';
                          } else {
                              $palette = ['#1d6fbf', '#7a4ea3', '#0f766e', '#b4536a'];
                              $colors[(int) $subject['id']] = $palette[$pickIndex % count($palette)];
                          }
                      }
                      $counts = [];
                      $pending = 0;
                      foreach ($students as $student) {
                          $chosen = (int) ($choiceMap[(int) $student['id']][$group] ?? 0);
                          if (!isset($labels[$chosen])) {
                              $pending++;
                              continue;
                          }
                          $counts[$chosen] = ($counts[$chosen] ?? 0) + 1;
                      }
                    ?>
                    <section class="choice-panel" data-choice-panel data-group="<?= (int) $groupIndex ?>">
                      <h4><?= h(implode(' বা ', $labels)) ?></h4>
                      <div class="choice-stats">
                        <?php foreach ($groupSubjects as $subject): ?>
                          <?php $subjectId = (int) $subject['id']; ?>
                          <?php if ($canEdit): ?>
                            <button type="button" class="choice-stat" style="--pick: <?= h($colors[$subjectId]) ?>" data-fill-group="<?= (int) $groupIndex ?>" data-fill-value="<?= $subjectId ?>">
                              সবাই <?= h($labels[$subjectId]) ?>
                              <strong data-count-for="<?= $subjectId ?>"><?= (int) ($counts[$subjectId] ?? 0) ?></strong>
                            </button>
                          <?php else: ?>
                            <span class="choice-stat" style="--pick: <?= h($colors[$subjectId]) ?>">
                              <?= h($labels[$subjectId]) ?>
                              <strong data-count-for="<?= $subjectId ?>"><?= (int) ($counts[$subjectId] ?? 0) ?></strong>
                            </span>
                          <?php endif; ?>
                        <?php endforeach; ?>
                        <span class="choice-stat choice-pending">বাকি <strong data-pending-for="<?= (int) $groupIndex ?>"><?= (int) $pending ?></strong></span>
                      </div>
                      <?php foreach ($students as $student): ?>
                        <?php
                          $studentId = (int) $student['id'];
                          $selected = (int) ($choiceMap[$studentId][$group] ?? 0);
                          if (!isset($labels[$selected])) {
                              $selected = 0;
                          }
                          $search = trim((string) $student['cls_roll'] . ' ' . ($student['name_ban'] ?? '') . ' ' . ($student['name_eng'] ?? ''));
                        ?>
                        <div class="choice-row<?= $selected === 0 ? ' is-open' : '' ?>" data-choice-row data-search="<?= h($search) ?>">
                          <span class="choice-roll"><?= h((string) $student['cls_roll']) ?></span>
                          <span class="choice-name"><?= h((string) ($student['name_ban'] ?: $student['name_eng'])) ?></span>
                          <span class="choice-picks">
                            <?php if ($canEdit): ?>
                              <input type="hidden" name="choice[<?= $studentId ?>][<?= (int) $groupIndex ?>]" value="<?= $selected > 0 ? $selected : '' ?>" data-choice-group="<?= (int) $groupIndex ?>">
                              <?php foreach ($groupSubjects as $subject): ?>
                                <?php $subjectId = (int) $subject['id']; ?>
                                <button type="button" class="choice-pick<?= $selected === $subjectId ? ' is-on' : '' ?>" style="--pick: <?= h($colors[$subjectId]) ?>" data-pick="<?= $subjectId ?>" data-choice-group="<?= (int) $groupIndex ?>" aria-pressed="<?= $selected === $subjectId ? 'true' : 'false' ?>"><?= h($labels[$subjectId]) ?></button>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <span class="choice-pick<?= $selected > 0 ? ' is-on' : '' ?>"<?= $selected > 0 ? ' style="--pick: ' . h($colors[$selected]) . '"' : '' ?>><?= h($selected > 0 ? $labels[$selected] : 'এখনো বাছেনি') ?></span>
                            <?php endif; ?>
                          </span>
                        </div>
                      <?php endforeach; ?>
                    </section>
                    <?php $groupIndex++; ?>
                  <?php endforeach; ?>
                  <?php if ($canEdit && $students !== []): ?>
                    <div class="choice-savebar">
                      <span class="text-secondary">হলুদ সারি এখনো বাছেনি। বিষয় চাপিয়ে সেভ করলে তাঁরা নাম্বার তালিকায় আসবেন।</span>
                      <button class="btn btn-primary">সেভ</button>
                    </div>
                  <?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
