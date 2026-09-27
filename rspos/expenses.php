<?php
require_once 'includes/auth.php';
requireLogin();
requireRole('admin');
require_once 'db_config.php';
$user = currentUser();

// ── Ensure expenses table exists ──────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    category ENUM('ingredients','staff','utilities','rent','maintenance','marketing','other') DEFAULT 'other',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
)");

$msg = $err = '';

// ── Add expense ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    $title    = trim($_POST['title'] ?? '');
    $cat      = $_POST['category'] ?? 'other';
    $amount   = floatval($_POST['amount'] ?? 0);
    $dateInput = trim($_POST['expense_date'] ?? '');
    $dateObj   = DateTime::createFromFormat('!d/m/Y', $dateInput);
    $dateValid = $dateObj && $dateObj->format('d/m/Y') === $dateInput;
    $date      = $dateValid ? $dateObj->format('Y-m-d') : date('Y-m-d');
    $notes    = trim($_POST['notes'] ?? '');
    $uid      = $user['id'];

    $allowed_cats = ['ingredients','staff','utilities','rent','maintenance','marketing','other'];
    if (!in_array($cat, $allowed_cats)) $cat = 'other';

    if (empty($title) || $amount <= 0 || !$dateValid) {
        $err = 'Title, valid amount, and date (dd/mm/yyyy) are required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO expenses (title,category,amount,expense_date,notes,created_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('ssdssi', $title, $cat, $amount, $date, $notes, $uid);
        $stmt->execute(); $stmt->close();
        $msg = 'Expense added successfully.';
    }
}

// ── Delete expense ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_expense') {
    $id = intval($_POST['expense_id']);
    $conn->query("DELETE FROM expenses WHERE id=$id");
    $msg = 'Expense deleted.';
}

// ── Date filter ───────────────────────────────────────────
$filterMonth = $_GET['month'] ?? date('Y-m');
$startDate   = $filterMonth . '-01';
$endDate     = date('Y-m-t', strtotime($startDate));

