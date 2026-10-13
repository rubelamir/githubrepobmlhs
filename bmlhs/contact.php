<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'যোগাযোগ',
    'description' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের ঠিকানা, মোবাইল, ই-মেইল ও অবস্থান মানচিত্র।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$contactSchool = currentSchool();
$contactMobile = trim((string) ($contactSchool['mobile'] ?? ''));
$contactMobileTel = preg_replace('/[^\d+]/', '', $contactMobile) ?? '';
$contactEmail = trim((string) ($contactSchool['email'] ?? ''));
$contactAddress = trim((string) ($contactSchool['address'] ?? ''));
$contactFacebook = schoolFacebookUrl($contactSchool);
$contactMap = schoolMapEmbedSrc($contactSchool);

$contactErrors = [];
$contactStatus = '';
$contactName = '';
$contactPhone = '';
$contactMessage = '';
if (!empty($_SESSION['contact_form_sent'])) {
    $contactStatus = 'sent';
    unset($_SESSION['contact_form_sent']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $contactErrors['form'] = 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। আবার চেষ্টা করুন।';
    } else {
        $contactName = trim((string) ($_POST['name'] ?? ''));
        $contactPhone = trim((string) ($_POST['mobile'] ?? ''));
        $contactMessage = trim((string) ($_POST['message'] ?? ''));
        $contactPhoneDigits = preg_replace('/\D+/', '', asciiDigits($contactPhone)) ?? '';

        if ($contactName === '' || mb_strlen($contactName, 'UTF-8') < 2) {
            $contactErrors['name'] = 'নাম লিখুন।';
        }
        if (strlen($contactPhoneDigits) !== 11) {
            $contactErrors['mobile'] = '১১ সংখ্যার মোবাইল নম্বর লিখুন।';
        }
        if ($contactMessage === '' || mb_strlen($contactMessage, 'UTF-8') < 8) {
            $contactErrors['message'] = 'বার্তাটি আরও বিস্তারিত লিখুন।';
        }

        if ($contactErrors === []) {
            $saved = false;
            try {
                $contactDb = publicDatabase();
                ensureContactMessagesTable($contactDb);
                $contactDb->prepare(
                    'INSERT INTO contact_messages (sender_name, mobile, message, is_read) VALUES (:name, :mobile, :message, 0)'
                )->execute([
                    'name' => mb_substr($contactName, 0, 120, 'UTF-8'),
                    'mobile' => mb_substr($contactPhoneDigits, 0, 20, 'UTF-8'),
                    'message' => mb_substr($contactMessage, 0, 2000, 'UTF-8'),
                ]);
                $saved = true;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
            }
            if ($saved) {
                $_SESSION['contact_form_sent'] = 1;
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Location: contact', true, 303);
                exit;
            }
            $contactErrors['form'] = 'বার্তা পাঠানো যায়নি। আবার চেষ্টা করুন।';
        }
    }
}
?>
<div class="school-contact-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>যোগাযোগ</h2>
            </div>
        </div>
    </section>

    <div class="container school-contact-wrap py-4 py-lg-5">
        <div class="school-contact-grid">
            <section class="school-contact-info" aria-labelledby="schoolContactInfoHeading">
                <h3 id="schoolContactInfoHeading">বিদ্যালয়ের ঠিকানা</h3>
                <p class="school-contact-lead"><?= h((string) ($contactSchool['school_name_bn'] ?? '')) ?></p>
                <ul class="school-contact-list">
                    <?php if ($contactAddress !== ''): ?>
                        <li>
                            <span class="school-contact-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                            <span><?= h($contactAddress) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($contactMobile !== ''): ?>
                        <li>
                            <span class="school-contact-icon" aria-hidden="true"><i class="fa-solid fa-phone"></i></span>
                            <span>মোবাইল: <a href="tel:<?= h($contactMobileTel) ?>"><?= h($contactMobile) ?></a></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($contactEmail !== ''): ?>
                        <li>
                            <span class="school-contact-icon" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
                            <span>ই-মেইল: <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a></span>
                        </li>
                    <?php endif; ?>
                </ul>
                <?php if ($contactFacebook !== ''): ?>
                    <a class="school-contact-facebook" href="<?= h($contactFacebook) ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-facebook-f" aria-hidden="true"></i>
                        ফেসবুক পেজ
                    </a>
                <?php endif; ?>
            </section>

            <section class="school-contact-form-card" aria-labelledby="schoolContactFormHeading">
                <h3 id="schoolContactFormHeading">বার্তা পাঠান</h3>
                <?php if ($contactStatus === 'sent'): ?>
                    <p class="school-contact-alert is-success" role="status">বার্তা পাঠানো হয়েছে।</p>
                <?php endif; ?>
                <?php if (isset($contactErrors['form'])): ?>
                    <p class="school-contact-alert is-error"><?= h($contactErrors['form']) ?></p>
                <?php endif; ?>
                <?php if ($contactStatus !== 'sent'): ?>
                <form class="school-contact-form" method="post" novalidate>
                    <?= csrfField() ?>
                    <label for="contactName">নাম</label>
                    <input id="contactName" name="name" type="text" maxlength="80" autocomplete="name" required value="<?= h($contactName) ?>">
                    <?php if (isset($contactErrors['name'])): ?><small><?= h($contactErrors['name']) ?></small><?php endif; ?>

                    <label for="contactMobile">মোবাইল</label>
                    <input id="contactMobile" name="mobile" type="tel" inputmode="numeric" maxlength="11" autocomplete="tel" required value="<?= h($contactPhone) ?>">
                    <?php if (isset($contactErrors['mobile'])): ?><small><?= h($contactErrors['mobile']) ?></small><?php endif; ?>

                    <label for="contactMessage">বার্তা</label>
                    <textarea id="contactMessage" name="message" rows="5" maxlength="2000" required><?= h($contactMessage) ?></textarea>
                    <?php if (isset($contactErrors['message'])): ?><small><?= h($contactErrors['message']) ?></small><?php endif; ?>

                    <button class="school-contact-submit" type="submit">পাঠান</button>
                </form>
                <?php endif; ?>
            </section>
        </div>

        <section class="school-contact-map" aria-label="অবস্থান মানচিত্র">
            <h3>অবস্থান মানচিত্র</h3>
            <div class="school-contact-map-frame">
                <iframe src="<?= h($contactMap) ?>" title="বিদ্যালয়ের অবস্থান" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
            </div>
        </section>
    </div>
</div>
<?php include 'footer.php'; ?>
