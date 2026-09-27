<?php
@ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /RestaurantPOS/login.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!is_array($roles)) $roles = [$roles];
    $currentRole = $_SESSION['user_role'] ?? '';
    if (!in_array($currentRole, $roles)) {
        // Redirect to proper home based on role
        $home = match($currentRole) {
            'admin'   => '/RestaurantPOS/dashboard.php',
            'cashier' => '/RestaurantPOS/cashier.php',
            'kitchen' => '/RestaurantPOS/kitchen_display.php',
            default   => '/RestaurantPOS/login.php',
        };
        header('Location: ' . $home);
        exit;
    }
}

function currentUser() {
    return [
        'id'       => $_SESSION['user_id']   ?? null,
        'name'     => $_SESSION['user_name'] ?? 'Guest',
        'role'     => $_SESSION['user_role'] ?? 'cashier',
        'initials' => strtoupper(substr($_SESSION['user_name'] ?? 'G', 0, 1))
    ];
}

function isAdmin() {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}
?>
