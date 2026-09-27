<?php
/**
 * admin_login.php — Dedicated Admin Login
 * URL: localhost/RestaurantPOS/admin_login.php
 * Only users with role='admin' can login here.
 */
session_start();
require_once 'db_config.php';

// Already logged in as admin → go to dashboard
if (!empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin') {
    header('Location: /RestaurantPOS/dashboard.php');
    exit;
}
// Logged in as non-admin → logout first
if (!empty($_SESSION['user_id'])) {
    session_destroy();
    session_start();
}

// ── Auto-ensure admin user exists ─────────────────────────
$adminCheck = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
if ($adminCheck->num_rows === 0) {
    // Create default admin
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO users (full_name,username,password,role,status)
                  VALUES ('Administrator','admin','$hash','admin','active')");
}

$error = '';

// ── Handle login ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id,full_name,username,password,role,status FROM users WHERE username=? AND role='admin' LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = 'admin';
            session_regenerate_id(true);
            header('Location: /RestaurantPOS/dashboard.php');
            exit;
        } else {
            if (!$user) {
                $error = 'No admin account found with that username. <a href="/RestaurantPOS/setup_admin.php" style="color:#e8a838;">Run setup →</a>';
            } elseif ($user['status'] !== 'active') {
                $error = 'This admin account is inactive. Contact system administrator.';
            } else {
                $error = 'Wrong password. Default password is <strong>admin123</strong> — run <a href="/RestaurantPOS/setup_admin.php" style="color:#e8a838;">setup</a> to reset.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Admin Login — ServePoint</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{
      font-family:'Segoe UI',Tahoma,sans-serif;
      min-height:100vh;
      background:#0f0f1a;
      display:flex;align-items:center;justify-content:center;
      padding:20px;
      background-image:
        radial-gradient(ellipse at 15% 20%, rgba(232,168,56,.1) 0%, transparent 50%),
        radial-gradient(ellipse at 85% 80%, rgba(26,26,46,.9) 0%, transparent 50%);
    }
    a{text-decoration:none;color:inherit;}

    /* ── Card ── */
    .card{
      width:100%;max-width:420px;
      background:#1e1e30;
      border:1px solid #2e2e4e;
      border-radius:20px;
      overflow:hidden;
      box-shadow:0 25px 60px rgba(0,0,0,.55);
    }

    /* ── Header ── */
    .card-head{
      background:linear-gradient(135deg,#141428,#1a1a38);
      padding:36px 32px 28px;
      text-align:center;
      border-bottom:1px solid #2e2e4e;
      position:relative;overflow:hidden;
    }
    .card-head::before{
      content:'';position:absolute;top:-30px;right:-30px;
      width:120px;height:120px;
      background:radial-gradient(circle,rgba(232,168,56,.15),transparent);
      border-radius:50%;
    }
    .logo-box{
      width:68px;height:68px;margin:0 auto 14px;
      background:linear-gradient(135deg,#e8a838,#c8891e);
      border-radius:18px;
      display:flex;align-items:center;justify-content:center;
      font-size:2rem;
      box-shadow:0 8px 24px rgba(232,168,56,.35);
    }
    .card-head h1{font-size:1.55rem;font-weight:900;color:#e8a838;margin-bottom:4px;}
    .card-head p{color:#9090b0;font-size:.84rem;}

    /* Admin badge */
    .admin-badge{
      display:inline-flex;align-items:center;gap:6px;
      background:linear-gradient(135deg,#e8a838,#c8891e);
      color:#000;
      font-size:.72rem;font-weight:800;
      text-transform:uppercase;letter-spacing:.8px;
      padding:5px 16px;border-radius:20px;
      margin-top:10px;
      box-shadow:0 4px 14px rgba(232,168,56,.4);
    }
    .admin-badge::before{content:'🔐 ';}

    /* ── Body ── */
    .card-body{padding:30px 32px 32px;}

    /* Alert */
    .alert-err{
      padding:11px 14px;
      background:rgba(220,53,69,.1);border-left:3px solid #dc3545;
      border-radius:8px;color:#ff8091;font-size:.84rem;
      margin-bottom:18px;
      display:flex;align-items:center;gap:8px;
    }

    /* Form */
    .fg{margin-bottom:16px;}
    .fg label{
      display:block;font-size:.75rem;font-weight:700;
      text-transform:uppercase;letter-spacing:.6px;
      color:#9090b0;margin-bottom:6px;
    }
    .inp-wrap{display:flex;align-items:stretch;}
    .inp-pre{
      padding:10px 13px;
      background:#252540;border:1.5px solid #2e2e4e;border-right:none;
      border-radius:8px 0 0 8px;
      color:#9090b0;font-size:.95rem;
      display:flex;align-items:center;
    }
    .inp-wrap input{
      flex:1;padding:10px 13px;
      background:#0f0f1a;border:1.5px solid #2e2e4e;
      border-radius:0;color:#f0f0f0;font-size:.9rem;
      outline:none;transition:all .2s;
    }
    .inp-wrap input:last-child{border-radius:0 8px 8px 0;}
    .inp-wrap input:focus{border-color:#e8a838;box-shadow:0 0 0 3px rgba(232,168,56,.12);}
    .inp-wrap input::placeholder{color:#4a4a6a;}
    .tog-btn{
      padding:0 13px;
      background:#252540;border:1.5px solid #2e2e4e;border-left:none;
      border-radius:0 8px 8px 0;
      color:#9090b0;cursor:pointer;font-size:1rem;
      transition:color .2s;
    }
    .tog-btn:hover{color:#e8a838;}

    /* Submit */
    .submit-btn{
      width:100%;padding:13px;
      background:linear-gradient(135deg,#e8a838,#c8891e);
      color:#000;font-size:1rem;font-weight:900;
      border:none;border-radius:10px;cursor:pointer;
      transition:all .22s;
      box-shadow:0 4px 16px rgba(232,168,56,.4);
      margin-top:4px;
    }
    .submit-btn:hover{
      background:linear-gradient(135deg,#f5c96a,#e8a838);
      box-shadow:0 8px 24px rgba(232,168,56,.6);
      transform:translateY(-1px);
    }

    /* Footer link */
    .card-foot{
      padding:16px 32px;
      border-top:1px solid #2e2e4e;
      text-align:center;
      font-size:.82rem;color:#9090b0;
    }
    .card-foot a{color:#e8a838;font-weight:700;}
    .card-foot a:hover{text-decoration:underline;}

    /* Default creds hint */
    .creds-hint{
      background:rgba(232,168,56,.07);border:1px solid rgba(232,168,56,.2);
      border-radius:8px;padding:12px 16px;margin-bottom:18px;
      font-size:.8rem;color:#9090b0;line-height:1.6;
    }
    .creds-hint strong{color:#e8a838;}
  </style>
</head>
<body>
  <div class="card">

    <!-- Header -->
    <div class="card-head">
      <div class="logo-box">🍽️</div>
      <h1>ServePoint</h1>
      <p>Restaurant Point of Sale System</p>
      <div class="admin-badge">Admin Portal</div>
    </div>

    <!-- Body -->
    <div class="card-body">

      <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
      <?php endif; ?>

      

      <form method="POST">
        <div class="fg">
          <label>👤 Admin Username</label>
          <div class="inp-wrap">
            <span class="inp-pre">👤</span>
            <input type="text" name="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   placeholder="Enter admin username"
                   autocomplete="username" required>
          </div>
        </div>

        <div class="fg">
          <label>🔒 Password</label>
          <div class="inp-wrap">
            <span class="inp-pre">🔒</span>
            <input type="password" name="password" id="apwd"
                   placeholder="Enter admin password"
                   autocomplete="current-password" required>
            <button type="button" class="tog-btn" onclick="togglePwd()">👁️</button>
          </div>
        </div>

        <button type="submit" class="submit-btn">🚀 Sign In as Admin</button>
      </form>

    </div>

    <!-- Footer -->
    <div class="card-foot">
      Not an admin?
      <a href="/RestaurantPOS/login.php">Staff / Cashier Login →</a>
    </div>
  </div>

  <script>
  function togglePwd(){
    const el = document.getElementById('apwd');
    el.type = el.type === 'password' ? 'text' : 'password';
  }
  </script>
</body>
</html>
