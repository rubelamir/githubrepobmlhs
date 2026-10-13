<?php
$pageAssets = [];
include 'header.php';
include 'navbar&marqueetag.php';
?>
<section class="reg_section sweet-mini-section">
  <div class="bg-mini-overlay d-flex align-items-center">
    <div class="container">
      <h2>বিদ্যালয়ের সম্মানিত শিক্ষক ও কর্মচারীবৃন্দ</h2>
    </div>
  </div>
</section>

<div class="school-staff-page">
  <div class="container py-4 py-lg-5">
    <p class="school-staff-intro mb-4">শিক্ষক ও কর্মচারীদের বিস্তারিত পরিচিতি দেখতে কার্ডের “প্রোফাইল দেখুন” বাটনে ক্লিক করুন।</p>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4 mb-2">
<?php
$sql = "SELECT id, teacher_name, teacher_mobile, teacher_subject, teacher_designation, teacher_pic
        FROM teacher_info
        WHERE teacher_status = 1
        ORDER BY " . teacherListOrderSql(publicDatabase());
$query = mysqli_query($conn, $sql);
if ($query && mysqli_num_rows($query) > 0) {
    while ($staffCard = mysqli_fetch_assoc($query)) {
        echo '<div class="col">';
        include __DIR__ . '/includes/staff-card.php';
        echo '</div>';
    }
} else {
    echo '<div class="col-12"><div class="school-result-empty">কোনো শিক্ষক বা কর্মচারীর তথ্য পাওয়া যায়নি।</div></div>';
}
?>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
