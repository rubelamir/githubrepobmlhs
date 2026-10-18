<?php
declare(strict_types=1);

$pageAssets = ['jquery'];
$pageMeta = [
    'title' => 'পরীক্ষার ফলাফল',
    'description' => 'পরীক্ষা ও শ্রেণি নির্বাচন করে ফলাফল দেখুন।',
];
include 'header.php';
include 'navbar&marqueetag.php';
require_once dirname(__DIR__) . '/shared/mpdf-bangla.php';

$db = publicDatabase();
$school = currentSchool();
$exams = schoolPublishedExams($db);
$publicResultsEnabled = $exams !== [];
$classes = $publicResultsEnabled ? schoolClasses($db, true) : [];
$yearNow = (int) date('Y');

$examId = (int) ($_GET['exam'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? (string) $yearNow));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$studentId = (int) ($_GET['student_id'] ?? 0);
$sections = $publicResultsEnabled && $classId > 0 ? schoolSections($db, $classId, true) : [];

$examName = '';
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = (string) ($exam['exam_name_bangla'] ?? '');
        break;
    }
}

$summaries = [];
$selected = null;
$subjectHighestMarks = [];
$selectedExamIsPublished = in_array(
    $examId,
    array_map(static fn (array $exam): int => (int) $exam['id'], $exams),
    true
);
if ($selectedExamIsPublished && $classId > 0 && $sessionYear !== '') {
    $summaries = classExamResultSummaries($db, $classId, $sectionId, $sessionYear, $examId, $search);
    if ($studentId > 0) {
        foreach ($summaries as $row) {
            if ((int) $row['student']['id'] === $studentId) {
                $selected = $row;
                break;
            }
        }
    } elseif (count($summaries) === 1 && $search !== '') {
        $selected = $summaries[0];
        $studentId = (int) $selected['student']['id'];
    }
}
if ($selected !== null) {
    $rankScope = $summaries;
    if ($search !== '') {
        $rankScope = classExamResultSummaries($db, $classId, $sectionId, $sessionYear, $examId);
    }
    foreach ($rankScope as $row) {
        if ((int) $row['student']['id'] === (int) $selected['student']['id']) {
            $selected['rank'] = $row['rank'];
        }
        foreach ($row['sheet']['rows'] as $paper) {
            $subjectId = (int) $paper['subject']['id'];
            $subjectHighestMarks[$subjectId] = max(
                (float) ($subjectHighestMarks[$subjectId] ?? 0),
                (float) $paper['total']
            );
        }
    }
}
$displaySummaries = sortResultSummariesByRoll($summaries);

function resultQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return 'result?' . http_build_query($params);
}

function resultGradeClass(string $grade): string
{
    return $grade === 'F' ? 'is-fail' : ($grade === 'A+' ? 'is-top' : 'is-pass');
}
?>
<section class="reg_section sweet-mini-section">
    <div class="bg-mini-overlay d-flex align-items-center">
        <div class="container">
            <h2>পরীক্ষার ফলাফল / Results</h2>
        </div>
    </div>
</section>

