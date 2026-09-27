<?php
require_once __DIR__ . '/auth.php';
requireLogin();
require_once __DIR__ . '/../db_config.php';
$user        = currentUser();
$role        = $user['role'];
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Role-based nav links
$adminNav = [
    ['dashboard.php',  '📊', 'Dashboard'],
    ['expenses.php',   '💰', 'Expenses'],
    ['orders.php',     '🛒', 'Orders'],
    ['menu.php',       '🍽️', 'Menu'],
    ['tables.php',     '🪑', 'Tables'],
    ['kitchen_display.php', '👨‍🍳','Kitchen'],
    ['billing.php',    '💳', 'Billing'],
    ['inventory.php',  '📦', 'Inventory'],
    ['reports.php',    '📈', 'Reports'],
    ['customers.php',  '👥', 'Customers'],
];
$cashierNav = [
    ['cashier.php',         '🏠', 'Home'],
    ['customer_order.php',  '🛒', 'New Order'],
    ['billing.php',         '💳', 'Billing'],
    ['tables.php',          '🪑', 'Tables'],
    ['orders.php',          '📋', 'My Orders'],
];
$kitchenNav = [
    ['kitchen_display.php', '🏠', 'Kitchen'],
    ['kitchen.php',         '📋', 'Order List'],
];

$navItems = match($role) {
    'admin'   => $adminNav,
    'cashier' => $cashierNav,
    'kitchen' => $kitchenNav,
    default   => $adminNav,
};

// Only kitchen staff receive new-order notifications.
$notificationStatus = 'pending';
$notificationTarget = 'kitchen_display.php';
$pendingCount = 0;
try {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='" . $conn->real_escape_string($notificationStatus) . "'");
    if ($res) $pendingCount = (int)$res->fetch_assoc()['cnt'];
} catch(Exception $e) {}

// Role badge colors
$roleBadge = match($role) {
    'admin'   => ['bg'=>'#e8a838','color'=>'#000','label'=>'Admin'],
    'cashier' => ['bg'=>'#17a2b8','color'=>'#fff','label'=>'Cashier'],
    'kitchen' => ['bg'=>'#28a745','color'=>'#fff','label'=>'Kitchen'],
    default   => ['bg'=>'#9090b0','color'=>'#fff','label'=>ucfirst($role)],
};
?>








<style>
/* ═══════════════ NAVBAR ═══════════════ */
.top-navbar{
  position:fixed;top:0;left:0;right:0;height:62px;
  background:#141428;border-bottom:1px solid #2e2e4e;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 20px;z-index:1000;
  box-shadow:0 2px 16px rgba(0,0,0,.45);
}
/* hide old desktop nav */
.top-navbar .navbar-nav{display:none!important;}

