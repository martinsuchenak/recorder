<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

$config = include __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    ob_end_clean();

    if ($_POST['action'] === 'login') {
        $_SESSION['user_id'] = $_POST['user_id'] ?? 'test-user';
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'logout') {
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? htmlspecialchars($_SESSION['user_id']) : '';
$initials = strtoupper(substr($userName, 0, 2));

$recorderUrl = '/recorder/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MyApp</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; }

    .navbar { background: #1a1a2e; color: white; padding: 0 2rem; display: flex; align-items: center; justify-content: space-between; height: 56px; }
    .navbar-brand { font-size: 1.25rem; font-weight: 600; }
    .navbar-user { display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
    .navbar-avatar { width: 32px; height: 32px; border-radius: 50%; background: #4a4a8a; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; }
    .navbar-login { background: none; border: 1px solid rgba(255,255,255,0.3); color: white; padding: 0.4rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
    .navbar-login:hover { background: rgba(255,255,255,0.1); }
    .navbar-logout { background: none; border: none; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 0.85rem; }
    .navbar-logout:hover { color: white; }

    .layout { display: flex; min-height: calc(100vh - 56px); }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e0e0e0; padding: 1rem 0; }
    .sidebar-item { padding: 0.6rem 1.5rem; color: #666; cursor: pointer; font-size: 0.9rem; }
    .sidebar-item:hover { background: #f0f0f0; }
    .sidebar-item.active { background: #e8f0fe; color: #1a73e8; font-weight: 500; }
    .content { flex: 1; padding: 2rem; max-width: 900px; }
    .content h1 { font-size: 1.5rem; margin-bottom: 1rem; }
    .content p { color: #555; line-height: 1.6; margin-bottom: 1rem; }

    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .card { background: white; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .card-label { font-size: 0.8rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
    .card-value { font-size: 1.5rem; font-weight: 600; }

    .record-btn { position: fixed; bottom: 2rem; right: 2rem; width: 56px; height: 56px; border-radius: 50%; border: none; background: #e53935; color: white; font-size: 1.5rem; cursor: pointer; box-shadow: 0 4px 12px rgba(229, 57, 53, 0.4); transition: transform 0.2s, box-shadow 0.2s; display: flex; align-items: center; justify-content: center; z-index: 100; }
    .record-btn:hover { transform: scale(1.1); box-shadow: 0 6px 16px rgba(229, 57, 53, 0.5); }
    .record-btn.recording { background: #333; animation: pulse 1.5s infinite; }
    .record-btn.open { background: #333; }
    .record-btn:disabled { background: #999; cursor: not-allowed; box-shadow: none; transform: none; }
    @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(229, 57, 53, 0.4); } 50% { box-shadow: 0 0 0 12px rgba(229, 57, 53, 0); } }

    .login-prompt { text-align: center; padding: 4rem 2rem; }
    .login-prompt h2 { margin-bottom: 1rem; }
    .login-prompt input { padding: 0.5rem; font-size: 1rem; border: 1px solid #ccc; border-radius: 4px; width: 200px; }
    .login-prompt button { padding: 0.5rem 1.5rem; font-size: 1rem; background: #1a73e8; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 0.5rem; }
    .login-prompt button:hover { background: #1557b0; }
    .login-prompt p { color: #888; margin-top: 1rem; font-size: 0.9rem; }

    .toast { position: fixed; bottom: 6rem; right: 2rem; background: #333; color: white; padding: 0.75rem 1.25rem; border-radius: 8px; font-size: 0.9rem; opacity: 0; transition: opacity 0.3s; z-index: 1001; max-width: 400px; word-break: break-all; }
    .toast.visible { opacity: 1; }
    .toast a { color: #64b5f6; }
  </style>
</head>
<body>

  <nav class="navbar">
    <div class="navbar-brand">MyApp</div>
    <div class="navbar-user">
      <?php if ($loggedIn): ?>
        <span><?= $userName ?></span>
        <div class="navbar-avatar"><?= $initials ?></div>
        <button class="navbar-logout" onclick="logout()">Sign out</button>
      <?php else: ?>
        <button class="navbar-login" onclick="showLogin()">Sign in</button>
      <?php endif; ?>
    </div>
  </nav>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-item active">Dashboard</div>
      <div class="sidebar-item">Projects</div>
      <div class="sidebar-item">Reports</div>
      <div class="sidebar-item">Settings</div>
    </aside>

    <?php if ($loggedIn): ?>
    <main class="content">
      <h1>Dashboard</h1>
      <div class="cards">
        <div class="card">
          <div class="card-label">Active Users</div>
          <div class="card-value">1,284</div>
        </div>
        <div class="card">
          <div class="card-label">Revenue</div>
          <div class="card-value">$48.2k</div>
        </div>
        <div class="card">
          <div class="card-label">Videos</div>
          <div class="card-value" id="video-count">0</div>
        </div>
        <div class="card">
          <div class="card-label">Status</div>
          <div class="card-value">Online</div>
        </div>
      </div>
      <p>Click the red button in the bottom-right corner to open the recorder and record your screen.</p>
      <div id="recordings"></div>
    </main>
    <?php else: ?>
    <main class="content">
      <div class="login-prompt">
        <h2>Welcome</h2>
        <p>Sign in to access the dashboard and start recording.</p>
        <form onsubmit="return handleLogin(event)">
          <input type="text" id="login-user" placeholder="User ID" value="test-user" required>
          <button type="submit">Sign in</button>
        </form>
      </div>
    </main>
    <?php endif; ?>
  </div>

  <?php if ($loggedIn): ?>
  <button class="record-btn" id="record-btn" title="Open recorder">&#9679;</button>
  <?php endif; ?>

  <div class="toast" id="toast"></div>

  <script>
    var RECORDER_URL = '<?= $recorderUrl ?>';
    var POPUP_WIDTH = 720;
    var POPUP_HEIGHT = 540;

    var recordBtn = document.getElementById('record-btn');
    var toastEl = document.getElementById('toast');
    var videoCountEl = document.getElementById('video-count');
    var recordingsEl = document.getElementById('recordings');

    var recorderWindow = null;
    var isRecording = false;
    var videoCount = 0;
    var toastTimer = null;

    function showToast(html, duration) {
      duration = duration || 5000;
      toastEl.innerHTML = html;
      toastEl.classList.add('visible');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function() { toastEl.classList.remove('visible'); }, duration);
    }

    function handleLogin(e) {
      e.preventDefault();
      var userId = document.getElementById('login-user').value;
      fetch('?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=login&user_id=' + encodeURIComponent(userId),
        credentials: 'same-origin'
      }).then(function() { location.reload(); });
      return false;
    }

    function logout() {
      fetch('?action=logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=logout',
        credentials: 'same-origin'
      }).then(function() { location.reload(); });
    }

    function showLogin() {
      var input = document.getElementById('login-user');
      if (input) input.focus();
    }

    <?php if ($loggedIn): ?>
    function openRecorder() {
      if (recorderWindow && !recorderWindow.closed) {
        recorderWindow.focus();
        return;
      }

      var left = screen.width - POPUP_WIDTH - 40;
      var top = screen.height - POPUP_HEIGHT - 80;

      recorderWindow = window.open(
        RECORDER_URL + '?embed=' + Date.now(),
        'recorder',
        'width=' + POPUP_WIDTH + ',height=' + POPUP_HEIGHT + ',left=' + left + ',top=' + top
      );

      if (!recorderWindow) {
        showToast('Popup blocked. Please allow popups for this site.');
        return;
      }

      recordBtn.classList.add('open');
      recordBtn.title = 'Recorder is open';

      var pollTimer = setInterval(function() {
        if (recorderWindow && recorderWindow.closed) {
          clearInterval(pollTimer);
          recorderWindow = null;
          isRecording = false;
          recordBtn.classList.remove('open', 'recording');
          recordBtn.title = 'Open recorder';
        }
      }, 500);
    }

    recordBtn.addEventListener('click', function() {
      if (!recorderWindow || recorderWindow.closed) {
        openRecorder();
      } else if (isRecording) {
        recorderWindow.postMessage({ type: 'RECORDER_STOP' }, '*');
      } else {
        recorderWindow.focus();
      }
    });

    window.addEventListener('message', function(event) {
      var data = event.data || {};
      var type = data.type;

      switch (type) {
        case 'RECORDER_STARTED':
          isRecording = true;
          recordBtn.classList.add('recording');
          recordBtn.title = 'Stop recording';
          showToast('Recording started...');
          break;

        case 'RECORDER_STOPPED':
          isRecording = false;
          recordBtn.classList.remove('recording');
          recordBtn.title = 'Recorder is open';
          showToast('Recording complete. Choose an action in the recorder window.');
          break;

        case 'RECORDER_UPLOADED':
          videoCount++;
          videoCountEl.textContent = videoCount;
          showToast('Upload complete! <a href="' + data.url + '" target="_blank">' + data.url + '</a>', 10000);

          var item = document.createElement('div');
          item.style.cssText = 'padding: 0.75rem; background: white; border-radius: 8px; margin-bottom: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);';
          item.innerHTML = '<strong>Recording #' + videoCount + '</strong> &mdash; <a href="' + data.url + '" target="_blank">' + data.url + '</a> <span style="color:#888;font-size:0.85rem;">(' + new Date().toLocaleTimeString() + ')</span>';
          recordingsEl.prepend(item);
          break;
      }
    });
    <?php endif; ?>
  </script>
</body>
</html>
