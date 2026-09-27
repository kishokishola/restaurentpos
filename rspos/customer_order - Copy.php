<?php
/**
 * customer_order.php — Customer self-ordering page
 */
error_reporting(0);
ini_set('display_errors','0');
require_once __DIR__ . '/db_config.php';

@$conn->query("UPDATE menu_items SET status='available' WHERE status='' OR status IS NULL");

// Add any missing columns
$existing = [];
$cr = $conn->query("SHOW COLUMNS FROM menu_items");
while($r = $cr->fetch_assoc()) $existing[] = $r['Field'];
$add = [
  'available_times' => "ALTER TABLE menu_items ADD COLUMN available_times VARCHAR(255) DEFAULT 'all'",
  'time_start'      => "ALTER TABLE menu_items ADD COLUMN time_start TIME DEFAULT NULL",
  'time_end'        => "ALTER TABLE menu_items ADD COLUMN time_end TIME DEFAULT NULL",
  'sort_order'      => "ALTER TABLE menu_items ADD COLUMN sort_order INT DEFAULT 0",
  'stock'           => "ALTER TABLE menu_items ADD COLUMN stock INT DEFAULT -1",
];
foreach($add as $col => $sql){ if(!in_array($col,$existing)) @$conn->query($sql); }

// Fetch tables
$tables = [];
$tq = $conn->query("SELECT id,table_number,status FROM restaurant_tables ORDER BY table_number");
while($t = $tq->fetch_assoc()) $tables[] = $t;

// Fetch categories
$cats = [];
$cq = $conn->query("SELECT id,name FROM categories WHERE status='active' ORDER BY name");
while($c = $cq->fetch_assoc()) $cats[] = $c;

// Fetch items
$hasTimes = in_array('available_times',$existing);
$hasRange = in_array('time_start',$existing);
$hasStock = in_array('stock',$existing);
$hasSort  = in_array('sort_order',$existing);

$sel  = "m.id,m.name,m.description,m.price,m.category_id,m.image,m.status,c.name AS cat_name";
if($hasTimes) $sel .= ",COALESCE(m.available_times,'all') as available_times";
if($hasRange) $sel .= ",m.time_start,m.time_end";
if($hasStock) $sel .= ",COALESCE(m.stock,-1) as stock";
$ord = $hasSort ? "COALESCE(m.sort_order,0) ASC,m.name" : "m.name";

$items = [];
$iq = $conn->query("SELECT $sel FROM menu_items m LEFT JOIN categories c ON m.category_id=c.id WHERE m.status IN ('available','unavailable') ORDER BY $ord");
while($m = $iq->fetch_assoc()) $items[] = $m;

$jsItems = json_encode(array_map(function($m) use($hasTimes,$hasRange,$hasStock){
  $img = '';
  if(!empty($m['image'])){
    $fp = __DIR__.'/assets/uploads/'.$m['image'];
    if(file_exists($fp)) $img = '/RestaurantPOS/assets/uploads/'.$m['image'];
  }
  return [
    'id'        => (int)$m['id'],
    'name'      => $m['name'],
    'desc'      => $m['description'] ?? '',
    'price'     => (float)$m['price'],
    'catId'     => (int)$m['category_id'],
    'catName'   => $m['cat_name'] ?? '',
    'img'       => $img,
    'status'    => $m['status'] ?? 'available',
    'times'     => $hasTimes ? ($m['available_times'] ?? 'all') : 'all',
    'timeStart' => $hasRange ? ($m['time_start'] ?? '') : '',
    'timeEnd'   => $hasRange ? ($m['time_end'] ?? '') : '',
    'stock'     => $hasStock ? (int)($m['stock'] ?? -1) : -1,
  ];
}, $items), JSON_UNESCAPED_UNICODE);

$jsCats = json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>$c['name']], $cats), JSON_UNESCAPED_UNICODE);

