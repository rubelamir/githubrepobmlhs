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
                        <h2>ডাউনলোড</h2>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- =============== Page Header Section End =============================== -->
    <br/>

    <?php
    $sql = 'SELECT * FROM files WHERE files_status = 1 ORDER BY ' . downloadListOrderSql(publicDatabase());
    $query = mysqli_query($conn, $sql);
    $count_row = mysqli_num_rows($query);
    if ($count_row > 0) {
    $sl = 0; ?>

<div class="table-responsive mb-5">
        <table class="table table-striped table-hover border-3 table-info table-bordered border-dark align-middle w-75 m-auto">
           
            <thead class="table-dark text-center">
                <tr>
    <th scope="col">#</th>
    <th scope="col">ফাইলের নাম</th>
    <th scope="col">তারিখ</th>
    <th scope="col">ফাইল</th>
                </tr>
            </thead>

<tbody>
  <?php
  $sl = 0;
  while ($row = mysqli_fetch_assoc($query)) {
     $date = date("d-m-Y", strtotime($row["files_creation_time"]));
    $fileName = publicMediaBasename((string) ($row['files_file'] ?? ''));
    $exttype = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $icon = 'image/pdf.png';
    if (in_array($exttype, ['jpg', 'jpeg', 'png'], true) && $fileName !== '') {
        $icon = 'files_file/' . $fileName;
    } elseif (in_array($exttype, ['xls', 'xlsx'], true)) {
        $icon = 'image/excel.png';
    } elseif (in_array($exttype, ['doc', 'docx'], true)) {
        $icon = 'image/word.png';
    }
  $sl++;
  ?>
  <tr>
    <th class="text-center" scope="row"><?php echo english_to_bengali($sl);?></th>
    <td><?php echo h((string) ($row['files_text'] ?? ''));?></td>
    <td class="text-nowrap text-center"><?php echo h($date);?></td>


    <td class="text-center"><?php if ($fileName !== ''): ?><a href="<?php echo h('files_file/' . $fileName); ?>" target="_blank" rel="noopener"><img width="40px" height="40px" src="<?php echo h($icon); ?>" alt=""></a><?php endif; ?></td>


  </tr>
  
  <?php }?>
            </tbody>
        </table>
    </div>
<?php }else{?>
    <br><br><br><br>
        <h2 class="text-center bg-warning">No Files record found</h2>
        <br>
    <?php } ?>
    
</div>
<!-- footer start -->
<?php include('footer.php');?>
<!-- footer close -->
