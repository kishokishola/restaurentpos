/**
 * pos_fullscreen.js — Persistent Universal Fullscreen Manager for ServePoint POS
 * - Keeps fullscreen active across all page navigations
 * - Remembers user's preference in localStorage
 * - Returns to small screen ONLY when the user clicks the "Exit Fullscreen" button
 */
(function() {
  'use strict';
  var STORAGE_KEY = 'resto_pos_fullscreen_state';

  function isFsActive() {
    return localStorage.getItem(STORAGE_KEY) === 'active';
  }

  function getFsElement() {
    return document.fullscreenElement || 
           document.webkitFullscreenElement || 
           document.mozFullScreenElement || 
           document.msFullscreenElement;
  }

  function requestFs() {
    var el = document.documentElement;
    var fn = el.requestFullscreen || 
             el.webkitRequestFullscreen || 
             el.mozRequestFullScreen || 
             el.msRequestFullscreen;
    if (fn) {
      try {
        var p = fn.call(el);
        if (p && p.catch) p.catch(function(){});
      } catch(e) {}
    }
  }

  function exitFs() {
    var fn = document.exitFullscreen || 
             document.webkitExitFullscreen || 
             document.mozCancelFullScreen || 
             document.msExitFullscreen;
    if (fn) {
      try {
        var p = fn.call(document);
        if (p && p.catch) p.catch(function(){});
      } catch(e) {}
    }
  }

  function updateButtonsUI(inFs) {
    var btns = document.querySelectorAll('.nb-fs, #nbFsBtn, #rpFsTrigger');
    btns.forEach(function(btn) {
      if (btn.id === 'rpFsTrigger') {
        if (inFs) {
          btn.innerHTML = '✅ Full Screen Active (Bar Hidden)';
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = '#fff';
        }
        return;
      }
      btn.classList.toggle('active', inFs);
      btn.innerHTML = '<span id="nbFsIcon">' + (inFs ? '🗗' : '⛶') + '</span> ' + (inFs ? 'Exit Full' : 'Fullscreen');
      btn.title = inFs ? 'Exit Fullscreen (Return to Small Screen)' : 'Toggle Full Screen (Hides Chrome Bar)';
    });

    var banner = document.getElementById('posFsResumeBanner');
    if (banner) {
      banner.style.display = (isFsActive() && !inFs) ? 'flex' : 'none';
    }
  }

  // Public Actions
  window.posEnterFullscreen = function() {
    localStorage.setItem(STORAGE_KEY, 'active');
    requestFs();
    updateButtonsUI(true);
  };

  window.posExitFullscreen = function() {
    // ONLY when user clicks the Exit button does it return to small screen!
    localStorage.setItem(STORAGE_KEY, 'inactive');
    exitFs();
    updateButtonsUI(false);
  };

  window.posToggleFullscreen = function() {
    if (isFsActive() && getFsElement()) {
      window.posExitFullscreen();
    } else {
      window.posEnterFullscreen();
    }
  };

  // Backwards compatibility
  window.nbToggleFullscreen = window.posToggleFullscreen;
  window.rpGoFullscreen = window.posEnterFullscreen;
  window.requestAppFullscreen = function() {
    if (isFsActive() || !localStorage.getItem(STORAGE_KEY)) {
      window.posEnterFullscreen();
    }
  };

  // Trigger fullscreen on user gestures if mode is active
  function onUserGesture() {
    if (isFsActive() && !getFsElement()) {
      requestFs();
    }
  }

  window.addEventListener('click', onUserGesture, { capture: true, passive: true });
  window.addEventListener('pointerdown', onUserGesture, { capture: true, passive: true });
  window.addEventListener('touchstart', onUserGesture, { capture: true, passive: true });
  window.addEventListener('keydown', onUserGesture, { capture: true, passive: true });

  // Pre-request fullscreen on clicking internal navigation links
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:') || a.target === '_blank') return;
    if (isFsActive()) {
      requestFs();
    }
  }, { capture: true });

  // On page load auto-restoration
  function initAutoRestore() {
    if (isFsActive()) {
      updateButtonsUI(true);
      requestFs();

      // Discreet hint banner if browser blocked load-time auto-fullscreen
      setTimeout(function() {
        if (isFsActive() && !getFsElement() && !document.getElementById('posFsResumeBanner')) {
          var b = document.createElement('div');
          b.id = 'posFsResumeBanner';
          b.innerHTML = '<span>⛶ Fullscreen Mode Active &nbsp;·&nbsp; <strong>Tap anywhere to expand full screen</strong></span>';
          b.setAttribute('style', 'position:fixed;bottom:14px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#e8a838,#c8891e);color:#000;font-family:"Segoe UI",sans-serif;font-size:0.82rem;font-weight:800;padding:8px 22px;border-radius:30px;box-shadow:0 6px 25px rgba(0,0,0,0.85);z-index:999999;cursor:pointer;display:flex;align-items:center;gap:8px;');
          b.onclick = function() { window.posEnterFullscreen(); b.remove(); };
          document.body.appendChild(b);
        }
      }, 400);
    } else {
      updateButtonsUI(false);
    }
  }

  function onFsChange() {
    var inFs = !!getFsElement();
    updateButtonsUI(inFs);
    var b = document.getElementById('posFsResumeBanner');
    if (b && inFs) b.style.display = 'none';
  }

  document.addEventListener('fullscreenchange', onFsChange);
  document.addEventListener('webkitfullscreenchange', onFsChange);
  document.addEventListener('mozfullscreenchange', onFsChange);
  document.addEventListener('MSFullscreenChange', onFsChange);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAutoRestore);
  } else {
    initAutoRestore();
  }
})();