// Meal time tabs — fixed set, HTML entity icons
$mealTabs = [
  ['name'=>'Morning Breakfast','icon'=>'&#127749;','label'=>'Morning',  'start'=>'05:00','end'=>'11:00'],
  ['name'=>'Lunch',            'icon'=>'&#9728;',  'label'=>'Lunch',    'start'=>'11:00','end'=>'15:00'],
  ['name'=>'Snacks',           'icon'=>'&#127871;','label'=>'Snacks',   'start'=>'15:00','end'=>'18:00'],
  ['name'=>'Coffee Time',      'icon'=>'&#9749;',  'label'=>'Coffee',   'start'=>'15:00','end'=>'19:00'],
  ['name'=>'Dinner',           'icon'=>'&#127769;','label'=>'Dinner',   'start'=>'18:00','end'=>'23:59'],
  ['name'=>'Ice Creams',       'icon'=>'&#127846;','label'=>'Ice Cream','start'=>'00:00','end'=>'23:59'],
  ['name'=>'Cool Drinks',      'icon'=>'&#129380;','label'=>'Drinks',   'start'=>'00:00','end'=>'23:59'],
];
$jsMealTabs = json_encode($mealTabs, JSON_UNESCAPED_UNICODE);
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
<title>Order Food &#8212; RestoPOS</title>
<style>
:root{--p:#e8a838;--pd:#c8891e;--pl:#f5c96a;--bg:#0f0f1a;--c:#1e1e30;--c2:#252540;--n:#141428;--t:#f0f0f0;--m:#9090b0;--b:#2e2e4e;--ok:#28a745;--er:#dc3545;--w:#ffc107;--r:12px;--rs:8px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:var(--bg);color:var(--t);min-height:100vh}
a{text-decoration:none;color:inherit}
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:var(--bg)}::-webkit-scrollbar-thumb{background:var(--pd);border-radius:10px}
.topbar{position:fixed;top:0;left:0;right:0;height:58px;background:var(--n);border-bottom:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;padding:0 18px;z-index:300;box-shadow:0 2px 12px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:9px;font-size:1.15rem;font-weight:800;color:var(--p)}
.brand-ico{width:34px;height:34px;background:linear-gradient(135deg,var(--p),var(--pd));border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.cbtn{position:relative;background:var(--p);color:#000;border:none;border-radius:var(--rs);padding:9px 18px;font-size:.88rem;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:7px;transition:all .2s}
.cbtn:hover{background:var(--pl)}
.cbadge{position:absolute;top:-7px;right:-7px;background:var(--er);color:#fff;border-radius:50%;width:20px;height:20px;font-size:.65rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid var(--n)}
.page{margin-top:58px;padding:18px 16px 100px;width:100%;max-width:none}
.welcome{background:linear-gradient(135deg,var(--c),var(--c2));border:1px solid var(--b);border-radius:var(--r);padding:20px 22px;margin-bottom:18px;text-align:center;position:relative;overflow:hidden}
.welcome h1{font-size:1.35rem;font-weight:800;margin-bottom:4px}.welcome h1 span{color:var(--p)}.welcome p{color:var(--m);font-size:.84rem}
.setup{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:18px}
.fld label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--m);margin-bottom:5px}
.fld select,.fld input{width:100%;padding:9px 13px;background:var(--c);border:1.5px solid var(--b);border-radius:var(--rs);color:var(--t);font-size:.88rem;outline:none;transition:all .2s}
.fld select:focus,.fld input:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(232,168,56,.12)}
.fld select option{background:var(--c)}
.sec{font-size:.9rem;font-weight:700;color:var(--t);margin-bottom:10px;display:flex;align-items:center;gap:7px}.sec span{color:var(--p)}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tab{display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 16px;border-radius:var(--rs);border:2px solid var(--b);background:var(--c);color:var(--m);cursor:pointer;font-size:.78rem;font-weight:700;min-width:70px;transition:all .2s;user-select:none}
.tab .ico{font-size:1.5rem}.tab:hover{border-color:var(--p);color:var(--p);background:rgba(232,168,56,.07)}.tab.active{border-color:var(--p);background:rgba(232,168,56,.13);color:var(--p)}
.pills{display:flex;gap:6px;overflow-x:auto;padding-bottom:5px;margin-bottom:12px;scrollbar-width:none}.pills::-webkit-scrollbar{display:none}
.pill{padding:5px 15px;border-radius:20px;border:1.5px solid var(--b);background:var(--bg);color:var(--m);font-size:.76rem;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all .2s}.pill.active{border-color:var(--p);color:var(--p);background:rgba(232,168,56,.1)}
.sw{position:relative;margin-bottom:12px}.sw input{width:100%;padding:9px 13px 9px 36px;background:var(--c);border:1.5px solid var(--b);border-radius:var(--rs);color:var(--t);font-size:.88rem;outline:none;transition:all .2s}.sw input:focus{border-color:var(--p)}.sw input::placeholder{color:var(--m)}.sw-ico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--m);pointer-events:none}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:12px}
.mc{background:var(--c);border:1.5px solid var(--b);border-radius:var(--r);overflow:hidden;display:flex;flex-direction:column;transition:all .2s}
.mc:hover{border-color:var(--p);transform:translateY(-2px);box-shadow:0 6px 20px rgba(232,168,56,.12)}.mc.ic{border-color:var(--p);background:rgba(232,168,56,.04)}.mc.so{opacity:.6}
.mth{height:120px;background:var(--c2);display:flex;align-items:center;justify-content:center;font-size:2.6rem;position:relative;overflow:hidden;flex-shrink:0}
.mth img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s}.mc:hover .mth img{transform:scale(1.06)}
.sdot{position:absolute;bottom:7px;right:7px;width:10px;height:10px;border-radius:50%;border:2px solid var(--c2);z-index:3}
.sdot.g{background:var(--ok);box-shadow:0 0 5px rgba(40,167,69,.7)}.sdot.r{background:var(--er);box-shadow:0 0 5px rgba(220,53,69,.7)}
.sov{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:4}
.slbl{background:rgba(220,53,69,.85);color:#fff;font-size:.72rem;font-weight:800;padding:4px 12px;border-radius:20px}
.mb{padding:9px 11px 11px;flex:1;display:flex;flex-direction:column}
.mn{font-size:.84rem;font-weight:700;margin-bottom:2px;line-height:1.25}.mca{font-size:.7rem;color:var(--m);margin-bottom:4px}.md{font-size:.7rem;color:var(--m);margin-bottom:5px;line-height:1.35;flex:1}
.mp{font-size:.98rem;font-weight:800;color:var(--p);margin-bottom:6px}
.mt{font-size:.67rem;font-weight:700;color:var(--p);background:rgba(232,168,56,.1);border:1px solid rgba(232,168,56,.25);border-radius:20px;padding:2px 8px;display:inline-block;margin-bottom:5px}
.ms{font-size:.68rem;font-weight:700;padding:2px 9px;border-radius:20px;display:inline-block;margin-bottom:5px}
.ms.ok{background:rgba(40,167,69,.12);color:var(--ok);border:1px solid rgba(40,167,69,.25)}.ms.low{background:rgba(255,193,7,.14);color:var(--w);border:1px solid rgba(255,193,7,.3)}.ms.out{background:rgba(220,53,69,.14);color:var(--er);border:1px solid rgba(220,53,69,.3)}
.sw2{font-size:.72rem;color:var(--er);font-weight:700;text-align:center;margin-top:4px;display:none;padding:3px 6px;background:rgba(220,53,69,.08);border-radius:6px;border:1px solid rgba(220,53,69,.2)}.sw2.on{display:block}
.ma{display:flex;align-items:center;gap:5px}
.ab{flex:1;padding:7px 4px;background:var(--p);color:#000;border:none;border-radius:var(--rs);font-size:.8rem;font-weight:800;cursor:pointer;transition:all .2s}.ab:hover{background:var(--pl)}.ab:disabled{opacity:.4;cursor:not-allowed;background:#2e2e4e;color:var(--m);pointer-events:none}
.qc{display:none;align-items:center;gap:4px;flex:1}.qc.on{display:flex}
.qb{width:28px;height:28px;border-radius:50%;border:none;background:var(--bg);color:var(--t);font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}.qb:hover{background:var(--p);color:#000}.qb.mn2:hover{background:var(--er);color:#fff}.qb:disabled{opacity:.3;cursor:not-allowed;pointer-events:none}.qv{flex:1;text-align:center;font-size:.92rem;font-weight:800}
.ni{grid-column:1/-1;text-align:center;padding:50px 20px;color:var(--m)}.ni .e{font-size:2.8rem;margin-bottom:10px}
.cov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:400}.cov.on{display:block}
.cd{position:fixed;top:0;right:0;bottom:0;width:350px;max-width:96vw;background:var(--c);border-left:1px solid var(--b);z-index:401;display:flex;flex-direction:column;transform:translateX(110%);transition:transform .28s ease;box-shadow:-4px 0 30px rgba(0,0,0,.5)}.cd.on{transform:translateX(0)}
.dh{padding:16px 18px;border-bottom:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.dt{font-size:.97rem;font-weight:800}.dc{background:none;border:none;color:var(--m);font-size:1.3rem;cursor:pointer;transition:all .2s;padding:2px 7px;border-radius:var(--rs);line-height:1}.dc:hover{color:var(--er);background:rgba(220,53,69,.1)}
.di{flex:1;overflow-y:auto;padding:8px 14px}
.de{text-align:center;padding:50px 16px;color:var(--m)}
.ditem{display:flex;align-items:center;gap:9px;padding:9px 0;border-bottom:1px solid var(--b)}.ditem:last-child{border-bottom:none}
.din{flex:1;min-width:0}.din-n{font-size:.86rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.din-s{font-size:.73rem;color:var(--m);margin-top:1px}.din-t{font-size:.8rem;font-weight:800;color:var(--p);margin-top:2px}
.dq{display:flex;align-items:center;gap:4px;flex-shrink:0}.dqb{width:25px;height:25px;border-radius:50%;border:none;background:var(--bg);color:var(--t);font-size:.88rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s}.dqb:hover{background:var(--p);color:#000}.dqb.mn2:hover{background:var(--er);color:#fff}.dqv{min-width:20px;text-align:center;font-weight:800;font-size:.86rem}
.dnotes{padding:10px 14px;border-top:1px solid var(--b);flex-shrink:0}.dnotes textarea{width:100%;padding:8px 11px;background:var(--bg);border:1.5px solid var(--b);border-radius:var(--rs);color:var(--t);font-size:.8rem;resize:none;outline:none;transition:all .2s}.dnotes textarea:focus{border-color:var(--p)}.dnotes textarea::placeholder{color:var(--m)}
.df{padding:13px 14px;background:var(--c2);border-top:1px solid var(--b);flex-shrink:0}
.sr{display:flex;justify-content:space-between;font-size:.82rem;color:var(--m);padding:2px 0}.sr.tot{font-size:1.05rem;font-weight:800;color:var(--p);border-top:1px solid var(--b);padding-top:9px;margin-top:5px}
.pb{width:100%;margin-top:11px;padding:13px;background:linear-gradient(135deg,var(--p),var(--pd));color:#000;border:none;border-radius:var(--rs);font-size:.95rem;font-weight:800;cursor:pointer;transition:all .2s}.pb:hover{background:linear-gradient(135deg,var(--pl),var(--p));box-shadow:0 4px 15px rgba(232,168,56,.4)}.pb:disabled{opacity:.5;cursor:not-allowed}
.clr{width:100%;margin-top:6px;padding:7px;background:transparent;color:var(--m);border:1px solid var(--b);border-radius:var(--rs);font-size:.8rem;cursor:pointer;transition:all .2s}.clr:hover{color:var(--er);border-color:var(--er)}
.fc{display:none;position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:var(--p);color:#000;border:none;border-radius:28px;padding:13px 26px;font-size:.9rem;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(232,168,56,.5);z-index:200;align-items:center;gap:8px;white-space:nowrap;transition:all .2s}.fc.on{display:flex}.fc:hover{background:var(--pl);transform:translateX(-50%) translateY(-2px)}
.ss{display:none;position:fixed;inset:0;background:rgba(4,3,14,.87);backdrop-filter:blur(12px);z-index:9000;flex-direction:column;align-items:center;justify-content:center;padding:20px}.ss.on{display:flex}
.cw{width:100%;max-width:460px;background:rgba(18,16,38,.96);border:1px solid rgba(232,168,56,.3);border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.8);max-height:92vh;display:flex;flex-direction:column}
@keyframes pop{0%{transform:scale(.3);opacity:0}70%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
.ct{text-align:center;padding:20px 22px 12px}.ct .tk{font-size:3.5rem;animation:pop .5s ease}.ct h2{font-size:1.55rem;font-weight:900;margin:6px 0 3px}.ct h2 span{color:var(--p)}.ct p{color:var(--m);font-size:.82rem}
.rc{margin:0 16px;background:rgba(255,255,255,.04);border:1px dashed rgba(232,168,56,.3);border-radius:10px;padding:14px 16px;font-family:'Courier New',monospace;font-size:.8rem;max-height:36vh;overflow-y:auto}
.rch{text-align:center;padding-bottom:10px;border-bottom:1px dashed rgba(255,255,255,.15);margin-bottom:10px}
.rcl{font-size:1.15rem;font-weight:900;color:var(--p)}.rcs{color:var(--m);font-size:.7rem;margin-top:2px}.rcd{color:var(--m);font-size:.68rem;margin-top:4px}
.rr{display:flex;justify-content:space-between;padding:2px 0;color:var(--t)}.rr.mu{color:var(--m)}.rr.tt{font-size:.98rem;font-weight:900;color:var(--p);border-top:1px dashed rgba(255,255,255,.15);padding-top:7px;margin-top:4px}
.rdiv{border:none;border-top:1px dashed rgba(255,255,255,.15);margin:7px 0}
.ri{display:flex;justify-content:space-between;padding:2px 0;font-size:.76rem}
.rth{text-align:center;margin-top:10px;font-size:.7rem;color:var(--m);line-height:1.7}
.ca{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;padding:14px 16px 18px;border-top:1px solid rgba(232,168,56,.15);margin-top:10px}
.cab{flex:1;min-width:110px;padding:10px 14px;border:none;border-radius:10px;font-size:.84rem;font-weight:800;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px}
.cap{background:linear-gradient(135deg,var(--p),var(--pd));color:#000}.cap:hover{background:linear-gradient(135deg,var(--pl),var(--p))}
.cag{background:var(--c2);color:var(--t);border:1px solid var(--b)}.cag:hover{border-color:var(--p);color:var(--p)}
@media(max-width:600px){.page{padding:14px 10px 100px}.tabs{gap:6px}.tab{padding:9px 13px;min-width:62px}.grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:9px}}
@media print{@page{size:58mm auto;margin:0}body>*{display:none!important}#printArea{display:block!important}}

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
</style>
</head>
<body>

<div class="topbar">
  <div class="brand"><div class="brand-ico">&#127869;</div><span>ServePoint</span></div>
  <div style="display:flex;align-items:center;gap:10px;">
    <button type="button" class="nb-fs" id="nbFsBtn" onclick="posToggleFullscreen()" title="Toggle Full Screen (Hides Chrome Search & Nav Bar)">
      <span id="nbFsIcon">⛶</span> Fullscreen
    </button>
    <button class="cbtn" id="cartBtn" onclick="openCart()">&#128722; My Order <span class="cbadge" id="cbadge">0</span></button>
  </div>
</div>

<div class="page">
  <div class="welcome">
    <h1>Welcome to <span>ServePoint</span> &#127869;</h1>
    <p>Select your items and send your order to the kitchen!</p>
  </div>

  <div class="setup">
    <div class="fld"><label>&#129681; Your Table</label>
      <select id="selTable">
        <option value="0">Takeaway / Walk-in</option>
        <?php foreach($tables as $t):
          $ic = match($t['status']){'available'=>'&#129002;','occupied'=>'&#128308;','reserved'=>'&#129001;',default=>''};
        ?><option value="<?=$t['id']?>"><?= $ic ?> Table <?=htmlspecialchars($t['table_number'])?> (<?=ucfirst($t['status'])?>)</option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="fld"><label>&#128100; Your Name (optional)</label>
      <input type="text" id="custName" placeholder="e.g. Ahmad, Sarah..." maxlength="80">
    </div>
  </div>

  <div class="sec">&#9200; Meal Time <span id="mealLbl"></span></div>
  <div class="tabs" id="tabsRow">
    <button class="tab" data-meal="all" onclick="selTab(this)">
      <span class="ico">&#127869;</span>All
    </button>
    <?php foreach($mealTabs as $tb):?>
    <button class="tab" data-meal="<?=htmlspecialchars($tb['name'])?>" onclick="selTab(this)">
      <span class="ico"><?= $tb['icon'] ?></span><?=htmlspecialchars($tb['label'])?>
    </button>
    <?php endforeach;?>
  </div>

  <div class="sec">&#127869; Browse Menu</div>
  <div class="pills" id="pillsRow">
    <button class="pill active" data-cat="all" onclick="selPill('all',this)">All Items</button>
    <?php foreach($cats as $c):?>
    <button class="pill" data-cat="<?=$c['id']?>" onclick="selPill('<?=$c['id']?>',this)"><?=htmlspecialchars($c['name'])?></button>
    <?php endforeach;?>
  </div>

  <div class="sw" style="margin-bottom:14px;">
    <span class="sw-ico">&#128269;</span>
    <input type="text" id="srch" placeholder="Search food or drinks..." oninput="doSearch(this.value)">
  </div>

  <div class="grid" id="grid"></div>
</div>

<button class="fc" id="fc" onclick="openCart()">&#128722; <span id="fcLbl">View Order</span></button>

<div class="cov" id="cov" onclick="closeCart()"></div>
<div class="cd" id="cd">
  <div class="dh">
    <span class="dt">&#129534; My Order <small id="dcnt" style="color:var(--m);font-weight:400"></small></span>
    <button class="dc" onclick="closeCart()">&times;</button>
  </div>
  <div class="di" id="di"><div class="de"><div style="font-size:2.8rem;margin-bottom:10px">&#128722;</div><p>No items yet!</p></div></div>
  <div class="dnotes"><textarea id="notes" rows="2" placeholder="&#128221; Special requests (optional)..."></textarea></div>
  <div class="df">
    <div class="sr"><span>Subtotal</span><span id="dSub">Rs 0.00</span></div>
    <div class="sr"><span>Tax (6%)</span><span id="dTax">Rs 0.00</span></div>
    <div class="sr tot"><span>TOTAL</span><span id="dTot">Rs 0.00</span></div>
    <button class="pb" id="pb" onclick="placeOrder()">&#10003; Send Order to Kitchen</button>
    <button class="clr" onclick="clearCart()">&#128465; Clear All</button>
  </div>
</div>

<div class="ss" id="ss">
  <div class="cw">
    <div class="ct"><div class="tk">&#127881;</div><h2>Order <span>Confirmed!</span></h2><p>Your order is being prepared &#128522;</p></div>
    <div class="rc" id="rc">
      <div class="rch"><div class="rcl">&#127869; SERVEPOINT</div><div class="rcs">Self-Order Receipt</div><div class="rcd" id="rcDt"></div></div>
      <div class="rr mu"><span>Order #</span><span id="rcN" style="color:var(--p);font-weight:700">-</span></div>
      <div class="rr mu"><span>Table</span><span id="rcT">-</span></div>
      <div class="rr mu"><span>Name</span><span id="rcP">-</span></div>
      <div class="rdiv"></div>
      <div id="rcIt"></div>
      <div class="rdiv"></div>
      <div class="rr mu"><span>Subtotal</span><span id="rcSb">Rs 0.00</span></div>
      <div class="rr mu"><span>Tax (6%)</span><span id="rcTx">Rs 0.00</span></div>
      <div class="rr tt"><span>TOTAL</span><span id="rcTo">Rs 0.00</span></div>
      <div class="rth">*** Thank You! Please Come Again ***</div>
    </div>
    <div class="ca">
      <button class="cab cag" onclick="window.print()">&#128424; Print</button>
      <button class="cab cap" onclick="newOrder()">+ Order More</button>
      <button class="cab cag" onclick="document.getElementById('ss').classList.remove('on')">&#10004; Done</button>
    </div>
  </div>
</div>
<div id="printArea" style="display:none"></div>

<script>
let ALL  = <?= $jsItems ?>;
let CATS = <?= $jsCats ?>;
const MEAL_TABS = <?= $jsMealTabs ?>;

let cart  = {};
let aMeal = 'all';
let aCatId= 0;
let aPill = 'all';
let aQ    = '';

const EM = ['&#127828;','&#127829;','&#127836;','&#127843;','&#129367;','&#127856;','&#9749;','&#129380;','&#129361;','&#127869;','&#127798;','&#127857;','&#129385;','&#127831;','&#127837;','&#127835;','&#129373;','&#129379;','&#129371;','&#127847;'];

function xe(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function h12(t){
  if(!t) return '';
  const p=t.split(':').map(Number);
  return (p[0]%12||12)+':'+(p[1]<10?'0':'')+p[1]+' '+(p[0]>=12?'PM':'AM');
}
function nowHHMM(){
  const n=new Date();
  return String(n.getHours()).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0');
}
function itemMatchesMeal(item){
  if(aMeal==='all'||!aMeal) return true;
  const times=(item.times||'all').split(',').map(s=>s.trim());
  if(times.includes('all')) return true;
  return times.includes(aMeal);
}
function detectCurrentMeal(){
  const now=nowHHMM();
  let match=null;
  MEAL_TABS.forEach(tab=>{if(now>=tab.start && now<tab.end) match=tab.name;});
  return match;
}

function renderGrid(){
  const g=document.getElementById('grid');
  let it=ALL.slice();
  it=it.filter(x=>itemMatchesMeal(x));
  if(aPill!=='all') it=it.filter(x=>String(x.catId)===String(aPill));
  if(aQ) it=it.filter(x=>x.name.toLowerCase().includes(aQ)||(x.catName||'').toLowerCase().includes(aQ));
  if(!it.length){
    g.innerHTML='<div class="ni"><div class="e">&#127869;</div><p>No items for this time. Try "All" tab!</p></div>';
    return;
  }
  let h='';
  it.forEach(x=>{
    const inC=!!cart[x.id], qty=inC?cart[x.id].qty:0;
    const av=x.status!=='unavailable';
    const stk=parseInt(x.stock??-1), rem=stk>=0?stk-qty:999;
    const fb=EM[x.id%EM.length];
    const th=x.img?`<img src="${xe(x.img)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;" onerror="this.style.display='none'">`:`<span style="font-size:2.6rem;">${fb}</span>`;
    const sd=`<span class="sdot ${av?'g':'r'}"></span>`;
    const sv=!av?`<div class="sov"><span class="slbl">Unavailable</span></div>`:'';
    const tm=(x.times||'all').split(',').map(s=>s.trim());
    const na=!tm.includes('all');
    const ts=na?tm.join(' &middot; '):'';
    const rs=(x.timeStart&&x.timeEnd)?`&#9200; ${h12(x.timeStart)}&ndash;${h12(x.timeEnd)}`:'';
    const tc=(na||rs)?`<div class="mt">${ts}${ts&&rs?' &nbsp;':''}${rs}</div>`:'';
    let sc='',sb=false;
    if(stk>=0){
      if(stk===0){sc=`<div class="ms out">Out of Stock</div>`;sb=true;}
      else if(rem<=0){sc=`<div class="ms out">Max reached</div>`;sb=true;}
      else if(stk<=5){sc=`<div class="ms low">Only ${rem} left!</div>`;}
      else{sc=`<div class="ms ok">${rem} available</div>`;}
    }
    const wm=stk>=0?`<div class="sw2" id="sw${x.id}">Max ${stk} available!</div>`:'';
    let ac='';
    if(!av||sb){
      ac=`<button class="ab" disabled>${!av?'Unavailable':'Out of Stock'}</button>`;
    }else{
      const po=rem<=0;
      ac=`<button type="button" class="ab" id="ab${x.id}" style="${inC?'display:none':''}" onclick="addItem(${x.id},'${xe(x.name)}',${x.price},${stk})">+ Add</button>
          <div class="qc${inC?' on':''}" id="qc${x.id}">
            <button type="button" class="qb mn2" onclick="chg(${x.id},-1,${stk})">-</button>
            <span class="qv" id="qv${x.id}">${qty}</span>
            <button type="button" class="qb" id="qp${x.id}" ${po?'disabled':''} onclick="chg(${x.id},1,${stk})">+</button>
          </div>`;
    }
    const cls=`mc${inC&&av?' ic':''}${!av||sb?' so':''}`;
    const dc=x.desc?`<div class="md">${xe(x.desc.substring(0,55))}${x.desc.length>55?'&hellip;':''}</div>`:'';
    h+=`<div class="${cls}" id="mc${x.id}"><div class="mth">${th}${sd}${sv}</div><div class="mb"><div class="mn">${xe(x.name)}</div><div class="mca">${xe(x.catName)}</div>${dc}${tc}${sc}<div class="mp">Rs ${x.price.toFixed(2)}</div><div class="ma">${ac}</div>${wm}</div></div>`;
  });
  g.innerHTML=h;
}

function addItem(id,name,price,stk){
  stk=parseInt(stk??-1);
  const cur=cart[id]?cart[id].qty:0;
  if(stk>=0&&cur>=stk){showW(id,stk);return;}
  cart[id]=cart[id]||{id,name,price:parseFloat(price),qty:0,stock:stk};
  cart[id].qty++;
  hideW(id);sync(id);renderCart();
}
function chg(id,d,stk){
  if(!cart[id])return;
  stk=parseInt(stk??cart[id].stock??-1);
  if(d>0&&stk>=0&&cart[id].qty>=stk){showW(id,stk);return;}
  cart[id].qty+=d;hideW(id);
  if(cart[id].qty<=0){delete cart[id];sync(id,true);}else sync(id);
  renderCart();
}
function showW(id,s){const e=document.getElementById('sw'+id);if(e){e.textContent=`Max ${s} available!`;e.classList.add('on');}}
function hideW(id){const e=document.getElementById('sw'+id);if(e)e.classList.remove('on');}
function sync(id,rm){
  const mc=document.getElementById('mc'+id),ab=document.getElementById('ab'+id),qc=document.getElementById('qc'+id),qv=document.getElementById('qv'+id),qp=document.getElementById('qp'+id);
  if(!mc)return;
  if(rm||!cart[id]){mc.classList.remove('ic');if(ab)ab.style.display='';if(qc)qc.classList.remove('on');}
  else{const q=cart[id].qty,s=parseInt(cart[id].stock??-1);mc.classList.add('ic');if(ab)ab.style.display='none';if(qc)qc.classList.add('on');if(qv)qv.textContent=q;if(qp)qp.disabled=(s>=0&&q>=s);}
}
function clearCart(){Object.keys(cart).forEach(id=>{sync(parseInt(id),true);});cart={};renderCart();}

function renderCart(){
  const di=document.getElementById('di');
  const it=Object.values(cart);
  let tq=0,sub=0;
  it.forEach(x=>{tq+=x.qty;sub+=x.price*x.qty;});
  const b=document.getElementById('cbadge'),f=document.getElementById('fc'),fl=document.getElementById('fcLbl'),dc=document.getElementById('dcnt');
  b.style.display=tq>0?'flex':'none';b.textContent=tq;
  f.classList.toggle('on',tq>0);fl.textContent=`View Order (${tq})`;
  dc.textContent=tq>0?`(${tq} item${tq>1?'s':''})`:'';
  if(!it.length){di.innerHTML='<div class="de"><div style="font-size:2.8rem;margin-bottom:10px">&#128722;</div><p>No items yet!</p></div>';setSummary(0);return;}
  let h='';
  it.forEach(x=>{const lt=x.price*x.qty;h+=`<div class="ditem"><div class="din"><div class="din-n" title="${xe(x.name)}">${xe(x.name)}</div><div class="din-s">Rs ${x.price.toFixed(2)} each</div><div class="din-t">Rs ${lt.toFixed(2)}</div></div><div class="dq"><button type="button" class="dqb mn2" onclick="chg(${x.id},-1,${x.stock??-1})">-</button><span class="dqv">${x.qty}</span><button type="button" class="dqb" onclick="chg(${x.id},1,${x.stock??-1})">+</button></div></div>`;});
  di.innerHTML=h;
  let s2=0;Object.values(cart).forEach(x=>s2+=x.price*x.qty);setSummary(s2);
}
function setSummary(s){
  const tax=Math.round(s*0.06*100)/100,tot=Math.round((s+tax)*100)/100;
  document.getElementById('dSub').textContent='Rs '+s.toFixed(2);
  document.getElementById('dTax').textContent='Rs '+tax.toFixed(2);
  document.getElementById('dTot').textContent='Rs '+tot.toFixed(2);
}
function openCart(){document.getElementById('cov').classList.add('on');document.getElementById('cd').classList.add('on');document.body.style.overflow='hidden';}
function closeCart(){document.getElementById('cov').classList.remove('on');document.getElementById('cd').classList.remove('on');document.body.style.overflow='';}

function selTab(btn){
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  aMeal=btn.dataset.meal;aCatId=0;aPill='all';aQ='';
  const lbl=document.getElementById('mealLbl');
  if(lbl)lbl.textContent=aMeal&&aMeal!=='all'?' &mdash; '+aMeal:'';
  document.getElementById('srch').value='';
  document.querySelectorAll('.pill').forEach(p=>p.classList.remove('active'));
  const ap=document.querySelector('.pill[data-cat="all"]');if(ap)ap.classList.add('active');
  renderGrid();
}
function selPill(catId,btn){
  aPill=catId;aMeal='all';aCatId=0;
  const lbl=document.getElementById('mealLbl');if(lbl)lbl.textContent='';
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
  const at=document.querySelector('.tab[data-meal="all"]');if(at)at.classList.add('active');
  document.querySelectorAll('.pill').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');renderGrid();
}
function doSearch(q){
  aQ=q.toLowerCase().trim();
  if(aQ){aMeal='all';aCatId=0;const lbl=document.getElementById('mealLbl');if(lbl)lbl.textContent='';document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));const at=document.querySelector('.tab[data-meal="all"]');if(at)at.classList.add('active');document.querySelectorAll('.pill').forEach(p=>p.classList.remove('active'));const ap=document.querySelector('.pill[data-cat="all"]');if(ap)ap.classList.add('active');}
  renderGrid();
}

function placeOrder(){
  const it=Object.values(cart);
  if(!it.length){alert('Please add at least one item first.');return;}
  const btn=document.getElementById('pb');
  btn.disabled=true;btn.textContent='Sending...';
  const fd=new FormData();
  fd.append('action','place_order');
  fd.append('table_id',document.getElementById('selTable').value);
  fd.append('customer_name',document.getElementById('custName').value.trim()||'Walk-in');
  fd.append('meal_type',aMeal&&aMeal!=='all'?aMeal:'General');
  fd.append('notes',document.getElementById('notes').value.trim());
  fd.append('items',JSON.stringify(it));
  fetch('/RestaurantPOS/ajax_customer_order.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
      btn.disabled=false;btn.textContent='Send Order to Kitchen';
      if(d.success){
        const snap={...cart};
        closeCart();showReceipt(d.order_number,d.total,d.subtotal,d.tax,snap);
        Object.values(snap).forEach(si=>{const item=ALL.find(x=>x.id===si.id);if(item&&item.stock>=0)item.stock=Math.max(0,item.stock-si.qty);});
        clearCart();renderGrid();
      }else{
        if(d.stock_error){
          const id=Number(d.item_id),available=Math.max(0,Number(d.available_stock)||0);
          const item=ALL.find(x=>x.id===id);if(item)item.stock=available;
          if(cart[id]){if(available===0)delete cart[id];else cart[id].qty=Math.min(cart[id].qty,available);}
          renderGrid();renderCart();setTimeout(()=>showW(id,available),0);
        }
        alert(d.message||'Failed. Try again.');
      }
    }).catch(e=>{btn.disabled=false;btn.textContent='Send Order to Kitchen';alert('Connection error: '+e.message);});
}

function showReceipt(num,total,sub,tax,snap){
  const now=new Date();
  document.getElementById('rcDt').textContent=now.toLocaleDateString('en-MY')+'  '+now.toLocaleTimeString('en-MY');
  document.getElementById('rcN').textContent=num;
  const ts=document.getElementById('selTable');
  document.getElementById('rcT').textContent=(ts.options[ts.selectedIndex]?.text||'Walk-in').replace(/^[^\s]+\s/,'');
  document.getElementById('rcP').textContent=document.getElementById('custName').value.trim()||'Walk-in';
  let ih='';Object.values(snap).forEach(x=>{const lt=x.price*x.qty;ih+=`<div class="ri"><span>${xe(x.name)} x${x.qty}</span><span>Rs ${lt.toFixed(2)}</span></div>`;});
  document.getElementById('rcIt').innerHTML=ih;
  document.getElementById('rcSb').textContent='Rs '+parseFloat(sub).toFixed(2);
  document.getElementById('rcTx').textContent='Rs '+parseFloat(tax).toFixed(2);
  document.getElementById('rcTo').textContent='Rs '+parseFloat(total).toFixed(2);
  document.getElementById('ss').classList.add('on');
  document.getElementById('printArea').innerHTML=document.getElementById('rc').outerHTML;
}
function newOrder(){
  document.getElementById('ss').classList.remove('on');
  aMeal='all';aCatId=0;aQ='';aPill='all';
  const lbl=document.getElementById('mealLbl');if(lbl)lbl.textContent='';
  document.getElementById('srch').value='';document.getElementById('notes').value='';
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
  const at=document.querySelector('.tab[data-meal="all"]');if(at)at.classList.add('active');
  document.querySelectorAll('.pill').forEach(p=>p.classList.remove('active'));
  const ap=document.querySelector('.pill[data-cat="all"]');if(ap)ap.classList.add('active');
  window.scrollTo({top:0,behavior:'smooth'});renderGrid();
}

function liveRefresh(){
  fetch('/RestaurantPOS/ajax_menu_items.php?t='+Date.now(),{cache:'no-store'})
    .then(r=>r.json()).then(data=>{
      if(!data||!Array.isArray(data.items))return;
      const byId=new Map(data.items.map(x=>[x.id,x]));
      const capped=[];
      Object.keys(cart).forEach(id=>{
        const fr=byId.get(Number(id));
        if(!fr||fr.status==='unavailable'){delete cart[id];return;}
        cart[id].name=fr.name;cart[id].price=Number(fr.price);cart[id].stock=Number(fr.stock);
        if(Number(fr.stock)>=0&&cart[id].qty>Number(fr.stock)){cart[id].qty=Number(fr.stock);capped.push({id:Number(id),stock:Number(fr.stock)});if(cart[id].qty<=0)delete cart[id];}
      });
      ALL.length=0;data.items.forEach(fr=>ALL.push(Object.assign({},fr,{stock:Number(fr.stock)})));
      if(data.cats&&Array.isArray(data.cats)){CATS.length=0;data.cats.forEach(c=>CATS.push(c));rebuildPillsUI(data.cats);}
      renderGrid();renderCart();
      capped.forEach(x=>setTimeout(()=>showW(x.id,x.stock),0));
    }).catch(()=>{});
}

function rebuildPillsUI(freshCats){
  const pillsEl=document.getElementById('pillsRow');if(!pillsEl)return;
  const curIds=Array.from(pillsEl.querySelectorAll('.pill[data-cat]')).map(p=>p.dataset.cat).filter(c=>c!=='all').join(',');
  const freshIds=freshCats.map(c=>String(c.id)).join(',');
  if(curIds===freshIds)return;
  let ph=`<button class="pill${aPill==='all'?' active':''}" data-cat="all" onclick="selPill('all',this)">All Items</button>`;
  freshCats.forEach(c=>{const ac=String(aPill)===String(c.id)?' active':'';ph+=`<button class="pill${ac}" data-cat="${c.id}" onclick="selPill('${c.id}',this)">${xe(c.name)}</button>`;});
  pillsEl.innerHTML=ph;
}

// Boot
renderGrid();renderCart();
(function(){
  const requested=new URLSearchParams(location.search).get('meal');
  const autoMeal=requested||detectCurrentMeal();
  if(autoMeal){const btn=document.querySelector(`.tab[data-meal="${CSS.escape(autoMeal)}"]`);if(btn){selTab(btn);return;}}
  const allTab=document.querySelector('.tab[data-meal="all"]');if(allTab)allTab.classList.add('active');
})();
liveRefresh();
setInterval(liveRefresh,2000);
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')liveRefresh();});
</script>

<?php include __DIR__ . '/includes/admin_popup.php'; ?>
<script src="/RestaurantPOS/assets/js/pos_fullscreen.js"></script>
</body>
</html>
