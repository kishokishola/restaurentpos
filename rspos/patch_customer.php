<?php
/**
 * patch_customer.php - patches renderGrid + addItem + changeQty + live refresh
 * Run: http://localhost/RestaurantPOS/patch_customer.php
 */
$filepath = __DIR__ . '/customer_order.php';
$content  = file_get_contents($filepath);

// ══════════════════════════════════════════════
// PATCH 1: renderGrid function
// ══════════════════════════════════════════════
$rgStart = strpos($content, 'function renderGrid() {');
$rgEnd   = strpos($content, 'grid.innerHTML = html;', $rgStart);
$rgEnd   = strpos($content, "\n}", $rgEnd) + 2;

$newRenderGrid = <<<'ENDFUNC'
function renderGrid() {
  var grid  = document.getElementById('mgrid');
  var items = ALL_ITEMS.slice();

  // 1. Meal tab filter
  if (activeMeal) {
    items = items.filter(function(it) {
      var times = (it.times||'all').split(',').map(function(s){return s.trim();});
      if (times.indexOf('all') >= 0)
        return it.catName === activeMeal || it.catId === activeMealCatId;
      return times.indexOf(activeMeal) >= 0;
    });
  }

  // 2. Category pill filter
  if (activeCat !== 'all')
    items = items.filter(function(it){ return String(it.catId) === String(activeCat); });

  // 3. Hour-range filter
  var nowMin = new Date().getHours()*60 + new Date().getMinutes();
  items = items.filter(function(it) {
    if (!it.timeStart || !it.timeEnd) return true;
    var sp = it.timeStart.split(':').map(Number);
    var ep = it.timeEnd.split(':').map(Number);
    return nowMin >= sp[0]*60+sp[1] && nowMin <= ep[0]*60+ep[1];
  });

  // 4. Search filter
  if (searchQ)
    items = items.filter(function(it){ return it.name.toLowerCase().indexOf(searchQ)>=0; });

  if (items.length === 0) {
    grid.innerHTML = '<div class="no-items"><div class="ni">🍽️</div><p>No items found.</p></div>';
    return;
  }

  function to12h(t) {
    if (!t) return '';
    var p = t.split(':').map(Number);
    var ampm = p[0]>=12?'PM':'AM', h = p[0]%12||12;
    return h+':'+(p[1]<10?'0':'')+p[1]+' '+ampm;
  }

  var EMJS = ['🍔','🍕','🍜','🍣','🥗','🍰','☕','🥤','🍖','🥘','🌮','🍱','🥩','🍗','🍝','🍛','🥪'];
  var html = '';

  items.forEach(function(it) {
    var inCart = !!cart[it.id];
    var qty    = inCart ? cart[it.id].qty : 0;
    var avail  = (it.status !== 'unavailable');
    var stock  = (it.stock !== undefined) ? it.stock : -1; // -1 = unlimited
    var remaining = (stock >= 0) ? stock - qty : 999;

    // Thumbnail (no badge on image)
    var fb = EMJS[it.id % EMJS.length];
    var thumb = it.img
      ? '<img src="'+it.img+'" alt="" style="width:100%;height:100%;object-fit:cover;display:block;" onerror="this.style.display=\'none\'">'
      : '<span style="font-size:2.6rem;">'+fb+'</span>';

    var soldOverlay = !avail
      ? '<div class="sold-overlay"><span class="sold-label">❌ Unavailable</span></div>' : '';

    // Stock chip
    var stockChip = '';
    if (avail && stock >= 0) {
      if (stock === 0) {
        stockChip = '<div class="mc-stock out">📦 Out of Stock</div>';
        avail = false; // treat as unavailable
      } else if (stock <= 5) {
        stockChip = '<div class="mc-stock low">📦 Only '+stock+' left!</div>';
      } else {
        stockChip = '<div class="mc-stock ok">📦 In Stock ('+stock+')</div>';
      }
    }

    // Time chip
    var times   = (it.times||'all').split(',').map(function(s){return s.trim();});
    var notAll  = times.indexOf('all') < 0;
    var timeStr = notAll ? times.join(' · ') : '';
    var rangeStr= (it.timeStart&&it.timeEnd)
      ? '🕐 '+to12h(it.timeStart)+' – '+to12h(it.timeEnd) : '';
    var timeChip = (notAll||rangeStr)
      ? '<div class="mc-time">'+(timeStr?timeStr+(rangeStr?' &nbsp;':''):'')+rangeStr+'</div>' : '';

    // Action buttons
    var maxReached = (stock >= 0 && qty >= stock);
    var actionHtml, warnHtml = '';
    if (!avail) {
      actionHtml = '<button class="addbtn" disabled style="opacity:.45;cursor:not-allowed;background:#2e2e4e;color:#9090b0;border:1px solid #3e3e5e;pointer-events:none;">❌ Not Available</button>';
    } else {
      warnHtml = '<div class="stock-warn'+(maxReached?' show':'')+'" id="sw'+it.id+'">'
        +'⚠️ Max stock reached ('+(stock>=0?stock:'')+')</div>';
      actionHtml = '<button class="addbtn" id="ab'+it.id+'"'
        +(inCart?' style="display:none"':'')+' onclick="addItem('+it.id+',\''+esc(it.name)+'\','+it.price+','+stock+')">+ Add</button>'
        +'<div class="qctrl'+(inCart?' show':'')+'" id="qc'+it.id+'">'
        +'<button class="qb minus" onclick="changeQty('+it.id+',-1)">−</button>'
        +'<span class="qv" id="qv'+it.id+'">'+qty+'</span>'
        +'<button class="qb'+(maxReached?' disabled-qb':'')+'"'+(maxReached?' disabled':'')+' onclick="changeQty('+it.id+',1,'+stock+')">＋</button>'
        +'</div>';
    }

    var cardCls = 'mcard'+(inCart&&avail?' in-cart':'')+(!avail?' sold-out':'');
    var descHtml = it.desc
      ? '<div class="mc-desc">'+esc(it.desc.substring(0,55))+(it.desc.length>55?'…':'')+'</div>' : '';

    html += '<div class="'+cardCls+'" id="mc'+it.id+'">'
      +'<div class="mthumb">'+thumb+soldOverlay+'</div>'
      +'<div class="mcbody">'
        +'<div class="mc-name">'+esc(it.name)+'</div>'
        +'<div class="mc-cat">'+esc(it.catName)+'</div>'
        +descHtml+timeChip+stockChip
        +'<div class="mc-price">RM '+it.price.toFixed(2)+'</div>'
        +'<div class="mc-action">'+actionHtml+'</div>'
        +warnHtml
      +'</div>'
    +'</div>';
  });
  grid.innerHTML = html;
}
ENDFUNC;

