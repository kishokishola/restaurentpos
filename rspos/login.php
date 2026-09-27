<?php
session_start();
require_once 'db_config.php';

if (isset($_SESSION['user_id'])) {
    $dest = match($_SESSION['user_role'] ?? '') {
        'admin'   => '/RestaurantPOS/dashboard.php',
        'kitchen' => '/RestaurantPOS/kitchen_display.php',
        default   => '/RestaurantPOS/cashier.php',
    };
    header('Location: ' . $dest);
    exit;
}

$error   = '';
$success = '';
$tab     = $_POST['tab'] ?? 'login'; // 'login' or 'register'

/* ══════════════════════════════════════════════
   HANDLE LOGIN
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id,full_name,username,password,role,status FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            session_regenerate_id(true);

            // Redirect based on role
            $redirect = match($user['role']) {
                'admin'   => '/RestaurantPOS/dashboard.php',
                'kitchen' => '/RestaurantPOS/kitchen_display.php',
                default   => '/RestaurantPOS/cashier.php',  // cashier
            };
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Incorrect username or password. Please try again.';
        }
    }
}

/* ══════════════════════════════════════════════
   HANDLE REGISTER
   One account per user — username must be unique
   Cannot change another user's username/password
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'register') {
    $full_name = trim($_POST['reg_full_name'] ?? '');
    $username  = trim($_POST['reg_username']  ?? '');
    $password  = trim($_POST['reg_password']  ?? '');
    $confirm   = trim($_POST['reg_confirm']   ?? '');
    $role      = 'cashier'; // default role for self-registered users

    if (empty($full_name) || empty($username) || empty($password) || empty($confirm)) {
        $error = 'All fields are required.';
        $tab   = 'register';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
        $tab   = 'register';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
        $tab   = 'register';
    } else {
        // Check if username already exists
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();

        if ($exists) {
            $error = "Username \"$username\" is already taken. Please choose a different username.";
            $tab   = 'register';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $conn->prepare("INSERT INTO users (full_name,username,password,role,status) VALUES (?,?,?,'cashier','active')");
            $ins->bind_param('sss', $full_name, $username, $hash);
            if ($ins->execute()) {
                $success = "Account created! You can now log in.";
                $tab     = 'login';
            } else {
                $error = 'Registration failed. Please try again.';
                $tab   = 'register';
            }
            $ins->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>ServePoint — Login</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    /* ── Full page dark BG ── */
    body.login-bg {
      min-height:100vh;
      background:
        radial-gradient(ellipse at 15% 15%, rgba(232,168,56,.1) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 85%, rgba(26,26,46,.9) 0%, transparent 55%),
        #0f0f1a;
      display:flex; align-items:center; justify-content:center; padding:20px;
    }

    /* ── Card ── */
    .lcard {
      width:100%; max-width:440px;
      background:var(--bg-card);
      border:1px solid var(--border);
      border-radius:20px;
      overflow:hidden;
      box-shadow:0 25px 60px rgba(0,0,0,.55);
    }

    /* ── Card header ── */
    .lhead {
      background:linear-gradient(135deg,#141428,#1a1a35);
      padding:36px 32px 28px;
      text-align:center;
      border-bottom:1px solid var(--border);
      position:relative; overflow:hidden;
    }
    .lhead::before {
      content:'';position:absolute;top:-30px;right:-30px;
      width:120px;height:120px;
      background:radial-gradient(circle,rgba(232,168,56,.15),transparent);
      border-radius:50%;
    }
    .lhead .logo-wrap {
      width:68px;height:68px;margin:0 auto 14px;
      background:linear-gradient(135deg,#e8a838,#c8891e);
      border-radius:18px;
      display:flex;align-items:center;justify-content:center;
      font-size:2rem;
      box-shadow:0 8px 24px rgba(232,168,56,.35);
    }
    .lhead h1 { font-size:1.55rem;font-weight:900;color:var(--primary);margin:0 0 4px; }
    .lhead p  { font-size:.84rem;color:var(--text-muted);margin:0; }

    /* ── Tabs ── */
    .ltabs {
      display:flex;
      background:rgba(255,255,255,.03);
      border-bottom:1px solid var(--border);
    }
    .ltab {
      flex:1; padding:13px; text-align:center;
      font-size:.88rem; font-weight:700; cursor:pointer;
      color:var(--text-muted); border:none; background:none;
      transition:all .2s; position:relative;
    }
    .ltab::after {
      content:''; position:absolute; bottom:0; left:10%; right:10%; height:2px;
      background:var(--primary); border-radius:2px 2px 0 0;
      transform:scaleX(0); transition:transform .2s;
    }
    .ltab.active { color:var(--primary); }
    .ltab.active::after { transform:scaleX(1); }

    /* ── Body ── */
    .lbody { padding:30px 32px 32px; }

    /* Input group */
    .inp-grp {
      display:flex; align-items:stretch;
    }
    .inp-pre {
      padding:10px 13px;
      background:var(--bg-card2);
      border:1.5px solid var(--border); border-right:none;
      border-radius:var(--radius-sm) 0 0 var(--radius-sm);
      color:var(--text-muted); font-size:.95rem;
      display:flex;align-items:center;
    }
    .inp-grp .form-control {
      border-radius:0; flex:1;
    }
    .inp-grp .form-control:last-child,
    .inp-grp .togpwd { border-radius:0 var(--radius-sm) var(--radius-sm) 0; }
    .togpwd {
      padding:0 13px;
      background:var(--bg-card2);
      border:1.5px solid var(--border); border-left:none;
      border-radius:0 var(--radius-sm) var(--radius-sm) 0;
      color:var(--text-muted); cursor:pointer; font-size:1rem;
      transition:var(--transition);
    }
    .togpwd:hover { color:var(--primary); }

    /* Submit btn */
    .lsubmit {
      width:100%;padding:13px;
      background:linear-gradient(135deg,#e8a838,#c8891e);
      color:#000; font-size:1rem; font-weight:900;
      border:none; border-radius:var(--radius-sm);
      cursor:pointer; transition:all .22s;
      box-shadow:0 4px 16px rgba(232,168,56,.4);
      margin-top:4px;
    }
    .lsubmit:hover {
      background:linear-gradient(135deg,#f5c96a,#e8a838);
      box-shadow:0 8px 24px rgba(232,168,56,.6);
      transform:translateY(-1px);
    }

    /* Alert */
    .lalert {
      padding:11px 15px;
      border-radius:8px; border-left:3px solid;
      font-size:.85rem; margin-bottom:18px;
      display:flex; align-items:center; gap:8px;
    }
    .lalert.err  { background:rgba(220,53,69,.1);  border-color:#dc3545; color:#ff8091; }
    .lalert.ok   { background:rgba(40,167,69,.1);  border-color:#28a745; color:#6fcf97; }

    .lform { display:none; }
    .lform.active { display:block; }

    .fdivider {
      text-align:center; margin:18px 0 14px;
      font-size:.78rem; color:var(--text-muted);
      position:relative;
    }
    .fdivider::before, .fdivider::after {
      content:''; position:absolute; top:50%;
      width:38%; height:1px; background:var(--border);
    }
    .fdivider::before { left:0; }
    .fdivider::after  { right:0; }
  </style>
</head>
<body class="login-bg">
  <div class="lcard">

    <!-- Header -->
    <div class="lhead">
      <div class="logo-wrap">🍽️</div>
      <h1>ServePoint</h1>
      <p>Restaurant Point of Sale System</p>
    </div>

    <!-- Tabs -->
    <div class="ltabs">
      <button class="ltab <?= $tab==='login'    ? 'active' : '' ?>" onclick="switchTab('login')"> Sign In</button>
      <button class="ltab <?= $tab==='register' ? 'active' : '' ?>" onclick="switchTab('register')"> Register</button>
    </div>

    <!-- Body -->
    <div class="lbody">

      <?php if ($error): ?>
        <div class="lalert err">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="lalert ok">✅ <?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- ── LOGIN FORM ── -->
      <form method="POST" class="lform <?= $tab==='login' ? 'active' : '' ?>" id="loginForm">
        <input type="hidden" name="tab" value="login">

        <div class="form-group">
          <label class="form-label">Username</label>
          <div class="inp-grp">
            <span class="inp-pre">👤</span>
            <input type="text" name="username" class="form-control"
                   placeholder="Enter your username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="inp-grp">
            <span class="inp-pre">🔒</span>
            <input type="password" name="password" id="lPwd" class="form-control"
                   placeholder="Enter your password"
                   autocomplete="current-password" required>
            <button type="button" class="togpwd" onclick="togglePwd('lPwd',this)">👁️</button>
          </div>
        </div>

        <button type="submit" class="lsubmit">🚀 Sign In</button>

        <div class="fdivider">or</div>
        <div style="text-align:center;">
          <span style="font-size:.83rem;color:var(--text-muted);">Don't have an account?</span>
          <button type="button" onclick="switchTab('register')"
                  style="background:none;border:none;color:var(--primary);font-size:.83rem;font-weight:700;cursor:pointer;margin-left:5px;">
            Register here →
          </button>
        </div>
        <div class="fdivider">admin access</div>
        <div style="text-align:center;">
          <a href="/RestaurantPOS/admin_login.php" style="color:var(--primary);font-size:.83rem;font-weight:700;text-decoration:none;">Sign in as Admin →</a>
        </div>
        <div style="text-align:center;margin-top:12px;">
          <a href="/RestaurantPOS/kitchen_login.php" style="color:var(--success);font-size:.83rem;font-weight:700;text-decoration:none;">👨‍🍳 Open Kitchen Access →</a>
        </div>
      </form>

      <!-- ── REGISTER FORM ── -->
      <form method="POST" class="lform <?= $tab==='register' ? 'active' : '' ?>" id="regForm">
        <input type="hidden" name="tab" value="register">

        <div class="form-group">
          <label class="form-label">Full Name</label>
          <div class="inp-grp">
            <span class="inp-pre">🙍</span>
            <input type="text" name="reg_full_name" class="form-control"
                   placeholder="Your full name"
                   value="<?= htmlspecialchars($_POST['reg_full_name'] ?? '') ?>"
                   required maxlength="100">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <div class="inp-grp">
            <span class="inp-pre">👤</span>
            <input type="text" name="reg_username" class="form-control"
                   placeholder="Choose a unique username"
                   value="<?= htmlspecialchars($_POST['reg_username'] ?? '') ?>"
                   autocomplete="off" required maxlength="50"
                   oninput="checkUsername(this.value)">
          </div>
          <small id="uCheck" style="font-size:.75rem;margin-top:4px;display:block;"></small>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="inp-grp">
            <span class="inp-pre">🔒</span>
            <input type="password" name="reg_password" id="rPwd" class="form-control"
                   placeholder="Min 6 characters" required minlength="6">
            <button type="button" class="togpwd" onclick="togglePwd('rPwd',this)">👁️</button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <div class="inp-grp">
            <span class="inp-pre">🔒</span>
            <input type="password" name="reg_confirm" id="rCfm" class="form-control"
                   placeholder="Repeat password" required
                   oninput="checkMatch()">
            <button type="button" class="togpwd" onclick="togglePwd('rCfm',this)">👁️</button>
          </div>
          <small id="matchMsg" style="font-size:.75rem;margin-top:4px;display:block;"></small>
        </div>

        <button type="submit" class="lsubmit" id="regSubmit">📝 Create Account</button>

        <div class="fdivider">or</div>
        <div style="text-align:center;">
          <span style="font-size:.83rem;color:var(--text-muted);">Already have an account?</span>
          <button type="button" onclick="switchTab('login')"
                  style="background:none;border:none;color:var(--primary);font-size:.83rem;font-weight:700;cursor:pointer;margin-left:5px;">
            Sign in →
          </button>
        </div>
      </form>

    </div><!-- /.lbody -->

    <!-- Admin login link -->
    <div style="padding:14px 32px;border-top:1px solid var(--border);text-align:center;font-size:.82rem;color:var(--text-muted);">
      Are you an Admin?
      <a href="/RestaurantPOS/admin_login.php"
         style="color:var(--primary);font-weight:700;margin-left:4px;">
        🔐 Admin Login →
      </a>
    </div>
  </div><!-- /.lcard -->

<script>
/* Tab switch */
function switchTab(t) {
  document.querySelectorAll('.ltab').forEach(function(b,i){
    b.classList.toggle('active', (i===0 && t==='login') || (i===1 && t==='register'));
  });
  document.getElementById('loginForm').classList.toggle('active', t==='login');
  document.getElementById('regForm').classList.toggle('active',  t==='register');
}

/* Toggle password visibility */
function togglePwd(id, btn) {
  var el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
  btn.textContent = el.type === 'password' ? '👁️' : '🙈';
}

/* Live password match check */
function checkMatch() {
  var p = document.getElementById('rPwd').value;
  var c = document.getElementById('rCfm').value;
  var m = document.getElementById('matchMsg');
  if (!c) { m.textContent=''; return; }
  if (p === c) { m.style.color='#6fcf97'; m.textContent='✅ Passwords match'; }
  else         { m.style.color='#ff8091'; m.textContent='❌ Passwords do not match'; }
}

/* Live username availability check */
var uTimer;
function checkUsername(val) {
  clearTimeout(uTimer);
  var el = document.getElementById('uCheck');
  if (!val || val.length < 3) { el.textContent=''; return; }
  el.style.color = '#9090b0';
  el.textContent = '⏳ Checking...';
  uTimer = setTimeout(function(){
    fetch('/RestaurantPOS/ajax_check_username.php?u=' + encodeURIComponent(val))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.taken) {
          el.style.color = '#ff8091';
          el.textContent = '❌ Username already taken';
        } else {
          el.style.color = '#6fcf97';
          el.textContent = '✅ Username available';
        }
      }).catch(function(){ el.textContent=''; });
  }, 500);
}
</script>
</body>
</html>
