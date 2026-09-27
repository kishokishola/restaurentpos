<?php
require_once 'includes/auth.php';
requireLogin();
requireRole('admin');   // ← Admin only — cashier/kitchen redirected away
require_once 'db_config.php';
$user = currentUser();
$today = date('Y-m-d');

$r = $conn->query("SELECT COALESCE(SUM(o.total),0) AS revenue, COUNT(*) AS orders FROM payments p JOIN orders o ON o.id=p.order_id WHERE DATE(p.paid_at)='$today' AND p.status='paid'");
$todayRow = $r->fetch_assoc();
$todayRevenue = number_format($todayRow['revenue'], 2);
$todayOrders  = $todayRow['orders'];

$r2 = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('pending','preparing')");
$pendingOrders = $r2->fetch_assoc()['cnt'];

$r3 = $conn->query("SELECT COUNT(*) AS cnt FROM restaurant_tables WHERE status='available'");
$availTables = $r3->fetch_assoc()['cnt'];

$r4 = $conn->query("SELECT COUNT(*) AS cnt FROM inventory WHERE quantity<=min_quantity");
$lowStock = (int)$r4->fetch_assoc()['cnt'];

$conn->query("UPDATE inventory SET status=CASE WHEN quantity=0 THEN 'out_of_stock' WHEN quantity<=min_quantity THEN 'low_stock' ELSE 'in_stock' END");

$recentOrders = $conn->query("SELECT o.id, o.order_number, o.status, o.total, o.created_at, COALESCE(t.table_number,'Takeaway') AS table_number, COALESCE(u.full_name,'-') AS cashier FROM orders o LEFT JOIN restaurant_tables t ON o.table_id=t.id LEFT JOIN users u ON o.cashier_id=u.id ORDER BY o.created_at DESC LIMIT 8");

$bestSelling = $conn->query("SELECT oi.item_name, SUM(oi.quantity) AS qty, SUM(oi.total_price) AS revenue FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE DATE(o.created_at)='$today' GROUP BY oi.item_name ORDER BY qty DESC LIMIT 5");

$weeklyData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $lbl = date('D', strtotime($d));
    $rw = $conn->query("SELECT COALESCE(SUM(o.total),0) AS rev FROM payments p JOIN orders o ON o.id=p.order_id WHERE DATE(p.paid_at)='$d' AND p.status='paid'");
    $weeklyData[] = ['label' => $lbl, 'value' => (float)$rw->fetch_assoc()['rev']];
}

$tableStats = $conn->query("SELECT status, COUNT(*) AS cnt FROM restaurant_tables GROUP BY status");
$tStats = ['available'=>0,'occupied'=>0,'reserved'=>0];
while ($row = $tableStats->fetch_assoc()) $tStats[$row['status']] = $row['cnt'];
$totalTables = array_sum($tStats);

$lowStockItems = $conn->query("SELECT item_name, quantity, min_quantity, unit FROM inventory WHERE quantity<=min_quantity ORDER BY quantity ASC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="/RestaurantPOS/manifest.json">
<title>Dashboard - RestoPOS</title>
<link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.welcome-banner{background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);border:1px solid var(--border);border-radius:var(--radius);padding:28px 32px;margin-bottom:28px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.welcome-banner::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:radial-gradient(circle,rgba(232,168,56,0.12),transparent 70%);border-radius:50%;}
.welcome-text h2{font-size:1.5rem;font-weight:800;}
.welcome-text p{color:var(--text-muted);font-size:0.88rem;margin-top:4px;}
.welcome-emoji{font-size:3.5rem;opacity:0.8;}
.status-legend{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;}
.legend-item{display:flex;align-items:center;gap:6px;font-size:0.8rem;color:var(--text-muted);}
.legend-dot{width:10px;height:10px;border-radius:50%;}
@media(max-width:640px){.welcome-banner{flex-direction:column;text-align:center;}.welcome-emoji{font-size:2.5rem;}}
</style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>
<div class="main-content">



