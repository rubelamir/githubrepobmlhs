<?php
declare(strict_types=1);

$curPageName = pathinfo(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME);
$navTree = [];
try {
    $navTree = publicNavMenuTree(publicDatabase());
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}

?>
<nav class="navbar navbar-expand-lg school-navbar sticky-top" aria-label="প্রধান মেনু">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand school-nav-home" href="index" aria-label="হোম">
            <i class="fas fa-home" aria-hidden="true"></i>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
            aria-expanded="false" aria-label="মেনু খুলুন">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mx-auto flex-wrap justify-content-center school-nav-list">
                <?php if ($navTree === []): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $curPageName === 'index' ? ' nav_link_active' : '' ?>" href="index">হোম</a>
                    </li>
                <?php endif; ?>
                <?php foreach ($navTree as $node): ?>
                    <?php
                    $item = $node['item'];
                    $children = $node['children'];
                    $label = (string) $item['label_bn'];
                    $href = publicNavHref((string) $item['url']);
                    $target = ((string) ($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
                    $rel = $target === '_blank' ? 'noopener noreferrer' : '';
                    $selfActive = publicNavIsActive((string) $item['url'], $curPageName);
                    $childActive = false;
                    foreach ($children as $child) {
                        if (publicNavIsActive((string) $child['url'], $curPageName)) {
                            $childActive = true;
                            break;
                        }
                    }
                    $isActive = $selfActive || $childActive;
                    ?>
                    <?php if ($children !== []): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= $isActive ? ' nav_link_active' : '' ?>"
                               href="<?= h($href) ?>"
                               id="navDrop<?= (int) $item['id'] ?>"
                               role="button"
                               data-bs-toggle="dropdown"
                               aria-expanded="false">
                                <?= h($label) ?>
                            </a>
                            <ul class="dropdown-menu school-nav-dropdown" aria-labelledby="navDrop<?= (int) $item['id'] ?>">
                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $childHref = publicNavHref((string) $child['url']);
                                    $childTarget = ((string) ($child['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
                                    $childRel = $childTarget === '_blank' ? 'noopener noreferrer' : '';
                                    $childIsActive = publicNavIsActive((string) $child['url'], $curPageName);
                                    ?>
                                    <li>
                                        <a class="dropdown-item<?= $childIsActive ? ' active' : '' ?>"
                                           href="<?= h($childHref) ?>"
                                           target="<?= h($childTarget) ?>"
                                           <?= $childRel !== '' ? 'rel="' . h($childRel) . '"' : '' ?>>
                                            <?= h((string) $child['label_bn']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive ? ' nav_link_active' : '' ?>"
                               href="<?= h($href) ?>"
                               target="<?= h($target) ?>"
                               <?= $rel !== '' ? 'rel="' . h($rel) . '"' : '' ?>>
                                <?= h($label) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php
            $navCtaLabel = trim((string) (currentSchool()['nav_cta_label'] ?? ''));
            $navCtaUrl = publicNavHref((string) (currentSchool()['nav_cta_url'] ?? 'online_admission'));
            if ($navCtaLabel !== ''):
            ?>
            <div class="school-nav-cta d-none d-lg-flex">
                <a class="btn btn-sm school-btn-admit" href="<?= h($navCtaUrl) ?>"><?= h($navCtaLabel) ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php
$headlines = [];
try {
    $headlineRows = tableExists(publicDatabase(), 'notice_headlines')
        ? fetchRows(publicDatabase(), 'SELECT notice_headlines FROM notice_headlines WHERE notice_headlines_status = 1')
        : [];
    foreach ($headlineRows as $headlineRow) {
        $headlines[] = (string) $headlineRow['notice_headlines'];
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}
if ($headlines !== []):
?>
<div class="school-ticker" aria-label="নোটিশ হেডলাইন">
    <div class="school-ticker-track">
        <?php for ($tickerPass = 0; $tickerPass < 2; $tickerPass++): ?>
        <div class="school-ticker-group"<?= $tickerPass === 1 ? ' aria-hidden="true"' : '' ?>>
            <?php foreach ($headlines as $headline): ?>
            <b><span class="school-ticker-dot">::</span><?= h($headline) ?></b>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>
<?php endif; ?>
