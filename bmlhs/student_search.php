<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';

$db = publicDatabase();
$searchQuery = publicStudentSearchQuery((string) ($_GET['q'] ?? ''));

if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['found' => publicStudentSearchExists($db, $searchQuery)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $suggestions = [];
    if (mb_strlen($searchQuery, 'UTF-8') >= 2) {
        foreach (searchPublicStudents($db, $searchQuery, 5) as $student) {
            $suggestions[] = [
                'id' => (int) $student['id'],
                'name' => publicStudentDisplayName($student),
                'class' => publicStudentClassLabel($student),
                'section' => publicStudentSectionLabel($student),
                'roll' => publicHasDisplayValue($student['cls_roll'] ?? '') ? bnDigits((string) $student['cls_roll']) : '',
                'url' => publicStudentProfileUrl((int) $student['id'], $searchQuery),
            ];
        }
    }
    echo json_encode(['items' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$students = $searchQuery !== '' ? searchPublicStudents($db, $searchQuery) : [];
if ($students === []) {
    header('Location: ./?search=empty#student-search', true, 303);
    exit;
}

$pageAssets = [];
$pageMeta = [
    'title' => 'সার্চ ফলাফল',
    'description' => 'ছাত্র-ছাত্রীর তথ্য অনুসন্ধানের ফলাফল',
];
include 'header.php';
include 'navbar&marqueetag.php';

$resultCount = count($students);
?>
<?php schoolPageHero('সার্চ ফলাফল', 'নাম, রোল, পিতা/মাতার নাম বা মোবাইল দিয়ে খুঁজে পাওয়া ছাত্র-ছাত্রী'); ?>

<div class="school-student-search">
    <div class="container py-4 py-lg-5">
        <div class="school-student-search-toolbar">
            <a class="school-staff-back" href="./#student-search">← হোমে ফিরুন</a>
            <form class="school-student-search-form" id="resultStudentSearch" action="student_search" method="get" role="search">
                <label class="visually-hidden" for="resultStudentSearchInput">ছাত্র-ছাত্রীর তথ্য খুজুন</label>
                <div class="school-home-search-field">
                <input class="school-home-search-input" type="search" id="resultStudentSearchInput" name="q" value="<?= h($searchQuery) ?>" placeholder="নাম, রোল, পিতা/মাতা বা মোবাইল" maxlength="120" autocomplete="off" enterkeyhint="search" aria-autocomplete="list" aria-controls="resultStudentSuggest" aria-describedby="resultStudentSearchMessage">
                </div>
                <div class="school-suggest" id="resultStudentSuggest" hidden role="listbox" aria-label="সার্চ সাজেশন"></div>
                <button class="school-home-search-btn" type="submit">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span>Search</span>
                </button>
            </form>
            <p class="school-student-search-message" id="resultStudentSearchMessage" role="status" aria-live="polite" hidden></p>
        </div>

        <div class="school-student-search-meta">
            <p class="mb-0">“<?= h($searchQuery) ?>” এর সাথে মিলেছে <strong><?= h(bnDigits((string) $resultCount)) ?></strong> জন ছাত্র-ছাত্রী</p>
        </div>

        <div class="school-student-search-table-wrap">
            <table class="table school-student-search-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>শ্রেণী</th>
                        <th>শাখা</th>
                        <th>রোল</th>
                        <th>নাম</th>
                        <th>পিতার নাম</th>
                        <th>মাতার নাম</th>
                        <th>জন্ম তারিখ</th>
                        <th>মোবাইল নম্বর</th>
                        <th>View</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student):
                        $name = publicStudentDisplayName($student);
                        $father = trim((string) ($student['fname_ban'] ?: $student['fname_eng'] ?? ''));
                        $mother = trim((string) ($student['mname_ban'] ?: $student['mname_eng'] ?? ''));
                        $birth = publicStaffDate($student['birth_date'] ?? null);
                        $mobile = publicStaffMobileDisplay((string) ($student['mobile'] ?? ''));
                    ?>
                    <tr>
                        <td><?= h(publicStudentClassLabel($student) !== '' ? publicStudentClassLabel($student) : '—') ?></td>
                        <td><?= h(publicStudentSectionLabel($student) !== '' ? publicStudentSectionLabel($student) : '—') ?></td>
                        <td><?= h(publicHasDisplayValue($student['cls_roll'] ?? '') ? bnDigits((string) $student['cls_roll']) : '—') ?></td>
                        <td><?= h($name !== '' ? $name : '—') ?></td>
                        <td><?= h($father !== '' ? $father : '—') ?></td>
                        <td><?= h($mother !== '' ? $mother : '—') ?></td>
                        <td><?= h($birth !== '' ? bnDigits($birth) : '—') ?></td>
                        <td><?= h($mobile !== '' ? bnDigits($mobile) : '—') ?></td>
                        <td>
                            <a class="school-search-view" href="<?= h(publicStudentProfileUrl((int) $student['id'], $searchQuery)) ?>">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="school-student-search-cards d-md-none">
            <?php foreach ($students as $student):
                $name = publicStudentDisplayName($student);
                $father = trim((string) ($student['fname_ban'] ?: $student['fname_eng'] ?? ''));
                $mother = trim((string) ($student['mname_ban'] ?: $student['mname_eng'] ?? ''));
                $birth = publicStaffDate($student['birth_date'] ?? null);
                $mobile = publicStaffMobileDisplay((string) ($student['mobile'] ?? ''));
            ?>
            <article class="school-student-search-card">
                <div class="school-student-search-card-head">
                    <h3><?= h($name !== '' ? $name : '—') ?></h3>
                    <a class="school-search-view" href="<?= h(publicStudentProfileUrl((int) $student['id'], $searchQuery)) ?>">View</a>
                </div>
                <dl>
                    <div><dt>শ্রেণী</dt><dd><?= h(publicStudentClassLabel($student) !== '' ? publicStudentClassLabel($student) : '—') ?></dd></div>
                    <div><dt>শাখা</dt><dd><?= h(publicStudentSectionLabel($student) !== '' ? publicStudentSectionLabel($student) : '—') ?></dd></div>
                    <div><dt>রোল</dt><dd><?= h(publicHasDisplayValue($student['cls_roll'] ?? '') ? bnDigits((string) $student['cls_roll']) : '—') ?></dd></div>
                    <div><dt>পিতার নাম</dt><dd><?= h($father !== '' ? $father : '—') ?></dd></div>
                    <div><dt>মাতার নাম</dt><dd><?= h($mother !== '' ? $mother : '—') ?></dd></div>
                    <div><dt>জন্ম তারিখ</dt><dd><?= h($birth !== '' ? bnDigits($birth) : '—') ?></dd></div>
                    <div><dt>মোবাইল নম্বর</dt><dd><?= h($mobile !== '' ? bnDigits($mobile) : '—') ?></dd></div>
                </dl>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php $keepDocumentOpen = true; include 'footer.php'; ?>
<script src="js/school-home-ui.js?v=<?= (int) @filemtime(__DIR__ . '/js/school-home-ui.js') ?>"></script>
</body>
</html>
