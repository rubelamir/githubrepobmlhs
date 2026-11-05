<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
ensureCardPunchSchema($db);
$basePath = '../';
$pageTitle = 'Card Punch | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'punch') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'সেশন শেষ, পেজ রিফ্রেশ করুন।']);
        exit;
    }
    try {
        $result = cardPunchRecord($db, (string) ($_POST['card'] ?? ''), 'web');
        if (($result['status'] ?? '') === 'present') {
            writeAuditLog($db, 'card_punch', 'student', (int) ($result['student']['id'] ?? 0), 'Card punch attendance');
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('card-punch: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'সার্ভার ত্রুটি, আবার চেষ্টা করুন।']);
    }
    exit;
}

$today = cardPunchNow()->format('Y-m-d');
$recent = fetchRows(
    $db,
    'SELECT p.punched_at, s.name_ban, s.name_eng, s.cls_roll FROM attendance_punch_log p
     JOIN students_info s ON s.id = p.student_id
     WHERE p.punched_at >= :d ORDER BY p.punched_at DESC LIMIT 15',
    ['d' => $today . ' 00:00:00']
);
$todayCount = (int) (fetchRows($db, 'SELECT COUNT(DISTINCT student_id) c FROM attendance_punch_log WHERE punched_at >= :d', ['d' => $today . ' 00:00:00'])[0]['c'] ?? 0);
$deviceUrl = publicSiteUrl() . '/dist/cron/card-punch.php?key=' . cardPunchDeviceKey() . '&card=CARD_NO';
$csrf = csrfToken();

$pageStyles = <<<'CSS'
.cp-box { max-width: 560px; margin: 0 auto; text-align: center; }
.cp-input { font-size: 1.4rem; text-align: center; }
.cp-result { min-height: 170px; border-radius: 1rem; padding: 1.25rem; margin-top: 1rem; background: #f1f5f9; transition: background .2s; }
.cp-result.ok { background: #dcfce7; } .cp-result.repeat { background: #fef9c3; } .cp-result.fail { background: #fee2e2; }
.cp-result img { width: 81px; height: 103px; object-fit: cover; border-radius: .5rem; border: 2px solid #fff; }
.cp-name { font-size: 1.35rem; font-weight: 700; }
CSS;
$pageScripts = [<<<'JS'
(function () {
  var input = document.getElementById('cpCard');
  var box = document.getElementById('cpResult');
  var list = document.getElementById('cpRecent');
  var token = document.getElementById('cpToken').value;
  var busy = false, ctx = null;
  function beep(freq, ms) {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(); o.frequency.value = freq; o.connect(ctx.destination);
      o.start(); setTimeout(function () { o.stop(); }, ms);
    } catch (e) {}
  }
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function show(r) {
    var cls = !r.ok ? 'fail' : (r.status === 'repeat' ? 'repeat' : 'ok');
    var html = '<div class="cp-result ' + cls + '">';
    if (r.student) {
      if (r.student.photo) html += '<img src="' + esc(r.student.photo) + '" alt=""><br>';
      html += '<div class="cp-name">' + esc(r.student.name) + '</div><div>' + esc(r.student.class) + ' • রোল ' + esc(r.student.roll) + '</div>';
    }
    html += '<div class="mt-2 fw-semibold">' + esc(r.message) + (r.time ? ' (' + esc(r.time) + ')' : '') + '</div></div>';
    box.innerHTML = html;
    if (r.ok && r.status === 'present') {
      beep(880, 150);
      var li = document.createElement('li');
      li.className = 'list-group-item';
      li.textContent = r.time + ' — ' + r.student.name + ' (রোল ' + r.student.roll + ')';
      list.insertBefore(li, list.firstChild);
    } else { beep(r.ok ? 600 : 250, 350); }
  }
  function send(card) {
    if (busy || !card) return;
    busy = true;
    var fd = new FormData();
    fd.append('action', 'punch'); fd.append('card', card); fd.append('csrf_token', token);
    fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(show)
      .catch(function () { show({ ok: false, message: 'সংযোগ ব্যর্থ, আবার চেষ্টা করুন।' }); })
      .then(function () { busy = false; input.value = ''; input.focus(); });
  }
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); send(input.value.trim()); }
  });
  document.addEventListener('click', function (e) {
    if (!/^(A|BUTTON|INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) input.focus();
  });
  input.focus();
})();
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid">
          <h3 class="mb-0">কার্ড পাঞ্চ / Card Punch</h3>
          <p class="text-secondary mb-0">আজ কার্ড পাঞ্চ করেছে: <strong><?= $todayCount ?></strong> জন</p>
        </div></div>
        <div class="app-content"><div class="container-fluid">
          <div class="cp-box">
            <input type="hidden" id="cpToken" value="<?= h($csrf) ?>">
            <input id="cpCard" class="form-control form-control-lg cp-input" autocomplete="off" placeholder="কার্ড স্ক্যান করুন">
            <div id="cpResult"><div class="cp-result">কার্ড রিডারে কার্ড ছোঁয়ান...</div></div>
            <ul id="cpRecent" class="list-group text-start mt-3">
              <?php foreach ($recent as $row): ?>
                <li class="list-group-item"><?= h(date('h:i A', strtotime((string) $row['punched_at']))) ?> — <?= h((string) ($row['name_ban'] ?: $row['name_eng'])) ?> (রোল <?= h((string) $row['cls_roll']) ?>)</li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php if (isHeadAdmin()): ?>
          <div class="card mt-4"><div class="card-header"><h3 class="card-title">ডিভাইস API (স্বতন্ত্র মেশিনের জন্য)</h3></div>
            <div class="card-body small"><p>মেশিন/সফটওয়্যার এই URL-এ কার্ড নম্বর পাঠালে উপস্থিতি হবে। কী গোপন রাখুন।</p><code class="user-select-all" style="word-break:break-all"><?= h($deviceUrl) ?></code></div></div>
          <?php endif; ?>
        </div></div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
