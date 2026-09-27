<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';

$period    = $_GET['period'] ?? 'daily';
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate   = $_GET['end']   ?? date('Y-m-d');

// ── Date range presets ────────────────────────────────────
if ($period === 'daily') {
    $startDate = $endDate = date('Y-m-d');
} elseif ($period === 'weekly') {
    $startDate = date('Y-m-d', strtotime('monday this week'));
    $endDate   = date('Y-m-d');
} elseif ($period === 'monthly') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-d');
} elseif ($period === 'custom') {
    $startDate = $_GET['start'] ?? date('Y-m-01');
    $endDate   = $_GET['end']   ?? date('Y-m-d');
}

$sd = $conn->real_escape_string($startDate);
$ed = $conn->real_escape_string($endDate);

// ── Summary stats ─────────────────────────────────────────
$summary = $conn->query("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total),0) AS total_revenue,
        COALESCE(AVG(total),0) AS avg_order,
        COALESCE(SUM(discount),0) AS total_discount,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM orders
    WHERE DATE(created_at) BETWEEN '$sd' AND '$ed'")->fetch_assoc();

// ── Revenue by date ───────────────────────────────────────
$byDate = $conn->query("
    SELECT DATE(created_at) AS date, COUNT(*) AS orders,
           COALESCE(SUM(total),0) AS revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN '$sd' AND '$ed' AND status='completed'
    GROUP BY DATE(created_at) ORDER BY date");
$dateLabels = $dateRevenue = $dateOrders = [];
while ($r = $byDate->fetch_assoc()) {
    $dateLabels[]  = date('d/m', strtotime($r['date']));
    $dateRevenue[] = (float)$r['revenue'];
    $dateOrders[]  = (int)$r['orders'];
}

// ── Best-selling items ────────────────────────────────────
$bestSelling = $conn->query("
    SELECT oi.item_name,
           SUM(oi.quantity) AS total_qty,
           SUM(oi.total_price) AS total_revenue,
           COUNT(DISTINCT oi.order_id) AS in_orders
    FROM order_items oi
    JOIN orders o ON oi.order_id=o.id
    WHERE DATE(o.created_at) BETWEEN '$sd' AND '$ed' AND o.status='completed'
    GROUP BY oi.item_name ORDER BY total_qty DESC LIMIT 10");
$bsRows = [];
$bsMax  = 1;
while ($r = $bestSelling->fetch_assoc()) {
    $bsRows[] = $r;
    if ($r['total_qty'] > $bsMax) $bsMax = $r['total_qty'];
}

// ── Payment method breakdown ──────────────────────────────
$payMethods = $conn->query("
    SELECT p.payment_method, COUNT(*) AS cnt, SUM(p.amount_paid) AS total
    FROM payments p
    JOIN orders o ON p.order_id=o.id
    WHERE DATE(o.created_at) BETWEEN '$sd' AND '$ed'
    GROUP BY p.payment_method");
$pmRows = [];
while ($r = $payMethods->fetch_assoc()) $pmRows[] = $r;

// ── Hourly distribution (today or selected range) ─────────
$hourly = $conn->query("
    SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt
    FROM orders
    WHERE DATE(created_at) BETWEEN '$sd' AND '$ed' AND status='completed'
    GROUP BY HOUR(created_at) ORDER BY hr");
$hourData = array_fill(0, 24, 0);
while ($r = $hourly->fetch_assoc()) $hourData[$r['hr']] = (int)$r['cnt'];

// ── Recent completed orders ───────────────────────────────
$recentOrders = $conn->query("
    SELECT o.*, COALESCE(t.table_number,'Takeaway') AS table_number,
           COALESCE(u.full_name,'—') AS cashier
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id=t.id
    LEFT JOIN users u ON o.cashier_id=u.id
    WHERE DATE(o.created_at) BETWEEN '$sd' AND '$ed' AND o.status='completed'
    ORDER BY o.created_at DESC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sales Reports — RestoPOS</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

  <div class="page-header">
   
    <button class="btn btn-primary" onclick="window.print()">🖨️ Print Report</button>
  </div>

  <!-- Period Tabs + Custom Range -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px;">
      <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <div class="report-tabs" style="margin:0; flex:none;">
          <a href="?period=daily"   class="report-tab <?= $period==='daily'?'active':'' ?>">📅 Today</a>
          <a href="?period=weekly"  class="report-tab <?= $period==='weekly'?'active':'' ?>">📆 This Week</a>
          <a href="?period=monthly" class="report-tab <?= $period==='monthly'?'active':'' ?>">🗓️ This Month</a>
          <a href="?period=custom"  class="report-tab <?= $period==='custom'?'active':'' ?>">🔧 Custom</a>
        </div>
        <?php if ($period === 'custom'): ?>
          <form method="GET" class="d-flex gap-2 align-center">
            <input type="hidden" name="period" value="custom">
            <input type="date" name="start" class="form-control" value="<?= $startDate ?>" style="max-width:160px;">
            <span class="text-muted">to</span>
            <input type="date" name="end"   class="form-control" value="<?= $endDate ?>"   style="max-width:160px;">
            <button class="btn btn-primary" type="submit">Apply</button>
          </form>
        <?php else: ?>
          <span class="text-muted fs-sm">📅 <?= date('d M Y', strtotime($startDate)) ?> — <?= date('d M Y', strtotime($endDate)) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Summary Stats -->
  <div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card">
      <div class="stat-icon orange">💰</div>
      <div class="stat-info">
        <div class="stat-value">Rs <?= number_format($summary['total_revenue'],2) ?></div>
        <div class="stat-label">Total Revenue</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue">🛒</div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['total_orders'] ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">✅</div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['completed'] ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon purple">📊</div>
      <div class="stat-info">
        <div class="stat-value">Rs <?= number_format($summary['avg_order'],2) ?></div>
        <div class="stat-label">Avg Order Value</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red">❌</div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['cancelled'] ?></div>
        <div class="stat-label">Cancelled</div>
      </div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="grid grid-2" style="gap:20px; margin-bottom:20px;">

    <!-- Revenue Chart -->
    <div class="card">
      <div class="card-header"><div class="card-title"><span class="icon">📈</span> Revenue Trend</div></div>
      <div class="card-body">
        <?php if (empty($dateLabels)): ?>
          <p class="text-muted text-center" style="padding:40px;">No data for this period.</p>
        <?php else: ?>
          <canvas id="revenueChart" height="180"></canvas>
        <?php endif; ?>
      </div>
    </div>

    <!-- Hourly Orders -->
    <div class="card">
      <div class="card-header"><div class="card-title"><span class="icon">🕐</span> Orders by Hour</div></div>
      <div class="card-body">
        <canvas id="hourlyChart" height="180"></canvas>
      </div>
    </div>

  </div>

  <!-- Best Selling + Payment Methods -->
  <div class="grid grid-2" style="gap:20px; margin-bottom:20px;">

    <!-- Best Selling -->
    <div class="card">
      <div class="card-header"><div class="card-title"><span class="icon">🏆</span> Best Selling Items</div></div>
      <div class="card-body p-0">
        <?php if (empty($bsRows)): ?>
          <p class="text-muted text-center" style="padding:40px;">No sales data.</p>
        <?php else: ?>
          <?php foreach ($bsRows as $i => $bs): ?>
            <div style="padding:12px 22px; <?= $i<count($bsRows)-1?'border-bottom:1px solid var(--border)':'' ?>">
              <div class="d-flex justify-between align-center" style="margin-bottom:5px;">
                <div>
                  <span style="background:var(--primary); color:#000; font-size:0.7rem; font-weight:800; padding:2px 7px; border-radius:10px; margin-right:7px;">#<?= $i+1 ?></span>
                  <strong style="font-size:0.88rem;"><?= htmlspecialchars($bs['item_name']) ?></strong>
                </div>
                <div class="text-right">
                  <div class="text-primary fw-bold"><?= $bs['total_qty'] ?> sold</div>
                  <div class="fs-sm text-muted">Rs <?= number_format($bs['total_revenue'],2) ?></div>
                </div>
              </div>
              <div class="progress">
                <div class="progress-bar" style="width:<?= round(($bs['total_qty']/$bsMax)*100) ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payment Methods -->
    <div class="card">
      <div class="card-header"><div class="card-title"><span class="icon">💳</span> Payment Methods</div></div>
      <div class="card-body">
        <?php if (empty($pmRows)): ?>
          <p class="text-muted text-center" style="padding:40px;">No payment data.</p>
        <?php else: ?>
          <canvas id="paymentChart" height="200"></canvas>
          <div style="margin-top:16px;">
            <?php foreach ($pmRows as $pm): ?>
              <div class="d-flex justify-between" style="padding:6px 0; border-bottom:1px solid var(--border);">
                <span><?= ucwords(str_replace('_',' ',$pm['payment_method'])) ?></span>
                <div class="text-right">
                  <span class="text-primary fw-bold">Rs <?= number_format($pm['total'],2) ?></span>
                  <span class="text-muted fs-sm" style="margin-left:10px;"><?= $pm['cnt'] ?> orders</span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Orders Detail Table -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><span class="icon">🧾</span> Completed Orders</div>
      <span class="text-muted fs-sm"><?= $recentOrders->num_rows ?> orders</span>
    </div>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Order #</th><th>Table</th><th>Subtotal</th><th>Discount</th><th>Service charge</th><th>Total</th><th>Cashier</th><th>Date/Time</th></tr></thead>
        <tbody>
          <?php if ($recentOrders->num_rows === 0): ?>
            <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No completed orders.</td></tr>
          <?php else: ?>
            <?php while ($o = $recentOrders->fetch_assoc()): ?>
              <tr>
                <td data-label="Order #"><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                <td data-label="Table"><?= htmlspecialchars($o['table_number']) ?></td>
                <td data-label="Subtotal">Rs <?= number_format($o['subtotal'],2) ?></td>
                <td data-label="Discount" class="text-success"><?= $o['discount']>0?'- RM '.number_format($o['discount'],2):'—' ?></td>
                <td data-label="Service Amount">Rs <?= number_format($o['tax'],2) ?></td>
                <td data-label="Total" class="text-primary fw-bold">Rs <?= number_format($o['total'],2) ?></td>
                <td data-label="Cashier" class="text-muted"><?= htmlspecialchars($o['cashier']) ?></td>
                <td data-label="Date" class="text-muted fs-sm"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.main-content -->

<script>
<?php if (!empty($dateLabels)): ?>
new Chart(document.getElementById('revenueChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($dateLabels) ?>,
    datasets: [{
      label: 'Revenue (Rs)',
      data: <?= json_encode($dateRevenue) ?>,
      borderColor: '#e8a838',
      backgroundColor: 'rgba(232,168,56,0.1)',
      borderWidth: 2,
      fill: true,
      tension: 0.4,
      pointBackgroundColor: '#e8a838',
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#9090b0' }, grid: { color: 'rgba(255,255,255,0.04)' } },
      y: { ticks: { color: '#9090b0', callback: v=>'RM'+v }, grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true }
    }
  }
});
<?php endif; ?>

const hourLabels = <?= json_encode(array_map(fn($h)=>$h.':00', range(0,23))) ?>;
const hourData   = <?= json_encode(array_values($hourData)) ?>;
new Chart(document.getElementById('hourlyChart'), {
  type: 'bar',
  data: {
    labels: hourLabels,
    datasets: [{ label:'Orders', data: hourData, backgroundColor:'rgba(23,162,184,0.6)', borderColor:'#17a2b8', borderWidth:1, borderRadius:4 }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color:'#9090b0', maxRotation:45 }, grid: { color:'rgba(255,255,255,0.04)' } },
      y: { ticks: { color:'#9090b0' }, grid: { color:'rgba(255,255,255,0.04)' }, beginAtZero:true }
    }
  }
});

<?php if (!empty($pmRows)): ?>
const pmLabels = <?= json_encode(array_map(fn($r)=>ucwords(str_replace('_',' ',$r['payment_method'])), $pmRows)) ?>;
const pmValues = <?= json_encode(array_map(fn($r)=>(float)$r['total'], $pmRows)) ?>;
new Chart(document.getElementById('paymentChart'), {
  type: 'doughnut',
  data: {
    labels: pmLabels,
    datasets: [{ data: pmValues, backgroundColor: ['#28a745','#17a2b8','#e8a838','#0079c1','#4285f4','#a855f7'], borderColor:'#1e1e30', borderWidth:3 }]
  },
  options: { cutout:'65%', plugins: { legend: { position:'bottom', labels:{ color:'#9090b0' } } } }
});
<?php endif; ?>
</script>
</body>
</html>