.nb-left{display:flex;align-items:center;gap:12px;flex-shrink:0;}
.nb-ham{
  display:flex;flex-direction:column;justify-content:center;gap:5px;
  width:38px;height:38px;
  background:rgba(255,255,255,.06);border:1.5px solid #2e2e4e;
  border-radius:8px;cursor:pointer;padding:8px 9px;transition:all .2s;
  flex-shrink:0;
}
.nb-ham:hover{background:rgba(232,168,56,.15);border-color:#e8a838;}
.nb-ham span{display:block;height:2px;background:#f0f0f0;border-radius:2px;transition:all .25s;width:100%;}
.nb-ham.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.nb-ham.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.nb-ham.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}

.nb-brand{display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0;}
.nb-logo{width:40px;height:40px;border-radius:50%;object-fit:cover;
          border:2px solid rgba(232,168,56,.4);box-shadow:0 0 10px rgba(232,168,56,.2);}
.nb-name{font-size:1.15rem;font-weight:800;color:#e8a838;letter-spacing:-.3px;white-space:nowrap;}

.nb-right{display:flex;align-items:center;gap:10px;flex-shrink:0;}

/* Role badge */
.nb-role{
  padding:4px 11px;border-radius:20px;font-size:.7rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.8px;flex-shrink:0;
  background:<?= $roleBadge['bg'] ?>;color:<?= $roleBadge['color'] ?>;
}

/* Bell */
@keyframes bellBlink{
  0%,100%{box-shadow:0 0 0 0 rgba(232,168,56,0);background:rgba(232,168,56,.1);}
  50%{box-shadow:0 0 0 7px rgba(232,168,56,.2);background:rgba(232,168,56,.25);}
}
.nb-bell{
  position:relative;width:38px;height:38px;
  background:rgba(255,255,255,.06);border:1.5px solid #2e2e4e;
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;cursor:pointer;text-decoration:none;
  transition:all .2s;color:#f0f0f0;flex-shrink:0;
}
.nb-bell:hover{background:rgba(232,168,56,.15);border-color:#e8a838;}
.nb-bell.ringing{border-color:#e8a838;animation:bellBlink 1.3s ease infinite;}
.nb-bell-dot{
  position:absolute;top:5px;right:5px;
  width:9px;height:9px;background:#dc3545;
  border-radius:50%;border:2px solid #141428;display:none;
}
.nb-bell.ringing .nb-bell-dot{display:block;}
.nb-bell-n{
  position:absolute;top:-5px;right:-5px;
  background:#dc3545;color:#fff;font-size:.6rem;font-weight:800;
  min-width:18px;height:18px;border-radius:9px;
  border:2px solid #141428;display:none;
  align-items:center;justify-content:center;padding:0 3px;
}
.nb-bell.ringing .nb-bell-n{display:flex;}

/* Avatar */
.nb-av{
  width:36px;height:36px;
  background:linear-gradient(135deg,#e8a838,#c8891e);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.85rem;color:#000;
  border:2px solid rgba(232,168,56,.3);flex-shrink:0;cursor:default;
}
/* Logout */
.nb-out{
  display:flex;align-items:center;gap:5px;
  padding:7px 13px;background:rgba(220,53,69,.1);
  border:1.5px solid rgba(220,53,69,.3);border-radius:8px;
  color:#ff8091;font-size:.8rem;font-weight:700;
  text-decoration:none;transition:all .2s;white-space:nowrap;flex-shrink:0;
}
.nb-out:hover{background:rgba(220,53,69,.25);border-color:#dc3545;color:#fff;}

/* Fullscreen Toggle */
.nb-fs{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 13px;background:rgba(232,168,56,.12);
  border:1.5px solid rgba(232,168,56,.35);border-radius:8px;
  color:#e8a838;font-size:.8rem;font-weight:700;
  cursor:pointer;transition:all .2s;white-space:nowrap;flex-shrink:0;
}
.nb-fs:hover{background:rgba(232,168,56,.28);border-color:#e8a838;}
.nb-fs.active{
  background:rgba(220,53,69,.18)!important;
  border-color:rgba(220,53,69,.45)!important;
  color:#ff8091!important;
}
.nb-fs.active:hover{
  background:rgba(220,53,69,.32)!important;
  border-color:#dc3545!important;
  color:#fff!important;
}

/* ═══════════════ DROPDOWN ═══════════════ */
#nbDrop{
  position:fixed;top:62px;left:0;width:250px;
  background:#181832;border-right:1px solid #2e2e4e;
  border-bottom:1px solid #2e2e4e;border-radius:0 0 16px 0;
  box-shadow:6px 8px 30px rgba(0,0,0,.55);z-index:999;overflow:hidden;
  max-height:0;opacity:0;pointer-events:none;
  transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .25s ease;
}
#nbDrop.open{max-height:700px;opacity:1;pointer-events:all;}

.dd-user{
  display:flex;align-items:center;gap:10px;
  padding:14px 16px;border-bottom:1px solid #2e2e4e;
}
.dd-uav{
  width:38px;height:38px;
  background:linear-gradient(135deg,#e8a838,#c8891e);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.88rem;color:#000;flex-shrink:0;
}
.dd-uname{font-size:.88rem;font-weight:700;color:#f0f0f0;}
.dd-urole{
  display:inline-block;margin-top:3px;
  font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;
  padding:2px 8px;border-radius:10px;
  background:<?= $roleBadge['bg'] ?>;color:<?= $roleBadge['color'] ?>;
}

.dd-section{
  padding:6px 10px 2px;
  font-size:.65rem;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:#4a4a6a;
}
.dd-link{
  display:flex;align-items:center;gap:12px;
  padding:10px 14px;border-radius:9px;margin:1px 6px;
  color:#9090b0;text-decoration:none;
  font-size:.88rem;font-weight:600;transition:all .18s;
}
.dd-link:hover,.dd-link.active{background:rgba(232,168,56,.12);color:#e8a838;}
.dd-link.active{border-left:3px solid #e8a838;padding-left:11px;}
.dd-ico{font-size:1.1rem;width:22px;text-align:center;flex-shrink:0;}
.dd-badge{
  margin-left:auto;background:#dc3545;color:#fff;
  font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:10px;
}

/* Divider in dropdown */
.dd-div{height:1px;background:#2e2e4e;margin:6px 12px;}

/* ── main content spacing ── */
.main-content{margin-top:62px!important;padding:24px!important;}

/* ── Overlay behind dropdown ── */
#nbOv{
  display:none;position:fixed;inset:0;top:62px;
  z-index:998;background:transparent;
}
#nbOv.open{display:block;}
</style>










<!-- ══════════ NAVBAR HTML ══════════ -->
<nav class="top-navbar">
  <div class="nb-left">
    <button class="nb-ham" id="nbHam" onclick="nbToggle()">
      <span></span><span></span><span></span>
    </button>
    <a href="/RestaurantPOS/<?= match($role) {
      'kitchen' => 'kitchen_display.php',
      'cashier' => 'cashier.php',
      default   => 'dashboard.php'
    } ?>" class="nb-brand">
      <img src="/RestaurantPOS/loo.JPG" class="nb-logo" alt="Logo">
      <span class="nb-name">ServePoint</span>
    </a>
    <span class="nb-role"><?= $roleBadge['label'] ?></span>
  </div>





  <div class="nb-right">
    <!-- Bell — always visible on kitchen screens for the cook -->
    <?php if ($role === 'kitchen' || in_array($currentPage, ['kitchen', 'kitchen_display'], true)): ?>
    <a href="/RestaurantPOS/<?= $notificationTarget ?>" class="nb-bell <?= $pendingCount > 0 ? 'ringing' : '' ?>" id="nbBell" title="New kitchen orders" aria-label="New kitchen orders">
      🔔
      <span class="nb-bell-dot"></span>
      <span class="nb-bell-n" id="nbBellN"><?= $pendingCount > 0 ? $pendingCount : '' ?></span>
    </a>
    <?php endif; ?>
    <button type="button" class="nb-fs" id="nbFsBtn" onclick="posToggleFullscreen()" title="Toggle Full Screen (Hides Chrome Search & Nav Bar)">
      <span id="nbFsIcon">⛶</span> Fullscreen
    </button>
    <div class="nb-av"><?= htmlspecialchars($user['initials']) ?></div>
    <a href="/RestaurantPOS/logout.php" class="nb-out">⏻ Logout</a>
  </div>
</nav>

<div id="nbOv" onclick="nbClose()"></div>





<!-- ══════════ DROPDOWN ══════════ -->
<div id="nbDrop">
  <div class="dd-user">
    <div class="dd-uav"><?= htmlspecialchars($user['initials']) ?></div>
    <div>
      <div class="dd-uname"><?= htmlspecialchars($user['name']) ?></div>
      <span class="dd-urole"><?= $roleBadge['label'] ?></span>
    </div>
  </div>



  <div style="padding:6px 0 8px;">
    <?php foreach ($navItems as $item):
      $pg     = basename($item[0], '.php');
      $active = $currentPage === $pg ? 'active' : '';
    ?>
      <a href="/RestaurantPOS/<?= $item[0] ?>" class="dd-link <?= $active ?>" onclick="nbClose()">
        <span class="dd-ico"><?= $item[1] ?></span>
        <span><?= $item[2] ?></span>
        <?php if ($item[0] === $notificationTarget && $pendingCount > 0): ?>
          <span class="dd-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>

    <?php if ($role === 'admin'): ?>
    <div class="dd-div"></div>
    <a href="/RestaurantPOS/expenses.php" class="dd-link <?= $currentPage==='expenses'?'active':'' ?>" onclick="nbClose()">
      <span class="dd-ico">💰</span><span>Expenses & Profit</span>
    </a>
    <?php endif; ?>
  </div>
</div>




<script>
function nbToggle(){
  var d=document.getElementById('nbDrop'),
      o=document.getElementById('nbOv'),
      h=document.getElementById('nbHam'),
      op=d.classList.contains('open');
  d.classList.toggle('open',!op);
  o.classList.toggle('open',!op);
  h.classList.toggle('open',!op);
}
function nbClose(){
  document.getElementById('nbDrop').classList.remove('open');
  document.getElementById('nbOv').classList.remove('open');
  document.getElementById('nbHam').classList.remove('open');
}
// Kitchen bell poller — updates promptly when a customer submits an order.
(function(){
  function poll(){
    fetch('/RestaurantPOS/ajax_bell.php')
      .then(r=>r.json()).then(d=>{
        var b=document.getElementById('nbBell'),
            n=document.getElementById('nbBellN');
        if(!b)return;
        var c=parseInt(d.pending)||0;
        b.classList.toggle('ringing',c>0);
        if(n){n.textContent=c>0?c:'';n.style.display=c>0?'flex':'none';}
      }).catch(()=>{});
  }
  poll();setInterval(poll,3000);
})();
</script>
<script src="/RestaurantPOS/assets/js/pos_fullscreen.js"></script>