<div class="school-result school-inner-page">
    <div class="container py-4 py-lg-5">
        <?php if (!$publicResultsEnabled): ?>
            <div class="alert alert-info text-center mb-0" role="status">
                বিদ্যালয় কর্তৃপক্ষের সিদ্ধান্ত অনুযায়ী বর্তমানে পরীক্ষার ফলাফল প্রকাশিত হচ্ছে না।
            </div>
        <?php else: ?>
        <form method="get" class="school-result-filters card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="exam">পরীক্ষা</label>
                        <select class="form-select" id="exam" name="exam" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>
                                    <?= h((string) ($exam['exam_name_bangla'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="session_year">সেশন / বর্ষ</label>
                        <select class="form-select" id="session_year" name="session_year">
                            <?php for ($y = $yearNow; $y >= $yearNow - 7; $y--): ?>
                                <option value="<?= $y ?>" <?= $sessionYear === (string) $y ? 'selected' : '' ?>><?= h(bnDigits((string) $y)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="cls">শ্রেণী</label>
                        <select class="form-select" id="cls" name="cls" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php echoClassOptions($classes, $classId > 0 ? (string) $classId : ''); ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="sections">শাখা / বিভাগ</label>
                        <select class="form-select" id="sections" name="sections">
                            <option value="0">সকল শাখা</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                                    <?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="q">রোল বা নাম</label>
                        <input class="form-control" id="q" name="q" value="<?= h($search) ?>" placeholder="যেমন: ১২ অথবা রহিম" autocomplete="off">
                    </div>
                    <div class="col-md-12 col-lg-2 ms-lg-auto">
                        <button class="btn school-result-submit w-100">ফলাফল দেখুন</button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($examId < 1 || $classId < 1): ?>
            <div class="school-result-empty">পরীক্ষা ও শ্রেণী নির্বাচন করে ফলাফল দেখুন।</div>
        <?php elseif ($summaries === []): ?>
            <div class="school-result-empty">এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি। মার্ক এন্ট্রি হয়েছে কি না যাচাই করুন।</div>
        <?php elseif ($selected !== null): ?>
            <?php
                $student = $selected['student'];
                $sheet = $selected['sheet'];
                $photo = studentPhotoUrl($student['student_pic'] ?? null);
                $stuSection = (int) ($student['sections'] ?? 0);
                $failedSubjects = array_values(array_filter(
                    $sheet['rows'],
                    static fn (array $paper): bool => (int) ($paper['subject']['is_optional'] ?? 0) !== 1 && (string) $paper['grade'] === 'F'
                ));
                $marksheetUrl = publicAbsoluteUrl('result?' . http_build_query([
                    'exam' => $examId,
                    'session_year' => $sessionYear,
                    'cls' => $classId,
                    'sections' => $sectionId,
                    'student_id' => (int) $student['id'],
                ]));
                $marksheetQr = banglaQrPngDataUri($marksheetUrl, 6);
            ?>
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3 no-print">
                <a class="btn btn-outline-secondary btn-sm" href="<?= h(resultQuery(['student_id' => ''])) ?>">← তালিকায় ফিরুন</a>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-danger btn-sm" href="<?= h('marksheet-pdf.php?' . http_build_query([
                        'exam' => $examId,
                        'session_year' => $sessionYear,
                        'cls' => $classId,
                        'sections' => $sectionId,
                        'student_id' => (int) $selected['student']['id'],
                    ])) ?>">PDF ডাউনলোড</a>
                    <button class="btn btn-success btn-sm" type="button" onclick="window.print()">প্রিন্ট / মার্কশিট</button>
                </div>
            </div>
            <?php
                $marksheetLogo = schoolLogoUrl($school);
                include __DIR__ . '/marksheet-content.php';
            ?>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="school-result-stat">
                        <span>প্রকাশিত ফলাফল</span>
                        <strong><?= h(bnDigits((string) count($summaries))) ?></strong>
                    </div>
                </div>
                <div class="col-md-8 d-flex align-items-center">
                    <p class="text-secondary mb-0"><?= h($examName) ?> · <?= h(classLabel($db, $classId)) ?> · সেশন <?= h(bnDigits($sessionYear)) ?></p>
                </div>
            </div>
            <div class="card border-0 shadow-sm school-result-list-card">
                <div class="table-responsive">
                    <table class="table school-result-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>রোল</th>
                                <th>নাম</th>
                                <th>মোট নম্বর</th>
                                <th>জিপিএ</th>
                                <th>গ্রেড</th>
                                <th class="no-print"></th>
                                <th>মেধা</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($displaySummaries as $row): ?>
                                <?php
                                    $student = $row['student'];
                                    $sheet = $row['sheet'];
                                    $detail = resultQuery(['student_id' => (int) $student['id']]);
                                ?>
                                <tr>
                                    <td><?= h(bnDigits((string) $student['cls_roll'])) ?></td>
                                    <td>
                                        <a class="school-result-name" href="<?= h($detail) ?>"><?= h((string) $student['name_ban']) ?></a>
                                        <?php if ((string) ($student['name_eng'] ?? '') !== ''): ?>
                                            <div class="small text-secondary"><?= h((string) $student['name_eng']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h(bnDigits((string) (int) round((float) $sheet['total']))) ?></td>
                                    <td><?= h(bnDigits(number_format((float) $sheet['gpa'], 2))) ?></td>
                                    <td><span class="school-grade-pill <?= resultGradeClass((string) $sheet['letter']) ?>"><?= h((string) $sheet['letter']) ?></span></td>
                                    <td class="no-print"><a class="btn btn-sm btn-outline-success" href="<?= h($detail) ?>">মার্কশিট</a></td>
                                    <td><?= h(bnDigits((string) (int) $row['rank'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php $keepDocumentOpen = true; include 'footer.php'; ?>
<?php if ($publicResultsEnabled): ?>
<script>
$(document).ready(function () {
  $('#cls').on('change', function () {
    var cls = $(this).val();
    if (!cls) {
      $('#sections').html('<option value="0">সকল শাখা</option>');
      return;
    }
    $.ajax({
      url: 'selectmenu.php',
      type: 'POST',
      data: { type: 'sections', id: cls },
      success: function (data) {
        var extra = '';
        $(data).filter('option').each(function () {
          if ($(this).attr('value')) extra += this.outerHTML;
        });
        $('#sections').html('<option value="0">সকল শাখা</option>' + extra);
      }
    });
  });
});
</script>
<?php endif; ?>
</body>
</html>
