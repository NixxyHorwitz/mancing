/**
 * MancingCuan — Casual Game Style Toast System
 * Usage: nToast('Pesan kamu', 'success'|'error'|'info'|'warn')
 *        nToast.copy('teks') — copy + toast otomatis
 */
(function() {
  let _container = null;

  function getContainer() {
    if (_container) return _container;
    _container = document.createElement('div');
    _container.id = 'cg-toast-container';
    _container.style.cssText = [
      'position:fixed','top:20px','left:50%',
      'transform:translateX(-50%)',
      'z-index:99999',
      'display:flex','flex-direction:column','align-items:center',
      'gap:10px','pointer-events:none','width:calc(100% - 32px)','max-width:360px'
    ].join(';');
    document.body.appendChild(_container);
    return _container;
  }

  // Casual game icon style: round icon box with emoji
  const ICONS = {
    success: '✅',
    error:   '💥',
    warn:    '🔔',
    info:    '💡',
    copy:    '📋'
  };

  // Warm pastel backgrounds like the image
  const BGS = {
    success: 'linear-gradient(135deg, #d4fce8 0%, #f0fdf4 100%)',
    error:   'linear-gradient(135deg, #ffe4e4 0%, #fff5f5 100%)',
    warn:    'linear-gradient(135deg, #fff3c0 0%, #fffbe8 100%)',
    info:    'linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%)',
    copy:    'linear-gradient(135deg, #d4fce8 0%, #f0fdf4 100%)'
  };

  // Icon box background (the round colored circle on left)
  const ICON_BGS = {
    success: 'linear-gradient(135deg, #34d399, #10b981)',
    error:   'linear-gradient(135deg, #f87171, #ef4444)',
    warn:    'linear-gradient(135deg, #fde047, #f59e0b)',
    info:    'linear-gradient(135deg, #60a5fa, #3b82f6)',
    copy:    'linear-gradient(135deg, #34d399, #10b981)'
  };

  const ICON_SHADOWS = {
    success: '0 4px 0 #059669',
    error:   '0 4px 0 #b91c1c',
    warn:    '0 4px 0 #d97706',
    info:    '0 4px 0 #1d4ed8',
    copy:    '0 4px 0 #059669'
  };

  // Border colors (thick, game-like)
  const BORDERS = {
    success: '#6ee7b7',
    error:   '#fca5a5',
    warn:    '#fde68a',
    info:    '#93c5fd',
    copy:    '#6ee7b7'
  };

  // Bottom shadow (flat 3D game effect)
  const SHADOWS = {
    success: '0 6px 0 #a7f3d0, 0 8px 16px rgba(16,185,129,0.15)',
    error:   '0 6px 0 #fecaca, 0 8px 16px rgba(239,68,68,0.15)',
    warn:    '0 6px 0 #fcd34d, 0 8px 16px rgba(245,158,11,0.2)',
    info:    '0 6px 0 #bfdbfe, 0 8px 16px rgba(59,130,246,0.15)',
    copy:    '0 6px 0 #a7f3d0, 0 8px 16px rgba(16,185,129,0.15)'
  };

  // Text colors (dark warm tones, not white)
  const TEXT_COLORS = {
    success: '#065f46',
    error:   '#991b1b',
    warn:    '#92400e',
    info:    '#1e3a8a',
    copy:    '#065f46'
  };

  // Decorative dots (3 small circles like in the image)
  function makeDots(color) {
    return '<div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;opacity:0.35">'
      + '<div style="width:6px;height:6px;border-radius:50%;background:' + color + '"></div>'
      + '<div style="width:4px;height:4px;border-radius:50%;background:' + color + '"></div>'
      + '<div style="width:5px;height:5px;border-radius:50%;background:' + color + '"></div>'
      + '</div>';
  }

  window.nToast = function(msg, type='info', duration=2800) {
    const el        = document.createElement('div');
    const icon      = ICONS[type]       || ICONS.info;
    const bg        = BGS[type]         || BGS.info;
    const iconBg    = ICON_BGS[type]    || ICON_BGS.info;
    const iconSh    = ICON_SHADOWS[type]|| ICON_SHADOWS.info;
    const bd        = BORDERS[type]     || BORDERS.info;
    const sh        = SHADOWS[type]     || SHADOWS.info;
    const txtColor  = TEXT_COLORS[type] || TEXT_COLORS.info;

    el.style.cssText = [
      'background:' + bg,
      'border:2.5px solid ' + bd,
      'border-radius:20px',
      'box-shadow:' + sh,
      'padding:10px 14px 10px 10px',
      'display:flex','align-items:center','gap:10px',
      'font-family:Nunito,Inter,sans-serif',
      'font-size:13px','font-weight:800',
      'line-height:1.35',
      'color:' + txtColor,
      'pointer-events:auto',
      'width:100%',
      'opacity:0',
      'transform:translateY(-24px) scale(0.88)',
      'transition:opacity .35s ease, transform .45s cubic-bezier(0.34, 1.56, 0.64, 1)',
      'position:relative',
      'overflow:hidden',
    ].join(';');

    // Icon circle (like in image: round, colored, with emoji)
    const iconHtml = '<div style="'
      + 'width:44px;height:44px;border-radius:14px;flex-shrink:0;'
      + 'background:' + iconBg + ';'
      + 'box-shadow:' + iconSh + ';'
      + 'display:flex;align-items:center;justify-content:center;'
      + 'font-size:22px;'
      + 'border:2px solid rgba(255,255,255,0.6);'
      + '">' + icon + '</div>';

    el.innerHTML = iconHtml
      + '<span style="flex:1;line-height:1.4">' + msg + '</span>'
      + makeDots(txtColor);

    getContainer().appendChild(el);

    // Animate in
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        el.style.opacity   = '1';
        el.style.transform = 'translateY(0) scale(1)';
      });
    });

    // Animate out
    setTimeout(() => {
      el.style.opacity    = '0';
      el.style.transform  = 'translateY(-12px) scale(0.92)';
      el.style.transition = 'opacity .2s ease, transform .2s ease';
      setTimeout(() => el.remove(), 220);
    }, duration);
  };

  // Convenience: copy to clipboard + show toast
  nToast.copy = function(text, label) {
    const display = label || text;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text)
        .then(() => nToast('Disalin: ' + display, 'copy'))
        .catch(() => _fallbackCopy(text, display));
    } else {
      _fallbackCopy(text, display);
    }
  };

  function _fallbackCopy(text, display) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand('copy');
      nToast('Disalin: ' + display, 'copy');
    } catch(e) {
      nToast('Salin manual: ' + text, 'warn', 5000);
    }
    document.body.removeChild(ta);
  }
})();
