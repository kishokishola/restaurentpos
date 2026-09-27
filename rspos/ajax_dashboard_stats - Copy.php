<?php
/**
 * ajax_dashboard_stats.php
 * Returns live dashboard stats as JSON.
 * Called every 30s by dashboard.php JS.
 */
error_reporting(0); ini_set('display_errors','0');
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { ob_end_clean(); echo json_encode(['error'=>'auth']); exit; }

require_once __DIR__ . '/db_config.php';
$today = date('Y-m-d');

$r1  = $conn->query("SELECT COALESCE(SUM(o.total),0) AS rev, COUNT(*) AS cnt FROM payments p JOIN orders o ON o.id=p.order_id WHERE DATE(p.paid_at)='$today' AND p.status='paid'")->fetch_assoc();
$r2  = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('pending','preparing')")->fetch_assoc();
$r3  = $conn->query("SELECT COUNT(*) AS avail FROM restaurant_tables WHERE status='available'")->fetch_assoc();
$r4  = $conn->query("SELECT COUNT(*) AS total FROM restaurant_tables")->fetch_assoc();
$r5  = $conn->query("SELECT COUNT(*) AS cnt FROM inventory WHERE quantity<=min_quantity")->fetch_assoc();

// Recent orders HTML
$recentQ = $conn->query("
    SELECT o.id, o.order_number, o.status, o.total, o.created_at,
           COALESCE(t.table_number,'Takeaway') AS table_number,
           COALESCE(u.full_name,'—') AS cashier
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id=t.id
    LEFT JOIN users u ON o.cashier_id=u.id
    ORDER BY o.created_at DESC LIMIT 8");

$badgeMap = ['completed'=>'badge-success','cancelled'=>'badge-danger','preparing'=>'badge-warning','ready'=>'badge-info'];
$html = '';
if ($recentQ->num_rows === 0) {
    $html = '<tr><td colspan="7" class="text-center text-muted" style="padding:30px;">No orders today yet.</td></tr>';
} else {
    while ($o = $recentQ->fetch_assoc()) {
        $badge = $badgeMap[$o['status']] ?? 'badge-muted';
        $esc_num    = htmlspecialchars($o['order_number']);
        $esc_table  = htmlspecialchars($o['table_number']);
        $esc_cash   = htmlspecialchars($o['cashier']);
        $esc_status = ucfirst($o['status']);
        $esc_total  = number_format($o['total'], 2);
        $esc_time   = date('h:i A', strtotime($o['created_at']));
        $html .= "<tr>
            <td><strong>$esc_num</strong></td>
            <td>$esc_table</td>
            <td>$esc_cash</td>
            <td><span class='badge $badge'>$esc_status</span></td>
            <td class='text-primary fw-bold'>RM $esc_total</td>
            <td class='text-muted' style='font-size:.78rem;'>$esc_time</td>
            <td><a href='/RestaurantPOS/billing.php?order_id={$o['id']}' class='btn btn-ghost btn-sm'>View</a></td>
        </tr>";
    }
}

ob_end_clean();
echo json_encode([
    'revenue'     => number_format((float)$r1['rev'], 2),
    'orders'      => (int)$r1['cnt'],
    'pending'     => (int)$r2['cnt'],
    'avail_tbl'   => (int)$r3['avail'],
    'total_tbl'   => (int)$r4['total'],
    'low_stock'   => (int)$r5['cnt'],
    'recentHtml'  => $html,
]);
?>
