<?php
$pageAssets = [];
include('header.php');
include('navbar&marqueetag.php');

?>

<div style="min-height: 35vw;">
   <!-- =============== Page Header Section Start ============================= -->
   <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <h2>স্কলারশিপ</h2>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- =============== Page Header Section End =============================== -->
    <div class="h4 text-center my-4 fw-bolder">বৃত্তি প্রাপ্ত ছাত্র/ছাত্রীর তালিকা</div>
    <?php
    $sql = "SELECT s.cls_roll, cls.class_name_ban as cls, sections.section_name_ban, s.name_ban, s.fname_ban, s.mobile, s.student_pic FROM students_info s LEFT JOIN cls ON s.cls = cls.id LEFT JOIN sections ON s.sections = sections.id WHERE s.scholarship_stipend_info = 'scholarship' && s.status = '1' ORDER BY " . studentsListOrderSql('s', 'cls', 'sections');
    $query = mysqli_query($conn, $sql);
    $count_row = $query ? mysqli_num_rows($query) : 0;
    if ($count_row > 0) {
    $sl = 0; ?>
    <div class="table-responsive mb-5">
        <table class="table table-striped table-hover border-3 table-info table-bordered border-dark align-middle w-75 m-auto text-center">
            <!-- <caption class="caption-top text-center">বর্তমান ম্যানেজিং কমিটি</caption> -->
            <thead class="table-dark text-center">
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">শ্রেণী</th>
                    <th scope="col">শাখা</th>
                    <th scope="col">রোল</th>
                    <th scope="col">নাম</th>
                    <th scope="col">পিতার নাম</th>
                    <th scope="col">মোবাইল নাম্বার</th>
                    <th scope="col">ছবি</th>
                </tr>
            </thead>
            <tbody>
                <?php
                
                while ($row = mysqli_fetch_assoc($query)) { $sl++;

                ?>
                <tr>
                    <th scope="row"><?php echo english_to_bengali($sl);?></th>
                    <td><?php echo hText((string) ($row["cls"] ?? ''));?></td>
                    <td><?php echo hText((string) ($row["section_name_ban"] ?? ''));?></td>
                    <td><?php echo h(english_to_bengali((string) ($row["cls_roll"] ?? '')));?></td>
                    <td><?php echo hText((string) ($row["name_ban"] ?? ''));?></td>
                    <td class="text-center"><?php echo hText((string) ($row["fname_ban"] ?? ''))?></td>
                    <td><?php echo h(english_to_bengali(publicStaffMobileDisplay((string) ($row['mobile'] ?? '')))); ?></td>
                    <td><img src="<?php echo htmlspecialchars(studentPhotoUrl((string) ($row['student_pic'] ?? '')), ENT_QUOTES, 'UTF-8');?>" class="rounded mx-auto d-block" alt="<?php echo hText((string) ($row["name_ban"] ?? ''));?>" width="80" height="80" style="object-fit:cover" loading="lazy" decoding="async"></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php }else{?>
        <h3 class="text-center">No Students Information found</h3>
    <?php } ?>
</div>

<!-- footer start -->
<?php include('footer.php');?>
<!-- footer close -->