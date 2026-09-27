<?php
session_start();
require_once 'db_config.php';

if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'kitchen') {
    header('Location: /RestaurantPOS/kitchen_display.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = substr(trim($_POST['display_name'] ?? ''), 0, 100);
    if ($name === '') {
        $error = 'Enter your name to open the kitchen screen.';
    } else {
        $username = 'kitchen_open_access';
        $row = $conn->query("SELECT id FROM users WHERE username='$username' LIMIT 1")->fetch_assoc();
        if (!$row) {
            $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (full_name,username,password,role,status) VALUES ('Kitchen Access',?,'$hash','kitchen','active')");
            $stmt->bind_param('s', $username); $stmt->execute(); $stmt->close();
            $row = $conn->query("SELECT id FROM users WHERE username='$username' LIMIT 1")->fetch_assoc();
        }
        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['user_name'] = $name;
        $_SESSION['user_role'] = 'kitchen';
        session_regenerate_id(true);
        header('Location: /RestaurantPOS/kitchen_display.php'); exit;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kitchen Access — ServePoint</title><link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css"><style>body{min-height:100vh;display:grid;place-items:center;padding:20px;background:#0f0f1a}.box{width:min(100%,420px);padding:32px;background:var(--bg-card);border:1px solid var(--border);border-radius:18px;text-align:center}.icon{font-size:3rem}.box .form-control{margin:18px 0}.box p{color:var(--text-muted);font-size:.88rem}</style></head><body><main class="box"><div class="icon">👨‍🍳</div><h1 class="page-title">Kitchen <span>Access</span></h1><p>Enter your name to open the kitchen order screen.</p><?php if($error):?><div class="alert alert-danger">⚠️ <?=htmlspecialchars($error)?></div><?php endif;?><form method="post"><input class="form-control" name="display_name" maxlength="100" placeholder="Cook name" required autofocus><button class="btn btn-primary btn-block btn-lg">Open Kitchen →</button></form><a class="btn btn-ghost" style="margin-top:16px" href="/RestaurantPOS/login.php">Staff / Admin login</a></main></body></html>
