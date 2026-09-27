<?php
/**
 * food_popup.php — Full-screen food popup
 * Shows on EVERY page load, no cooldown, no session check.
 * Include just before </body> on any page.
 */
?>
<!-- ══════════════════════════════════════════════════════════
     FULL-SCREEN FOOD POPUP — Shows every page load
══════════════════════════════════════════════════════════ -->
<div id="fpOverlay">
<style>
/* ── Overlay ── */
#fpOverlay{
  position:fixed;inset:0;
  background:rgba(0,0,0,.88);
  z-index:999999;
  display:flex;align-items:center;justify-content:center;
  padding:0;
  animation:fpFadeIn .35s ease forwards;
}
@keyframes fpFadeIn{from{opacity:0}to{opacity:1}}
@keyframes fpSlideUp{from{opacity:0;transform:translateY(50px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes fpShine{0%{left:-120%}100%{left:220%}}
@keyframes fpPulse{0%,100%{box-shadow:0 0 0 0 rgba(232,168,56,.55)}70%{box-shadow:0 0 0 14px rgba(232,168,56,0)}}
@keyframes fpFloat{0%,100%{transform:translateY(0) rotate(-5deg)}50%{transform:translateY(-12px) rotate(5deg)}}
@keyframes fpBadge{0%{transform:scale(0) rotate(-15deg);opacity:0}70%{transform:scale(1.12) rotate(4deg)}100%{transform:scale(1) rotate(0);opacity:1}}
@keyframes fpPopIn{0%{transform:scale(.4);opacity:0}70%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}

/* ── Main popup card ── */
#fpCard{
  position:relative;
  width:100vw;
  height:100vh;
  max-width:100vw;
  max-height:100vh;
  display:flex;
  flex-direction:column;
  animation:fpSlideUp .42s cubic-bezier(.22,1,.36,1) forwards;
  overflow:hidden;
  background:#0f0f1a;
}

/* ── Hero image — takes top 60% of screen ── */
#fpHero{
  position:relative;
  flex:0 0 60vh;
  min-height:280px;
  overflow:hidden;
}
#fpHero img{
  width:100%;height:100%;
  object-fit:cover;
  object-position:center 40%;
  display:block;
  transform:scale(1.04);
  transition:transform 8s ease;
}
#fpCard:hover #fpHero img{ transform:scale(1.08); }

/* Dark gradient on image bottom */
#fpHero::after{
  content:'';
  position:absolute;inset:0;
  background:linear-gradient(
    to bottom,
    rgba(0,0,0,.1) 0%,
    rgba(0,0,0,.05) 40%,
    rgba(15,15,26,.97) 100%
  );
}

/* Floating food emojis on image */
.fp-moji{
  position:absolute;
  font-size:2.4rem;
  animation:fpFloat 3.5s ease infinite;
  pointer-events:none;
  z-index:5;
  filter:drop-shadow(0 4px 10px rgba(0,0,0,.6));
}

/* Shine sweep */
#fpShine{
  position:absolute;top:0;bottom:0;width:80px;
  background:linear-gradient(to right,transparent,rgba(255,255,255,.22),transparent);
  animation:fpShine 3s ease 1s infinite;
  z-index:6;pointer-events:none;
}

/* Title on image */
#fpHeroTitle{
  position:absolute;bottom:0;left:0;right:0;
  z-index:7;
  padding:20px 32px 24px;
  text-align:left;
}
#fpHeroTitle h1{
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(1.8rem,5vw,3.2rem);
  font-weight:900;
  color:#fff;
  line-height:1.15;
  margin:0 0 6px;
  text-shadow:0 3px 20px rgba(0,0,0,.7);
  letter-spacing:-.5px;
}
#fpHeroTitle h1 span{
  color:#e8a838;
  text-shadow:0 0 30px rgba(232,168,56,.6);
}
#fpHeroTitle p{
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(.85rem,2.2vw,1.15rem);
  color:rgba(255,255,255,.85);
  margin:0;
  font-weight:400;
  text-shadow:0 2px 10px rgba(0,0,0,.6);
}

