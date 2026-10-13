<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'ইতিহাস',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$historyText = '';
$historyQuery = mysqli_query(
    $conn,
    'SELECT school_history FROM school_history WHERE school_history_status = 1 ORDER BY school_history_creation_time DESC LIMIT 1'
);
if ($historyQuery instanceof mysqli_result && ($historyRow = mysqli_fetch_assoc($historyQuery))) {
    $historyText = (string) ($historyRow['school_history'] ?? '');
}
?>
<div class="school-about-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>ইতিহাস</h2>
            </div>
        </div>
    </section>
    <div class="container py-4 py-lg-5">
        <h3 class="school-section-title">বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস</h3>
        <?php if ($historyText !== ''): ?>
            <p class="school-about-text"><?= nl2br(h($historyText)) ?></p>
        <?php else: ?>
            <p class="text-secondary mb-0"><?= h((string) (currentSchool()['about_short'] ?? 'এই মুহূর্তে ইতিহাস প্রকাশিত নেই।')) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
