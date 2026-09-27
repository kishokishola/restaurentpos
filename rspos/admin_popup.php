<?php
/**
 * admin_popup.php
 * - Full-width pure black starry background covering 100% of viewport
 * - Automatically enters and maintains browser fullscreen on interaction (hides Chrome search/nav bar)
 * - Fullscreen toggle button
 * - Responsive popup card
 */
?>
<style>
/* ── Hide page scroll and content while popup active ── */
html.rp-on, html.rp-on body {
  overflow: hidden !important;
  height: 100% !important;
  width: 100% !important;
  margin: 0 !important;
  padding: 0 !important;
}
html.rp-on body > *:not(#rpWrap) {
  visibility: hidden !important;
  pointer-events: none !important;
  user-select: none !important;
}

/* ══════════════════════════════════════════════
   FULLSCREEN OVERLAY — Black Background Full Width
══════════════════════════════════════════════ */
#rpWrap {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100vw !important;
  width: 100% !important;
  height: 100vh !important;
  height: 100% !important;
  min-width: 100vw !important;
  min-height: 100vh !important;
  margin: 0 !important;
  padding: 0 !important;
  z-index: 2147483647 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  box-sizing: border-box !important;
  /* Deep true black with subtle starry glow */
  background-color: #000000 !important;
  background-image: 
    radial-gradient(circle at 50% 50%, rgba(18, 16, 36, 0.88) 0%, #000000 100%),
    url('/RestaurantPOS/assets/images/dark_bg.webp') !important;
  background-size: cover !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
  animation: rpFdIn .35s ease forwards;
}
@keyframes rpFdIn { from{opacity:0} to{opacity:1} }

/* Blur + dim layer — sits on bg image, behind card */
#rpWrap::before {
  content: '';
  position: absolute !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100% !important;
  height: 100% !important;
  background: rgba(0, 0, 0, 0.72) !important;
  backdrop-filter: blur(22px) brightness(0.4) saturate(0.2) !important;
  -webkit-backdrop-filter: blur(22px) brightness(0.4) saturate(0.2) !important;
  z-index: 0 !important;
  pointer-events: none !important;
}

/* ══════════════════════════════════════════════
   CENTERED POPUP CARD
══════════════════════════════════════════════ */
#rpCard {
  position: relative;
  z-index: 1;
  width: min(480px, 92vw);
  background: rgba(14, 13, 28, 0.94);
  border: 1.5px solid rgba(232,168,56,.35);
  border-radius: 20px;
  overflow: hidden;
  box-shadow:
    0 0 0 1px rgba(232,168,56,.12),
    0 32px 90px rgba(0,0,0,.95),
    inset 0 1px 0 rgba(255,255,255,.08);
  animation: rpCardUp .46s cubic-bezier(.22,1,.36,1) forwards;
}
@keyframes rpCardUp {
  from { opacity:0; transform:translateY(30px) scale(.96) }
  to   { opacity:1; transform:translateY(0)    scale(1)   }
}

/* ── Image strip ── */
#rpImg {
  position: relative;
  height: 200px;
  overflow: hidden;
  flex-shrink: 0;
}
#rpImg img {
  width:100%; height:100%;
  object-fit:cover; object-position:center 40%;
  display:block;
  transform:scale(1.06);
  transition:transform 10s ease;
}
#rpCard:hover #rpImg img { transform:scale(1.11); }
#rpImg::after {
  content:'';
  position:absolute; inset:0;
  background:linear-gradient(to bottom,rgba(0,0,0,.05) 0%,rgba(14,13,28,.98) 100%);
}

/* Shine */
@keyframes rpSh { 0%{left:-110%} 100%{left:220%} }
#rpShine {
  position:absolute; top:0; bottom:0; width:70px;
  background:linear-gradient(to right,transparent,rgba(255,255,255,.18),transparent);
  animation:rpSh 3.5s ease 1s infinite;
  z-index:2; pointer-events:none;
}
/* Floating emojis */
@keyframes rpFl { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-9px)} }
.rpm {
  position:absolute; animation:rpFl 3.5s ease infinite;
  pointer-events:none; z-index:3;
  filter:drop-shadow(0 3px 8px rgba(0,0,0,.8));
}
/* Image title */
#rpTxt {
  position:absolute; bottom:0; left:0; right:0;
  z-index:4; padding:12px 24px 18px;
}
#rpTxt h2 {
  font-family:'Segoe UI',sans-serif;
  font-size:clamp(1.35rem,3.5vw,1.85rem);
  font-weight:900; color:#fff;
  margin:0 0 4px;
  text-shadow:0 2px 14px rgba(0,0,0,.9);
  line-height:1.2;
}
#rpTxt h2 em { color:#e8a838; font-style:normal; text-shadow:0 0 24px rgba(232,168,56,.9); }
#rpTxt p {
  font-family:'Segoe UI',sans-serif;
  font-size:.83rem; color:rgba(255,255,255,.8); margin:0;
  text-shadow:0 2px 8px rgba(0,0,0,.9);
}