<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon orange">💰</div>
    <div class="stat-info">
      <div class="stat-value" data-stat="revenue">Rs <?= $todayRevenue ?></div>
      <div class="stat-label">Today Revenue</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">🛒</div>
    <div class="stat-info">
      <div class="stat-value" data-stat="orders"><?= $todayOrders ?></div>
      <div class="stat-label">Completed Orders</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">⏳</div>
    <div class="stat-info">
      <div class="stat-value" data-stat="pending"><?= $pendingOrders ?></div>
      <div class="stat-label">Pending Orders</div>
      <?php if($pendingOrders>0): ?><div class="stat-change down">Needs attention</div><?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">🪑</div>
    <div class="stat-info">
      <div class="stat-value" data-stat="avail_tbl"><?= $availTables ?>/<?= $totalTables ?></div>
      <div class="stat-label">Tables Available</div>
      <div class="stat-change"><?= $tStats['occupied'] ?> occupied</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $lowStock>0?'red':'green' ?>">📦</div>
    <div class="stat-info">
      <div class="stat-value" data-stat="low_stock"><?= $lowStock ?></div>
      <div class="stat-label">Low Stock Alerts</div>
      <?php if($lowStock>0): ?><div class="stat-change down">Check inventory</div><?php else: ?><div class="stat-change up">All stocked</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-2" style="gap:20px;margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">📈</span> Weekly Revenue</div><span class="badge badge-primary">Last 7 Days</span></div>
    <div class="card-body"><canvas id="revenueChart" height="140"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">🪑</span> Table Overview</div><a href="/RestaurantPOS/tables.php" class="btn btn-ghost btn-sm">View All</a></div>
    <div class="card-body">
      <div style="text-align:center;margin-bottom:16px;"><canvas id="tableChart" width="180" height="180" style="max-width:180px;margin:auto;"></canvas></div>
      <div class="status-legend">
        <div class="legend-item"><div class="legend-dot" style="background:var(--success)"></div><?= $tStats['available'] ?> Available</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--danger)"></div><?= $tStats['occupied'] ?> Occupied</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--warning)"></div><?= $tStats['reserved'] ?> Reserved</div>
      </div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><div class="card-title"><span class="icon">🛒</span> Recent Orders</div><a href="/RestaurantPOS/orders.php" class="btn btn-outline btn-sm">View All</a></div>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Order #</th><th>Table</th><th>Cashier</th><th>Status</th><th>Total</th><th>Time</th><th>Action</th></tr></thead>
      <tbody id="dashOrdersBody">
        <?php if($recentOrders->num_rows===0): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:30px;">No orders today yet.</td></tr>
        <?php else: ?>
          <?php while($o=$recentOrders->fetch_assoc()):
            $badge=match($o['status']){'completed'=>'badge-success','cancelled'=>'badge-danger','preparing'=>'badge-warning','ready'=>'badge-info',default=>'badge-muted'};
          ?>
          <tr>
            <td data-label="Order #"><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
            <td data-label="Table"><?= htmlspecialchars($o['table_number']) ?></td>
            <td data-label="Cashier"><?= htmlspecialchars($o['cashier']) ?></td>
            <td data-label="Status"><span class="badge <?= $badge ?>"><?= ucfirst($o['status']) ?></span></td>
            <td data-label="Total" class="text-primary fw-bold">Rs <?= number_format($o['total'],2) ?></td>
            <td data-label="Time" class="text-muted fs-sm"><?= date('h:i A',strtotime($o['created_at'])) ?></td>
            <td data-label="Action"><a href="/RestaurantPOS/billing.php?order_id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
          </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2" style="gap:20px;margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">🏆</span> Best Sellers Today</div></div>
    <div class="card-body p-0">
      <?php $bsRows=[];$bsMax=1; while($bs=$bestSelling->fetch_assoc()){$bsRows[]=$bs;if($bs['qty']>$bsMax)$bsMax=$bs['qty'];} ?>
      <?php if(empty($bsRows)): ?><p class="text-muted text-center" style="padding:30px;">No sales data yet.</p>
      <?php else: ?>
        <?php foreach($bsRows as $i=>$bs): ?>
          <div style="padding:12px 22px;<?= $i<count($bsRows)-1?'border-bottom:1px solid var(--border)':'' ?>">
            <div class="d-flex justify-between align-center" style="margin-bottom:5px;">
              <span class="fw-bold" style="font-size:0.88rem;"><?= htmlspecialchars($bs['item_name']) ?></span>
              <span class="text-primary fw-bold"><?= $bs['qty'] ?> sold</span>
            </div>
            <div class="progress"><div class="progress-bar" style="width:<?= round(($bs['qty']/$bsMax)*100) ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">⚠️</span> Low Stock Alerts</div><a href="/RestaurantPOS/inventory.php" class="btn btn-ghost btn-sm">Manage</a></div>
    <div class="card-body p-0">
      <?php $lsRows=[]; while($ls=$lowStockItems->fetch_assoc()) $lsRows[]=$ls; ?>
      <?php if(empty($lsRows)): ?><p class="text-success text-center" style="padding:30px;">All items stocked.</p>
      <?php else: ?>
        <?php foreach($lsRows as $i=>$ls): $pct=$ls['min_quantity']>0?min(100,round(($ls['quantity']/$ls['min_quantity'])*100)):100; ?>
          <div style="padding:12px 22px;<?= $i<count($lsRows)-1?'border-bottom:1px solid var(--border)':'' ?>">
            <div class="d-flex justify-between align-center" style="margin-bottom:5px;">
              <span class="fw-bold" style="font-size:0.88rem;">🥘 <?= htmlspecialchars($ls['item_name']) ?></span>
              <span class="badge <?= $ls['quantity']==0?'badge-danger':'badge-warning' ?>"><?= $ls['quantity']==0?'Out of Stock':number_format($ls['quantity'],1).' '.$ls['unit'] ?></span>
            </div>
            <div class="stock-level"><div class="stock-bar"><div class="stock-fill <?= $ls['quantity']==0?'low':($pct<=50?'low':'medium') ?>" style="width:<?= $pct ?>%"></div></div><span class="fs-sm text-muted">Ingredient min: <?= $ls['min_quantity'] ?></span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>



