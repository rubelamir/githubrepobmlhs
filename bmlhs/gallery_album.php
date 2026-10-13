<?php
declare(strict_types=1);

$pageAssets = ['fancybox'];
$pageMeta = [
    'title' => 'ছবির অ্যালবাম',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের অনুষ্ঠানভিত্তিক ছবির অ্যালবাম।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$albumId = publicRecordId('gallery_album');
$album = publicGalleryAlbum($db, $albumId);
if ($album === null) {
    http_response_code(404);
}
$photos = $album ? publicGalleryPhotos($db, $albumId) : [];
?>
<div class="school-inner-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container"><h2><?= $album ? h((string) $album['title']) : 'অ্যালবাম পাওয়া যায়নি' ?></h2></div>
        </div>
    </section>
    <div class="container school-inner-wrap py-4 py-lg-5">
        <a class="school-back-link" href="image_gallery">← সব অ্যালবাম</a>
        <?php if ($album === null): ?>
            <div class="school-inner-empty">এই অ্যালবামটি খুঁজে পাওয়া যায়নি।</div>
        <?php else: ?>
            <p class="school-inner-lead">
                <?= h((string) ($album['event_name'] ?: $album['title'])) ?>
                <?php if (!empty($album['album_year'])): ?> · <?= h(bnDigits((string) $album['album_year'])) ?><?php endif; ?>
            </p>
            <?php if ($photos === []): ?>
                <div class="school-inner-empty">এই অ্যালবামে এখনও কোনো ছবি নেই।</div>
            <?php else: ?>
                <div class="school-photo-grid">
                    <?php foreach ($photos as $row): ?>
                        <a class="school-photo-card" data-fancybox="album" href="<?= h(schoolMediaUrl((string) $row['image_gallery_file'], 'image_gallery_file')) ?>">
                            <img src="<?= h(schoolMediaUrl((string) $row['image_gallery_file'], 'image_gallery_file')) ?>" alt="<?= h((string) $row['image_gallery_caption']) ?>" loading="lazy">
                            <span><?= h((string) $row['image_gallery_caption']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
