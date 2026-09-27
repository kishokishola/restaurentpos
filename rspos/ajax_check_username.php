<?php
/**
 * ajax_check_username.php
 * Checks if a username is already taken (for registration form).
 * Returns JSON: { "taken": true/false }
 */
error_reporting(0);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

$u = trim($_GET['u'] ?? '');
if (strlen($u) < 3) {
    echo json_encode(['taken' => false]);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$stmt->bind_param('s', $u);
$stmt->execute();
$stmt->store_result();
$taken = $stmt->num_rows > 0;
$stmt->close();

echo json_encode(['taken' => $taken]);
?>
