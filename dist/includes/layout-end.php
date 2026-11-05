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
    <?php
    $preloaderPages = ['student-form', 'add_teacher', 'committee', 'retirement-teachers', 'notices', 'events', 'carousel', 'cms-gallery', 'meritorious-students', 'downloads', 'syllabus', 'homepage-cards'];
    if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'), $preloaderPages, true)):
    ?>
    <style>
      #savePreloader { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; flex-direction: column; gap: .75rem; background: rgba(15, 23, 42, .55); color: #fff; text-align: center; padding: 1rem; }
      #savePreloader.is-active { display: flex; }
      #savePreloader .spinner-border { width: 3rem; height: 3rem; }
    </style>
    <div id="savePreloader" role="status" aria-live="polite">
      <div class="spinner-border text-light" aria-hidden="true"></div>
      <div class="fw-semibold">সংরক্ষণ হচ্ছে, অনুগ্রহ করে অপেক্ষা করুন...</div>
    </div>
    <script>
      (function () {
        var overlay = document.getElementById('savePreloader');
        if (!overlay) return;
        // Bubble phase on document: runs after confirm()/validation handlers, which cancel the submit.
        document.addEventListener('submit', function (event) {
          var form = event.target;
          if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
          if ((form.getAttribute('method') || '').toLowerCase() !== 'post') return;
          overlay.classList.add('is-active');
          form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
            setTimeout(function () { btn.disabled = true; }, 0);
          });
        });
        window.addEventListener('pageshow', function () { overlay.classList.remove('is-active'); });
      })();
    </script>
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