/* ── Bottom section ── */
#fpBottom{
  flex:1;
  background:linear-gradient(160deg,#141428 0%,#1a1a35 100%);
  padding:clamp(16px,3vh,28px) clamp(18px,4vw,40px) clamp(18px,3vh,32px);
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  gap:14px;
  overflow:hidden;
}

/* Badge */
#fpBadge{
  display:inline-flex;align-items:center;gap:7px;
  background:linear-gradient(135deg,#e8a838,#c8891e);
  color:#000;
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(.7rem,1.6vw,.82rem);font-weight:800;
  text-transform:uppercase;letter-spacing:.9px;
  padding:6px 18px;border-radius:30px;
  width:fit-content;
  box-shadow:0 4px 18px rgba(232,168,56,.45);
  animation:fpBadge .6s ease .5s both;
}

/* Category chips */
#fpCats{
  display:flex;gap:8px;flex-wrap:wrap;
}
.fp-cat{
  display:inline-flex;align-items:center;gap:6px;
  padding:9px 20px;
  background:rgba(255,255,255,.06);
  border:1.5px solid rgba(255,255,255,.12);
  border-radius:30px;
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(.78rem,1.8vw,.9rem);font-weight:700;
  color:rgba(255,255,255,.88);
  cursor:pointer;
  transition:all .22s;
  text-decoration:none;
  white-space:nowrap;
}
.fp-cat:hover{
  background:rgba(232,168,56,.22);
  border-color:#e8a838;
  color:#e8a838;
  transform:translateY(-3px);
  box-shadow:0 6px 18px rgba(232,168,56,.25);
}
.fp-cat .fci{ font-size:1.15rem; }

/* CTA row */
#fpCta{
  display:flex;align-items:center;gap:16px;flex-wrap:wrap;
}
#fpOrderBtn{
  display:inline-flex;align-items:center;gap:10px;
  padding:clamp(13px,2.5vh,17px) clamp(28px,4vw,40px);
  background:linear-gradient(135deg,#e8a838,#c8891e);
  color:#000;
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(.95rem,2.2vw,1.15rem);font-weight:900;
  border:none;border-radius:14px;
  cursor:pointer;
  text-decoration:none;
  transition:all .22s;
  letter-spacing:.2px;
  animation:fpPulse 2s ease 1.5s infinite;
  box-shadow:0 6px 25px rgba(232,168,56,.5);
  position:relative;overflow:hidden;
}
#fpOrderBtn::after{
  content:'';
  position:absolute;top:0;left:-100%;bottom:0;width:60px;
  background:linear-gradient(to right,transparent,rgba(255,255,255,.3),transparent);
  transition:left .5s;
}
#fpOrderBtn:hover{
  background:linear-gradient(135deg,#f5c96a,#e8a838);
  transform:translateY(-3px);
  box-shadow:0 10px 35px rgba(232,168,56,.65);
  animation:none;
}
#fpOrderBtn:hover::after{ left:200%; }

#fpCloseText{
  font-family:'Segoe UI',Tahoma,sans-serif;
  font-size:clamp(.78rem,1.6vw,.88rem);
  color:rgba(255,255,255,.4);
  background:none;border:none;cursor:pointer;
  text-decoration:underline;
  transition:color .2s;
  padding:4px;
}
#fpCloseText:hover{ color:rgba(255,255,255,.75); }

/* ── X close button ── */
#fpClose{
  position:absolute;top:16px;right:18px;
  z-index:20;
  width:42px;height:42px;
  border-radius:50%;
  background:rgba(0,0,0,.6);
  border:1.5px solid rgba(255,255,255,.2);
  color:#fff;
  font-size:1.15rem;font-weight:700;
  cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all .2s;
  line-height:1;
}
#fpClose:hover{
  background:rgba(220,53,69,.9);
  border-color:transparent;
  transform:scale(1.12);
}

