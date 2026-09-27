<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin','cashier']);
require_once 'db_config.php';
$user = currentUser();
$today = date('Y-m-d');

// ── Today's payment stats ─────────────────────────────────
$uid = $user['id'];
$myStats = $conn->query("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total),0) AS revenue
    FROM payments p
    JOIN orders o ON o.id=p.order_id
    WHERE p.status='paid' AND DATE(p.paid_at)='$today'")->fetch_assoc();

// Today's paid bills
$recentPaid = $conn->query("
    SELECT o.id, o.order_number, o.total, o.created_at,
           COALESCE(t.table_number,'Takeaway') AS tbl,
           p.payment_method
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id=t.id
    LEFT JOIN payments p ON p.order_id=o.id
    WHERE p.status='paid' AND DATE(p.paid_at)='$today'
    ORDER BY p.paid_at DESC LIMIT 10");

$availTables = $conn->query("SELECT COUNT(*) AS cnt FROM restaurant_tables WHERE status='available'")->fetch_assoc()['cnt'];
$totalTables = $conn->query("SELECT COUNT(*) AS cnt FROM restaurant_tables")->fetch_assoc()['cnt'];

?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/RestaurantPOS/manifest.json">
  <title>Cashier — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  
  
  
  <style>
    /* Quick action cards */
    .qa-grid{
      display:grid;
      grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
      gap:14px;
      margin-bottom:24px;
    }
    .qa-card{
      background:var(--bg-card);
      border:1.5px solid var(--border);
      border-radius:var(--radius);
      padding:22px 16px;
      text-align:center;
      text-decoration:none;
      color:var(--text-main);
      transition:var(--transition);
      cursor:pointer;
      display:flex;flex-direction:column;
      align-items:center;gap:10px;
    }
    .qa-card:hover{
      border-color:var(--primary);
      transform:translateY(-3px);
      box-shadow:0 8px 24px rgba(232,168,56,.15);
      color:var(--primary);
    }
    .qa-card.primary{background:linear-gradient(135deg,rgba(232,168,56,.15),rgba(232,168,56,.05));border-color:var(--primary);}
    .qa-icon{font-size:2.4rem;}
    .qa-label{font-size:.88rem;font-weight:700;}

    /* Greeting banner */
    .greet-banner{
      background:linear-gradient(135deg,#141428,#1a1a38);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:22px 26px;
      margin-bottom:24px;
      display:flex;align-items:center;justify-content:space-between;
      gap:16px;flex-wrap:wrap;
    }
    .greet-left h2{font-size:1.35rem;font-weight:800;margin-bottom:4px;}
    .greet-left h2 span{color:var(--primary);}
    .greet-left p{color:var(--text-muted);font-size:.85rem;}
    .greet-time{
      font-size:1.6rem;font-weight:800;color:var(--primary);
      font-variant-numeric:tabular-nums;
    }
  </style>
</head>




<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  
  
  
  
  
  
  
  

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:24px;">
    <div class="stat-card">
      <div class="stat-icon green">💵</div>
      <div class="stat-info">
        <div class="stat-value" id="cRevenue" style="font-size:1.45rem;">Rs <?= number_format($myStats['revenue'],2) ?></div>
        <div class="stat-label">Total Revenue Today</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue">🧾</div>
      <div class="stat-info">
        <div class="stat-value" id="cOrders"><?= $myStats['orders'] ?></div>
        <div class="stat-label">Bills Processed Today</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">🪑</div>
      <div class="stat-info">
        <div class="stat-value" id="cTables"><?= $availTables ?>/<?= $totalTables ?></div>
        <div class="stat-label">Tables Available</div>
      </div>
    </div>
  </div>

  <!-- Quick Actions -->
  <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:12px;">⚡ Quick Actions</h3>
  <div class="qa-grid">
    <a class="qa-card primary" href="/RestaurantPOS/customer_order.php">
      <span class="qa-icon">🛒</span>
      <span class="qa-label">New Order</span>
    </a>
    <a class="qa-card" href="/RestaurantPOS/billing.php">
      <span class="qa-icon">💳</span>
      <span class="qa-label">Billing</span>
    </a>
    <a class="qa-card" href="/RestaurantPOS/tables.php">
      <span class="qa-icon">🪑</span>
      <span class="qa-label">Tables</span>
    </a>
    <a class="qa-card" href="/RestaurantPOS/orders.php">
      <span class="qa-icon">📋</span>
      <span class="qa-label">All Orders</span>
    </a>
    <a class="qa-card" href="/RestaurantPOS/kitchen.php">
      <span class="qa-icon">👨‍🍳</span>
      <span class="qa-label">Kitchen Status</span>
    </a>
    <a class="qa-card" href="/RestaurantPOS/customers.php">
      <span class="qa-icon">👥</span>
      <span class="qa-label">Customers</span>
    </a>
  </div>

  <!-- Recent paid today -->
  <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon">✅</span> My Bills Today</div>
        <span class="badge badge-success"><?= $myStats['orders'] ?> paid</span>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>Order</th><th>Table</th><th>Method</th><th>Total</th><th>Time</th></tr>
          </thead>
          <tbody id="recentPaidBody">
            <?php if ($recentPaid->num_rows === 0): ?>
              <tr><td colspan="5" class="text-center text-muted" style="padding:30px;">No bills processed today yet.</td></tr>
            <?php else: ?>
              <?php while ($o = $recentPaid->fetch_assoc()): ?>
              <tr>
                <td><strong style="color:var(--primary);"><?= htmlspecialchars($o['order_number']) ?></strong></td>
                <td><?= htmlspecialchars($o['tbl']) ?></td>
                <td>
                  <?= match($o['payment_method'] ?? 'cash'){
                    'card'       => '💳 Card',
                    'online'     => '📱 Online',
                    'paypal'     => '🅿️ PayPal',
                    'google_pay' => '🌐 GPay',
                    'apple_pay'  => '🍏 Apple Pay',
                    default      => '💵 Cash'
                  } ?>
                </td>
                <td class="text-primary fw-bold">Rs <?= number_format($o['total'],2) ?></td>
                <td class="text-muted" style="font-size:.78rem;"><?= date('h:i A', strtotime($o['created_at'])) ?></td>
              </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
  </div>

</div>

<script>


// ── Auto-refresh cashier stats every 20s ─────────────────
function refreshCashierStats() {
  fetch('/RestaurantPOS/ajax_cashier_stats.php')
    .then(r => r.json())
    .then(d => {
      const rev  = document.getElementById('cRevenue');
      const ord  = document.getElementById('cOrders');
      const tbl  = document.getElementById('cTables');
      const rBody= document.getElementById('recentPaidBody');

      if(rev)  rev.textContent  = 'Rs ' + d.revenue;
      if(ord)  ord.textContent  = d.orders;
      if(tbl)  tbl.textContent  = d.avail_tbl + '/' + d.total_tbl;
      if(rBody && d.recentHtml  !== undefined) rBody.innerHTML = d.recentHtml;
    }).catch(()=>{});
}
refreshCashierStats();
setInterval(refreshCashierStats, 20000);
</script>

<?php include __DIR__ . '/includes/admin_popup.php'; ?>
</body>
</html>
