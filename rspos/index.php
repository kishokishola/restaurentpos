<?php
// Entry point — redirect to login or dashboard
session_start();
if (!empty($_SESSION['user_id'])) {
    $home = match ($_SESSION['user_role'] ?? '') {
        'admin' => 'dashboard.php',
        'cashier' => 'cashier.php',
        'kitchen' => 'kitchen_display.php',
        default => 'login.php',
    };
    header('Location: /RestaurantPOS/' . $home);
} else {
    header('Location: /RestaurantPOS/login.php');
}
exit;
?>