/* ── Body ── */
#rpBody {
  padding: 20px 24px 24px;
  display:flex; flex-direction:column; gap:14px;
}
/* Live badge */
@keyframes rpDt { 0%,100%{opacity:1} 50%{opacity:.25} }
#rpBadge {
  display:inline-flex; align-items:center; gap:7px;
  background:linear-gradient(135deg,#e8a838,#c8891e);
  color:#000; font-family:'Segoe UI',sans-serif;
  font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px;
  padding:5px 14px 5px 10px; border-radius:30px; width:fit-content;
  box-shadow:0 4px 14px rgba(232,168,56,.4);
}
.rpdt { width:7px;height:7px;background:#000;border-radius:50%;animation:rpDt 1.2s ease infinite;flex-shrink:0; }

/* Quick links */
#rpLinks { display:flex; gap:7px; flex-wrap:wrap; }
.rpl {
  display:inline-flex; align-items:center; gap:5px;
  padding:7px 15px;
  background:rgba(255,255,255,.055);
  border:1.5px solid rgba(255,255,255,.1);
  border-radius:30px;
  font-family:'Segoe UI',sans-serif;
  font-size:.81rem; font-weight:700; color:rgba(255,255,255,.85);
  text-decoration:none; transition:all .2s; white-space:nowrap;
}
.rpl:hover {
  background:rgba(232,168,56,.18); border-color:#e8a838;
  color:#e8a838; transform:translateY(-2px);
}

/* Fullscreen Trigger Button */
.rp-fs-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 11px 16px;
  background: rgba(232, 168, 56, 0.14);
  border: 1.5px solid var(--primary);
  border-radius: 12px;
  color: var(--primary);
  font-family: 'Segoe UI', sans-serif;
  font-size: .88rem;
  font-weight: 800;
  cursor: pointer;
  transition: all .2s ease;
  box-shadow: 0 4px 14px rgba(232, 168, 56, 0.2);
}
.rp-fs-btn:hover {
  background: var(--primary);
  color: #000;
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(232, 168, 56, 0.45);
}

/* CTA */
#rpCta { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:2px; }
@keyframes rpPuls { 0%,100%{box-shadow:0 5px 20px rgba(232,168,56,.5)} 50%{box-shadow:0 5px 30px rgba(232,168,56,.85)} }
#rpBtn {
  display:inline-flex; align-items:center; gap:8px;
  padding:12px 28px;
  background:linear-gradient(135deg,#e8a838,#c8891e);
  color:#000; font-family:'Segoe UI',sans-serif;
  font-size:.95rem; font-weight:900;
  border:none; border-radius:11px; cursor:pointer;
  text-decoration:none; transition:all .22s;
  animation:rpPuls 2s ease 2s infinite;
}
#rpBtn:hover {
  background:linear-gradient(135deg,#f5c96a,#e8a838);
  transform:translateY(-2px); animation:none;
  box-shadow:0 8px 28px rgba(232,168,56,.7);
}
/* ← Arrow */
#rpArr {
  display:inline-flex; align-items:center; gap:6px;
  font-family:'Segoe UI',sans-serif;
  font-size:.84rem; font-weight:600;
  color:rgba(255,255,255,.5);
  background:none; border:none; cursor:pointer; padding:6px;
  transition:color .2s;
}
#rpArr:hover { color:rgba(255,255,255,.9); }
#rpArr .a { transition:transform .2s; }
#rpArr:hover .a { transform:translateX(-4px); }

@media(max-width:480px){
  #rpImg{height:165px;}
  #rpTxt{padding:10px 16px 14px;}
  #rpBody{padding:14px 16px 20px;gap:11px;}
  #rpBtn{padding:11px 22px;font-size:.88rem;}
  .rpl{padding:6px 11px;font-size:.76rem;}
}
</style>

