<?php
/**
 * ajax_cashier_stats.php
 * Returns live cashier dashboard stats + tables HTML as JSON.
 * Called every 20s by cashier.php.
 */
error_reporting(0); ini_set('display_errors','0');
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { ob_end_clean(); echo json_encode(['error'=>'auth']); exit; }

require_once __DIR__ . '/db_config.php';
$today = date('Y-m-d');

// Stats
$r1 = $conn->query("SELECT COUNT(*) AS orders, COALESCE(SUM(o.total),0) AS rev FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.status='paid' AND DATE(p.paid_at)='$today'")->fetch_assoc();
$r3 = $conn->query("SELECT COUNT(*) AS avail FROM restaurant_tables WHERE status='available'")->fetch_assoc();
$r4 = $conn->query("SELECT COUNT(*) AS total FROM restaurant_tables")->fetch_assoc();

// Recent paid HTML
$rQ = $conn->query("
    SELECT o.id, o.order_number, o.total, o.created_at,
           COALESCE(t.table_number,'Takeaway') AS tbl,
           p.payment_method
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id=t.id
    LEFT JOIN payments p ON p.order_id=o.id
    WHERE p.status='paid' AND DATE(p.paid_at)='$today'
    ORDER BY p.paid_at DESC LIMIT 10");

$rHtml = '';
if ($rQ->num_rows === 0) {
    $rHtml = '<tr><td colspan="5" class="text-center text-muted" style="padding:30px;">No bills processed today yet.</td></tr>';
} else {
    $pmLabels = [
        'card'       => '💳 Card',
        'online'     => '📱 Online',
        'cash'       => '💵 Cash',
        'paypal'     => '🅿️ PayPal',
        'google_pay' => '🌐 GPay',
        'apple_pay'  => '🍏 Apple Pay'
    ];
    while ($o = $rQ->fetch_assoc()) {
        $num  = htmlspecialchars($o['order_number']);
        $tbl  = htmlspecialchars($o['tbl']);
        $pm   = $pmLabels[$o['payment_method'] ?? 'cash'] ?? '💵 Cash';
        $tot  = number_format($o['total'],2);
        $time = date('h:i A', strtotime($o['created_at']));
        $rHtml .= "<tr>
            <td><strong style='color:var(--primary);'>$num</strong></td>
            <td>$tbl</td>
            <td>$pm</td>
            <td class='text-primary fw-bold'>Rs $tot</td>
            <td class='text-muted' style='font-size:.78rem;'>$time</td>
        </tr>";
    }
}

ob_end_clean();
echo json_encode([
    'revenue'     => number_format((float)$r1['rev'], 2),
    'orders'      => (int)$r1['orders'],
    'avail_tbl'   => (int)$r3['avail'],
    'total_tbl'   => (int)$r4['total'],
    'recentHtml'  => $rHtml,
]);
?>
