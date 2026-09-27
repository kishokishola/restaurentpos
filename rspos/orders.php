<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'db_config.php';
$user = currentUser();

$msg = $err = '';

// ── Cancel order (form POST only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    $id = intval($_POST['order_id']);
    $conn->query("UPDATE orders SET status='cancelled' WHERE id=$id");
    $ord = $conn->query("SELECT table_id FROM orders WHERE id=$id")->fetch_assoc();
    if ($ord && $ord['table_id']) {
        $conn->query("UPDATE restaurant_tables SET status='available' WHERE id={$ord['table_id']}");
    }
    $msg = 'Order cancelled.';
}

// ── Fetch page data ───────────────────────────────────────
$allTables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_number");
$customers = $conn->query("SELECT * FROM customers ORDER BY name");

$catQ = $conn->query("SELECT * FROM categories WHERE status='active' ORDER BY name");
$categories = [];
while ($c = $catQ->fetch_assoc()) $categories[] = $c;

$menuQ = $conn->query("
    SELECT m.*, c.name AS cat_name
    FROM menu_items m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.status = 'available'
    ORDER BY c.name, m.name");
$menuItems = [];
while ($m = $menuQ->fetch_assoc()) $menuItems[] = $m;

// ── Orders list ───────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'active';
$statusWhere  = match($filterStatus) {
    'active'    => "WHERE o.status IN ('pending','preparing','ready')",
    'completed' => "WHERE o.status='completed'",
    'cancelled' => "WHERE o.status='cancelled'",
    default     => ''
};

$ordersList = $conn->query("
    SELECT o.*,
           COALESCE(t.table_number,'Takeaway') AS table_number,
           COALESCE(u.full_name,'—')            AS cashier_name,
           COALESCE(cu.name,'Walk-in')           AS customer_name,
           (SELECT COUNT(*) FROM order_items WHERE order_id=o.id) AS item_count,
           (SELECT GROUP_CONCAT(item_name,' x',quantity SEPARATOR ', ')
            FROM order_items WHERE order_id=o.id LIMIT 1) AS items_preview
    FROM orders o
    LEFT JOIN restaurant_tables t  ON o.table_id    = t.id
    LEFT JOIN users u              ON o.cashier_id  = u.id
    LEFT JOIN customers cu         ON o.customer_id = cu.id
    $statusWhere
    ORDER BY o.created_at DESC
    LIMIT 50");
?>




<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Order Management — RestoPOS</title>
  <link rel="stylesheet" href="/RestaurantPOS/assets/css/style.css">
  <style>
    /* ── New Order full-screen overlay ── */
    .order-overlay {
      display: none;
      position: fixed; inset: 0;
      background: var(--bg-dark);
      z-index: 2000;
      flex-direction: column;
    }
    .order-overlay.open { display: flex; }

    .order-overlay-header {
      background: var(--navbar-bg);
      border-bottom: 1px solid var(--border);
      padding: 14px 20px;
      display: flex; align-items: center;
      justify-content: space-between;
      flex-wrap: wrap; gap: 10px;
      flex-shrink: 0;
    }

    .order-body {
      display: grid;
      grid-template-columns: 1fr 360px;
      flex: 1;
      overflow: hidden;
      gap: 0;
    }

    /* left menu pane */
    .menu-pane {
      overflow-y: auto;
      padding: 16px;
      border-right: 1px solid var(--border);
    }

    /* right cart pane */
    .cart-pane {
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: var(--bg-card);
    }
    .cart-pane-header {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      font-weight: 700; font-size: 0.95rem;
      flex-shrink: 0;
    }
    .cart-items {
      flex: 1;
      overflow-y: auto;
      padding: 8px 12px;
    }
    .cart-footer {
      flex-shrink: 0;
      border-top: 1px solid var(--border);
    }

    /* menu item row */
    .menu-item-row {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      margin-bottom: 8px;
      cursor: pointer;
      transition: var(--transition);
      background: var(--bg-card2);
    }
    .menu-item-row:hover,
    .menu-item-row:active {
      border-color: var(--primary);
      background: rgba(232,168,56,0.07);
    }
    .item-emoji {
      width: 42px; height: 42px;
      background: var(--bg-dark);
      border-radius: 8px;
      display: flex; align-items: center;
      justify-content: center;
      font-size: 1.3rem; flex-shrink: 0;
    }

    /* cart items */
    .cart-item {
      display: flex; align-items: center; gap: 8px;
      padding: 9px 4px;
      border-bottom: 1px solid var(--border);
    }
    .cart-item:last-child { border-bottom: none; }
    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name {
      font-size: 0.86rem; font-weight: 600;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .cart-item-sub { font-size: 0.75rem; color: var(--text-muted); }
    .qty-ctrl { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
    .qty-btn {
      width: 24px; height: 24px; border-radius: 50%;
      border: none; background: var(--bg-dark);
      color: var(--text-main); cursor: pointer; font-size: 0.95rem;
      display: flex; align-items: center; justify-content: center;
      transition: var(--transition);
    }
    .qty-btn:hover { background: var(--primary); color: #000; }
    .qty-val { min-width: 20px; text-align: center; font-weight: 700; font-size: 0.88rem; }

    /* summary */
    .cart-summary { padding: 12px 16px; background: var(--bg-card2); }
    .sum-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; color: var(--text-muted); }
    .sum-row.total { font-size: 1.05rem; font-weight: 800; color: var(--primary); border-top: 1px solid var(--border); padding-top: 8px; margin-top: 4px; }

    /* category tabs */
    .cat-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
    .cat-btn {
      padding: 6px 14px; border-radius: 20px;
      border: 1.5px solid var(--border);
      background: var(--bg-dark); color: var(--text-muted);
      cursor: pointer; font-size: 0.8rem; font-weight: 600;
      transition: var(--transition);
    }
    .cat-btn.active { border-color: var(--primary); color: var(--primary); background: rgba(232,168,56,0.1); }

    /* orders table items preview */
    .items-preview { font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; }

    @media (max-width: 768px) {
      .order-body { grid-template-columns: 1fr; }
      .cart-pane { max-height: 380px; }
    }
  </style>
</head>
<body class="pos-bg">
<?php include 'includes/navbar.php'; ?>

<div class="main-content">

 
 
 
 
 
 
 
 
 

  <?php if ($msg): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- Status Tabs -->
  <div class="report-tabs" style="max-width:520px; margin-bottom:20px;">
    <a href="?status=active"    class="report-tab <?= $filterStatus==='active'   ?'active':'' ?>">🔴 Active</a>
    <a href="?status=completed" class="report-tab <?= $filterStatus==='completed'?'active':'' ?>">✅ Completed</a>
    <a href="?status=cancelled" class="report-tab <?= $filterStatus==='cancelled'?'active':'' ?>">❌ Cancelled</a>
    <a href="?status=all"       class="report-tab <?= $filterStatus==='all'      ?'active':'' ?>">📋 All</a>
  </div>

  <!-- Orders Table -->
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th>Table</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th>Cashier</th>
            <th>Time</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="ordersTableBody">
          <?php if ($ordersList->num_rows === 0): ?>
            <tr>
              <td colspan="9" class="text-center text-muted" style="padding:50px;">
                <div style="font-size:2.5rem; margin-bottom:10px;">📋</div>
                No orders found.
              </td>
            </tr>
          <?php else: ?>
            <?php while ($o = $ordersList->fetch_assoc()):
              $badge = match($o['status']) {
                'completed' => 'badge-success',
                'cancelled' => 'badge-danger',
                'preparing' => 'badge-warning',
                'ready'     => 'badge-info',
                default     => 'badge-muted'
              };
              // Get order items for this row
              $oi = $conn->query("SELECT item_name, quantity FROM order_items WHERE order_id={$o['id']}");
              $itemLines = [];
              while ($r = $oi->fetch_assoc()) $itemLines[] = $r['quantity'].'× '.$r['item_name'];
            ?>
            <tr>
              <td data-label="Order #">
                <strong style="color:var(--primary);"><?= htmlspecialchars($o['order_number']) ?></strong>
              </td>
              <td data-label="Table">
                <span class="badge badge-muted">🪑 <?= htmlspecialchars($o['table_number']) ?></span>
              </td>
              <td data-label="Customer">
                <span>👤 <?= htmlspecialchars($o['customer_name']) ?></span>
              </td>
              <td data-label="Items">
                <span class="badge badge-primary"><?= $o['item_count'] ?> items</span>
                <div class="items-preview"><?= htmlspecialchars(implode(', ', array_slice($itemLines, 0, 3))) ?><?= count($itemLines)>3?' …':'' ?></div>
              </td>
              <td data-label="Total">
                <span class="text-primary fw-bold">Rs <?= number_format($o['total'],2) ?></span>
              </td>
              <td data-label="Status">
                <span class="badge <?= $badge ?>"><?= ucfirst($o['status']) ?></span>
              </td>
              <td data-label="Cashier" class="text-muted fs-sm"><?= htmlspecialchars($o['cashier_name']) ?></td>
              <td data-label="Time" class="text-muted fs-sm"><?= date('d/m H:i', strtotime($o['created_at'])) ?></td>
              <td data-label="Actions">
                <div class="d-flex gap-1">
                  <a href="/RestaurantPOS/billing.php?order_id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">💳 Bill</a>
                  <?php if (!in_array($o['status'], ['completed','cancelled'])): ?>
                    <form method="POST" onsubmit="return confirm('Cancel this order?')" style="display:inline;">
                      <input type="hidden" name="action"   value="cancel_order">
                      <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                      <button class="btn btn-danger btn-sm">✕</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.main-content -->

<!-- ══════════════════════════════════════════════════
     NEW ORDER FULL-SCREEN OVERLAY
══════════════════════════════════════════════════ -->
<div class="order-overlay" id="newOrderOverlay">

  <!-- Header bar -->
  <div class="order-overlay-header">
    <div class="d-flex align-center gap-2" style="flex-wrap:wrap; gap:8px;">
      <span style="font-size:1.1rem; font-weight:800; color:var(--primary);">🛒 New Order</span>

      <select id="orderTable" class="form-control" style="width:auto;">
        <option value="0">🪑 Takeaway</option>
        <?php
        $allTables->data_seek(0);
        while ($t = $allTables->fetch_assoc()):
          $icon = match($t['status']) { 'available'=>'🟢', 'occupied'=>'🔴', 'reserved'=>'🟡', default=>'' };
        ?>
          <option value="<?= $t['id'] ?>"><?= $icon ?> <?= htmlspecialchars($t['table_number']) ?> (<?= ucfirst($t['status']) ?>)</option>
        <?php endwhile; ?>
      </select>

      <select id="orderCustomer" class="form-control" style="width:auto;">
        <option value="0">👤 Walk-in</option>
        <?php
        $customers->data_seek(0);
        while ($c = $customers->fetch_assoc()):
        ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> <?= $c['phone'] ? '('.$c['phone'].')':'' ?></option>
        <?php endwhile; ?>
      </select>
    </div>

    <button class="btn btn-danger btn-sm" onclick="closeNewOrder()">✕ Close</button>
  </div>

  <!-- Body: menu left, cart right -->
  <div class="order-body">

    <!-- LEFT: Menu Items -->
    <div class="menu-pane">

      <!-- Search -->
      <div style="position:relative; margin-bottom:12px;">
        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted);">🔍</span>
        <input type="text" id="menuSearch" class="form-control" style="padding-left:36px;"
               placeholder="Search food or drink..." oninput="filterMenu(this.value)">
      </div>

      <!-- Category filter tabs -->
      <div class="cat-tabs" id="catTabs">
        <button class="cat-btn active" onclick="filterCat('all',this)">🍽️ All</button>
        <?php foreach ($categories as $cat): ?>
          <button class="cat-btn" onclick="filterCat('<?= $cat['id'] ?>',this)">
            <?= htmlspecialchars($cat['name']) ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- Menu items list -->
      <div id="menuList">
        <?php
        $emojis = ['🍔','🍕','🍜','🍣','🥗','🍰','☕','🥤','🍖','🥘','🌮','🍱','🥩','🍗','🍝'];
        foreach ($menuItems as $m):
          $emoji = $emojis[$m['id'] % count($emojis)];
        ?>
          <div class="menu-item-row"
               data-cat="<?= $m['category_id'] ?>"
               data-name="<?= strtolower(htmlspecialchars($m['name'])) ?>"
               onclick="addItem(<?= $m['id'] ?>, <?= htmlspecialchars(json_encode($m['name'])) ?>, <?= floatval($m['price']) ?>)">
            <div class="item-emoji"><?= $emoji ?></div>
            <div style="flex:1; min-width:0;">
              <div style="font-weight:600; font-size:0.88rem;"><?= htmlspecialchars($m['name']) ?></div>
              <div style="font-size:0.74rem; color:var(--text-muted);"><?= htmlspecialchars($m['cat_name'] ?? '') ?></div>
            </div>
            <div style="font-weight:700; color:var(--primary); white-space:nowrap;">
              Rs <?= number_format($m['price'],2) ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($menuItems)): ?>
          <div style="text-align:center; padding:40px; color:var(--text-muted);">
            No menu items found. <a href="/RestaurantPOS/menu.php" style="color:var(--primary);">Add items →</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Cart / Current Order -->
    <div class="cart-pane">

      <!-- Cart header -->
      <div class="cart-pane-header">
        🧾 Current Order
        <span id="cartCount" class="badge badge-primary" style="margin-left:8px;">0 items</span>
      </div>

      <!-- Selected table / customer info bar -->
      <div id="orderInfoBar" style="padding:8px 14px; background:rgba(232,168,56,0.07); border-bottom:1px solid var(--border); font-size:0.8rem; display:flex; gap:14px; flex-wrap:wrap;">
        <span id="infoTable" style="color:var(--text-muted);">🪑 Takeaway</span>
        <span id="infoCustomer" style="color:var(--text-muted);">👤 Walk-in</span>
      </div>

      <!-- Cart items -->
      <div class="cart-items" id="cartItems">
        <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
          <div style="font-size:2.5rem; margin-bottom:10px;">🛒</div>
          <p>Tap any food item to add</p>
        </div>
      </div>

      <!-- Notes -->
      <div style="padding:10px 14px; border-top:1px solid var(--border); flex-shrink:0;">
        <textarea id="orderNotes" class="form-control" rows="2"
                  style="resize:none; font-size:0.82rem;"
                  placeholder="📝 Special instructions (optional)..."></textarea>
      </div>

      <!-- Order summary + place button -->
      <div class="cart-summary">
        <div class="sum-row"><span>Subtotal</span><span id="sumSubtotal">Rs 0.00</span></div>
        <div class="sum-row"><span>Service Amount (6%)</span><span id="sumTax">Rs 0.00</span></div>
        <div class="sum-row total"><span>TOTAL</span><span id="sumTotal">Rs 0.00</span></div>
        <button id="placeBtn" class="btn btn-primary btn-block btn-lg" style="margin-top:10px;" onclick="placeOrder()">
          ✅ Place Order
        </button>
        <button class="btn btn-ghost btn-block" style="margin-top:6px; font-size:0.82rem;" onclick="clearCart()">
          🗑️ Clear All
        </button>
      </div>

    </div><!-- /.cart-pane -->
  </div><!-- /.order-body -->
</div><!-- /.order-overlay -->

<!-- Success Modal -->
<div class="modal-overlay" id="successModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-body text-center" style="padding:40px 30px;">
      <div style="font-size:4rem; margin-bottom:12px;">✅</div>
      <h2 style="margin-bottom:4px;">Order Placed!</h2>
      <p id="successNum"   class="text-muted"  style="font-size:1rem; margin:8px 0 4px;"></p>
      <p id="successTotal" class="text-primary fw-bold" style="font-size:1.5rem; margin-bottom:20px;"></p>
      <div class="d-flex gap-2" style="justify-content:center;">
        <a id="billBtn" href="#" class="btn btn-primary">💳 Generate Bill</a>
        <button class="btn btn-outline" onclick="anotherOrder()">➕ New Order</button>
        <button class="btn btn-ghost" onclick="doneOrdering()">✔ Done</button>
      </div>
    </div>
  </div>
</div>

<script>
// ─────────────────────────────────────────────────────────
// Cart state
// ─────────────────────────────────────────────────────────
let cart = {};   // { id: { id, name, price, qty } }

// ─────────────────────────────────────────────────────────
// Overlay open / close
// ─────────────────────────────────────────────────────────
function openNewOrder() {
  document.getElementById('newOrderOverlay').classList.add('open');
  document.getElementById('menuSearch').focus();
  updateInfoBar();
}
function closeNewOrder() {
  document.getElementById('newOrderOverlay').classList.remove('open');
}

// Update info bar when table/customer changes
document.getElementById('orderTable').addEventListener('change', updateInfoBar);
document.getElementById('orderCustomer').addEventListener('change', updateInfoBar);

function updateInfoBar() {
  const tSel = document.getElementById('orderTable');
  const cSel = document.getElementById('orderCustomer');
  const tText = tSel.options[tSel.selectedIndex].text;
  const cText = cSel.options[cSel.selectedIndex].text;
  document.getElementById('infoTable').textContent    = '🪑 ' + tText;
  document.getElementById('infoCustomer').textContent = '👤 ' + cText;
}

// ─────────────────────────────────────────────────────────
// Add / remove items
// ─────────────────────────────────────────────────────────
function addItem(id, name, price) {
  if (cart[id]) {
    cart[id].qty++;
  } else {
    cart[id] = { id: id, name: name, price: parseFloat(price), qty: 1 };
  }
  renderCart();
}

function changeQty(id, delta) {
  if (!cart[id]) return;
  cart[id].qty += delta;
  if (cart[id].qty <= 0) delete cart[id];
  renderCart();
}

function removeItem(id) {
  delete cart[id];
  renderCart();
}

function clearCart() {
  cart = {};
  renderCart();
}

// ─────────────────────────────────────────────────────────
// Render cart
// ─────────────────────────────────────────────────────────
function renderCart() {
  const container = document.getElementById('cartItems');
  const items     = Object.values(cart);

  if (items.length === 0) {
    container.innerHTML = `
      <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
        <div style="font-size:2.5rem; margin-bottom:10px;">🛒</div>
        <p>Tap any food item to add</p>
      </div>`;
    document.getElementById('cartCount').textContent = '0 items';
    updateSummary(0);
    return;
  }

  let html      = '';
  let subtotal  = 0;
  let totalQty  = 0;

  items.forEach(it => {
    const lineTotal = it.price * it.qty;
    subtotal  += lineTotal;
    totalQty  += it.qty;
    html += `
    <div class="cart-item">
      <div class="cart-item-info">
        <div class="cart-item-name" title="${escHtml(it.name)}">${escHtml(it.name)}</div>
        <div class="cart-item-sub">Rs ${it.price.toFixed(2)} × ${it.qty} = <b style="color:var(--primary)">Rs ${lineTotal.toFixed(2)}</b></div>
      </div>
      <div class="qty-ctrl">
        <button class="qty-btn" onclick="changeQty(${it.id}, -1)">−</button>
        <span class="qty-val">${it.qty}</span>
        <button class="qty-btn" onclick="changeQty(${it.id}, 1)">＋</button>
        <button class="qty-btn" onclick="removeItem(${it.id})" style="color:var(--danger); margin-left:2px;" title="Remove">🗑</button>
      </div>
    </div>`;
  });

  container.innerHTML = html;
  document.getElementById('cartCount').textContent = totalQty + (totalQty === 1 ? ' item' : ' items');
  updateSummary(subtotal);
}

function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function updateSummary(subtotal) {
  const tax   = Math.round(subtotal * 0.06 * 100) / 100;
  const total = Math.round((subtotal + tax) * 100) / 100;
  document.getElementById('sumSubtotal').textContent = 'Rs ' + subtotal.toFixed(2);
  document.getElementById('sumTax').textContent      = 'Rs ' + tax.toFixed(2);
  document.getElementById('sumTotal').textContent    = 'Rs ' + total.toFixed(2);
}

// ─────────────────────────────────────────────────────────
// Category filter
// ─────────────────────────────────────────────────────────
function filterCat(catId, btn) {
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.menu-item-row').forEach(row => {
    row.style.display = (catId === 'all' || row.dataset.cat === catId) ? '' : 'none';
  });
}