<div id="rpWrap">
  <div id="rpCard">
    <div id="rpImg">
      <img src="/RestaurantPOS/bc1.jpg" alt="" loading="eager" decoding="async">
      <div id="rpShine"></div>
      <span class="rpm" style="top:9%;left:4%;font-size:2rem;animation-delay:.3s">🍛</span>
      <span class="rpm" style="top:6%;left:18%;font-size:1.5rem;animation-delay:1.2s">☕</span>
      <span class="rpm" style="top:8%;right:5%;font-size:1.9rem;animation-delay:.7s">🍗</span>
      <span class="rpm" style="top:5%;right:19%;font-size:1.4rem;animation-delay:1.8s">🥤</span>
      <div id="rpTxt">
        <h2>Welcome to <em>RestoPOS</em> 🍽️</h2>
        <p>Your restaurant system is live and ready.</p>
      </div>
    </div>
    <div id="rpBody">
      <div id="rpBadge"><span class="rpdt"></span>Live &nbsp;·&nbsp; <?= date('D d M Y') ?></div>
      
      <button type="button" class="rp-fs-btn" id="rpFsTrigger" onclick="rpGoFullscreen(event)">
        ⛶ Enter Full Screen App (Hide Chrome Bar)
      </button>

      <div id="rpLinks">
        <a class="rpl" href="/RestaurantPOS/customer_order.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">🛒 New Order</a>
        <a class="rpl" href="/RestaurantPOS/kitchen.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">👨‍🍳 Kitchen</a>
        <a class="rpl" href="/RestaurantPOS/tables.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">🪑 Tables</a>
        <a class="rpl" href="/RestaurantPOS/billing.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">💳 Billing</a>
        <a class="rpl" href="/RestaurantPOS/reports.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">📊 Reports</a>
      </div>
      <div id="rpCta">
        <a id="rpBtn" href="/RestaurantPOS/customer_order.php" onclick="if(window.posEnterFullscreen)window.posEnterFullscreen()">Place New Order</a>
        <button id="rpArr" onclick="rpDone(event)">
          <span class="a">←</span>&nbsp;
          <?= basename($_SERVER['PHP_SELF'])==='customer_order.php' ? 'Back to Menu' : 'Continue to Dashboard' ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  /* 1. Hide all page content behind popup */
  document.documentElement.classList.add('rp-on');

  /* 2. Request browser fullscreen function */
  function requestAppFullscreen() {
    if (window.posEnterFullscreen) {
      window.posEnterFullscreen();
      return;
    }
    var el = document.documentElement;
    if (!document.fullscreenElement && !document.webkitFullscreenElement) {
      try {
        if (el.requestFullscreen) {
          el.requestFullscreen().catch(function(){});
        } else if (el.webkitRequestFullscreen) {
          el.webkitRequestFullscreen();
        } else if (el.mozRequestFullScreen) {
          el.mozRequestFullScreen();
        } else if (el.msRequestFullscreen) {
          el.msRequestFullscreen();
        }
      } catch(e){}
    }
  }

  /* Any click on the popup backdrop or interactive elements requests fullscreen */
  document.addEventListener('click', function(e) {
    var w = document.getElementById('rpWrap');
    if (w && w.contains(e.target)) {
      requestAppFullscreen();
    }
  }, { passive: true });

  /* Enter full screen button handler */
  function rpGoFullscreen(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    requestAppFullscreen();
    var btn = document.getElementById('rpFsTrigger');
    if (btn) {
      btn.innerHTML = '✅ Full Screen Active (Bar Hidden)';
      btn.style.background = '#28a745';
      btn.style.borderColor = '#28a745';
      btn.style.color = '#fff';
    }
  }

  /* 3. Dismiss popup (maintains fullscreen) */
  function rpDone(e){
    if (e) e.preventDefault();
    /* Enter fullscreen on dismissal so Chrome search/nav bar stays hidden! */
    requestAppFullscreen();

    var w = document.getElementById('rpWrap');
    if(!w) return;
    w.style.transition = 'opacity .28s ease, transform .28s ease';
    w.style.opacity = '0';
    w.style.transform = 'scale(0.98)';
    document.documentElement.classList.remove('rp-on');
    setTimeout(function(){ if(w) w.remove(); }, 300);
  }

  window.rpDone = rpDone;
  window.rpGoFullscreen = rpGoFullscreen;
  window.requestAppFullscreen = requestAppFullscreen;

  /* ESC also closes */
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') rpDone();
  });
})();
</script>
