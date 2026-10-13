<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'নোটিশ',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের প্রকাশিত নোটিশসমূহ।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$noticeQuery = mysqli_query($conn, 'SELECT * FROM main_notice WHERE main_notice_status = 1 ORDER BY main_notice_creation_time DESC');
$noticeRows = [];
if ($noticeQuery instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($noticeQuery)) {
        $noticeRows[] = $row;
    }
}
?>
<div style="min-height: 35vw;">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>নোটিশ</h2>
            </div>
        </div>
    </section>
    <br>

    <?php if ($noticeRows === []): ?>
        <h3 class="text-center text-secondary my-5">এই মুহূর্তে কোনো নোটিশ প্রকাশিত নেই</h3>
    <?php else: ?>
        <div class="table-responsive mb-5">
            <table class="table table-striped table-hover border-3 table-info table-bordered border-dark align-middle w-75 m-auto school-notice-table">
                <thead class="table-dark text-center">
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">নোটিশ</th>
                        <th scope="col">তারিখ</th>
                        <th scope="col">ডাউনলোড</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($noticeRows as $sl => $row):
                    $date = date('d-m-Y', strtotime((string) $row['main_notice_creation_time']));
                    $noticeFile = publicMediaBasename((string) ($row['main_notice_file'] ?? ''));
                    $exttype = strtolower(pathinfo($noticeFile, PATHINFO_EXTENSION));
                    $fileUrl = $noticeFile !== '' ? 'main_notice_file/' . $noticeFile : '';
                    $noticeHref = publicNoticeUrl((int) $row['id']);
                ?>
                    <tr>
                        <th class="text-center" scope="row"><?= h(english_to_bengali((string) ($sl + 1))) ?></th>
                        <td><a href="<?= h($noticeHref) ?>"><?= h((string) $row['main_notice_text']) ?></a></td>
                        <td class="text-nowrap text-center"><?= h($date) ?></td>
                        <td class="text-center">
                            <?php if ($noticeFile !== ''): ?>
                                <a href="<?= h($fileUrl) ?>" target="_blank" rel="noopener" class="school-notice-file-link">
                                    <?php if ($exttype === 'pdf'): ?>
                                        <i class="fa-solid fa-file-pdf school-notice-file-icon school-notice-file-pdf" aria-hidden="true"></i>
                                        <span class="visually-hidden">PDF</span>
                                    <?php elseif ($exttype === 'doc' || $exttype === 'docx'): ?>
                                        <i class="fa-solid fa-file-word school-notice-file-icon school-notice-file-word" aria-hidden="true"></i>
                                        <span class="visually-hidden">Word</span>
                                    <?php elseif ($exttype === 'xls' || $exttype === 'xlsx'): ?>
                                        <i class="fa-solid fa-file-excel school-notice-file-icon school-notice-file-excel" aria-hidden="true"></i>
                                        <span class="visually-hidden">Excel</span>
                                    <?php elseif (in_array($exttype, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)): ?>
                                        <img width="40" height="40" src="<?= h($fileUrl) ?>" alt="ছবি">
                                    <?php else: ?>
                                        <i class="fa-solid fa-file school-notice-file-icon" aria-hidden="true"></i>
                                        <span class="visually-hidden">ফাইল</span>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