/* ── Responsive ── */
@media(max-width:600px){
  #fpHero{ flex:0 0 52vh; min-height:220px; }
  #fpHeroTitle{ padding:14px 18px 18px; }
  #fpBottom{ gap:10px; padding:14px 16px 18px; }
  .fp-cat{ padding:7px 14px; }
  #fpCats{ gap:6px; }
}
@media(max-width:400px){
  #fpHero{ flex:0 0 45vh; }
  .fp-cat span:not(.fci){ display:none; }
  .fp-cat{ padding:8px 12px; }
}
</style>

  <div id="fpCard">
    <!-- X Close -->
    <button id="fpClose" onclick="fpClose()" title="Close">✕</button>

    <!-- Hero image -->
    <div id="fpHero">
      <img src="/RestaurantPOS/bc1.jpg" alt="Delicious food spread" loading="eager" decoding="async">
      <div id="fpShine"></div>

      <!-- Floating emojis -->
      <span class="fp-moji" style="top:12%;left:3%;animation-delay:.2s;font-size:2.8rem">🍛</span>
      <span class="fp-moji" style="top:8%;left:14%;animation-delay:1.1s;font-size:1.9rem">🥤</span>
      <span class="fp-moji" style="top:10%;right:5%;animation-delay:.6s;font-size:2.5rem">🍗</span>
      <span class="fp-moji" style="top:6%;right:17%;animation-delay:1.7s;font-size:1.7rem">🌮</span>

      <!-- Title overlay -->
      <div id="fpHeroTitle">
        <h1>Fresh Food,<br><span>Perfect Taste</span> 🍽️</h1>
        <p>Order directly from your table — hot meals sent straight to the kitchen!</p>
      </div>
    </div>

    <!-- Bottom content -->
    <div id="fpBottom">
      <div>
        <div id="fpBadge">✨ Now Serving &nbsp;|&nbsp; Order Anytime</div>
      </div>

      <!-- Category chips -->
      <div id="fpCats">
        <a class="fp-cat" href="/RestaurantPOS/customer_order.php?meal=Morning+Breakfast">
          <span class="fci">🌅</span><span>Morning</span>
        </a>
        <a class="fp-cat" href="/RestaurantPOS/customer_order.php?meal=Lunch">
          <span class="fci">☀️</span><span>Lunch</span>
        </a>
        <a class="fp-cat" href="/RestaurantPOS/customer_order.php?meal=Dinner">
          <span class="fci">🌙</span><span>Dinner</span>
        </a>
        <a class="fp-cat" href="/RestaurantPOS/customer_order.php?meal=Snacks">
          <span class="fci">🍿</span><span>Snacks</span>
        </a>
        <a class="fp-cat" href="/RestaurantPOS/customer_order.php?meal=Juicy+Drinks">
          <span class="fci">🥤</span><span>Juicy</span>
        </a>
      </div>

      <!-- CTA -->
      <div id="fpCta">
        <a id="fpOrderBtn" href="/RestaurantPOS/customer_order.php">
          🛒 Order Now — It's Easy!
        </a>
        <button id="fpCloseText" onclick="fpClose()">Skip for now</button>
      </div>
    </div>
  </div><!-- /#fpCard -->
</div><!-- /#fpOverlay -->

<script>
(function(){
  function fpClose(){
    var el = document.getElementById('fpOverlay');
    if(!el) return;
    el.style.transition = 'opacity .3s ease';
    el.style.opacity    = '0';
    setTimeout(function(){ el && el.remove(); }, 320);
  }
  // Expose globally
  window.fpClose = fpClose;

  // ESC key
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') fpClose();
  });

  // Click outside card closes overlay (click on dark bg)
  document.getElementById('fpOverlay').addEventListener('click', function(e){
    if(e.target === this) fpClose();
  });
})();
</script>
