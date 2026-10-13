<?php
declare(strict_types=1);

require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/includes/site.php';

$id = publicRecordId('notice');
$row = null;
if ($id > 0) {
    $statement = schoolMysqli()->prepare(
        'SELECT id, main_notice_text, main_notice_file, main_notice_status,
                DATE_FORMAT(main_notice_creation_time, "%Y-%m-%d %H:%i:%s") AS main_notice_creation_time
         FROM main_notice
         WHERE id = ? AND main_notice_status = 1'
    );
    $statement->bind_param('i', $id);
    $statement->execute();
    $query = $statement->get_result();
    if ($query instanceof mysqli_result) {
        $row = mysqli_fetch_assoc($query) ?: null;
    }
    $statement->close();
}

$noticeText = trim((string) ($row['main_notice_text'] ?? ''));
$noticeTitle = $noticeText !== '' ? schoolSnippet(preg_replace('/\s+/u', ' ', $noticeText) ?? $noticeText, 80) : 'নোটিশ';
$sharePath = $id > 0 ? publicNoticeUrl($id) : 'notice';
$pageAssets = [];
$pageMeta = [
    'title' => $noticeTitle,
    'description' => $noticeText !== '' ? schoolSnippet($noticeText, 160) : 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের নোটিশ।',
    'url' => $sharePath,
    'type' => 'article',
];
include 'header.php';
include 'navbar&marqueetag.php';

if ($row === null) {
    http_response_code(404);
}
$shareUrl = $row === null ? '' : publicAbsoluteUrl($sharePath);
?>
<div style="min-height: 35vw;">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>নোটিশ</h2>
            </div>
        </div>
    </section>
    <div class="container py-4">
        <?php if ($row === null): ?>
            <p class="text-center text-secondary">এই নোটিশটি পাওয়া যায়নি।</p>
            <p class="text-center"><a href="notice">নোটিশ তালিকায় ফিরুন</a></p>
        <?php else:
            $createdRaw = preg_replace('/\.\d+/', '', (string) ($row['main_notice_creation_time'] ?? '')) ?? '';
            $createdStamp = trim($createdRaw) !== '' ? strtotime($createdRaw) : false;
            $date = $createdStamp ? date('d-m-Y', $createdStamp) : '';
            $time = $createdStamp ? date('g:i a', $createdStamp) : '';
            $noticeFile = publicMediaBasename((string) ($row['main_notice_file'] ?? ''));
            $exttype = $noticeFile !== '' ? strtolower(pathinfo($noticeFile, PATHINFO_EXTENSION)) : '';
            $fileUrl = $noticeFile !== '' ? 'main_notice_file/' . $noticeFile : '';
        ?>
            <article class="school-notice-single">
                <p>নোটিশ আপলোডের তারিখ: <?= h($date) ?>, সময় : <?= h($time) ?></p>
                <h3 class="mx-auto"><?= nl2br(h($noticeText)) ?></h3>
                <?php if ($noticeFile !== '' && $exttype === 'pdf'): ?>
                    <iframe style="width:100%; height: 8.5in" src="<?= h($fileUrl) ?>" title="নোটিশ ফাইল"></iframe>
                <?php elseif ($noticeFile !== ''): ?>
                    <a href="<?= h($fileUrl) ?>" class="btn btn-warning fw-bolder" target="_blank" rel="noopener">ডাউনলোড</a>
                <?php endif; ?>
                <div class="school-share">
                    <a class="school-share-btn is-facebook" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($shareUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-facebook-f" aria-hidden="true"></i>
                        ফেসবুকে শেয়ার
                    </a>
                </div>
            </article>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
