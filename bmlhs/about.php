<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'ইতিহাস',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের ইতিহাস, ঐতিহ্য ও নেতৃত্বের বাণী।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$aboutSchool = currentSchool();
$historyText = '';
if (tableExists($db, 'school_history')) {
    $historyRows = fetchRows(
        $db,
        'SELECT school_history FROM school_history WHERE school_history_status = 1 ORDER BY school_history_creation_time DESC LIMIT 1'
    );
    $historyText = trim((string) ($historyRows[0]['school_history'] ?? ''));
}
if ($historyText === '') {
    $historyText = trim((string) ($aboutSchool['about_short'] ?? ''));
}

$paragraphs = schoolHistoryParagraphs($historyText);
$leadParagraph = $paragraphs[0] ?? '';
$bodyParagraphs = $paragraphs !== [] ? array_slice($paragraphs, 1) : [];
$established = trim((string) ($aboutSchool['established'] ?? ''));
$eiin = trim((string) ($aboutSchool['eiin'] ?? ''));
$mpo = trim((string) ($aboutSchool['mpo_code'] ?? ''));
$timeline = schoolHistoryTimeline($historyText, $established, 12, $db);
$campusPhotos = array_slice(publicGalleryPhotos($db), 0, 8);
$teacherCount = tableExists($db, 'teacher_info') ? tableCount($db, 'teacher_info') : 0;
$studentCount = tableExists($db, 'students_info')
    ? tableCount($db, 'students_info', 'status = 1')
    : 0;

$stats = [];
if ($established !== '') {
    $stats[] = ['label' => 'স্থাপিত', 'value' => bnDigits($established)];
}
if ($eiin !== '') {
    $stats[] = ['label' => 'EIIN', 'value' => bnDigits($eiin)];
}
if ($mpo !== '') {
    $stats[] = ['label' => 'MPO', 'value' => bnDigits($mpo)];
}
if ($teacherCount > 0) {
    $stats[] = ['label' => 'শিক্ষক-কর্মচারী', 'value' => bnDigits((string) $teacherCount)];
}
if ($studentCount > 0) {
    $stats[] = ['label' => 'শিক্ষার্থী', 'value' => bnDigits((string) $studentCount)];
}
?>
<div class="school-about-page">
    <?php schoolPageHero('আমাদের ইতিহাস', 'ঐতিহ্য, শিক্ষা ও সুনাগরিক গড়ার পথচলা', 'পরিচিতি'); ?>

    <?php if ($stats !== []): ?>
    <section class="school-about-stats" aria-label="বিদ্যালয়ের তথ্য">
        <div class="container school-about-stats-inner">
            <?php foreach ($stats as $stat): ?>
                <div class="school-about-stat">
                    <span class="school-about-stat-label"><?= h($stat['label']) ?></span>
                    <strong class="school-about-stat-value"><?= h($stat['value']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="container school-about-wrap py-4 py-lg-5">
        <section class="school-about-story school-reveal" data-reveal="history" aria-label="বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস">
            <?php schoolSectionHead('বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস', 'আমাদের কথা', 'প্রতিষ্ঠানের গল্প এক নজরে', 'h2'); ?>
            <?php if ($leadParagraph !== ''): ?>
                <p class="school-about-lead"><?= h($leadParagraph) ?></p>
                <?php foreach ($bodyParagraphs as $paragraph): ?>
                    <p class="school-about-text"><?= h($paragraph) ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-secondary mb-0">এই মুহূর্তে ইতিহাস প্রকাশিত নেই।</p>
            <?php endif; ?>
        </section>

        <?php if ($timeline !== []): ?>
        <section class="school-about-timeline school-reveal" data-reveal="history" aria-label="ঐতিহ্যের ধাপ">
            <?php schoolSectionHead('ঐতিহ্যের ধাপ', 'কালরেখা', 'বছরভিত্তিক উল্লেখযোগ্য মুহূর্ত', 'h2'); ?>
            <ol class="school-about-timeline-list">
                <?php foreach ($timeline as $item): ?>
                    <li>
                        <span class="school-about-timeline-year"><?= h(bnDigits($item['year'])) ?></span>
                        <span class="school-about-timeline-label"><?= h($item['label']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php if ($campusPhotos !== []): ?>
        <section class="school-about-campus school-reveal" data-reveal="cards" aria-label="ক্যাম্পাসের ছবি">
            <div class="school-about-campus-head">
                <?php schoolSectionHead('ক্যাম্পাসের ছবি', 'গ্যালারি', 'বিদ্যালয় জীবনের কিছু মুহূর্ত'); ?>
                <a class="school-about-campus-all" href="image_gallery">সব ছবি <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="school-about-campus-strip">
                <?php foreach ($campusPhotos as $photo):
                    $caption = trim((string) ($photo['image_gallery_caption'] ?? ''));
                    $src = schoolMediaUrl((string) ($photo['image_gallery_file'] ?? ''), 'image_gallery_file');
                    if ($src === '') {
                        continue;
                    }
                ?>
                    <a class="school-about-campus-shot" href="image_gallery" title="<?= h($caption !== '' ? $caption : 'গ্যালারি') ?>">
                        <img src="<?= h($src) ?>" alt="<?= h($caption) ?>" loading="lazy" decoding="async">
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <p class="school-about-cta">
            <a class="btn school-btn-admit" href="contact">যোগাযোগ করুন</a>
            <a class="school-text-more" href="teacher_staff">শিক্ষক মণ্ডলী <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a>
        </p>
    </div>
</div>
<?php $keepDocumentOpen = true; include 'footer.php'; ?>
<script src="js/home-scroll-reveal.js?v=<?= (int) @filemtime(__DIR__ . '/js/home-scroll-reveal.js') ?>"></script>
</body>
</html>