// ── Revenue from orders (completed) ──────────────────────
$rev = $conn->query("SELECT COALESCE(SUM(total),0) AS total, COALESCE(SUM(subtotal),0) AS sub,
                            COALESCE(SUM(discount),0) AS disc, COUNT(*) AS orders
                     FROM orders WHERE status='completed'
                     AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'")->fetch_assoc();

$revenue      = floatval($rev['total']);
$totalOrders  = intval($rev['orders']);

// ── Total expenses ────────────────────────────────────────
$expTotal = floatval($conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses
    WHERE expense_date BETWEEN '$startDate' AND '$endDate'")->fetch_assoc()['t']);

$profit     = $revenue - $expTotal;
$profitPct  = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;

// ── Expenses by category ──────────────────────────────────
$expByCat = $conn->query("SELECT category, SUM(amount) AS total FROM expenses
    WHERE expense_date BETWEEN '$startDate' AND '$endDate' GROUP BY category ORDER BY total DESC");

// ── Top sold items (auto cost tracking) ──────────────────
$topItems = $conn->query("SELECT oi.item_name,
    SUM(oi.quantity) AS qty_sold,
    SUM(oi.total_price) AS revenue,
    COUNT(DISTINCT oi.order_id) AS in_orders
    FROM order_items oi
    JOIN orders o ON oi.order_id=o.id
    WHERE o.status='completed'
    AND DATE(o.created_at) BETWEEN '$startDate' AND '$endDate'
    GROUP BY oi.item_name ORDER BY qty_sold DESC LIMIT 10");

// ── Expenses list ─────────────────────────────────────────
$expList = $conn->query("SELECT e.*, u.full_name AS added_by
    FROM expenses e LEFT JOIN users u ON e.created_by=u.id
    WHERE e.expense_date BETWEEN '$startDate' AND '$endDate'
    ORDER BY e.expense_date DESC, e.id DESC");

// ── Daily revenue chart data (last 30 days) ───────────────
$dailyRev = [];
for ($i = 29; $i >= 0; $i--) {
    $d  = date('d-m-Y', strtotime("-$i days"));
    $lbl = date('d/m', strtotime($d));
    $r  = $conn->query("SELECT COALESCE(SUM(total),0) AS rev FROM orders WHERE status='completed' AND DATE(created_at)='$d'")->fetch_assoc();
    $ex = $conn->query("SELECT COALESCE(SUM(amount),0) AS exp FROM expenses WHERE expense_date='$d'")->fetch_assoc();
    $dailyRev[] = ['label'=>$lbl, 'rev'=>(float)$r['rev'], 'exp'=>(float)$ex['exp']];
}

$catEmojis = ['ingredients'=>'🥘','staff'=>'👥','utilities'=>'💡','rent'=>'🏠',
              'maintenance'=>'🔧','marketing'=>'📣','other'=>'📌'];
?>








<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Expenses — ServePoint</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>
<div class="main-content">

  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">💰 <span>Expenses</span> & Profit</h1>
      <p class="page-subtitle">Track revenue, costs and net profit</p>
    </div>
    <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center;">
      <input type="month" id="monthPicker" class="form-control" style="width:auto;"
             value="<?= $filterMonth ?>"
             onchange="window.location='?month='+this.value">
      <button class="btn btn-primary" onclick="document.getElementById('addExpModal').classList.add('open')">
        ➕ Add Expense
      </button>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- KPI Cards -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:24px;">
    <div class="stat-card">
      <div class="stat-icon green">📈</div>
      <div class="stat-info">
        <div class="stat-value" style="font-size:1.5rem;">Rs <?= number_format($revenue,2) ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-change"><?= $totalOrders ?> completed orders</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red">📉</div>
      <div class="stat-info">
        <div class="stat-value" style="font-size:1.5rem;color:var(--danger);">Rs <?= number_format($expTotal,2) ?></div>
        <div class="stat-label">Total Expenses</div>
        <div class="stat-change down">This month</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon <?= $profit >= 0 ? 'green' : 'red' ?>">💵</div>
      <div class="stat-info">
        <div class="stat-value" style="font-size:1.5rem;color:<?= $profit>=0?'var(--success)':'var(--danger)' ?>">
          Rs <?= number_format(abs($profit),2) ?>
        </div>
        <div class="stat-label">Net Profit <?= $profit < 0 ? '(Loss)' : '' ?></div>
        <div class="stat-change <?= $profit>=0?'up':'down' ?>"><?= $profitPct ?>% margin</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange">🍽️</div>
      <div class="stat-info">
        <div class="stat-value" style="font-size:1.5rem;"><?= $totalOrders ?></div>
        <div class="stat-label">Orders This Month</div>
        <div class="stat-change">Avg Rs <?= $totalOrders > 0 ? number_format($revenue/$totalOrders,2) : '0.00' ?>/order</div>
      </div>
    </div>
  </div>

  <!-- Chart -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <div class="card-title"><span class="icon">📊</span> Revenue vs Expenses (Last 30 Days)</div>
    </div>
    <div class="card-body">
      <canvas id="revExpChart" height="100"></canvas>
    </div>
  </div>

  <!-- 2-col: Expenses by category + Top items -->
  <div class="grid grid-2" style="gap:20px;margin-bottom:20px;">

    <!-- Expenses by category -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon">🗂️</span> Expenses by Category</div>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if ($expByCat->num_rows === 0): ?>
          <p class="text-muted text-center" style="padding:30px;">No expenses this month.</p>
        <?php else:
          // get max for bar
          $cats = []; while($c=$expByCat->fetch_assoc()) $cats[] = $c;
          $maxCat = max(array_column($cats,'total')) ?: 1;
          foreach ($cats as $i => $c):
            $pct = round(($c['total']/$maxCat)*100);
            $pctOfTotal = $expTotal > 0 ? round(($c['total']/$expTotal)*100,1) : 0;
        ?>
          <div style="padding:12px 20px;<?= $i < count($cats)-1 ? 'border-bottom:1px solid var(--border)' : '' ?>">
            <div class="d-flex justify-between align-center" style="margin-bottom:6px;">
              <span style="font-size:.88rem;font-weight:600;">
                <?= $catEmojis[$c['category']] ?? '📌' ?> <?= ucfirst($c['category']) ?>
              </span>
              <span class="text-primary fw-bold">Rs <?= number_format($c['total'],2) ?>
                <small class="text-muted">(<?= $pctOfTotal ?>%)</small>
              </span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Top sold items -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon">🏆</span> Top Selling Items</div>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if ($topItems->num_rows === 0): ?>
          <p class="text-muted text-center" style="padding:30px;">No sales data.</p>
        <?php else:
          $items = []; while($ti=$topItems->fetch_assoc()) $items[]=$ti;
          $maxQty = max(array_column($items,'qty_sold')) ?: 1;
          foreach ($items as $i => $ti):
            $pct = round(($ti['qty_sold']/$maxQty)*100);
        ?>
          <div style="padding:10px 20px;<?= $i<count($items)-1?'border-bottom:1px solid var(--border)':'' ?>">
            <div class="d-flex justify-between align-center" style="margin-bottom:5px;">
              <span style="font-size:.85rem;font-weight:600;"><?= htmlspecialchars($ti['item_name']) ?></span>
              <span>
                <span class="badge badge-primary"><?= $ti['qty_sold'] ?> sold</span>
                <span class="text-primary fw-bold" style="margin-left:6px;font-size:.85rem;">Rs <?= number_format($ti['revenue'],2) ?></span>
              </span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Expenses list -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><span class="icon">📋</span> Expense Records</div>
      <span class="badge badge-danger">Rs <?= number_format($expTotal,2) ?> total</span>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Date</th><th>Title</th><th>Category</th><th>Amount</th><th>Notes</th><th>Added By</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php if ($expList->num_rows === 0): ?>
            <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">No expenses recorded for this month.</td></tr>
          <?php else: ?>
            <?php while ($e = $expList->fetch_assoc()): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($e['expense_date'])) ?></td>
              <td><strong><?= htmlspecialchars($e['title']) ?></strong></td>
              <td><span class="badge badge-warning"><?= $catEmojis[$e['category']] ?> <?= ucfirst($e['category']) ?></span></td>
              <td><span class="text-danger fw-bold">Rs <?= number_format($e['amount'],2) ?></span></td>
              <td class="text-muted fs-sm"><?= htmlspecialchars($e['notes'] ?? '—') ?></td>
              <td class="text-muted fs-sm"><?= htmlspecialchars($e['added_by'] ?? '—') ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Delete this expense?')" style="display:inline;">
                  <input type="hidden" name="action" value="delete_expense">
                  <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                  <button class="btn btn-danger btn-sm">🗑</button>
                </form>
              </td>
            </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ── Add Expense Modal ── -->
<div class="modal-overlay" id="addExpModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <div class="modal-title">➕ Add Expense</div>
      <button class="modal-close" onclick="document.getElementById('addExpModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_expense">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Title *</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Rice purchase, Staff salary..." required>
        </div>
        <div class="grid grid-2" style="gap:12px;">
          <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="category" class="form-control">
              <option value="ingredients">🥘 Ingredients</option>
              <option value="staff">👥 Staff</option>
              <option value="utilities">💡 Utilities</option>
              <option value="rent">🏠 Rent</option>
              <option value="maintenance">🔧 Maintenance</option>
              <option value="marketing">📣 Marketing</option>
              <option value="other" selected>📌 Other</option>
            </select>
			
			
			
			
			
			
			
			
          </div>
          <div class="form-group">
            <label class="form-label">Amount (Rs) *</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Date *</label>
          <input type="text" name="expense_date" class="form-control" value="<?= date('d/m/Y') ?>" placeholder="dd/mm/yyyy" pattern="\d{2}/\d{2}/\d{4}" inputmode="numeric" required>
        </div>
        <div class="form-group">
          <label class="form-label">Notes (optional)</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Additional details..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addExpModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Expense</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open');}));

const labels  = <?= json_encode(array_column($dailyRev,'label')) ?>;
const revData = <?= json_encode(array_column($dailyRev,'rev')) ?>;
const expData = <?= json_encode(array_column($dailyRev,'exp')) ?>;

new Chart(document.getElementById('revExpChart'),{
  type:'bar',
  data:{
    labels:labels,
    datasets:[
      {label:'Revenue (Rs)',data:revData,backgroundColor:'rgba(232,168,56,.75)',borderColor:'#e8a838',borderWidth:1.5,borderRadius:4},
      {label:'Expenses (Rs)',data:expData,backgroundColor:'rgba(220,53,69,.65)',borderColor:'#dc3545',borderWidth:1.5,borderRadius:4}
    ]
  },
  options:{
    responsive:true,
    plugins:{legend:{labels:{color:'#9090b0'}}},
    scales:{
      x:{ticks:{color:'#9090b0',maxTicksLimit:10},grid:{color:'rgba(255,255,255,.04)'}},
      y:{ticks:{color:'#9090b0',callback:v=>'Rs'+v},grid:{color:'rgba(255,255,255,.04)'},beginAtZero:true}
    }
  }
});
</script>

</body>
</html>
