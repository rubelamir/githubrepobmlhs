      </main>
<?php
if (!isset($basePath)) {
    $basePath = '';
}
include __DIR__ . '/footer.php';
?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="<?= h($basePath) ?>js/adminlte.js"></script>
    <?php if (!empty($includeCropper)): ?>
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
    <script src="<?= h($basePath) ?>js/image-cropper.js"></script>
    <?php endif; ?>
    <?php if (!empty($pageScriptFiles) && is_array($pageScriptFiles)): ?>
      <?php foreach ($pageScriptFiles as $scriptFile): ?>
    <script src="<?= h((string) $scriptFile) ?>"></script>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
      <?php foreach ($pageScripts as $script): ?>
        <script><?= $script ?></script>
      <?php endforeach; ?>
    <?php endif; ?>
    <script>
      document.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const toggle = target.closest('[data-bmlhs-sidebar-toggle]');
        const overlay = target.closest('.sidebar-overlay');
        const body = document.body;
        const sidebarToggle = document.querySelector('[data-bmlhs-sidebar-toggle]');

        if (toggle) {
          event.preventDefault();
          const isMobile = window.matchMedia('(max-width: 991.98px)').matches;
          if (isMobile) {
            const open = !body.classList.contains('sidebar-open');
            body.classList.toggle('sidebar-open', open);
            body.classList.toggle('sidebar-collapse', !open);
            toggle.setAttribute('aria-expanded', String(open));
          } else {
            const collapsed = body.classList.toggle('sidebar-collapse');
            toggle.setAttribute('aria-expanded', String(!collapsed));
          }
        } else if (overlay && body.classList.contains('sidebar-open')) {
          body.classList.remove('sidebar-open');
          body.classList.add('sidebar-collapse');
          sidebarToggle?.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('DOMContentLoaded', function () {
        const sidebarToggle = document.querySelector('[data-bmlhs-sidebar-toggle]');
        if (sidebarToggle) {
          const isMobile = window.matchMedia('(max-width: 991.98px)').matches;
          const isOpen = isMobile
            ? document.body.classList.contains('sidebar-open')
            : !document.body.classList.contains('sidebar-collapse');
          sidebarToggle.setAttribute('aria-expanded', String(isOpen));
        }

        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token) {
          document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
            if (!form.querySelector('input[name="csrf_token"]')) {
              const input = document.createElement('input');
              input.type = 'hidden';
              input.name = 'csrf_token';
              input.value = token;
              form.appendChild(input);
            }
          });
        }

        const sidebarWrapper = document.querySelector('.sidebar-wrapper');
        const isMobile = window.innerWidth <= 992;
        if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined && !isMobile) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
          });
        }
      });
    </script>
  </body>
</html>
