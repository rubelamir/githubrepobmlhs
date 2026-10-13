<?php
declare(strict_types=1);

$pageAssets = ['fancybox'];
$pageMeta = [
    'title' => 'ছবির গ্যালারী',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের অনুষ্ঠান ও বছরভিত্তিক ছবির অ্যালবাম।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$yearFilter = trim((string) ($_GET['year'] ?? ''));
$allAlbums = publicGalleryAlbums($db);
$albums = $allAlbums;
if ($yearFilter !== '') {
    $albums = array_values(array_filter(
        $allAlbums,
        static fn (array $album): bool => (string) ($album['album_year'] ?? '') === $yearFilter
    ));
}
$years = array_values(array_unique(array_filter(array_map(
    static fn (array $album): string => (string) ($album['album_year'] ?? ''),
    $allAlbums
))));
rsort($years, SORT_NUMERIC);
$loosePhotos = $yearFilter === '' ? publicUngroupedGalleryPhotos($db) : [];
?>
<div class="school-inner-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container"><h2>ছবির গ্যালারী</h2></div>
        </div>
    </section>
    <div class="container school-inner-wrap py-4 py-lg-5">
        <p class="school-inner-lead">বছর ও অনুষ্ঠান অনুযায়ী অ্যালবাম বেছে ছবি দেখুন।</p>
        <?php if ($years !== []): ?>
            <div class="school-filter-chips">
                <a class="school-chip<?= $yearFilter === '' ? ' is-active' : '' ?>" href="image_gallery">সব বছর</a>
                <?php foreach ($years as $year): ?>
                    <a class="school-chip<?= $yearFilter === $year ? ' is-active' : '' ?>" href="image_gallery?year=<?= h($year) ?>"><?= h(bnDigits($year)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($albums !== []): ?>
            <div class="school-album-grid">
                <?php foreach ($albums as $album):
                    $cover = trim((string) ($album['cover_file'] ?? ''));
                    $coverSrc = $cover !== '' ? schoolMediaUrl($cover, 'image_gallery_file') : 'image/1.jpg';
                    $count = (int) ($album['photo_count'] ?? 0);
                ?>
                    <a class="school-album-card" href="<?= h(publicGalleryAlbumUrl((int) $album['id'])) ?>">
                        <span class="school-album-cover"><img src="<?= h($coverSrc) ?>" alt="" loading="lazy"></span>
                        <span class="school-album-body">
                            <strong><?= h((string) $album['title']) ?></strong>
                            <?php if (trim((string) ($album['event_name'] ?? '')) !== ''): ?>
                                <em><?= h((string) $album['event_name']) ?></em>
                            <?php endif; ?>
                            <small>
                                <?= $album['album_year'] ? h(bnDigits((string) $album['album_year'])) : '' ?>
                                <?= $count > 0 ? ' · ' . h(bnDigits((string) $count)) . ' ছবি' : '' ?>
                            </small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($loosePhotos !== []): ?>
            <h3 class="school-section-title<?= $albums !== [] ? ' mt-4' : '' ?> mb-3">আনগ্রুপড ছবি</h3>
            <div class="school-photo-grid">
                <?php foreach ($loosePhotos as $row): ?>
                    <a class="school-photo-card" data-fancybox="ungrouped" href="<?= h(schoolMediaUrl((string) $row['image_gallery_file'], 'image_gallery_file')) ?>">
                        <img src="<?= h(schoolMediaUrl((string) $row['image_gallery_file'], 'image_gallery_file')) ?>" alt="<?= h((string) $row['image_gallery_caption']) ?>" loading="lazy">
                        <span><?= h((string) $row['image_gallery_caption']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($albums === [] && $loosePhotos === []): ?>
            <div class="school-inner-empty">এই মুহূর্তে কোনো অ্যালবাম প্রকাশিত নেই।</div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
