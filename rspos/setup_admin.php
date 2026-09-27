<?php
/**
 * setup_admin.php — Force-fix admin user
 * Visit: http://localhost/RestaurantPOS/setup_admin.php
 */
require_once __DIR__ . '/db_config.php';
$log = [];

// Step 1: Check if 'admin' username exists
$chk = $conn->query("SELECT id, username, password, role, status FROM users WHERE username='admin' LIMIT 1");
$existing = $chk->fetch_assoc();

if ($existing) {
    // User with username='admin' exists
    $log[] = "Found user: id={$existing['id']}, username={$existing['username']}, role={$existing['role']}, status={$existing['status']}";

    // Force role = admin, status = active, and reset password
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $id = (int)$existing['id'];
    $conn->query("UPDATE users SET role='admin', status='active', password='$newHash', full_name='Administrator' WHERE id=$id");
    $log[] = "✅ FIXED: Set role=admin, status=active, password=admin123 for user id=$id";
} else {
    // No admin user at all — create one
    $log[] = "No 'admin' user found. Creating...";
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO users (full_name, username, password, role, status)
                  VALUES ('Administrator', 'admin', '$newHash', 'admin', 'active')");
    $newId = $conn->insert_id;
    $log[] = "✅ Created admin user with id=$newId";
}

// Step 2: Verify
$verify = $conn->query("SELECT id, full_name, username, role, status FROM users WHERE username='admin' LIMIT 1")->fetch_assoc();

// Step 3: Test password_verify
$testOk = $verify ? password_verify('admin123', $conn->query("SELECT password FROM users WHERE username='admin' LIMIT 1")->fetch_assoc()['password']) : false;
$log[] = "Password verify test: " . ($testOk ? "✅ OK" : "❌ FAIL");

// All users
$allUsers = $conn->query("SELECT id, full_name, username, role, status FROM users ORDER BY id");
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Setup Admin</title>
  <style>
    body{font-family:monospace;background:#0f0f1a;color:#f0f0f0;padding:30px;line-height:1.8;}
    h2{color:#e8a838;} h3{color:#9090b0;margin:20px 0 8px;}
    .log div{padding:3px 0;font-size:.88rem;}
    table{border-collapse:collapse;width:100%;max-width:700px;margin-top:8px;}
    th,td{border:1px solid #2e2e4e;padding:9px 14px;text-align:left;font-size:.86rem;}
    th{background:#252540;color:#e8a838;}
    .admin{color:#e8a838;font-weight:700;}
    .cashier{color:#17a2b8;} .kitchen{color:#28a745;}
    .box{background:rgba(40,167,69,.1);border-left:4px solid #28a745;
         padding:16px 20px;border-radius:8px;margin-top:20px;}
    .warn{background:rgba(255,193,7,.1);border-left:4px solid #ffc107;
          padding:14px 18px;border-radius:8px;margin-top:12px;font-size:.85rem;}
    a{color:#e8a838;font-weight:700;}
    .big{font-size:1rem;font-weight:700;color:#6fcf97;margin-top:4px;}
  </style>
</head>
<body>
  <h2>🔧 Admin Setup — ServePoint</h2>

  <h3>Actions taken:</h3>
  <div class="log">
    <?php foreach ($log as $l): ?>
      <div><?= htmlspecialchars($l) ?></div>
    <?php endforeach; ?>
  </div>

  <?php if ($verify): ?>
  <div class="box">
    <div>✅ <strong>Admin user ready!</strong></div>
    <div class="big">Username: admin &nbsp;|&nbsp; Password: admin123</div>
    <div style="margin-top:10px;">
      👉 <a href="/RestaurantPOS/admin_login.php">Click here → Admin Login Page</a>
    </div>
  </div>
  <?php endif; ?>

  <h3>All users in DB:</h3>
  <table>
    <thead><tr><th>ID</th><th>Full Name</th><th>Username</th><th>Role</th><th>Status</th></tr></thead>
    <tbody>
      <?php while ($u = $allUsers->fetch_assoc()): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><?= htmlspecialchars($u['full_name']) ?></td>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td><span class="<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
        <td><?= ucfirst($u['status']) ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

  <div class="warn">
    ⚠️ <strong>Delete this file after use!</strong><br>
    Admin Login → <a href="/RestaurantPOS/admin_login.php">admin_login.php</a><br>
    Staff Login &nbsp;→ <a href="/RestaurantPOS/login.php">login.php</a>
  </div>
</body>
</html>
