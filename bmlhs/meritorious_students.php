<?php
declare(strict_types=1);

$pageAssets = ['fancybox'];
$pageMeta = [
    'title' => 'কৃতি শিক্ষার্থী কর্ণার',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের কৃতি শিক্ষার্থী কর্ণার।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$batch = trim((string) ($_GET['batch'] ?? ''));
$students = publicMeritoriousStudents($db, $batch);
$batches = publicMeritoriousBatches($db);
$dummy = meritoriousDummyPhoto();
?>
<div class="school-inner-page school-merit-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container"><h2>কৃতি শিক্ষার্থী কর্ণার</h2></div>
        </div>
    </section>
    <div class="container school-inner-wrap py-4 py-lg-5">
        <p class="school-inner-lead">নাম, পিতা-মাতার নাম, ব্যাচ, বিভাগ ও ঐচ্ছিক ফলাফলসহ কৃতি শিক্ষার্থীদের তালিকা।</p>

        <?php if ($batches !== []): ?>
            <div class="school-filter-chips">
                <a class="school-chip<?= $batch === '' ? ' is-active' : '' ?>" href="meritorious_students">সব ব্যাচ</a>
                <?php foreach ($batches as $year): ?>
                    <a class="school-chip<?= $batch === $year ? ' is-active' : '' ?>" href="meritorious_students?batch=<?= h($year) ?>"><?= h(bnDigits($year)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($students === []): ?>
            <div class="school-inner-empty">এই মুহূর্তে কোনো কৃতি শিক্ষার্থীর তথ্য প্রকাশিত নেই।</div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-4">
                <?php foreach ($students as $row):
                    $name = meritoriousDisplayName($row);
                    $nameEng = trim((string) ($row['students_name_eng'] ?? ''));
                    $father = meritoriousParentName($row, 'father');
                    $mother = meritoriousParentName($row, 'mother');
                    $year = trim((string) ($row['session_year'] ?? ''));
                    $groupLabel = meritoriousGroupLabel((string) ($row['section_group'] ?? ''));
                    $result = trim((string) ($row['results'] ?? ''));
                    $mobile = trim((string) ($row['mobile'] ?? ''));
                    $photo = meritoriousPhotoUrl($row['picture'] ?? null);
                    $isDummy = $photo === $dummy;
                    $tel = preg_replace('/\D+/', '', $mobile) ?? '';
                ?>
                    <div class="col">
                        <article class="school-staff-card school-merit-card h-100">
                            <div class="school-staff-card-top">
                                <span class="school-staff-card-crest" aria-hidden="true"></span>
                                <div class="school-staff-card-photo">
                                    <?php if ($isDummy): ?>
                                        <img src="<?= h($dummy) ?>" alt="" width="112" height="142" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <a href="<?= h($photo) ?>" data-fancybox="merit" data-caption="<?= h($name) ?>">
                                            <img src="<?= h($photo) ?>" alt="<?= h($name) ?>" width="112" height="142" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= h($dummy) ?>';">
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="school-staff-card-body">
                                <h3 class="school-staff-card-name"><?= h($name) ?></h3>
                                <?php if ($nameEng !== '' && $nameEng !== $name): ?>
                                    <p class="school-merit-en"><?= h($nameEng) ?></p>
                                <?php endif; ?>
                                <?php if ($groupLabel !== ''): ?>
                                    <p class="school-staff-card-role"><?= h($groupLabel) ?></p>
                                <?php endif; ?>
                                <dl class="school-merit-facts">
                                    <?php if ($father !== ''): ?>
                                        <div><dt>পিতা</dt><dd><?= h($father) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($mother !== ''): ?>
                                        <div><dt>মাতা</dt><dd><?= h($mother) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($year !== ''): ?>
                                        <div><dt>ব্যাচ</dt><dd><?= h(bnDigits($year)) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($result !== ''): ?>
                                        <div><dt>ফলাফল</dt><dd><?= h(bnDigits($result)) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($mobile !== ''): ?>
                                        <div>
                                            <dt>মোবাইল</dt>
                                            <dd>
                                                <?php if ($tel !== ''): ?>
                                                    <a href="tel:<?= h($tel) ?>"><?= h($mobile) ?></a>
                                                <?php else: ?>
                                                    <?= h($mobile) ?>
                                                <?php endif; ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
