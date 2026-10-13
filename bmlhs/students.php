<?php
declare(strict_types=1);

$pageAssets = ['jquery'];
$pageMeta = [
    'title' => 'ছাত্র-ছাত্রীর তালিকা',
    'description' => 'শ্রেণী, শাখা ও শিক্ষাবর্ষ অনুযায়ী অধ্যয়নরত ছাত্র-ছাত্রীর তালিকা দেখুন।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$classes = schoolClasses($db, true);
$yearNow = (int) date('Y');

$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? (string) $yearNow));
$search = trim((string) ($_GET['q'] ?? ''));
$sections = $classId > 0 ? schoolSections($db, $classId, true) : [];

$filtersSubmitted = isset($_GET['cls']) && trim((string) $_GET['cls']) !== '';

$students = [];
$maleCount = 0;
$femaleCount = 0;
if ($filtersSubmitted && $classId > 0 && $sessionYear !== '') {
    [$where, $params] = studentsQueryFilters([
        'cls' => (string) $classId,
        'sections' => $sectionId > 0 ? (string) $sectionId : '',
        'session_year' => $sessionYear,
        'q' => $search,
    ]);
    $where[] = publicEnrolledStudentFilterSql($db);
    $sql = 'SELECT s.id, s.cls_roll, s.name_ban, s.name_eng, s.fname_ban, s.fname_eng,
                   s.mname_ban, s.mname_eng, s.mobile, s.student_pic, s.gender,
                   c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            FROM students_info s' . studentsListJoinSql() . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);
    foreach ($students as $row) {
        $gender = strtolower(trim((string) ($row['gender'] ?? '')));
        if ($gender === 'male') {
            $maleCount++;
        } elseif ($gender === 'female') {
            $femaleCount++;
        }
    }
}
$totalCount = count($students);
?>
<style>
@media print {
  @page { size: A4 landscape; margin: 8mm; }
}
</style>
<section class="reg_section sweet-mini-section">
    <div class="bg-mini-overlay d-flex align-items-center">
        <div class="container">
            <h2>অধ্যয়নরত ছাত্র-ছাত্রী</h2>
        </div>
    </div>
</section>

