<?php
$pageAssets = ['owl', 'fancybox'];
include('header.php');
include('navbar&marqueetag.php');
include('carousel&verticalnoticeboardpanel.php');
$schoolHome = currentSchool();
$homeDb = publicDatabase();
$infoCards = homepageInfoCards($homeDb);
$importantLinks = homepageLinks($homeDb, 'important');
$homeFacebook = schoolFacebookUrl($schoolHome);
$homeSearchEmpty = (string) ($_GET['search'] ?? '') === 'empty';

$glance = homepageGlanceStats($homeDb);
$staffCount = (int) $glance['staff'];
$boyCount = (int) $glance['boys'];
$girlCount = (int) $glance['girls'];
$committeeCount = (int) $glance['committee'];
$classSectionChart = is_array($glance['chart']) ? $glance['chart'] : [];
$classSectionChartTotal = 0;
foreach ($classSectionChart as $chartRow) {
    $classSectionChartTotal += (int) ($chartRow['value'] ?? 0);
}
$glanceItems = [
    ['fa-solid fa-users', $committeeCount, 'কমিটি মেম্বার'],
    ['fa-solid fa-user-tie', $staffCount, 'শিক্ষক ও কর্মচারী'],
    ['fa-solid fa-user', $boyCount, 'ছাত্র'],
    ['fa-solid fa-person-dress', $girlCount, 'ছাত্রী'],
];
?>
<section class="school-glance" aria-label="এক নজরে">
    <div class="school-glance-grid">
        <?php foreach ($glanceItems as [$glanceIcon, $glanceCount, $glanceLabel]): ?>
        <div class="school-glance-item">
            <i class="<?= h($glanceIcon) ?>" aria-hidden="true"></i>
            <span class="school-glance-copy">
                <span class="school-glance-value" data-count="<?= (int) $glanceCount ?>"><?= h(english_to_bengali((string) $glanceCount)) ?></span>
                <span class="school-glance-label"><?= h($glanceLabel) ?></span>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
$leaderMessages = homepageLeaderMessages($homeDb);
if ($leaderMessages !== []):
    $messagesTitle = trim((string) ($schoolHome['title_messages'] ?? ''));
    if ($messagesTitle === '') {
        $messagesTitle = 'সভাপতি ও প্রধান শিক্ষকের বাণী';
    }
?>
<section class="school-messages school-reveal" data-reveal="messages" aria-label="<?= h($messagesTitle) ?>">
    <div class="school-messages-inner">
            <?php schoolSectionHead($messagesTitle, 'নেতৃত্বের বাণী', 'সভাপতি ও প্রধান শিক্ষকের পক্ষ থেকে শিক্ষার্থী ও অভিভাবকদের উদ্দেশ্যে'); ?>
        <div class="school-messages-grid<?= count($leaderMessages) === 1 ? ' is-single' : '' ?>">
            <?php foreach ($leaderMessages as $leaderMessage):
                $msgName = trim((string) ($leaderMessage['display_name'] ?? ''));
                $msgPhoto = optimizedPublicImageUrl(trim((string) ($leaderMessage['display_photo'] ?? '')));
                $msgHeading = (string) ($leaderMessage['heading'] ?? '');
                $msgRole = (string) ($leaderMessage['designation'] ?? '');
                $msgText = (string) ($leaderMessage['message'] ?? '');
                $msgInitial = $msgName !== '' ? mb_strtoupper(mb_substr($msgName, 0, 1, 'UTF-8'), 'UTF-8') : '';
                $msgProfile = ((int) ($leaderMessage['teacher_id'] ?? 0) > 0)
                    ? publicStaffProfileUrl((int) $leaderMessage['teacher_id'])
                    : '';
            ?>
            <article class="school-message-card">
                <div class="school-message-photo">
                    <span class="school-message-fallback" aria-hidden="true">
                        <?php if ($msgInitial !== ''): ?><?= h($msgInitial) ?><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
                    </span>
                    <?php if ($msgPhoto !== ''): ?>
                        <img src="<?= h($msgPhoto) ?>" alt="<?= h($msgName) ?>" width="108" height="137" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
                    <?php endif; ?>
                </div>
                <blockquote class="school-message-quote">
                    <p class="school-message-kicker"><?= h($msgHeading) ?></p>
                    <p><?= nl2br(h($msgText)) ?></p>
                    <?php if ($msgName !== '' || $msgRole !== ''): ?>
                    <footer class="school-message-sign">
                        <?php if ($msgName !== ''): ?>
                            <cite>
                                <?php if ($msgProfile !== ''): ?>
                                    <a href="<?= h($msgProfile) ?>"><?= h($msgName) ?></a>
                                <?php else: ?>
                                    <?= h($msgName) ?>
                                <?php endif; ?>
                            </cite>
                        <?php endif; ?>
                        <?php if ($msgRole !== ''): ?>
                            <span><?= h($msgRole) ?></span>
                        <?php endif; ?>
                    </footer>
                    <?php endif; ?>
                </blockquote>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php