<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">⚡</span> Quick Actions</div></div>
  <div class="card-body">
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <a href="/RestaurantPOS/customer_order.php" class="btn btn-primary btn-lg">🛒 New Order</a>
      <a href="/RestaurantPOS/expenses.php"  class="btn btn-primary btn-lg">💰 Expenses</a>
      <a href="/RestaurantPOS/tables.php"    class="btn btn-outline btn-lg">🪑 Tables</a>
      <a href="/RestaurantPOS/kitchen_display.php" class="btn btn-outline btn-lg">👨‍🍳 Kitchen</a>
      <a href="/RestaurantPOS/billing.php"   class="btn btn-outline btn-lg">💳 Billing</a>
      <a href="/RestaurantPOS/reports.php"   class="btn btn-outline btn-lg">📊 Reports</a>
      <?php if(isAdmin()): ?>
        <a href="/RestaurantPOS/menu.php"      class="btn btn-ghost btn-lg">🍽️ Menu</a>
        <a href="/RestaurantPOS/inventory.php" class="btn btn-ghost btn-lg">📦 Inventory</a>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>
<script>
new Chart(document.getElementById('revenueChart'),{type:'bar',data:{labels:<?= json_encode(array_column($weeklyData,'label')) ?>,datasets:[{label:'Revenue (Rs)',data:<?= json_encode(array_column($weeklyData,'value')) ?>,backgroundColor:'rgba(232,168,56,0.7)',borderColor:'#e8a838',borderWidth:2,borderRadius:6}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#9090b0'},grid:{color:'rgba(255,255,255,0.05)'}},y:{ticks:{color:'#9090b0',callback:v=>'RM'+v},grid:{color:'rgba(255,255,255,0.05)'},beginAtZero:true}}}});
new Chart(document.getElementById('tableChart'),{type:'doughnut',data:{labels:['Available','Occupied','Reserved'],datasets:[{data:[<?= $tStats['available'] ?>,<?= $tStats['occupied'] ?>,<?= $tStats['reserved'] ?>],backgroundColor:['#28a745','#dc3545','#ffc107'],borderColor:'#1e1e30',borderWidth:3}]},options:{cutout:'70%',plugins:{legend:{display:false}}}});

// ── Auto-refresh stats every 30s ──────────────────────────
function refreshDashStats() {
  fetch('/RestaurantPOS/ajax_dashboard_stats.php')
    .then(r => r.json())
    .then(d => {
      // Update stat cards by data-stat attribute
      if (d.revenue    !== undefined) document.querySelectorAll('[data-stat="revenue"]').forEach(el    => el.textContent = 'Rs ' + d.revenue);
      if (d.orders     !== undefined) document.querySelectorAll('[data-stat="orders"]').forEach(el     => el.textContent = d.orders);
      if (d.pending    !== undefined) document.querySelectorAll('[data-stat="pending"]').forEach(el    => el.textContent = d.pending);
      if (d.avail_tbl  !== undefined) document.querySelectorAll('[data-stat="avail_tbl"]').forEach(el  => el.textContent = d.avail_tbl + '/' + d.total_tbl);
      if (d.low_stock  !== undefined) document.querySelectorAll('[data-stat="low_stock"]').forEach(el  => el.textContent = d.low_stock);

      // Update recent orders table
      if (d.recentHtml !== undefined) {
        const tbody = document.getElementById('dashOrdersBody');
        if (tbody) tbody.innerHTML = d.recentHtml;
      }
    }).catch(() => {});
}
setInterval(refreshDashStats, 30000);
</script>
<?php include __DIR__ . '/includes/admin_popup.php'; ?>
</body>
</html>
