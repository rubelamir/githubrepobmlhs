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
    $pdo = publicDatabase();
    $hasFolders = tableExists($pdo, 'file_folders') && columnExists($pdo, 'files', 'folder_id');
    $folderRows = [];
    $currentFolder = null;
    $requestedFolder = $hasFolders ? (int) ($_GET['folder'] ?? 0) : 0;
    if ($hasFolders) {
        $folderRows = fetchRows(
            $pdo,
            'SELECT f.id, f.folder_name, COUNT(x.id) AS file_count
             FROM file_folders f
             JOIN files x ON x.folder_id = f.id AND x.files_status = 1
             GROUP BY f.id, f.folder_name, f.sort_order
             ORDER BY f.sort_order ASC, f.id ASC'
        );
        foreach ($folderRows as $folderRow) {
            if ((int) $folderRow['id'] === $requestedFolder) {
                $currentFolder = $folderRow;
            }
        }
    }
    $where = 'files_status = 1';
    if ($hasFolders) {
        $where .= $currentFolder !== null ? ' AND folder_id = ' . (int) $currentFolder['id'] : ' AND folder_id IS NULL';
    }
    $sql = 'SELECT * FROM files WHERE ' . $where . ' ORDER BY ' . downloadListOrderSql($pdo);
    $query = mysqli_query($conn, $sql);
    $count_row = mysqli_num_rows($query);
    $showFolders = $currentFolder === null && $folderRows !== [];
    ?>
    <?php if ($currentFolder !== null): ?>
    <div class="w-75 m-auto mb-3 d-flex align-items-center gap-3">
        <a class="btn btn-outline-dark btn-sm" href="download_preview.php">&larr; সব ফোল্ডার</a>
        <h4 class="mb-0"><?php echo h((string) $currentFolder['folder_name']); ?></h4>
    </div>
    <?php endif; ?>
    <?php if ($showFolders): ?>
    <div class="row g-3 w-75 m-auto mb-4">
        <?php foreach ($folderRows as $folderRow): ?>
        <div class="col-6 col-md-4">
            <a class="d-block text-decoration-none border border-dark rounded p-3 bg-info-subtle text-dark h-100" href="download_preview.php?folder=<?php echo (int) $folderRow['id']; ?>">
                <div class="fs-1">&#128193;</div>
                <div class="fw-bold"><?php echo h((string) $folderRow['folder_name']); ?></div>
                <small><?php echo english_to_bengali((int) $folderRow['file_count']); ?> টি ফাইল</small>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
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
<?php }elseif (!$showFolders) {?>
    <br><br><br><br>
        <h2 class="text-center bg-warning">No Files record found</h2>
        <br>
    <?php } ?>
    
</div>
<!-- footer start -->
<?php include('footer.php');?>
<!-- footer close -->
