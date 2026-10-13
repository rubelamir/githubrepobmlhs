<?php
[$flashType, $flashMessage] = consumeFlash();
if ($flashMessage !== ''):
    $alertClass = $flashType === 'success' ? 'success' : ($flashType === 'warning' ? 'warning' : 'danger');
?>
  <div class="alert alert-<?= h($alertClass) ?> alert-dismissible fade show" role="alert">
    <?= h($flashMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
