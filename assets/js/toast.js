/**
 * MancingCuan — Toast bertema Coral Reef Claymorphism
 * Pemakaian:  nToast('Pesan', 'success'|'error'|'warn'|'info'|'copy', durasiMs)
 *             nToast.copy('teks', 'Label') — salin ke clipboard + toast
 * Pesan boleh berisi HTML sederhana (<b>, <strong>). Emoji di awal pesan
 * dibuang agar tampilan konsisten dengan ikon tema.
 */
(function () {
  'use strict';
  var container = null;

  var THEME = {
    success: { grad: 'linear-gradient(145deg,#6ee7b7,#059669)', glow: 'rgba(5,150,105,.35)',  ink: '#065f46', bar: '#10b981', label: 'Berhasil' },
    error:   { grad: 'linear-gradient(145deg,#fda4af,#e11d48)', glow: 'rgba(225,29,72,.35)',  ink: '#9f1239', bar: '#f43f5e', label: 'Gagal' },
    warn:    { grad: 'linear-gradient(145deg,#fde68a,#f59e0b)', glow: 'rgba(245,158,11,.35)', ink: '#92400e', bar: '#f59e0b', label: 'Perhatian' },
    info:    { grad: 'linear-gradient(145deg,#c084fc,#7c3aed)', glow: 'rgba(124,58,237,.32)', ink: '#5b21b6', bar: '#a855f7', label: 'Info' },
    copy:    { grad: 'linear-gradient(145deg,#67e8f9,#0891b2)', glow: 'rgba(8,145,178,.32)',  ink: '#155e75', bar: '#22d3ee', label: 'Disalin' }
  };

  var ICONS = {
    success: '<path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
    error:   '<path d="M8.5 8.5l7 7M15.5 8.5l-7 7" stroke="#fff" stroke-width="2.6" stroke-linecap="round" fill="none"/>',
    warn:    '<path d="M12 7v6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><circle cx="12" cy="16.8" r="1.5" fill="#fff"/>',
    info:    '<circle cx="12" cy="7.6" r="1.5" fill="#fff"/><path d="M12 11v6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>',
    copy:    '<rect x="8.5" y="8.5" width="9" height="9" rx="2.2" stroke="#fff" stroke-width="2" fill="none"/><path d="M6.5 14.5V7.8c0-.7.6-1.3 1.3-1.3h6.7" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/>'
  };

  function injectStyle() {
    if (document.getElementById('ntoast-style')) return;
    var st = document.createElement('style');
    st.id = 'ntoast-style';
    st.textContent = [
      '#ntoast-wrap{position:fixed;top:calc(env(safe-area-inset-top,0px) + 14px);left:50%;transform:translateX(-50%);z-index:2147483000;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none;width:calc(100% - 28px);max-width:380px}',
      '.ntoast{position:relative;width:100%;display:flex;align-items:center;gap:11px;padding:10px 12px 12px 10px;border:2px solid #fff;border-radius:20px;',
      'background:linear-gradient(160deg,#ffffff 0%,#fff7ed 60%,#ffedd5 100%);',
      'box-shadow:0 14px 30px rgba(30,27,46,.16),0 4px 10px rgba(30,27,46,.06),inset 3px 3px 6px #fff,inset -4px -4px 9px rgba(251,146,60,.16);',
      'font-family:Nunito,Inter,-apple-system,sans-serif;pointer-events:auto;cursor:pointer;overflow:hidden;',
      'opacity:0;transform:translateY(-18px) scale(.92);transition:opacity .3s ease,transform .45s cubic-bezier(.34,1.56,.64,1)}',
      '.ntoast.in{opacity:1;transform:none}',
      '.ntoast.out{opacity:0;transform:translateY(-10px) scale(.95);transition:opacity .2s ease,transform .2s ease}',
      '.ntoast-ico{width:40px;height:40px;flex-shrink:0;border-radius:14px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.9);box-shadow:inset 2px 2px 3px rgba(255,255,255,.55),inset -2px -2px 4px rgba(0,0,0,.15)}',
      '.ntoast-ico svg{width:24px;height:24px}',
      '.ntoast-body{flex:1;min-width:0}',
      '.ntoast-lbl{font-size:9.5px;font-weight:900;letter-spacing:.7px;text-transform:uppercase;opacity:.75;margin-bottom:1px}',
      '.ntoast-msg{font-size:12.5px;font-weight:800;line-height:1.4;color:#1e1b2e;word-wrap:break-word}',
      '.ntoast-msg b,.ntoast-msg strong{font-weight:900}',
      '.ntoast-x{width:24px;height:24px;flex-shrink:0;border:none;border-radius:50%;background:#fff;color:#6b6478;font-size:15px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:inset -1px -1px 2px rgba(251,146,60,.2)}',
      '.ntoast-bar{position:absolute;left:12px;right:12px;bottom:5px;height:3px;border-radius:999px;background:rgba(0,0,0,.06);overflow:hidden}',
      '.ntoast-bar i{display:block;height:100%;border-radius:999px;transform-origin:left center}',
      '@media (prefers-reduced-motion:reduce){.ntoast{transition:opacity .2s ease}.ntoast-bar i{animation:none!important}}'
    ].join('');
    document.head.appendChild(st);
  }

  function getContainer() {
    if (container && document.body.contains(container)) return container;
    injectStyle();
    container = document.createElement('div');
    container.id = 'ntoast-wrap';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');
    document.body.appendChild(container);
    return container;
  }

  function cleanMsg(msg) {
    var s = String(msg == null ? '' : msg);
    try { s = s.replace(/^(?:[\p{Extended_Pictographic}️‍]|\s)+/u, ''); } catch (e) {}
    return s;
  }

  window.nToast = function (msg, type, duration) {
    type = THEME[type] ? type : 'info';
    duration = duration || 3000;
    var t = THEME[type];
    var wrap = getContainer();
    while (wrap.children.length >= 3) wrap.removeChild(wrap.firstChild);

    var el = document.createElement('div');
    el.className = 'ntoast';
    el.innerHTML =
      '<span class="ntoast-ico" style="background:' + t.grad + ';box-shadow:0 5px 12px ' + t.glow + ',inset 2px 2px 3px rgba(255,255,255,.55),inset -2px -2px 4px rgba(0,0,0,.15)">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS[type] + '</svg></span>' +
      '<div class="ntoast-body"><div class="ntoast-lbl" style="color:' + t.ink + '">' + t.label + '</div>' +
        '<div class="ntoast-msg">' + cleanMsg(msg) + '</div></div>' +
      '<button type="button" class="ntoast-x" aria-label="Tutup">&times;</button>' +
      '<span class="ntoast-bar"><i style="background:' + t.bar + '"></i></span>';
    wrap.appendChild(el);

    var bar = el.querySelector('.ntoast-bar i');
    if (bar.animate) bar.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: duration, easing: 'linear', fill: 'forwards' });

    var closed = false;
    function close() {
      if (closed) return; closed = true;
      el.classList.remove('in'); el.classList.add('out');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }
    el.addEventListener('click', close);
    requestAnimationFrame(function () { requestAnimationFrame(function () { el.classList.add('in'); }); });
    setTimeout(close, duration);
    return el;
  };

  window.nToast.copy = function (text, label) {
    var display = label || text;
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text)
        .then(function () { window.nToast(display + ' berhasil disalin', 'copy'); })
        .catch(function () { fallbackCopy(text, display); });
    } else {
      fallbackCopy(text, display);
    }
  };

  function fallbackCopy(text, display) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand('copy');
      window.nToast(display + ' berhasil disalin', 'copy');
    } catch (e) {
      window.nToast('Salin manual: ' + text, 'warn', 5000);
    }
    document.body.removeChild(ta);
  }
})();
