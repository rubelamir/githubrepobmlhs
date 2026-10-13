<?php
$pageAssets = [];
$pageMeta = ['title' => 'অনলাইন ভর্তি'];
include('header.php');
if (isset($_SESSION['admission_login_id'])) {
    header('Location: view_admission_form');
    exit;
}
include('navbar&marqueetag.php');

$admHero = [
    'kicker' => 'ভর্তি প্রক্রিয়া',
    'title' => 'অনলাইন ভর্তি',
    'text' => 'টোকেন নিয়ে আবেদন পূরণ করুন, পরে লগইন করে ফর্ম দেখুন, সম্পাদনা করুন ও প্রিন্ট নিন।',
];
?>
<div class="adm-page">
    <?php include('admission_navigation.php'); ?>
    <section class="adm-wrap">
        <div class="container">
            <div class="adm-steps">
                <article class="adm-step-card">
                    <span class="adm-step-no">১</span>
                    <strong>টোকেন সংগ্রহ</strong>
                    <p>বিদ্যালয় থেকে অব্যবহৃত ভর্তি টোকেন নিন।</p>
                </article>
                <article class="adm-step-card">
                    <span class="adm-step-no">২</span>
                    <strong>অনলাইন আবেদন</strong>
                    <p>জন্ম সনদ অনুসারে ফর্ম পূরণ করে জমা দিন।</p>
                </article>
                <article class="adm-step-card">
                    <span class="adm-step-no">৩</span>
                    <strong>লগইন ও প্রিন্ট</strong>
                    <p>মোবাইল দিয়ে লগইন করে ফর্ম দেখুন বা সংশোধন করুন।</p>
                </article>
                <article class="adm-step-card">
                    <span class="adm-step-no">৪</span>
                    <strong>কাগজপত্র জমা</strong>
                    <p>প্রিন্ট করা ফর্ম ও প্রয়োজনীয় কাগজ বিদ্যালয়ে জমা দিন।</p>
                </article>
            </div>
            <div class="adm-portal-grid">
                <article class="adm-portal-card">
                    <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i>
                    <h2>নতুন ভর্তি আবেদন</h2>
                    <p>টোকেন, ছবি ও সঠিক তথ্য দিয়ে নতুন আবেদন করুন।</p>
                    <a class="btn" href="admission">আবেদন শুরু করুন</a>
                </article>
                <article class="adm-portal-card">
                    <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                    <h2>আবেদনকারী লগইন</h2>
                    <p>আগে আবেদন করে থাকলে লগইন করে ফর্ম দেখুন।</p>
                    <a class="btn" href="admission_login">লগইন করুন</a>
                </article>
                <article class="adm-portal-card">
                    <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
                    <h2>আবেদনকারীর তালিকা</h2>
                    <p>চলতি বছরের প্রাথমিক আবেদন তালিকা দেখুন।</p>
                    <a class="btn" href="view_admission">তালিকা দেখুন</a>
                </article>
                <article class="adm-portal-card">
                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                    <h2>পাসওয়ার্ড সহায়তা</h2>
                    <p>পাসওয়ার্ড ভুলে গেলে যাচাই করে নতুন করে সেট করুন।</p>
                    <a class="btn" href="admission_forget_password">পুনরুদ্ধার করুন</a>
                </article>
            </div>
        </div>
    </section>
</div>
<?php include('footer.php'); ?>