endif;
?>
<div class="row m-0">
    <div class="col-lg-8 p-3 school-home-main">
        <section class="mb-4 school-reveal" data-reveal="history">
            <?php schoolSectionHead((string) $schoolHome['title_history'], 'আমাদের কথা', 'প্রতিষ্ঠানের সংক্ষিপ্ত পরিচিতি'); ?>
            <?php
            $historyRows = tableExists($homeDb, 'school_history')
                ? fetchRows($homeDb, 'SELECT school_history FROM school_history WHERE school_history_status = 1 ORDER BY school_history_creation_time DESC LIMIT 1')
                : [];
            $historyFull = (string) ($historyRows[0]['school_history'] ?? '');
            if ($historyFull === '') {
                $historyFull = (string) ($schoolHome['about_short'] ?? '');
            }
            $historyTeaser = schoolHistoryTeaser($historyFull);
            if ($historyTeaser['text'] !== '') { ?>
                    <p class="mb-0 school-history-teaser"><?= nl2br(h($historyTeaser['text'])) ?></p>
                    <?php if ($historyTeaser['truncated']): ?>
                        <a class="school-text-more" href="about">বিস্তারিত <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
            <?php } else { ?>
                <p class="text-secondary mb-0">এই মুহূর্তে ইতিহাস প্রকাশিত নেই।</p>
            <?php } ?>
        </section>

        <section class="mb-4 school-reveal" data-reveal="cards">
            <?php schoolSectionHead((string) $schoolHome['title_info'], 'দ্রুত লিংক', 'প্রয়োজনীয় পাতা এক নজরে'); ?>
            <div class="row g-3">
                <?php foreach ($infoCards as $card):
                    $cardHref = publicNavHref((string) $card['url']);
                    $cardTitle = (string) $card['title'];
                    $cardImg = homepageCardImageSrc((string) $card['image_path']);
                    $cardTarget = preg_match('#^(https?:)?//#i', $cardHref) === 1 ? '_blank' : '_self';
                    ?>
                    <div class="col-sm-6 col-md-4">
                        <a class="school-card" href="<?= h($cardHref) ?>"<?= $cardTarget === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                            <div class="school-card-media">
                                <img src="<?= h($cardImg) ?>" alt="" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
                                <span class="school-card-caption"><?= h($cardTitle) ?></span>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mb-4 school-events school-reveal" data-reveal="events">
            <div class="school-events-head">
                <?php schoolSectionHead((string) $schoolHome['title_events'], 'ক্যাম্পাস', 'সাম্প্রতিক অনুষ্ঠান ও কার্যক্রম'); ?>
                <a class="school-events-all" href="events">সকল ইভেন্ট <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php
            $bnEventMonths = [
                1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
                5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
                9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
            ];
            $homeEvents = tableExists($homeDb, 'events')
    ? fetchRows($homeDb, 'SELECT * FROM events WHERE events_status = 1 ORDER BY ' . eventListOrderSql($homeDb) . ' LIMIT 3')
                : [];
            if ($homeEvents === []): ?>
                <p class="text-secondary mb-0">এই মুহূর্তে কোনো ইভেন্ট প্রকাশিত নেই।</p>
            <?php else:
                $featured = $homeEvents[0];
                $sideEvents = array_slice($homeEvents, 1);
                $eventStamp = static function (array $row): int {
                    $stamp = strtotime((string) ($row['events_creation_time'] ?? ''));
                    return $stamp !== false ? $stamp : time();
                };
                $eventHref = static function (array $row): string {
                    return h(eventPublicHref((int) $row['id']));
                };
                $featuredStamp = $eventStamp($featured);
                ?>
                <div class="school-events-layout<?= $sideEvents === [] ? ' is-single' : '' ?>">
                    <article class="school-event-featured">
                        <a class="school-event-featured-media" href="<?= $eventHref($featured) ?>">
                            <img src="<?= h(schoolMediaUrl((string) ($featured['events_file'] ?? ''), 'events_file')) ?>" alt="" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
                            <span class="school-event-date">
                                <strong><?= h(date('d', $featuredStamp)) ?></strong>
                                <span><?= h($bnEventMonths[(int) date('n', $featuredStamp)] ?? date('M', $featuredStamp)) ?></span>
                            </span>
                        </a>
                        <div class="school-event-featured-body">
                            <p class="school-event-kicker"><i class="fa-regular fa-calendar" aria-hidden="true"></i> সাম্প্রতিক</p>
                            <h3 class="school-event-featured-title">
                                <a href="<?= $eventHref($featured) ?>"><?= h(schoolSnippet((string) ($featured['events_headlines'] ?? ''), 90)) ?></a>
                            </h3>
                            <p class="school-event-excerpt"><?= h(schoolSnippet(eventPlainText((string) ($featured['events_details'] ?? '')), 180)) ?></p>
                            <a class="school-event-more" href="<?= $eventHref($featured) ?>">বিস্তারিত পড়ুন <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a>
                        </div>
                    </article>
                    <?php if ($sideEvents !== []): ?>
                        <div class="school-events-side">
                            <?php foreach ($sideEvents as $row):
                                $stamp = $eventStamp($row);
                                ?>
                                <article class="school-event-compact">
                                    <a class="school-event-compact-media" href="<?= $eventHref($row) ?>">
                                        <img src="<?= h(schoolMediaUrl((string) ($row['events_file'] ?? ''), 'events_file')) ?>" alt="" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
                                    </a>
                                    <div class="school-event-compact-body">
                                        <time class="school-event-compact-date" datetime="<?= h(date('Y-m-d', $stamp)) ?>">
                                            <?= h(date('d', $stamp) . ' ' . ($bnEventMonths[(int) date('n', $stamp)] ?? date('M', $stamp))) ?>
                                        </time>
                                        <h3 class="school-event-compact-title">
                                            <a href="<?= $eventHref($row) ?>"><?= h(schoolSnippet((string) ($row['events_headlines'] ?? ''), 72)) ?></a>
                                        </h3>
                                        <a class="school-event-more is-quiet" href="<?= $eventHref($row) ?>">বিস্তারিত</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="mb-4">
            <?php schoolSectionHead((string) $schoolHome['title_ssc'], 'ফলাফল', 'পাবলিক পরীক্ষায় অর্জন'); ?>
            <?php include('ssc_result.php'); ?>
        </section>

        <section class="mb-2">
            <?php schoolSectionHead((string) $schoolHome['title_teachers'], 'শিক্ষক মণ্ডলী', 'যাঁরা প্রতিদিন পাঠদান করেন'); ?>
            <?php include('owlcarouselteahercard.php'); ?>
        </section>
    </div>

    <aside class="col-lg-4 p-3 school-home-aside">
        <?php if ($classSectionChart !== []): ?>
        <section class="school-class-chart mb-4" aria-labelledby="homeClassChartHeading">
            <h5 id="homeClassChartHeading" class="w-100 school-aside-heading">ছাত্র-ছাত্রীর তথ্য চার্ট</h5>
            <div class="school-class-chart-card">
                <p class="school-class-chart-total">মোট <?= h(english_to_bengali((string) $classSectionChartTotal)) ?> জন</p>
                <div class="school-class-chart-layout">
                    <div class="school-class-chart-canvas-wrap">
                        <canvas id="homeClassSectionChart" width="280" height="280" role="img" aria-label="ছাত্র-ছাত্রীর তথ্য চার্ট"></canvas>
                    </div>
                    <ul class="school-class-chart-legend" id="homeClassSectionLegend"></ul>
                </div>
                <script type="application/json" id="homeClassSectionChartData"><?= htmlspecialchars(json_encode($classSectionChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8') ?></script>
            </div>
        </section>
        <?php endif; ?>

        <section class="school-home-search-card mb-4<?= $homeSearchEmpty ? ' has-message' : '' ?>" id="student-search" aria-label="Search">
            <div class="school-home-search-head">
                <span class="school-home-search-badge" aria-hidden="true">
                    <i class="fa-solid fa-user-graduate"></i>
                </span>
                <div>
                    <h5 class="school-home-search-title">Search</h5>
                    <p class="school-home-search-kicker">ছাত্র-ছাত্রীর তথ্য</p>
                </div>
            </div>
            <form class="school-home-search" id="homeStudentSearch" action="student_search" method="get" role="search">
                <label class="visually-hidden" for="homeStudentSearchInput">ছাত্র-ছাত্রীর তথ্য খুজুন</label>
                <div class="school-home-search-field">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input class="school-home-search-input" type="search" id="homeStudentSearchInput" name="q" value="" placeholder="নাম, রোল, পিতা/মাতা বা মোবাইল" maxlength="120" autocomplete="off" enterkeyhint="search" aria-autocomplete="list" aria-controls="homeStudentSuggest" aria-describedby="homeStudentSearchHint homeStudentSearchMessage"<?= $homeSearchEmpty ? ' aria-invalid="true"' : '' ?>>
                </div>
                <div class="school-suggest" id="homeStudentSuggest" hidden role="listbox" aria-label="সার্চ সাজেশন"></div>
                <button class="school-home-search-btn" type="submit">
                    <span>Search</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
            <p class="school-home-search-message" id="homeStudentSearchMessage" role="status" aria-live="polite"<?= $homeSearchEmpty ? '' : ' hidden' ?>><?= $homeSearchEmpty ? 'কোন তথ্য খুঁজে পাওয়া যায় নাই' : '' ?></p>
            <p class="school-home-search-hint" id="homeStudentSearchHint">নাম, পিতা/মাতার নাম, রোল অথবা মোবাইল নাম্বার দিয়ে খুঁজুন</p>
        </section>

        <section class="school-prayer mb-4" id="schoolPrayerTimes" aria-labelledby="homePrayerHeading" data-city="Lakshmipur" data-country="Bangladesh" data-latitude="22.9447" data-longitude="90.8300" data-method="1" data-school="1">
            <h5 id="homePrayerHeading" class="w-100 school-aside-heading">নামাজের সময়সূচি</h5>
            <div class="school-prayer-card">
                <div class="school-prayer-meta">
                    <span class="school-prayer-place"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> লক্ষ্মীপুর, বাংলাদেশ</span>
                    <time class="school-prayer-date" data-prayer-date></time>
                </div>
                <p class="school-prayer-status is-loading" data-prayer-status role="status" aria-live="polite">Loading...</p>
                <div class="school-prayer-board" data-prayer-board hidden>
                    <div class="school-prayer-cols" aria-hidden="true">
                        <span>নামাজ</span>
                        <span>শুরু</span>
                        <span>শেষ</span>
                    </div>
                    <ul class="school-prayer-list" data-prayer-list></ul>
                    <div class="school-prayer-forbidden">
                        <h6 class="school-prayer-forbidden-title">নিষিদ্ধ সময়</h6>
                        <div class="school-prayer-cols" aria-hidden="true">
                            <span>সময়</span>
                            <span>শুরু</span>
                            <span>শেষ</span>
                        </div>
                        <ul class="school-prayer-list" data-prayer-forbidden-list></ul>
                    </div>
                </div>
                <noscript>
                    <p class="school-prayer-status">নামাজের সময় দেখতে জাভাস্ক্রিপ্ট চালু করুন।</p>
                </noscript>
            </div>
        </section>

        <?php
        $anthemFile = __DIR__ . '/audio/national-anthem.mp3';
        $anthemSrc = is_file($anthemFile)
            ? 'audio/national-anthem.mp3?v=' . (int) filemtime($anthemFile)
            : '';
        ?>
        <?php if ($anthemSrc !== ''): ?>
        <section class="school-anthem mb-4" id="schoolNationalAnthem" aria-labelledby="homeAnthemHeading" data-src="<?= h($anthemSrc) ?>">
            <h5 id="homeAnthemHeading" class="w-100 school-aside-heading">জাতীয় সংগীত</h5>
            <div class="school-anthem-card">
                <button type="button" class="school-anthem-play" data-anthem-play aria-pressed="false" aria-label="জাতীয় সংগীত চালান">
                    <i class="fa-solid fa-play" data-anthem-icon aria-hidden="true"></i>
                </button>
                <div class="school-anthem-copy">
                    <strong>আমার সোনার বাংলা</strong>
                    <span data-anthem-status>চালাতে আইকনে চাপুন</span>
                    <span class="school-anthem-time" data-anthem-time>০:০০ / ০:০০</span>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($homeFacebook !== ''): ?>
        <section class="school-home-facebook mb-4" aria-labelledby="homeFacebookHeading">
            <h5 id="homeFacebookHeading" class="w-100 school-aside-heading">ফেসবুক</h5>
            <a class="school-home-facebook-link" href="<?= h($homeFacebook) ?>" target="_blank" rel="noopener noreferrer" aria-label="ফেসবুক পেজ ভিজিট করুন">
                <span class="school-home-facebook-icon" aria-hidden="true">
                    <i class="fa-brands fa-facebook-f"></i>
                </span>
                <span class="school-home-facebook-copy">
                    <strong>বিদ্যালয়ের ফেসবুক পেজ</strong>
                    <span>আইকনে ক্লিক করে ভিজিট করুন</span>
                </span>
            </a>
        </section>
        <?php endif; ?>

        <section class="school-home-hotline mb-4" aria-labelledby="homeHotlineHeading">
            <h5 id="homeHotlineHeading" class="w-100 school-aside-heading">জরুরী হটলাইন</h5>
            <figure class="school-home-hotline-card mb-0">
                <img class="school-home-hotline-img" src="image/national-helpline.jpg" width="220" height="445" alt="জাতীয় জরুরী হটলাইন: সরকারি তথ্য ও সেবা ৩৩৩, জরুরি সেবা ৯৯৯, নারী ও শিশু নির্যাতন প্রতিরোধে ১০৯, দুদক ১০৬, দুর্যোগের আগাম বার্তা ১০৯০" loading="lazy" decoding="async">
            </figure>
        </section>

        <div class="mb-4 school-reveal" data-reveal="links">
            <h5 class="w-100 school-aside-heading"><?= h((string) $schoolHome['title_important_links']) ?></h5>
            <ul class="links">
                <?php foreach ($importantLinks as $link):
                    $href = publicNavHref((string) $link['url']);
                    $target = ((string) ($link['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
                    ?>
                    <li><a href="<?= h($href) ?>"<?= $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h((string) $link['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
</div>

<?php $keepDocumentOpen = true; include('footer.php'); ?>
<?php if ($classSectionChart !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script src="js/student-class-chart.js?v=<?= (int) @filemtime(__DIR__ . '/js/student-class-chart.js') ?>"></script>
<?php endif; ?>
<script src="js/prayer-times.js?v=<?= (int) @filemtime(__DIR__ . '/js/prayer-times.js') ?>"></script>
<script src="js/national-anthem.js?v=<?= (int) @filemtime(__DIR__ . '/js/national-anthem.js') ?>"></script>
<script src="js/home-scroll-reveal.js?v=<?= (int) @filemtime(__DIR__ . '/js/home-scroll-reveal.js') ?>"></script>
<script src="js/school-home-ui.js?v=<?= (int) @filemtime(__DIR__ . '/js/school-home-ui.js') ?>"></script>
<script>
    $(document).ready(function() {
        $('.owl-carousel').owlCarousel({
            loop: true,
            margin: 10,
            autoplay: true,
            autoHeight: false,
            autoplayTimeout: 2000,
            autoplayHoverPause: true,
            responsive: {
                0: { items: 1, nav: false, dots: false },
                500: { items: 2, nav: false, dots: false },
                750: { items: 3, nav: false, dots: false }
            }
        });
    });
</script>
</body>
</html>
