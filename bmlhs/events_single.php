<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';

$pageAssets = ['fancybox'];
$db = publicDatabase();
$eventId = eventIdFromPublicParam(isset($_GET['id']) ? (string) $_GET['id'] : null);
$event = null;
if ($eventId > 0 && tableExists($db, 'events')) {
    $found = fetchRows($db, 'SELECT * FROM events WHERE id = :id AND events_status = 1 LIMIT 1', ['id' => $eventId]);
    $event = $found[0] ?? null;
}
if (!is_array($event)) {
    http_response_code(404);
}

$eventImages = is_array($event) ? eventImages($db, (int) $event['id']) : [];
$coverFile = is_array($event) ? trim((string) ($event['events_file'] ?? '')) : '';
$sharePath = is_array($event) ? eventPublicHref((int) $event['id']) : 'events';
$shareUrl = publicAbsoluteUrl($sharePath);
$excerpt = is_array($event)
    ? schoolSnippet(eventPlainText((string) ($event['events_details'] ?? '')), 160)
    : 'এই ইভেন্টটি পাওয়া যায়নি।';
$pageMeta = [
    'title' => is_array($event) ? (string) $event['events_headlines'] : 'ইভেন্ট পাওয়া যায়নি',
    'description' => $excerpt,
    'image' => $coverFile !== '' ? schoolMediaUrl($coverFile, 'events_file') : '',
    'url' => $shareUrl,
    'type' => 'article',
];

include 'header.php';
include 'navbar&marqueetag.php';

$rendered = is_array($event) ? renderEventDetailsHtml((string) ($event['events_details'] ?? ''), $eventImages) : ['html' => '', 'unused' => []];
$related = [];
if (is_array($event) && tableExists($db, 'events')) {
    $related = fetchRows(
        $db,
        'SELECT * FROM events WHERE events_status = 1 AND id <> :id ORDER BY ' . eventListOrderSql($db) . ' LIMIT 3',
        ['id' => (int) $event['id']]
    );
}
$date = is_array($event) ? schoolBengaliDate((string) ($event['events_creation_time'] ?? '')) : null;
$facebookShare = facebookSharerUrl($shareUrl);
?>
<div class="school-news-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <h2>ইভেন্টস</h2>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="container school-news-wrap py-4 py-lg-5">
        <nav class="school-news-breadcrumb" aria-label="breadcrumb">
            <a href="index">হোম</a>
            <span aria-hidden="true">/</span>
            <a href="events">ইভেন্টস</a>
            <?php if (is_array($event)): ?>
                <span aria-hidden="true">/</span>
                <span><?= h(schoolSnippet((string) $event['events_headlines'], 48)) ?></span>
            <?php endif; ?>
        </nav>

        <?php if (!is_array($event)): ?>
            <div class="school-news-empty">
                <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                <h3>এই ইভেন্টটি পাওয়া যায়নি</h3>
                <p>লিংকটি ভুল হতে পারে, অথবা খবরটি আর প্রকাশিত নেই।</p>
                <a class="school-news-read" href="events">সকল ইভেন্টস দেখুন</a>
            </div>
        <?php else: ?>
            <article class="school-news-article">
                <p class="school-news-kicker">ইভেন্টস</p>
                <h1 class="school-news-article-title"><?= h((string) $event['events_headlines']) ?></h1>
                <div class="school-news-article-meta">
                    <time datetime="<?= h($date['iso']) ?>">
                        <i class="fa-regular fa-clock" aria-hidden="true"></i>
                        <?= h($date['day'] . ' ' . $date['month'] . ' ' . $date['year'] . ' · ' . $date['time']) ?>
                    </time>
                    <div class="school-news-share-row">
                        <a class="school-news-share-fb" href="<?= h($facebookShare) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-facebook-f" aria-hidden="true"></i> ফেসবুকে শেয়ার
                        </a>
                        <button type="button" class="school-news-copy" id="eventCopyLink" data-url="<?= h($shareUrl) ?>">
                            <i class="fa-solid fa-link" aria-hidden="true"></i> লিংক কপি
                        </button>
                    </div>
                </div>

                <?php if ($coverFile !== ''): ?>
                    <figure class="school-news-cover">
                        <a href="<?= h(schoolMediaUrl($coverFile, 'events_file')) ?>" data-fancybox="gallery">
                            <img src="<?= h(schoolMediaUrl($coverFile, 'events_file')) ?>" alt="<?= h((string) $event['events_headlines']) ?>">
                        </a>
                    </figure>
                <?php endif; ?>

                <div class="school-news-body">
                    <?= $rendered['html'] !== '' ? $rendered['html'] : '<p class="text-secondary">বিস্তারিত লেখা নেই।</p>' ?>
                </div>

                <?php if ($rendered['unused'] !== []): ?>
                    <div class="school-news-more-photos">
                        <h2>আরও ছবি</h2>
                        <div class="school-news-more-grid">
                            <?php foreach ($rendered['unused'] as $item): ?>
                                <?= eventImageFigureHtml($item['image'], (int) $item['number']) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>

            <?php if ($related !== []): ?>
                <aside class="school-news-related">
                    <h2>আরও পড়ুন</h2>
                    <div class="school-news-related-grid">
                        <?php foreach ($related as $row):
                            $relatedDate = schoolBengaliDate((string) ($row['events_creation_time'] ?? ''));
                            $href = eventPublicHref((int) $row['id']);
                            $relatedCover = trim((string) ($row['events_file'] ?? ''));
                            ?>
                            <a class="school-news-related-card" href="<?= h($href) ?>">
                                <span class="school-news-related-media">
                                    <?php if ($relatedCover !== ''): ?>
                                        <img src="<?= h(schoolMediaUrl($relatedCover, 'events_file')) ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                </span>
                                <span class="school-news-related-body">
                                    <time><?= h($relatedDate['day'] . ' ' . $relatedDate['month']) ?></time>
                                    <strong><?= h((string) $row['events_headlines']) ?></strong>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('eventCopyLink')?.addEventListener('click', async function () {
  var url = this.getAttribute('data-url') || '';
  try {
    await navigator.clipboard.writeText(url);
    this.classList.add('is-copied');
    this.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> কপি হয়েছে';
  } catch (error) {
    window.prompt('লিংক কপি করুন', url);
  }
});
</script>
<?php include 'footer.php'; ?>