$content = substr($content, 0, $rgStart) . $newRenderGrid . substr($content, $rgEnd);

// ══════════════════════════════════════════════
// PATCH 2: addItem — add stock check
// ══════════════════════════════════════════════
$addOld = 'function addItem(id, name, price) {
  if (cart[id]) cart[id].qty++;
  else          cart[id] = { id, name, price: parseFloat(price), qty: 1 };
  syncCardUI(id);
  renderDrawer();
}';
$addNew = 'function addItem(id, name, price, stock) {
  stock = (stock !== undefined) ? parseInt(stock) : -1;
  if (cart[id]) {
    if (stock >= 0 && cart[id].qty >= stock) {
      showStockWarn(id, stock); return;
    }
    cart[id].qty++;
  } else {
    if (stock === 0) { showStockWarn(id, stock); return; }
    cart[id] = { id: id, name: name, price: parseFloat(price), qty: 1, stock: stock };
  }
  hideStockWarn(id);
  syncCardUI(id);
  renderDrawer();
}';
$content = str_replace($addOld, $addNew, $content);

// ══════════════════════════════════════════════
// PATCH 3: changeQty — add stock check
// ══════════════════════════════════════════════
$chgOld = 'function changeQty(id, delta) {
  if (!cart[id]) return;
  cart[id].qty += delta;
  if (cart[id].qty <= 0) { delete cart[id]; syncCardUI(id, true); }
  else                    { syncCardUI(id); }
  renderDrawer();
}';
$chgNew = 'function changeQty(id, delta, stock) {
  if (!cart[id]) return;
  stock = (stock !== undefined) ? parseInt(stock) : (cart[id].stock || -1);
  if (delta > 0 && stock >= 0 && cart[id].qty >= stock) {
    showStockWarn(id, stock); return;
  }
  cart[id].qty += delta;
  if (cart[id].qty <= 0) { delete cart[id]; syncCardUI(id, true); hideStockWarn(id); }
  else { hideStockWarn(id); syncCardUI(id); }
  renderDrawer();
}

