<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'সিলেবাস',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের সিলেবাস।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$files = publicSyllabusFiles($db);
$selected = $files[0] ?? null;
$requested = publicRecordId('syllabus');
if ($requested > 0) {
    foreach ($files as $file) {
        if ((int) $file['id'] === $requested) {
            $selected = $file;
            break;
        }
    }
}
?>
<div class="school-inner-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container"><h2>সিলেবাস</h2></div>
        </div>
    </section>
    <div class="container school-inner-wrap py-4 py-lg-5">
        <p class="school-inner-lead">প্রকাশিত সিলেবাস ফাইল দেখুন অথবা ডাউনলোড করুন।</p>
        <?php if ($files === []): ?>
            <div class="school-inner-empty">এই মুহূর্তে কোনো সিলেবাস প্রকাশিত নেই।</div>
        <?php else: ?>
            <div class="school-file-list">
                <?php foreach ($files as $file):
                    $title = trim((string) ($file['syllabus_title'] ?? '')) ?: 'সিলেবাস';
                    $path = trim((string) ($file['syllabus_file'] ?? ''));
                    $href = $path !== '' ? 'syllabus_file/' . $path : '';
                    $active = $selected && (int) $selected['id'] === (int) $file['id'];
                ?>
                    <a class="school-file-item<?= $active ? ' is-active' : '' ?>" href="<?= h(publicSyllabusUrl((int) $file['id'])) ?>">
                        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                        <span>
                            <strong><?= h($title) ?></strong>
                            <small><?= h(date('d-m-Y', strtotime((string) $file['syllabus_creation_time']))) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php
            $selFile = trim((string) ($selected['syllabus_file'] ?? ''));
            $selExt = strtolower(pathinfo($selFile, PATHINFO_EXTENSION));
            $selUrl = $selFile !== '' ? 'syllabus_file/' . $selFile : '';
            ?>
            <?php if ($selUrl !== '' && $selExt === 'pdf'): ?>
                <div class="school-pdf-frame">
                    <iframe src="<?= h($selUrl) ?>" title="সিলেবাস"></iframe>
                </div>
                <p class="text-center mt-3"><a class="school-contact-submit" href="<?= h($selUrl) ?>" target="_blank" rel="noopener">ডাউনলোড</a></p>
            <?php elseif ($selUrl !== ''): ?>
                <p class="text-center"><a class="school-contact-submit" href="<?= h($selUrl) ?>" target="_blank" rel="noopener">ফাইল খুলুন</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
