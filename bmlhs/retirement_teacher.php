<?php
declare(strict_types=1);

$pageAssets = ['fancybox'];
$pageMeta = [
    'title' => 'অবসরপ্রাপ্ত শিক্ষক-কর্মচারী',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের অবসরপ্রাপ্ত ও স্মরণীয় শিক্ষক-কর্মচারীবৃন্দ।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$retired = retirementTeachers($db, true);
?>
<div class="school-retired-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>স্মরণীয় যারা</h2>
            </div>
        </div>
    </section>

    <div class="container py-4 py-lg-5">
        <p class="school-staff-intro mb-4">অবসরপ্রাপ্ত ও প্রয়াত শিক্ষক-কর্মচারীবৃন্দ — যাঁদের নিষ্ঠায় বিদ্যালয় আজকের এই রূপ পেয়েছে।</p>
        <?php if ($retired === []): ?>
            <div class="school-news-empty">
                <i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i>
                <h3>এই মুহূর্তে কোনো তথ্য প্রকাশিত নেই</h3>
                <p>অবসরপ্রাপ্ত শিক্ষক-কর্মচারী যোগ করা হলে এখানে কার্ড আকারে দেখা যাবে।</p>
            </div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4">
                <?php foreach ($retired as $person):
                    $name = (string) ($person['teacher_name'] ?? '');
                    $dummy = retirementDummyPhoto();
                    $photo = retirementTeacherPhotoUrl($person['picture'] ?? null);
                    $isDummy = $photo === $dummy;
                    $years = retirementYearRange((int) ($person['joining_year'] ?? 0), (int) ($person['retirement_year'] ?? 0));
                    $designation = publicStaffDesignationLabel((string) ($person['teacher_designation'] ?? ''));
                    ?>
                    <div class="col">
                        <article class="school-staff-card school-retired-card h-100">
                            <div class="school-staff-card-top">
                                <span class="school-staff-card-crest" aria-hidden="true"></span>
                                <div class="school-staff-card-photo">
                                    <?php if ($isDummy): ?>
                                        <img src="<?= h($dummy) ?>" alt="<?= h($name) ?>" width="112" height="142" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <a href="<?= h($photo) ?>" data-fancybox="gallery" data-caption="<?= h(trim($name . ($designation !== '' ? ' · ' . $designation : '') . ' · ' . $years)) ?>">
                                            <img src="<?= h($photo) ?>" alt="<?= h($name) ?>" width="112" height="142" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= h($dummy) ?>';">
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="school-staff-card-body">
                                <h3 class="school-staff-card-name"><?= h($name) ?></h3>
                                <?php if ($designation !== ''): ?>
                                    <p class="school-staff-card-role"><?= h($designation) ?></p>
                                <?php endif; ?>
                                <p class="school-retired-years"><?= h($years) ?></p>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
