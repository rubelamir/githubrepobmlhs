<!-- carousel & vertical notice board panel start -->
<div class="row m-0 p-0 school-hero">
    <div class="col-lg-8 p-0 m-0">
        <?php
        $carouselRows = [];
        try {
            $carouselRows = publicCarouselSlides(publicDatabase());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
        }
        ?>
        <div id="carouselExampleCaptions" class="carousel slide carousel-fade" data-bs-ride="carousel">
            <div class="carousel-indicators school-carousel-dots">
                <?php foreach ($carouselRows as $slideIndex => $carouselRow): ?>
                <button type="button" data-bs-target="#carouselExampleCaptions" data-bs-slide-to="<?= (int) $slideIndex ?>"
                    <?= $slideIndex === 0 ? 'class="active" aria-current="true"' : '' ?>
                    aria-label="স্লাইড <?= (int) $slideIndex + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
            <div class="carousel-inner">
                <?php foreach ($carouselRows as $slideIndex => $carouselRow): ?>
                <div class="carousel-item<?= $slideIndex === 0 ? ' active' : '' ?>" data-bs-interval="10000">
                    <?= schoolResponsiveImage(
                        (string) $carouselRow['carousel_file'],
                        'carousel_file',
                        [
                            'class' => 'd-block w-100 img-fluid',
                            'alt' => '',
                            'fetchpriority' => $slideIndex === 0 ? 'high' : null,
                            'loading' => $slideIndex === 0 ? null : 'lazy',
                            'decoding' => 'async',
                            'onerror' => "this.classList.add('is-broken')",
                        ]
                    ) ?>
                    <?php if (trim((string) $carouselRow['carousel_text']) !== ''): ?>
                    <div class="carousel-caption d-none d-md-block">
                        <span><?= h((string) $carouselRow['carousel_text']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#carouselExampleCaptions" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#carouselExampleCaptions" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
            </button>
        </div>
    </div>
    <div class="col-lg-4 p-0 school-notice-panel">
        <div class="school-notice-board">
            <div class="school-notice-head">
                <h2 class="school-notice-title">নোটিশ বোর্ড</h2>
            </div>
            <?php
                $noticeRows = [];
                try {
                    $noticeRows = tableExists(publicDatabase(), 'main_notice')
                        ? fetchRows(
                            publicDatabase(),
                            'SELECT * FROM main_notice WHERE main_notice_status = 1 ORDER BY main_notice_creation_time DESC LIMIT 10'
                        )
                        : [];
                } catch (Throwable $exception) {
                    error_log($exception->getMessage());
                }
                $isScrolling = count($noticeRows) > 4;
                $renderNoticeItem = static function (array $row, bool $inert = false): void {
                    $stamp = strtotime((string) $row['main_notice_creation_time']);
                    $noticeHref = publicNoticeUrl((int) $row['id']);
                    ?>
                    <a class="school-notice-item" role="listitem" href="<?= h($noticeHref) ?>"<?= $inert ? ' tabindex="-1"' : '' ?>>
                        <time class="school-notice-date" datetime="<?= h(date('Y-m-d', $stamp)) ?>">
                            <span class="school-notice-day"><?= h(date('j-M', $stamp)) ?></span>
                            <span class="school-notice-date-divider" aria-hidden="true"></span>
                            <span class="school-notice-month"><?= h(date('Y', $stamp)) ?></span>
                        </time>
                        <span class="school-notice-text"><?= h((string) $row['main_notice_text']) ?></span>
                    </a>
                    <?php
                };
                ?>
            <div class="school-notice-marquee">
                <?php if ($noticeRows === []): ?>
                <p class="school-notice-empty mb-0">এই মুহূর্তে কোনো নোটিশ নেই।</p>
                <?php else: ?>
                <div class="school-notice-scroll<?= $isScrolling ? ' is-scrolling' : '' ?>">
                    <div class="school-notice-list" role="list">
                        <?php foreach ($noticeRows as $row) {
                            $renderNoticeItem($row);
                        } ?>
                    </div>
                    <?php if ($isScrolling): ?>
                    <div class="school-notice-list" role="presentation" aria-hidden="true">
                        <?php foreach ($noticeRows as $row) {
                            $renderNoticeItem($row, true);
                        } ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="school-notice-foot">
                <a href="notice" class="btn btn-sm school-notice-all">সকল নোটিশ</a>
            </div>
        </div>
    </div>
</div>
<!-- carousel & vertical notice board panel close -->