function filterMenu(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.menu-item-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
}

// ─────────────────────────────────────────────────────────
// Place Order
// ─────────────────────────────────────────────────────────
function placeOrder() {
  const items = Object.values(cart);
  if (items.length === 0) {
    alert('Please select at least one item.');
    return;
  }

  const btn = document.getElementById('placeBtn');
  btn.disabled  = true;
  btn.innerHTML = '⏳ Saving order...';

  const fd = new FormData();
  fd.append('action',      'place_order');
  fd.append('table_id',    document.getElementById('orderTable').value);
  fd.append('customer_id', document.getElementById('orderCustomer').value);
  fd.append('notes',       document.getElementById('orderNotes').value);
  fd.append('items',       JSON.stringify(items));

  fetch('/RestaurantPOS/ajax_order.php', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(text => {
      btn.disabled  = false;
      btn.innerHTML = '✅ Place Order';

      let data;
      try {
        data = JSON.parse(text);
      } catch(e) {
        console.error('Server raw response:', text);
        alert('Server error. Press F12 → Console tab to see details.');
        return;
      }

      if (data.success) {
        closeNewOrder();
        // Show success popup
        document.getElementById('successNum').textContent   = '🧾 ' + data.order_number;
        document.getElementById('successTotal').textContent = 'Rs ' + parseFloat(data.total).toFixed(2);
        document.getElementById('billBtn').href = '/RestaurantPOS/billing.php?order_id=' + data.order_id;
        document.getElementById('successModal').classList.add('open');
        clearCart();
        // Refresh orders table
        refreshOrdersTable();
      } else {
        alert('❌ ' + (data.message || 'Failed to place order.'));
      }
    })
    .catch(err => {
      btn.disabled  = false;
      btn.innerHTML = '✅ Place Order';
      console.error('Fetch error:', err);
      alert('Connection error.\n\nMake sure XAMPP Apache & MySQL are running.\n\n' + err.message);
    });
}

// ─────────────────────────────────────────────────────────
// After success
// ─────────────────────────────────────────────────────────
function anotherOrder() {
  document.getElementById('successModal').classList.remove('open');
  openNewOrder();
}
function doneOrdering() {
  document.getElementById('successModal').classList.remove('open');
  location.reload();
}

// ─────────────────────────────────────────────────────────
// Refresh orders list without full page reload
// ─────────────────────────────────────────────────────────
function refreshOrdersTable() {
  // Simple: just reload the page after 1s so new order shows up
  setTimeout(() => location.reload(), 800);
}
</script>
</body>
</html>
