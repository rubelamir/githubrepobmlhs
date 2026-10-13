<?php
declare(strict_types=1);

http_response_code(404);
$pageAssets = [];
$pageMeta = [
    'title' => 'পাতা পাওয়া যায়নি',
    'description' => 'আপনি যে পাতাটি খুঁজছেন সেটি পাওয়া যায়নি।',
];
include 'header.php';
include 'navbar&marqueetag.php';
?>
<div class="school-error-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <h2>পাতা পাওয়া যায়নি</h2>
            </div>
        </div>
    </section>
    <div class="container school-error-wrap py-5 text-center">
        <p class="school-error-code">৪০৪</p>
        <h3>এই পাতাটি খুঁজে পাওয়া যায়নি</h3>
        <p>লিংকটি ভুল হতে পারে, অথবা পাতাটি সরানো হয়েছে। হোমপেজে ফিরে আবার চেষ্টা করুন।</p>
        <a class="school-error-home" href="index">হোমে ফিরুন</a>
    </div>
</div>
<?php include 'footer.php'; ?>
