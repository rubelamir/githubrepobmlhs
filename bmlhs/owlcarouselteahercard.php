<!-- owl carousel start -->
<div class="owl-carousel owl-theme">
<?php
$staffCards = tableExists(publicDatabase(), 'teacher_info')
    ? fetchRows(
        publicDatabase(),
        'SELECT id, teacher_name, teacher_mobile, teacher_subject, teacher_designation, teacher_pic
         FROM teacher_info
         WHERE teacher_status = 1
         ORDER BY ' . teacherListOrderSql(publicDatabase())
    )
    : [];
foreach ($staffCards as $staffCard):
?>
  <div class="item px-2">
    <?php include __DIR__ . '/includes/staff-card.php'; ?>
  </div>
<?php endforeach; ?>
</div>
<!-- owl carousel end -->
