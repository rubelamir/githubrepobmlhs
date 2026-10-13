<?php
$curPageName = substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strrpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/') + 1);
$curPageName = substr($curPageName, 0, strrpos($curPageName, '.php') ?: strlen($curPageName));
$loggedIn = isset($_SESSION['admission_login_id']);
$admHero = (isset($admHero) && is_array($admHero)) ? $admHero : null;

$navItems = [
    ['href' => 'admission', 'label' => 'নতুন আবেদন', 'icon' => 'fa-file-lines', 'page' => 'admission', 'show' => !$loggedIn],
    ['href' => 'view_admission', 'label' => 'আবেদনকারীর তালিকা', 'icon' => 'fa-list', 'page' => 'view_admission', 'show' => true],
    ['href' => 'view_admission_form', 'label' => 'ফর্ম দেখুন', 'icon' => 'fa-id-card', 'page' => 'view_admission_form', 'show' => $loggedIn],
    ['href' => 'admission_form_edit', 'label' => 'ফর্ম সম্পাদনা', 'icon' => 'fa-pen-to-square', 'page' => 'admission_form_edit', 'show' => $loggedIn],
    ['href' => 'admission_login', 'label' => 'লগইন', 'icon' => 'fa-right-to-bracket', 'page' => 'admission_login', 'show' => !$loggedIn],
    ['href' => 'admission_forget_password', 'label' => 'পাসওয়ার্ড পুনরুদ্ধার', 'icon' => 'fa-key', 'page' => 'admission_forget_password', 'show' => !$loggedIn],
    ['href' => 'admission_reset_password', 'label' => 'পাসওয়ার্ড পরিবর্তন', 'icon' => 'fa-unlock-keyhole', 'page' => 'admission_reset_password', 'show' => !$loggedIn],
    ['href' => 'admission_logout', 'label' => 'লগআউট', 'icon' => 'fa-right-from-bracket', 'page' => 'admission_logout', 'show' => $loggedIn],
];
?>
<nav class="adm-nav" aria-label="ভর্তি মেনু">
    <div class="container">
        <ul class="nav justify-content-center flex-wrap">
            <?php foreach ($navItems as $item): ?>
                <?php if (!$item['show']) { continue; } ?>
                <li class="nav-item">
                    <a class="nav-link<?= $curPageName === $item['page'] ? ' is-active' : '' ?>" href="<?= h($item['href']) ?>">
                        <i class="fa-solid <?= h($item['icon']) ?>" aria-hidden="true"></i>
                        <span><?= h($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php if ($admHero !== null): ?>
<section class="adm-hero">
    <div class="container">
        <?php if (trim((string) ($admHero['kicker'] ?? '')) !== ''): ?>
            <p class="adm-kicker"><?= h((string) $admHero['kicker']) ?></p>
        <?php endif; ?>
        <h1><?= h((string) ($admHero['title'] ?? 'অনলাইন ভর্তি')) ?></h1>
        <?php if (trim((string) ($admHero['text'] ?? '')) !== ''): ?>
            <p><?= h((string) $admHero['text']) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
