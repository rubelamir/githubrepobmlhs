<?php
[$flashType, $flashMessage] = consumeFlash();
if ($flashMessage !== ''):
    $alertClass = in_array($flashType, ['success', 'warning', 'info'], true) ? $flashType : 'danger';
?>
  <div class="alert alert-<?= h($alertClass) ?> alert-dismissible fade show" role="alert">
    <?= h($flashMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