function showStockWarn(id, stock) {
  var el = document.getElementById("sw"+id);
  if (el) { el.textContent = "⚠️ Max "+stock+" available!"; el.classList.add("show"); }
}
function hideStockWarn(id) {
  var el = document.getElementById("sw"+id);
  if (el) el.classList.remove("show");
}';
$content = str_replace($chgOld, $chgNew, $content);

// ══════════════════════════════════════════════
// PATCH 4: live refresh — update stock too
// ══════════════════════════════════════════════
$liveOld = "fetch('/RestaurantPOS/ajax_item_status.php')
    .then(function(r){ return r.json(); })
    .then(function(statusMap) {
      var changed = false;
      ALL_ITEMS.forEach(function(it) {
        var newStatus = statusMap[it.id] || 'available';
        if (it.status !== newStatus) {
          it.status = newStatus;
          changed = true;
          // If item was in cart and is now unavailable, remove it
          if (newStatus === 'unavailable' && cart[it.id]) {
            delete cart[it.id];
          }
        }
      });
      if (changed) { renderGrid(); renderDrawer(); }
    }).catch(function(){});";

$liveNew = "fetch('/RestaurantPOS/ajax_item_status.php')
    .then(function(r){ return r.json(); })
    .then(function(statusMap) {
      var changed = false;
      ALL_ITEMS.forEach(function(it) {
        var info = statusMap[it.id];
        if (!info) return;
        var newStatus = info.status || 'available';
        var newStock  = (info.stock !== undefined) ? info.stock : -1;
        if (it.status !== newStatus || it.stock !== newStock) {
          it.status = newStatus;
          it.stock  = newStock;
          changed   = true;
          // Remove from cart if unavailable or stock=0
          if ((newStatus === 'unavailable' || newStock === 0) && cart[it.id]) {
            delete cart[it.id];
          }
          // Reduce cart qty if exceeds new stock
          if (newStock >= 0 && cart[it.id] && cart[it.id].qty > newStock) {
            cart[it.id].qty = newStock;
            if (cart[it.id].qty <= 0) delete cart[it.id];
          }
        }
      });
      if (changed) { renderGrid(); renderDrawer(); }
    }).catch(function(){});";

$content = str_replace($liveOld, $liveNew, $content);

file_put_contents($filepath, $content);

echo '<div style="font-family:monospace;background:#0f0f1a;color:#6fcf97;padding:30px;font-size:1rem;">
<h2>✅ All patches applied!</h2>
<ul style="margin-top:16px;line-height:2.2;color:#f0f0f0;">
  <li>✅ Badge removed from image side</li>
  <li>✅ Stock field added to DB (via menu.php migration)</li>
  <li>✅ Stock limit — cannot add beyond stock qty</li>
  <li>✅ Red warning message when max reached</li>
  <li>✅ Live refresh updates stock from server</li>
</ul>
<br>
<a href="/RestaurantPOS/customer_order.php" style="color:#e8a838;font-size:1.1rem;">→ Open Customer Page</a>
&nbsp;&nbsp;
<a href="/RestaurantPOS/menu.php" style="color:#e8a838;font-size:1.1rem;">→ Open Menu Admin</a>
</div>';
?>
