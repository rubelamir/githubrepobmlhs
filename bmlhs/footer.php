<?php
$footerSchool = isset($school) && is_array($school) ? $school : currentSchool();
$footerLogo = (isset($logo) && is_string($logo) && $logo !== '') ? $logo : schoolLogoUrl($footerSchool);
$footerLinks = homepageLinks(publicDatabase(), 'footer');
$footerAbout = trim((string) ($footerSchool['about_short'] ?? ''));
$footerAddress = trim((string) ($footerSchool['address'] ?? ''));
$footerMobile = trim((string) ($footerSchool['mobile'] ?? ''));
$footerMobileTel = preg_replace('/[^\d+]/', '', $footerMobile) ?? '';
$footerEmail = trim((string) ($footerSchool['email'] ?? ''));
$footerFacebook = schoolFacebookUrl($footerSchool);
$footerCredit = trim((string) ($footerSchool['footer_credit'] ?? ''));
$footerEstablished = trim((string) ($footerSchool['established'] ?? ''));
$footerEiin = trim((string) ($footerSchool['eiin'] ?? ''));
$footerMpo = trim((string) ($footerSchool['mpo_code'] ?? ''));
$footerHasLinks = $footerLinks !== [];
?>
<footer class="school-footer mt-auto">
  <div class="school-footer-atmosphere" aria-hidden="true"></div>
  <div class="container school-footer-inner">
    <div class="school-footer-grid<?= $footerHasLinks ? '' : ' is-compact' ?>">
      <section class="school-footer-brand-block" aria-label="বিদ্যালয়ের পরিচিতি">
        <a class="school-footer-logo-link" href="index">
          <img class="school-footer-logo" src="<?= h($footerLogo) ?>" alt="<?= h((string) $footerSchool['school_name_en']) ?>" width="72" height="72">
        </a>
        <div class="school-footer-brand"><?= h((string) $footerSchool['school_name_bn']) ?></div>
        <p class="school-footer-en"><?= h((string) $footerSchool['school_name_en']) ?></p>
        <?php if ($footerFacebook !== '' || $footerEmail !== '' || $footerMobileTel !== ''): ?>
          <div class="school-footer-social">
            <?php if ($footerFacebook !== ''): ?>
              <a class="school-footer-social-btn" href="<?= h($footerFacebook) ?>" target="_blank" rel="noopener noreferrer" aria-label="ফেসবুক পেজ">
                <i class="fa-brands fa-facebook-f" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
            <?php if ($footerEmail !== ''): ?>
              <a class="school-footer-social-btn" href="mailto:<?= h($footerEmail) ?>" aria-label="ই-মেইল পাঠান">
                <i class="fa-solid fa-envelope" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
            <?php if ($footerMobileTel !== ''): ?>
              <a class="school-footer-social-btn" href="tel:<?= h($footerMobileTel) ?>" aria-label="মোবাইলে কল করুন">
                <i class="fa-solid fa-phone" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($footerAbout !== ''): ?>
          <p class="school-footer-lead"><?= h($footerAbout) ?></p>
        <?php endif; ?>
      </section>

      <?php if ($footerHasLinks): ?>
      <nav class="school-footer-nav" aria-label="ফুটার লিংক">
        <h2 class="school-footer-heading">দ্রুত লিংক</h2>
        <div class="school-footer-links">
          <?php foreach ($footerLinks as $link):
              $href = publicNavHref((string) $link['url']);
              $target = ((string) ($link['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
          ?>
            <a href="<?= h($href) ?>"<?= $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h((string) $link['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </nav>
      <?php endif; ?>

      <section class="school-footer-contact" aria-labelledby="schoolFooterContactHeading">
        <h2 class="school-footer-heading" id="schoolFooterContactHeading">যোগাযোগ</h2>
        <ul class="school-footer-contact-list">
          <?php if ($footerAddress !== ''): ?>
            <li>
              <span class="school-footer-contact-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
              <span><?= h($footerAddress) ?></span>
            </li>
          <?php endif; ?>
          <?php if ($footerMobile !== ''): ?>
            <li>
              <span class="school-footer-contact-icon" aria-hidden="true"><i class="fa-solid fa-phone"></i></span>
              <span>মোবাইল: <a href="tel:<?= h($footerMobileTel) ?>"><?= h($footerMobile) ?></a></span>
            </li>
          <?php endif; ?>
          <?php if ($footerEmail !== ''): ?>
            <li>
              <span class="school-footer-contact-icon" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
              <span>ই-মেইল: <a href="mailto:<?= h($footerEmail) ?>"><?= h($footerEmail) ?></a></span>
            </li>
          <?php endif; ?>
        </ul>
        <?php if ($footerEstablished !== '' || $footerEiin !== '' || $footerMpo !== ''): ?>
          <div class="school-footer-facts">
            <?php if ($footerEstablished !== ''): ?>
              <span class="school-footer-fact"><small>স্থাপিত</small><strong><?= h($footerEstablished) ?></strong></span>
            <?php endif; ?>
            <?php if ($footerEiin !== ''): ?>
              <span class="school-footer-fact"><small>EIIN</small><strong><?= h($footerEiin) ?></strong></span>
            <?php endif; ?>
            <?php if ($footerMpo !== ''): ?>
              <span class="school-footer-fact"><small>MPO</small><strong><?= h($footerMpo) ?></strong></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
  <div class="school-footer-bottom">
    <div class="container school-footer-bottom-inner">
      <p class="school-footer-copy">© <?= h((string) date('Y')) ?> <?= h((string) $footerSchool['school_name_bn']) ?> · সর্বস্বত্ব সংরক্ষিত</p>
      <a class="school-footer-app-download" href="<?= h(adminUrl('downloads/bmlhs-android.apk')) ?>" download>
        <i class="fa-brands fa-android" aria-hidden="true"></i>
        Android App ডাউনলোড
      </a>
      <?php if ($footerCredit !== ''): ?>
        <p class="school-footer-credit"><?= h($footerCredit) ?></p>
      <?php endif; ?>
    </div>
  </div>
</footer>
</div>

<button class="school-to-top" id="schoolToTop" type="button" aria-label="উপরে যান">
  <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>
</button>
<script>
(function () {
  var button = document.getElementById('schoolToTop');
  if (!button) {
    return;
  }
  function toggle() {
    button.classList.toggle('is-visible', window.scrollY > 420);
  }
  window.addEventListener('scroll', toggle, { passive: true });
  button.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  toggle();
})();
</script>
<script>
(function () {
  if (!('serviceWorker' in navigator)) {
    return;
  }
  navigator.serviceWorker.getRegistrations().then(function (regs) {
    regs.forEach(function (reg) { reg.unregister(); });
  });
  if (window.caches) {
    caches.keys().then(function (keys) {
      keys.forEach(function (key) {
        if (String(key).indexOf('bmlhs-offline') === 0) {
          caches.delete(key);
        }
      });
    });
  }
  try {
    localStorage.removeItem('schoolOfflineData');
    localStorage.removeItem('schoolPwaDismissed');
  } catch (e) {}
})();
</script>

<script>
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  if (!token || !window.XMLHttpRequest) {
    return;
  }
  var send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    try {
      this.setRequestHeader('X-CSRF-Token', token);
    } catch (error) {}
    return send.apply(this, arguments);
  };
})();
</script>
<?php if ($useAsset['jquery'] ?? false): ?>
<script src="vendor/jquery-3.7.0.min.js"></script>
<?php endif; ?>
<!-- bootstrap.bundle already ships Popper, and Font Awesome is loaded as CSS. -->
<script src="vendor/bootstrap-5.3.1-dist/js/bootstrap.bundle.min.js"></script>
<?php if ($useAsset['owl'] ?? false): ?>
<script src="vendor/OwlCarousel2-2.3.4/dist/owl.carousel.min.js"></script>
<?php endif; ?>
<?php if ($useAsset['sweetalert'] ?? false): ?>
<script src="vendor/sweetalert2/sweetalert2@11.7.27_dist_sweetalert2.all.min.js"></script>
<?php endif; ?>
<?php if ($useAsset['fancybox'] ?? false): ?>
<script src="vendor/fancybox/fancybox.umd.js"></script>
<?php endif; ?>
<?php if ($useAsset['datatables'] ?? false): ?>
<script src="vendor/DataTables/datatables.min.js"></script>
<?php endif; ?>
<?php if ($useAsset['croppie'] ?? false): ?>
<script src="vendor/Croppie-2.6.4/croppie.min.js"></script>
<?php endif; ?>
<?php include("function_footer.php"); ?>
<?php if (empty($keepDocumentOpen)): ?>
</body>
</html>
<?php endif; ?>
