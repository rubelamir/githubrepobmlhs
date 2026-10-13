<?php
declare(strict_types=1);

$pageMeta = [
    'title' => 'ইভেন্টস',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের সাম্প্রতিক ইভেন্টস, অনুষ্ঠান ও খবর।',
    'type' => 'website',
];
$pageAssets = [];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$events = tableExists($db, 'events')
    ? fetchRows($db, 'SELECT * FROM events WHERE events_status = 1 ORDER BY ' . eventListOrderSql($db))
    : [];
$featured = $events[0] ?? null;
$rest = $featured !== null ? array_slice($events, 1) : [];
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
        <?php if ($events === []): ?>
            <div class="school-news-empty">
                <i class="fa-regular fa-newspaper" aria-hidden="true"></i>
                <h3>এই মুহূর্তে কোনো ইভেন্ট প্রকাশিত নেই</h3>
                <p>নতুন অনুষ্ঠান ও খবর প্রকাশিত হলে এখানে দেখা যাবে।</p>
            </div>
        <?php else: ?>
            <?php
            $leadDate = schoolBengaliDate((string) ($featured['events_creation_time'] ?? ''));
            $leadHref = eventPublicHref((int) $featured['id']);
            $leadShare = facebookSharerUrl(publicAbsoluteUrl($leadHref));
            $leadCover = trim((string) ($featured['events_file'] ?? ''));
            ?>
            <article class="school-news-lead">
                <a class="school-news-lead-media" href="<?= h($leadHref) ?>">
                    <?php if ($leadCover !== ''): ?>
                        <img src="<?= h(schoolMediaUrl($leadCover, 'events_file')) ?>" alt="" loading="lazy" decoding="async">
                    <?php endif; ?>
                    <span class="school-news-badge">প্রধান খবর</span>
                </a>
                <div class="school-news-lead-body">
                    <p class="school-news-kicker">ইভেন্টস</p>
                    <time datetime="<?= h($leadDate['iso']) ?>">
                        <?= h($leadDate['day'] . ' ' . $leadDate['month'] . ' ' . $leadDate['year']) ?>
                    </time>
                    <h3 class="school-news-lead-title">
                        <a href="<?= h($leadHref) ?>"><?= h((string) $featured['events_headlines']) ?></a>
                    </h3>
                    <?php
                    $leadExcerpt = schoolSnippet(eventPlainText((string) ($featured['events_details'] ?? '')), 240);
                    if (mb_strlen(trim($leadExcerpt), 'UTF-8') > 8):
                    ?>
                    <p class="school-news-excerpt"><?= h($leadExcerpt) ?></p>
                    <?php endif; ?>
                    <div class="school-news-lead-actions">
                        <a class="school-news-read" href="<?= h($leadHref) ?>">সম্পূর্ণ খবর পড়ুন <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a>
                        <a class="school-news-share-fb" href="<?= h($leadShare) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-facebook-f" aria-hidden="true"></i> শেয়ার
                        </a>
                    </div>
                </div>
            </article>

            <?php if ($rest !== []): ?>
                <div class="school-news-section-head">
                    <h3>আরও ইভেন্টস</h3>
                </div>
                <div class="school-news-grid">
                    <?php foreach ($rest as $row):
                        $date = schoolBengaliDate((string) ($row['events_creation_time'] ?? ''));
                        $href = eventPublicHref((int) $row['id']);
                        $share = facebookSharerUrl(publicAbsoluteUrl($href));
                        $cover = trim((string) ($row['events_file'] ?? ''));
                        ?>
                        <article class="school-news-card">
                            <a class="school-news-card-media" href="<?= h($href) ?>">
                                <?php if ($cover !== ''): ?>
                                    <img src="<?= h(schoolMediaUrl($cover, 'events_file')) ?>" alt="" loading="lazy" decoding="async">
                                <?php endif; ?>
                                <span class="school-news-card-date">
                                    <strong><?= h($date['day']) ?></strong>
                                    <span><?= h($date['month']) ?></span>
                                </span>
                            </a>
                            <div class="school-news-card-body">
                                <p class="school-news-kicker">ইভেন্টস</p>
                                <h3 class="school-news-card-title">
                                    <a href="<?= h($href) ?>"><?= h((string) $row['events_headlines']) ?></a>
                                </h3>
                                <p class="school-news-excerpt is-short"><?= h(schoolSnippet(eventPlainText((string) ($row['events_details'] ?? '')), 140)) ?></p>
                                <div class="school-news-card-foot">
                                    <a class="school-news-read is-quiet" href="<?= h($href) ?>">বিস্তারিত</a>
                                    <a class="school-news-share-fb is-icon" href="<?= h($share) ?>" target="_blank" rel="noopener noreferrer" aria-label="ফেসবুকে শেয়ার">
                                        <i class="fa-brands fa-facebook-f" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