<div class="school-students school-inner-page">
    <div class="container py-4 py-lg-5">
        <div class="school-result-intro mb-4">
            <p class="mb-0">শ্রেণী ও শাখা বেছে নিয়ে অধ্যয়নরত ছাত্র-ছাত্রীর তালিকা দেখুন।</p>
        </div>

        <form method="get" class="school-result-filters card border-0 shadow-sm mb-4 no-print">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="cls">শ্রেণী</label>
                        <select class="form-select" id="cls" name="cls" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php echoClassOptions($classes, $classId > 0 ? (string) $classId : ''); ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="sections">শাখা</label>
                        <select class="form-select" id="sections" name="sections">
                            <option value="0">সকল শাখা</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                                    <?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="session_year">শিক্ষাবর্ষ</label>
                        <select class="form-select" id="session_year" name="session_year">
                            <?php for ($y = $yearNow + 1; $y >= 2015; $y--): ?>
                                <option value="<?= $y ?>" <?= $sessionYear === (string) $y ? 'selected' : '' ?>><?= h(bnDigits((string) $y)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="q">রোল বা নাম</label>
                        <input class="form-control" id="q" name="q" value="<?= h($search) ?>" placeholder="যেমন: ১২ অথবা রহিম" autocomplete="off">
                    </div>
                    <div class="col-md-12 col-lg-2 ms-lg-auto">
                        <button class="btn school-result-submit w-100">দেখুন</button>
                    </div>
                </div>
            </div>
        </form>

        <?php if (!$filtersSubmitted): ?>
            <div class="school-result-empty">অনুগ্রহ করে শ্রেণী নির্বাচন করে "দেখুন" বাটনে ক্লিক করুন।</div>
        <?php elseif ($students === []): ?>
            <div class="school-result-empty">এই ফিল্টারে কোনো ছাত্র-ছাত্রীর তথ্য পাওয়া যায়নি।</div>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="school-result-stat">
                        <span>মোট শিক্ষার্থী</span>
                        <strong><?= h(bnDigits((string) $totalCount)) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="school-result-stat">
                        <span>ছাত্র</span>
                        <strong><?= h(bnDigits((string) $maleCount)) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="school-result-stat">
                        <span>ছাত্রী</span>
                        <strong><?= h(bnDigits((string) $femaleCount)) ?></strong>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-center">
                    <p class="text-secondary mb-0">
                        <?= h(classLabel($db, $classId)) ?><?= $sectionId > 0 ? ' · ' . h(sectionLabel($db, $sectionId)) : '' ?> · সেশন <?= h(bnDigits($sessionYear)) ?>
                    </p>
                </div>
            </div>

            <div class="card border-0 shadow-sm school-result-list-card school-students-table-wrap">
                <div class="table-responsive">
                    <table class="table school-students-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ছবি</th>
                                <th>রোল</th>
                                <th>নাম</th>
                                <th>পিতার নাম</th>
                                <th>শ্রেণী</th>
                                <th>শাখা</th>
                                <th>মোবাইল</th>
                                <th class="no-print">বিস্তারিত</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 0;
                            foreach ($students as $row):
                                $sl++;
                                $name = publicStudentDisplayName($row);
                                $father = trim((string) ($row['fname_ban'] ?: ($row['fname_eng'] ?? '')));
                                $mobile = publicStaffMobileDisplay((string) ($row['mobile'] ?? ''));
                                $photo = studentPhotoUrl($row['student_pic'] ?? null);
                                $classText = publicStudentClassLabel($row);
                                $sectionText = publicStudentSectionLabel($row);
                                $profileUrl = publicStudentProfileUrl((int) $row['id']);
                            ?>
                            <tr>
                                <td><?= h(bnDigits((string) $sl)) ?></td>
                                <td><img class="school-students-photo" src="<?= h($photo) ?>" alt="<?= h($name) ?>" loading="lazy"></td>
                                <td><?= h(publicHasDisplayValue($row['cls_roll'] ?? '') ? bnDigits((string) $row['cls_roll']) : '—') ?></td>
                                <td>
                                    <a class="school-result-name" href="<?= h($profileUrl) ?>"><?= h($name !== '' ? $name : '—') ?></a>
                                </td>
                                <td><?= h($father !== '' ? $father : '—') ?></td>
                                <td><?= h($classText !== '' ? $classText : '—') ?></td>
                                <td><?= h($sectionText !== '' ? $sectionText : '—') ?></td>
                                <td><?= h($mobile !== '' ? bnDigits($mobile) : '—') ?></td>
                                <td class="no-print"><a class="school-search-view" href="<?= h($profileUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> দেখুন</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="school-students-cards d-md-none">
                <?php foreach ($students as $row):
                    $name = publicStudentDisplayName($row);
                    $father = trim((string) ($row['fname_ban'] ?: ($row['fname_eng'] ?? '')));
                    $mobile = publicStaffMobileDisplay((string) ($row['mobile'] ?? ''));
                    $photo = studentPhotoUrl($row['student_pic'] ?? null);
                    $classText = publicStudentClassLabel($row);
                    $sectionText = publicStudentSectionLabel($row);
                    $profileUrl = publicStudentProfileUrl((int) $row['id']);
                ?>
                <article class="school-students-card">
                    <img class="school-students-card-photo" src="<?= h($photo) ?>" alt="<?= h($name) ?>" loading="lazy">
                    <div class="school-students-card-body">
                        <div class="school-students-card-head">
                            <h3><?= h($name !== '' ? $name : '—') ?></h3>
                            <a class="school-search-view" href="<?= h($profileUrl) ?>">দেখুন</a>
                        </div>
                        <dl>
                            <div><dt>রোল</dt><dd><?= h(publicHasDisplayValue($row['cls_roll'] ?? '') ? bnDigits((string) $row['cls_roll']) : '—') ?></dd></div>
                            <div><dt>শ্রেণী</dt><dd><?= h($classText !== '' ? $classText : '—') ?></dd></div>
                            <div><dt>শাখা</dt><dd><?= h($sectionText !== '' ? $sectionText : '—') ?></dd></div>
                            <div><dt>পিতার নাম</dt><dd><?= h($father !== '' ? $father : '—') ?></dd></div>
                            <div><dt>মোবাইল</dt><dd><?= h($mobile !== '' ? bnDigits($mobile) : '—') ?></dd></div>
                        </dl>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
<script>
$(function () {
    var $cls = $('#cls');
    var $sections = $('#sections');
    $cls.on('change', function () {
        var cls = $(this).val();
        if (!cls) {
            $sections.html('<option value="0">সকল শাখা</option>');
            return;
        }
        $.ajax({
            url: 'selectmenu.php',
            type: 'POST',
            data: { type: 'sections', id: cls },
            success: function (data) {
                var extra = '';
                $(data).filter('option').each(function () {
                    if ($(this).attr('value')) {
                        extra += this.outerHTML;
                    }
                });
                $sections.html('<option value="0">সকল শাখা</option>' + extra);
            }
        });
    });
});
</script>
