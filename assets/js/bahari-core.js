/* ══════════════════════════════════════════════════════════════════
   BAHARI CORE — modul bersama dunia 3D MancingCuan
   Dipakai oleh: user/minigame.php (Pemancingan Bahari Nusantara)
   Isi: util, getar, suara (SFX + ambience laut), data spesies,
        pembuat model 3D ikan & peti, gelombang laut (JS == GLSL).
   Fungsi 3D memanggil THREE saat dipakai, jadi file ini boleh dimuat
   sebelum three.min.js.
   ══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const lerp = (a, b, t) => a + (b - a) * t;
const rand = (a, b) => a + Math.random() * (b - a);
const smooth = (a, b, x) => { const t = clamp((x - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };
const damp = (dt, k) => 1 - Math.exp(-dt * k);
const fmtRp = (n) => 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');

/* ───────────────────────── GETAR ───────────────────────── */
const Haptic = {
  buzz(p) {
    try {
      const ua = navigator.userActivation;
      if (navigator.vibrate && (!ua || ua.hasBeenActive)) navigator.vibrate(p);
    } catch (e) {}
  }
};

/* ───────────────────────── SFX ─────────────────────────
   Cipratan air disintesis berlapis: semburan air (noise bandpass yang
   menyapu turun), dentum benda masuk air, dan tetesan-tetesan kecil
   (nada naik cepat) agar terdengar seperti air sungguhan.             */
const Sfx = (() => {
  let ctx = null, muted = false, out = null, sampleCfg = null, loading = false;
  const samples = { small: [], big: [] };
  function ensure() {
    if (!ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) {
        ctx = new AC();
        out = ctx.createDynamicsCompressor();
        out.threshold.value = -14; out.ratio.value = 4;
        out.connect(ctx.destination);
      }
    }
    if (ctx && ctx.state === 'suspended') ctx.resume();
    if (ctx && sampleCfg && !loading) loadSamples();
    return ctx;
  }
  /* Rekaman cipratan asli (BigSoundBank, CC0) — dimuat setelah audio dibuka */
  function loadSamples() {
    loading = true;
    ['small', 'big'].forEach((kind) => (sampleCfg[kind] || []).forEach((url) => {
      fetch(url).then((r) => r.arrayBuffer())
        .then((ab) => new Promise((res, rej) => ctx.decodeAudioData(ab, res, rej)))
        .then((buf) => samples[kind].push(buf)).catch(() => {});
    }));
  }
  function playSample(k) {
    const set = k >= 0.85 && samples.big.length ? samples.big : samples.small;
    if (!set.length) return false;
    const buf = set[Math.floor(Math.random() * set.length)];
    const t0 = ctx.currentTime, len = Math.min(buf.duration, 0.7 + 1.3 * k);
    const src = ctx.createBufferSource(); src.buffer = buf;
    src.playbackRate.value = rand(0.92, 1.12) * (k < 0.3 ? 1.25 : 1);
    const g = ctx.createGain();
    g.gain.setValueAtTime(clamp(0.25 + 0.5 * k, 0.15, 0.9), t0);
    g.gain.setValueAtTime(clamp(0.25 + 0.5 * k, 0.15, 0.9), t0 + len * 0.6);
    g.gain.exponentialRampToValueAtTime(0.001, t0 + len);
    let node = g;
    if (ctx.createStereoPanner) { const pn = ctx.createStereoPanner(); pn.pan.value = rand(-0.3, 0.3); g.connect(pn); node = pn; }
    src.connect(g); node.connect(out);
    src.start(t0); src.stop(t0 + len + 0.05);
    return true;
  }
  function noiseBuf(c, dur, curve) {
    const len = Math.max(1, Math.floor(c.sampleRate * dur));
    const buf = c.createBuffer(1, len, c.sampleRate);
    const d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, curve || 2);
    return buf;
  }
  function tone(freq, dur, type, vol, slide, delay) {
    if (muted) return;
    const c = ensure(); if (!c) return;
    const t0 = c.currentTime + (delay || 0);
    const o = c.createOscillator(), g = c.createGain();
    o.type = type || 'sine';
    o.frequency.setValueAtTime(freq, t0);
    if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(40, freq + slide), t0 + dur);
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.exponentialRampToValueAtTime(vol || 0.1, t0 + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    o.connect(g); g.connect(out);
    o.start(t0); o.stop(t0 + dur + 0.02);
  }
  function droplet(delay, vol) {
    const c = ensure(); if (!c || muted) return;
    const t0 = c.currentTime + delay, f = rand(900, 2600);
    const o = c.createOscillator(), g = c.createGain();
    o.type = 'sine';
    o.frequency.setValueAtTime(f, t0);
    o.frequency.exponentialRampToValueAtTime(f * rand(1.4, 1.9), t0 + 0.045);
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.exponentialRampToValueAtTime(vol, t0 + 0.004);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.07);
    o.connect(g); g.connect(out);
    o.start(t0); o.stop(t0 + 0.09);
  }
  /** Cipratan air; k = kekuatan (0.2 riak kecil … 1.6 ikan melompat) */
  function splash(k) {
    if (muted) return;
    const c = ensure(); if (!c) return;
    k = k == null ? 1 : k;
    if (playSample(k)) { // rekaman asli + beberapa tetesan sintetis
      const n = Math.round(1 + 3 * k);
      for (let i = 0; i < n; i++) droplet(rand(0.12, 0.3 + 0.4 * k), rand(0.01, 0.025));
      return;
    }
    const t0 = c.currentTime, dur = 0.25 + 0.4 * k;
    // 1) semburan air
    const src = c.createBufferSource(); src.buffer = noiseBuf(c, dur, 2.4);
    const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 0.7;
    bp.frequency.setValueAtTime(3200, t0); bp.frequency.exponentialRampToValueAtTime(650, t0 + dur);
    const g = c.createGain(); g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.32 * k, t0 + 0.012); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    src.connect(bp); bp.connect(g); g.connect(out); src.start(t0);
    // 2) dentum masuk air
    if (k > 0.35) {
      const s2 = c.createBufferSource(); s2.buffer = noiseBuf(c, 0.18, 1.5);
      const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 420;
      const g2 = c.createGain(); g2.gain.value = 0.35 * k;
      s2.connect(lp); lp.connect(g2); g2.connect(out); s2.start(t0);
      tone(190, 0.2, 'sine', 0.14 * k, -130);
    }
    // 3) tetesan air yang jatuh kembali
    const drops = Math.round(2 + 7 * k);
    for (let i = 0; i < drops; i++) droplet(rand(0.06, 0.25 + 0.5 * k), rand(0.015, 0.045) * Math.min(1.2, k + 0.3));
  }
  return {
    unlock: ensure,
    ctx: ensure,
    useSamples(cfg) { sampleCfg = cfg; if (ctx) loadSamples(); },
    isMuted: () => muted,
    toggle() { muted = !muted; return muted; },
    splash,
    whoosh() {
      if (muted) return; const c = ensure(); if (!c) return;
      const s = c.createBufferSource(); s.buffer = noiseBuf(c, 0.45, 1.2);
      const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 1.2;
      bp.frequency.setValueAtTime(600, c.currentTime); bp.frequency.exponentialRampToValueAtTime(2600, c.currentTime + 0.35);
      const g = c.createGain(); g.gain.value = 0.14;
      s.connect(bp); bp.connect(g); g.connect(out); s.start();
      tone(1800, 0.3, 'triangle', 0.015, -900, 0.05); // desis senar
    },
    plop() { splash(0.45); tone(380, 0.12, 'sine', 0.08, -200); },
    nibble() { splash(0.15); tone(300, 0.06, 'sine', 0.04, -60); },
    strike() { splash(1.1); tone(660, 0.12, 'square', 0.05, 0, 0.05); tone(990, 0.2, 'square', 0.05, 0, 0.16); },
    tick() { tone(1600, 0.025, 'square', 0.02); },
    churn() { splash(rand(0.18, 0.32)); },
    snap() { tone(240, 0.35, 'sawtooth', 0.08, -160); },
    coin() { tone(1320, 0.12, 'triangle', 0.07, 500); },
    cash() { tone(1046, 0.1, 'triangle', 0.07); tone(1568, 0.16, 'triangle', 0.07, 0, 0.08); tone(2093, 0.22, 'triangle', 0.06, 0, 0.17); },
    pop() { tone(700, 0.09, 'sine', 0.06, 300); },
    thud() { tone(140, 0.15, 'sine', 0.1, -60); }
  };
})();

/* ───────────────────────── AMBIENCE LAUT ─────────────────────────
   Lebih tenang & tidak nonstop: dasar laut sangat pelan, lalu ombak
   datang satu-satu dengan jeda acak (kadang hening lebih lama),
   kecipak air sesekali, dan camar jarang terdengar.                    */
const Ambience = (() => {
  let started = false, master = null, ctx = null, el = null, buf = null, volume = 0.35;
  function brownBuffer(c, sec) {
    const len = Math.floor(c.sampleRate * sec), b = c.createBuffer(2, len, c.sampleRate);
    for (let ch = 0; ch < 2; ch++) {
      const d = b.getChannelData(ch); let last = 0;
      for (let i = 0; i < len; i++) { last = (last + 0.02 * (Math.random() * 2 - 1)) / 1.02; d[i] = last * 3.5; }
      const fade = Math.floor(c.sampleRate * 0.3);
      for (let i = 0; i < fade; i++) { const k = i / fade; d[i] *= k; d[len - 1 - i] *= k; }
    }
    return b;
  }
  function pan(node, v) {
    if (!ctx.createStereoPanner) return node;
    const p = ctx.createStereoPanner(); p.pan.value = v; node.connect(p); return p;
  }
  function bed() {
    const src = ctx.createBufferSource(); src.buffer = buf; src.loop = true;
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 320;
    const g = ctx.createGain(); g.gain.value = 0.07;
    src.connect(lp); lp.connect(g); g.connect(master); src.start();
  }
  function wave() {
    if (!started) return;
    const t0 = ctx.currentTime, rise = rand(1.4, 2.6), fall = rand(2.2, 3.8), peak = rand(0.1, 0.2);
    const src = ctx.createBufferSource(); src.buffer = buf; src.playbackRate.value = rand(0.8, 1.1);
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass';
    lp.frequency.setValueAtTime(260, t0);
    lp.frequency.linearRampToValueAtTime(rand(700, 1100), t0 + rise);
    lp.frequency.linearRampToValueAtTime(300, t0 + rise + fall);
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.linearRampToValueAtTime(peak, t0 + rise);
    g.gain.linearRampToValueAtTime(0.0001, t0 + rise + fall);
    src.connect(lp); lp.connect(g); pan(g, rand(-0.6, 0.6)).connect(master);
    src.start(t0, Math.random() * 4); src.stop(t0 + rise + fall + 0.1);
    const lull = Math.random() < 0.25 ? rand(9000, 15000) : rand(3500, 7500); // kadang hening lebih lama
    setTimeout(wave, (rise + fall) * 600 + lull);
  }
  function lap() {
    if (!started) return;
    const len = Math.floor(ctx.sampleRate * 0.3), b = ctx.createBuffer(1, len, ctx.sampleRate), d = b.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.sin(Math.PI * i / len);
    const s = ctx.createBufferSource(); s.buffer = b;
    const f = ctx.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = rand(450, 750);
    const g = ctx.createGain(); g.gain.value = rand(0.025, 0.05);
    s.connect(f); f.connect(g); g.connect(master); s.start();
    setTimeout(lap, rand(3000, 7000));
  }
  function gull() {
    if (!started) return;
    const calls = 1 + Math.floor(Math.random() * 2), pv = rand(-0.8, 0.8);
    for (let k = 0; k < calls; k++) {
      const t0 = ctx.currentTime + k * rand(0.32, 0.45);
      const o = ctx.createOscillator(); o.type = 'sawtooth';
      o.frequency.setValueAtTime(rand(1700, 2100), t0);
      o.frequency.exponentialRampToValueAtTime(rand(1050, 1250), t0 + 0.3);
      const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1700; bp.Q.value = 1.4;
      const g = ctx.createGain();
      g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.012, t0 + 0.04); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.34);
      o.connect(bp); bp.connect(g); pan(g, pv).connect(master);
      o.start(t0); o.stop(t0 + 0.36);
    }
    setTimeout(gull, rand(20000, 42000));
  }
  return {
    start(url, vol) {
      if (started) return;
      ctx = Sfx.ctx(); if (!ctx) return;
      started = true;
      if (vol != null) volume = vol;
      master = ctx.createGain(); master.gain.value = 0.0001; master.connect(ctx.destination);
      master.gain.exponentialRampToValueAtTime(Sfx.isMuted() ? 0.0001 : volume, ctx.currentTime + 3);
      buf = brownBuffer(ctx, 8);
      if (url) {
        el = new Audio(url); el.loop = true;
        try {
          const src = ctx.createMediaElementSource(el); const g = ctx.createGain(); g.gain.value = 0.6;
          src.connect(g); g.connect(master);
        } catch (e) { el.volume = 0.3; }
        el.play().catch(() => {});
      } else {
        bed();
        setTimeout(wave, 800);
        setTimeout(gull, rand(8000, 16000));
      }
      setTimeout(lap, 2000);
    },
    setMuted(m) {
      if (!master) return;
      master.gain.cancelScheduledValues(ctx.currentTime);
      master.gain.setTargetAtTime(m ? 0.0001 : volume, ctx.currentTime, 0.25);
    }
  };
})();

/* ───────────────────────── DATA SPESIES ─────────────────────────
   Profil tubuh = ketinggian punggung (top), perut (bot), lebar (wid)
   di titik-titik u sepanjang badan (-1 ekor → +1 moncong), panjang L=1. */
const U_KNOTS = [-1, -0.85, -0.6, -0.3, 0, 0.3, 0.6, 0.8, 0.92, 1];
const BADGES = ['biasa', 'bagus', 'langka', 'super', 'jackpot'];
const BADGE_LABEL = { biasa: 'Biasa', bagus: 'Bagus', langka: 'Langka', super: 'Super', jackpot: 'Jackpot', zonk: 'Zonk' };
const BADGE_SPECIES = { biasa: 'mujair', bagus: 'gurame', langka: 'kakap', super: 'barramundi', jackpot: 'chest', zonk: 'boot' }; // zonk = sampah, tidak dijual

const SPECIES = {
  mujair: {
    title: 'Ikan Mujair / Nila', latin: 'Oreochromis mossambicus',
    top: [0.055, 0.0975, 0.14, 0.21, 0.235, 0.225, 0.19, 0.14, 0.09, 0],
    bot: [0.055, 0.0875, 0.12, 0.17, 0.19, 0.17, 0.14, 0.10, 0.06, 0],
    wid: [0.03, 0.045, 0.06, 0.085, 0.095, 0.09, 0.08, 0.06, 0.04, 0],
    back: 0x4a5844, flank: 0xa2ad92, belly: 0xe4e8d8, bars: 9, scaleRep: [12, 6],
    shininess: 45, specular: 0x666666,
    tail: 'truncate', tailSize: 1.0, finIn: 0x6b7562, finOut: 0xf97316, finBars: true,
    dorsal: [{ u0: -0.62, u1: 0.5, h: 0.15, spines: 0.55 }],
    anal: { u0: -0.66, u1: -0.18, h: 0.09 },
    eye: { u: 0.8, r: 0.03, iris: 0xc8a032 }
  },
  gurame: {
    title: 'Ikan Gurame', latin: 'Osphronemus goramy',
    top: [0.055, 0.122, 0.19, 0.29, 0.32, 0.32, 0.30, 0.255, 0.15, 0],
    bot: [0.055, 0.113, 0.17, 0.26, 0.29, 0.27, 0.22, 0.16, 0.09, 0],
    wid: [0.03, 0.0475, 0.065, 0.09, 0.10, 0.10, 0.09, 0.075, 0.05, 0],
    back: 0x6b5a2e, flank: 0xd8c27f, belly: 0xf4ecd4, scaleRep: [7, 4],
    shininess: 60, specular: 0x8a7a4a,
    tail: 'round', tailSize: 1.05, finIn: 0x9a8550, finOut: 0xd9c38a,
    dorsal: [{ u0: -0.66, u1: 0.18, h: 0.1, rearHigh: true }],
    anal: { u0: -0.7, u1: 0.25, h: 0.13, rearHigh: true },
    eye: { u: 0.8, r: 0.026, iris: 0xc2410c },
    filaments: true
  },
  kakap: {
    title: 'Ikan Kakap Merah', latin: 'Lutjanus campechanus',
    top: [0.055, 0.0925, 0.13, 0.20, 0.225, 0.215, 0.17, 0.115, 0.06, 0],
    bot: [0.055, 0.0825, 0.11, 0.155, 0.17, 0.155, 0.12, 0.085, 0.045, 0],
    wid: [0.03, 0.05, 0.07, 0.10, 0.11, 0.105, 0.09, 0.065, 0.035, 0],
    back: 0xa3132a, flank: 0xef3b4a, belly: 0xffc7c2, scaleRep: [11, 5],
    shininess: 70, specular: 0x8a5a5a,
    tail: 'forked', tailSize: 1.15, finIn: 0xd6283b, finOut: 0xff7a7a,
    dorsal: [{ u0: -0.55, u1: 0.42, h: 0.13, spines: 0.5 }],
    anal: { u0: -0.58, u1: -0.2, h: 0.1 },
    eye: { u: 0.78, r: 0.038, iris: 0xdc2626 },
    teeth: true
  },
  barramundi: {
    title: 'Ikan Barramundi (Kakap Putih)', latin: 'Lates calcarifer',
    top: [0.055, 0.08, 0.105, 0.15, 0.165, 0.16, 0.13, 0.085, 0.065, 0],
    bot: [0.055, 0.0725, 0.09, 0.125, 0.135, 0.125, 0.10, 0.075, 0.05, 0],
    wid: [0.03, 0.045, 0.06, 0.08, 0.088, 0.085, 0.075, 0.055, 0.035, 0],
    back: 0x56645f, flank: 0xd9dfe4, belly: 0xf8fafc, scaleRep: [12, 6],
    shininess: 160, specular: 0xffffff, chrome: true,
    tail: 'round', tailSize: 1.0, finIn: 0x6b7280, finOut: 0xa8b0b8,
    dorsal: [{ u0: 0.04, u1: 0.42, h: 0.14, spines: 1.0 }, { u0: -0.52, u1: -0.03, h: 0.11 }],
    anal: { u0: -0.56, u1: -0.22, h: 0.1 },
    eye: { u: 0.8, r: 0.032, iris: 0xf59e0b, glow: true },
    jaw: true
  }
};

/* Kompresi ujung kepala agar moncong membulat, bukan runcing seperti jarum */
function snoutX(u) { return u > 0.78 ? 0.5 * (0.78 + (u - 0.78) * 0.5) : u * 0.5; }
const SNOUT_TIP = snoutX(1);

function profile(arr) {
  return (u) => {
    u = clamp(u, -1, 1);
    let i = 0;
    while (i < U_KNOTS.length - 2 && u > U_KNOTS[i + 1]) i++;
    const t = (u - U_KNOTS[i]) / (U_KNOTS[i + 1] - U_KNOTS[i]);
    const p0 = arr[Math.max(0, i - 1)], p1 = arr[i], p2 = arr[i + 1], p3 = arr[Math.min(arr.length - 1, i + 2)];
    const t2 = t * t, t3 = t2 * t;
    return Math.max(0, 0.5 * ((2 * p1) + (-p0 + p2) * t + (2 * p0 - 5 * p1 + 4 * p2 - p3) * t2 + (-p0 + 3 * p1 - 3 * p2 + p3) * t3));
  };
}

/* ───────────────────────── PEMBUAT MODEL 3D ───────────────────────── */

const TexCache = {};
function canvasTex(key, w, h, draw, repeat) {
  if (TexCache[key]) return TexCache[key];
  const c = document.createElement('canvas'); c.width = w; c.height = h;
  draw(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c);
  if (repeat) { t.wrapS = t.wrapT = THREE.RepeatWrapping; }
  t.__shared = true;
  TexCache[key] = t;
  return t;
}
function dotTexture() {
  return canvasTex('dot', 64, 64, (g, w, h) => {
    const gr = g.createRadialGradient(32, 32, 0, 32, 32, 32);
    gr.addColorStop(0, 'rgba(255,255,255,1)');
    gr.addColorStop(0.35, 'rgba(255,255,255,0.65)');
    gr.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = gr; g.fillRect(0, 0, w, h);
  });
}
function scaleTexture() {
  // Pola sisik tumpang-tindih (dipakai sebagai map + bumpMap)
  return canvasTex('scales', 128, 128, (g, w, h) => {
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, w, h);
    const s = 16;
    for (let row = -1; row < h / (s * 0.5) + 1; row++) {
      for (let col = -1; col < w / s + 1; col++) {
        const x = col * s + (row % 2 ? s / 2 : 0), y = row * s * 0.5;
        g.beginPath(); g.arc(x, y, s * 0.55, 0.15 * Math.PI, 0.85 * Math.PI);
        g.strokeStyle = 'rgba(0,0,0,0.16)'; g.lineWidth = 2; g.stroke();
        g.beginPath(); g.arc(x, y - 1.5, s * 0.45, 0.25 * Math.PI, 0.75 * Math.PI);
        g.strokeStyle = 'rgba(255,255,255,0.5)'; g.lineWidth = 1.2; g.stroke();
      }
    }
  }, true);
}

function shadeFinGeometry(geo, cIn, cOut, tFn, bars) {
  const p = geo.attributes.position;
  const cols = new Float32Array(p.count * 3);
  const a = new THREE.Color(cIn), b = new THREE.Color(cOut), tmp = new THREE.Color();
  for (let i = 0; i < p.count; i++) {
    const x = p.getX(i), y = p.getY(i);
    const t = clamp(tFn(x, y), 0, 1);
    tmp.copy(a).lerp(b, Math.pow(t, 1.4));
    if (bars && Math.sin(x * 60) > 0.45) tmp.multiplyScalar(0.8);
    cols[i * 3] = tmp.r; cols[i * 3 + 1] = tmp.g; cols[i * 3 + 2] = tmp.b;
  }
  geo.setAttribute('color', new THREE.BufferAttribute(cols, 3));
}
function finMaterial(sp) {
  return new THREE.MeshPhongMaterial({
    vertexColors: true, side: THREE.DoubleSide, transparent: true, opacity: 0.88,
    shininess: 40, specular: 0x444444, depthWrite: true
  });
}
function shapeFrom(points) {
  const s = new THREE.Shape();
  s.moveTo(points[0][0], points[0][1]);
  for (let i = 1; i < points.length; i++) s.lineTo(points[i][0], points[i][1]);
  s.closePath();
  return s;
}

function buildFish(spKey) {
  const sp = SPECIES[spKey];
  const top = profile(sp.top), bot = profile(sp.bot), wid = profile(sp.wid);
  const root = new THREE.Group();
  const uniforms = { uTime: { value: 0 }, uAmp: { value: 0.04 }, uFreq: { value: 7 } };

  /* Badan: bola → dideformasi mengikuti profil spesies */
  const geo = new THREE.SphereGeometry(1, 48, 28);
  geo.rotateZ(-Math.PI / 2); // kutub ke sumbu X (moncong +X)
  const pos = geo.attributes.position;
  const cols = new Float32Array(pos.count * 3);
  const cB = new THREE.Color(sp.back), cF = new THREE.Color(sp.flank), cU = new THREE.Color(sp.belly);
  const white = new THREE.Color(0xffffff), tmp = new THREE.Color();
  for (let i = 0; i < pos.count; i++) {
    const u = pos.getX(i), y = pos.getY(i), z = pos.getZ(i);
    const Y = y >= 0 ? y * top(u) : y * bot(u);
    pos.setXYZ(i, snoutX(u), Y, z * wid(u));
    tmp.copy(cU).lerp(cF, smooth(-0.75, 0.05, y)).lerp(cB, smooth(0.15, 0.9, y));
    if (sp.bars && y > -0.6 && u < 0.6 && u > -0.92) {
      const b = Math.sin((u * 0.5 + 0.5) * Math.PI * sp.bars * 2);
      if (b > 0.3) tmp.multiplyScalar(0.74);
    }
    if (Math.abs(u - 0.6) < 0.022 && y > -0.65 && y < 0.75) tmp.multiplyScalar(0.78);   // tutup insang
    if (Math.abs(y - 0.3) < 0.035 && u < 0.58 && u > -0.85) tmp.lerp(white, 0.18);       // gurat sisi
    if (sp.chrome && y > -0.2 && y < 0.35) tmp.lerp(white, 0.25);                        // kilau krom
    cols[i * 3] = tmp.r; cols[i * 3 + 1] = tmp.g; cols[i * 3 + 2] = tmp.b;
  }
  geo.setAttribute('color', new THREE.BufferAttribute(cols, 3));
  geo.computeVertexNormals();

  const tex = scaleTexture().clone();
  tex.needsUpdate = true;
  tex.repeat.set(sp.scaleRep[0], sp.scaleRep[1]);
  const bodyMat = new THREE.MeshPhongMaterial({
    vertexColors: true, map: tex, bumpMap: tex, bumpScale: 0.004,
    shininess: sp.shininess, specular: sp.specular,
    emissive: sp.chrome ? 0x1a1f24 : 0x000000
  });
  bodyMat.onBeforeCompile = (sh) => {
    sh.uniforms.uTime = uniforms.uTime; sh.uniforms.uAmp = uniforms.uAmp; sh.uniforms.uFreq = uniforms.uFreq;
    sh.vertexShader = 'uniform float uTime;\nuniform float uAmp;\nuniform float uFreq;\n' +
      sh.vertexShader.replace('#include <begin_vertex>',
        '#include <begin_vertex>\n float sw = 0.5 - transformed.x;\n transformed.z += sin(uTime * uFreq + transformed.x * 6.0) * uAmp * sw * sw;');
  };
  const body = new THREE.Mesh(geo, bodyMat);
  root.add(body);

  const fMat = finMaterial(sp);
  const swayAt = (x, t) => Math.sin(t * uniforms.uFreq.value + x * 6.0) * uniforms.uAmp.value * (0.5 - x) * (0.5 - x);

  /* Ekor */
  const ts = 0.24 * sp.tailSize;
  let tailPts;
  if (sp.tail === 'forked') {
    tailPts = [[0, 0.05], [-0.12 * ts / 0.24, 0.1 * ts / 0.24], [-1.05 * ts, 0.95 * ts], [-0.95 * ts, 0.75 * ts], [-0.62 * ts, 0.05], [-0.62 * ts, -0.05], [-0.95 * ts, -0.75 * ts], [-1.05 * ts, -0.95 * ts], [-0.12 * ts / 0.24, -0.1 * ts / 0.24], [0, -0.05]];
  } else if (sp.tail === 'truncate') {
    tailPts = [[0, 0.05]];
    for (let i = 0; i <= 10; i++) { const a = i / 10; tailPts.push([-ts * (0.35 + 0.65 * Math.sin(a * Math.PI / 2)), lerp(0.03, 0.62 * ts, a)]); }
    for (let i = 0; i <= 8; i++) { const a = i / 8; tailPts.push([-ts * (1.0 - 0.05 * Math.sin(a * Math.PI)), lerp(0.62 * ts, -0.62 * ts, a)]); }
    for (let i = 10; i >= 0; i--) { const a = i / 10; tailPts.push([-ts * (0.35 + 0.65 * Math.sin(a * Math.PI / 2)), -lerp(0.03, 0.62 * ts, a)]); }
    tailPts.push([0, -0.05]);
  } else { // round (kipas membulat)
    tailPts = [[0, 0.05]];
    for (let i = 0; i <= 20; i++) { const a = -Math.PI / 2 + Math.PI * i / 20; tailPts.push([-ts * 0.35 - Math.cos(a) * ts * 0.75, Math.sin(-a) * ts * 0.62]); }
    tailPts.push([0, -0.05]);
  }
  const tailGeo = new THREE.ShapeGeometry(shapeFrom(tailPts));
  shadeFinGeometry(tailGeo, sp.finIn, sp.finOut, (x) => -x / (ts * 1.05), sp.finBars);
  const tail = new THREE.Mesh(tailGeo, fMat);
  const tailPivot = new THREE.Group();
  tailPivot.position.x = -0.49;
  tailPivot.add(tail);
  root.add(tailPivot);

  /* Sirip punggung (mendukung duri tajam & sirip terbelah dua) */
  sp.dorsal.forEach((d) => {
    const N = 30, pts = [];
    for (let i = 0; i <= N; i++) { const u = lerp(d.u1, d.u0, i / N); pts.push([snoutX(u), top(u) - 0.014]); }
    for (let i = N; i >= 0; i--) {
      const s = i / N, u = lerp(d.u1, d.u0, s);
      let prof = smooth(0, 0.1, s) * (1 - smooth(0.82, 1, s));
      prof *= d.rearHigh ? (0.45 + 0.75 * s) : (1 - 0.15 * s);
      let fh = d.h * prof;
      if (d.spines && s < d.spines && i % 2 === 1) fh *= 0.62; // membran di antara duri
      pts.push([snoutX(u), top(u) + fh]);
    }
    const g = new THREE.ShapeGeometry(shapeFrom(pts));
    shadeFinGeometry(g, sp.finIn, sp.finOut, (x, y) => (y - top(x * 2)) / (d.h * 1.1), sp.finBars);
    root.add(new THREE.Mesh(g, fMat));
  });

  /* Sirip dubur */
  {
    const d = sp.anal, N = 20, pts = [];
    for (let i = 0; i <= N; i++) { const u = lerp(d.u1, d.u0, i / N); pts.push([snoutX(u), -bot(u) + 0.014]); }
    for (let i = N; i >= 0; i--) {
      const s = i / N, u = lerp(d.u1, d.u0, s);
      let prof = smooth(0, 0.15, s) * (1 - smooth(0.8, 1, s));
      prof *= d.rearHigh ? (0.5 + 0.7 * s) : 1;
      pts.push([snoutX(u), -bot(u) - d.h * prof]);
    }
    const g = new THREE.ShapeGeometry(shapeFrom(pts));
    shadeFinGeometry(g, sp.finIn, sp.finOut, (x, y) => (-bot(x * 2) - y) / (d.h * 1.1), sp.finBars);
    root.add(new THREE.Mesh(g, fMat));
  }

  /* Sirip dada (kiri-kanan) */
  const pecs = [];
  [-1, 1].forEach((side) => {
    const s = new THREE.Shape();
    s.moveTo(0, 0); s.quadraticCurveTo(-0.06, 0.05, -0.14, 0.012); s.quadraticCurveTo(-0.07, -0.035, 0, 0);
    const g = new THREE.ShapeGeometry(s, 8);
    shadeFinGeometry(g, sp.finIn, sp.finOut, (x) => -x / 0.14, false);
    const m = new THREE.Mesh(g, fMat);
    const piv = new THREE.Group();
    piv.position.set(0.27, -0.03, side * wid(0.55) * 0.98);
    piv.rotation.y = side * 0.55;
    piv.add(m);
    root.add(piv);
    pecs.push({ piv, side });
  });

  /* Sirip perut / sungut cambuk khas gurame */
  const filaments = [];
  [-1, 1].forEach((side) => {
    const base = new THREE.Group();
    base.position.set(0.2, -bot(0.4) * 0.85, side * 0.022);
    root.add(base);
    if (sp.filaments) {
      const seg = 9, len = 0.07;
      const mat = new THREE.MeshPhongMaterial({ color: 0xf3d9a4, shininess: 30 });
      let parent = base; base.rotation.z = -0.95;
      const chain = [];
      for (let i = 0; i < seg; i++) {
        const g = new THREE.CylinderGeometry(0.006 * (1 - i / seg) + 0.002, 0.006 * (1 - (i + 1) / seg) + 0.002, len, 5);
        g.translate(0, -len / 2, 0);
        const piv = new THREE.Group();
        piv.position.y = i === 0 ? 0 : -len;
        piv.add(new THREE.Mesh(g, mat));
        parent.add(piv); parent = piv; chain.push(piv);
      }
      filaments.push({ chain, side });
    } else {
      const s = new THREE.Shape();
      s.moveTo(0, 0); s.lineTo(-0.08, -0.05); s.lineTo(-0.05, 0.005); s.closePath();
      const g = new THREE.ShapeGeometry(s);
      shadeFinGeometry(g, sp.finIn, sp.finOut, (x) => -x / 0.08, false);
      const m = new THREE.Mesh(g, fMat);
      m.rotation.y = side * 0.35;
      base.add(m);
    }
  });

  /* Mata */
  const eu = sp.eye.u, ex = snoutX(eu), ey = top(eu) * 0.28, ez = wid(eu) * 0.84, er = sp.eye.r;
  const scleraMat = new THREE.MeshPhongMaterial({ color: 0xf8fafc, shininess: 120, specular: 0xffffff });
  const irisMat = new THREE.MeshPhongMaterial({ color: sp.eye.iris, shininess: 120, specular: 0xffffff, emissive: sp.eye.glow ? sp.eye.iris : 0x000000, emissiveIntensity: sp.eye.glow ? 0.55 : 0 });
  const pupilMat = new THREE.MeshPhongMaterial({ color: 0x0b0b0f, shininess: 200, specular: 0xffffff });
  [-1, 1].forEach((side) => {
    const eye = new THREE.Group();
    eye.position.set(ex, ey, side * ez);
    const sc = new THREE.Mesh(new THREE.SphereGeometry(er, 14, 10), scleraMat); sc.scale.z = 0.55;
    const ir = new THREE.Mesh(new THREE.SphereGeometry(er * 0.72, 14, 10), irisMat); ir.scale.z = 0.5; ir.position.z = side * er * 0.2;
    const pu = new THREE.Mesh(new THREE.SphereGeometry(er * 0.42, 12, 8), pupilMat); pu.scale.z = 0.5; pu.position.z = side * er * 0.33;
    eye.add(sc, ir, pu);
    root.add(eye);
  });

  /* Mulut, gigi kakap, rahang barramundi */
  const mouthMat = new THREE.MeshPhongMaterial({ color: 0x2b1a1a, shininess: 10 });
  const mouth = new THREE.Mesh(new THREE.BoxGeometry(0.035, 0.005, wid(0.94) * 1.7), mouthMat);
  mouth.position.set(SNOUT_TIP - 0.018, -0.01, 0);
  root.add(mouth);
  if (sp.teeth) {
    const tMat = new THREE.MeshPhongMaterial({ color: 0xffffff, shininess: 80 });
    [-1, 1].forEach((side) => {
      for (let i = 0; i < 3; i++) {
        const c = new THREE.Mesh(new THREE.ConeGeometry(0.004, 0.012, 5), tMat);
        c.position.set(SNOUT_TIP - 0.03 + i * 0.008, -0.004, side * (wid(0.94) * 0.7 - i * 0.004));
        c.rotation.x = Math.PI;
        root.add(c);
      }
    });
  }
  if (sp.jaw) {
    const jaw = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.012, wid(0.9) * 1.6), new THREE.MeshPhongMaterial({ color: sp.flank, shininess: 120, specular: 0xffffff }));
    jaw.position.set(SNOUT_TIP - 0.02, -0.022, 0);
    jaw.rotation.z = 0.12;
    root.add(jaw);
  }

  function update(t, amp) {
    uniforms.uTime.value = t;
    uniforms.uAmp.value = amp;
    const f = uniforms.uFreq.value;
    const x = -0.49;
    tailPivot.position.z = swayAt(x, t);
    const slope = 6 * amp * Math.cos(t * f + x * 6) * (0.5 - x) * (0.5 - x) - 2 * amp * Math.sin(t * f + x * 6) * (0.5 - x);
    tailPivot.rotation.y = -Math.atan(slope) * 1.4;
    pecs.forEach((p) => { p.piv.rotation.y = p.side * (0.55 + Math.sin(t * 5 + p.side) * 0.25); });
    filaments.forEach((fl) => fl.chain.forEach((piv, i) => {
      piv.rotation.z = -0.06 + Math.sin(t * 3 - i * 0.7 + fl.side) * 0.2;
      piv.rotation.x = Math.sin(t * 2.2 - i * 0.6 + fl.side * 2) * 0.16 * fl.side;
    }));
  }
  update(0, 0.04);
  return { group: root, update, isChest: false };
}

function buildChest(withLight) {
  const root = new THREE.Group();
  const wood = canvasTex('wood', 128, 128, (g, w, h) => {
    g.fillStyle = '#7a4a26'; g.fillRect(0, 0, w, h);
    for (let y = 0; y < h; y += 32) {
      g.fillStyle = y % 64 ? '#86532c' : '#6f4221'; g.fillRect(0, y, w, 30);
      g.fillStyle = 'rgba(0,0,0,0.35)'; g.fillRect(0, y + 30, w, 2);
      for (let k = 0; k < 6; k++) { g.strokeStyle = 'rgba(40,20,8,0.25)'; g.beginPath(); g.moveTo(0, y + 4 + k * 4); g.bezierCurveTo(w * 0.3, y + 2 + k * 4, w * 0.6, y + 8 + k * 4, w, y + 4 + k * 4); g.stroke(); }
    }
    for (let k = 0; k < 14; k++) { g.fillStyle = 'rgba(52,140,110,' + rand(0.15, 0.35).toFixed(2) + ')'; g.beginPath(); g.arc(rand(0, w), rand(0, h), rand(4, 12), 0, Math.PI * 2); g.fill(); }
  });
  const woodMat = new THREE.MeshPhongMaterial({ map: wood, shininess: 15 });
  const goldMat = new THREE.MeshPhongMaterial({ color: 0xfbbf24, specular: 0xfff3c4, shininess: 120, emissive: 0x3a2400 });
  const copperMat = new THREE.MeshPhongMaterial({ color: 0xc2703d, specular: 0xffd2b0, shininess: 90 });

  const body = new THREE.Mesh(new THREE.BoxGeometry(0.9, 0.46, 0.56), woodMat);
  root.add(body);
  [-0.32, 0.32].forEach((x) => { const b = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.48, 0.58), goldMat); b.position.x = x; root.add(b); });
  const rim = new THREE.Mesh(new THREE.BoxGeometry(0.92, 0.05, 0.58), copperMat); rim.position.y = 0.21; root.add(rim);
  const lock = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.14, 0.03), goldMat); lock.position.set(0, 0.12, 0.29); root.add(lock);
  const hole = new THREE.Mesh(new THREE.CylinderGeometry(0.015, 0.015, 0.035, 8), new THREE.MeshBasicMaterial({ color: 0x1c1007 })); hole.rotation.x = Math.PI / 2; hole.position.set(0, 0.12, 0.3); root.add(hole);

  const lidPivot = new THREE.Group();
  lidPivot.position.set(0, 0.23, -0.28);
  const lidGeo = new THREE.CylinderGeometry(0.28, 0.28, 0.9, 18, 1, false, 0, Math.PI);
  lidGeo.rotateZ(Math.PI / 2);
  const lid = new THREE.Mesh(lidGeo, woodMat); lid.position.z = 0.28; lid.scale.y = 0.75;
  lidPivot.add(lid);
  [-0.32, 0.32].forEach((x) => {
    const g = new THREE.CylinderGeometry(0.29, 0.29, 0.06, 18, 1, true, 0, Math.PI); g.rotateZ(Math.PI / 2);
    const b = new THREE.Mesh(g, goldMat); b.position.set(x, 0, 0.28); b.scale.y = 0.75; lidPivot.add(b);
  });
  lidPivot.rotation.x = -0.75;
  root.add(lidPivot);

  /* Teritip & karang yang menempel */
  const barnMat = new THREE.MeshPhongMaterial({ color: 0xe7e5e4, shininess: 20 });
  for (let i = 0; i < 16; i++) {
    const r = rand(0.018, 0.034);
    const b = new THREE.Mesh(new THREE.ConeGeometry(r, r * 1.1, 7), barnMat);
    const face = i % 4;
    if (face === 0) { b.position.set(rand(-0.42, 0.42), rand(-0.2, 0.15), 0.285); b.rotation.x = Math.PI / 2; }
    else if (face === 1) { b.position.set(0.455, rand(-0.2, 0.15), rand(-0.25, 0.25)); b.rotation.z = -Math.PI / 2; }
    else if (face === 2) { b.position.set(-0.455, rand(-0.2, 0.15), rand(-0.25, 0.25)); b.rotation.z = Math.PI / 2; }
    else { b.position.set(rand(-0.42, 0.42), -0.235, rand(-0.25, 0.25)); b.rotation.x = Math.PI; }
    root.add(b);
  }
  const coralMat = new THREE.MeshPhongMaterial({ color: 0xfb7185, shininess: 30 });
  [[0.38, -0.18, 0.3, 0.3], [-0.4, -0.2, 0.28, -0.4]].forEach(([x, y, z, a]) => {
    const br = new THREE.Group(); br.position.set(x, y, z); br.rotation.z = a;
    [[0, 0.12, 0], [0.4, 0.08, 0.04], [-0.45, 0.09, 0.08]].forEach(([rz, l, oy]) => {
      const c = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.016, l, 6), coralMat);
      c.position.y = l / 2 + oy; c.rotation.z = rz; br.add(c);
    });
    root.add(br);
  });

  /* Isi harta: koin, mutiara, cahaya */
  const glow = new THREE.Mesh(new THREE.PlaneGeometry(0.8, 0.48), new THREE.MeshBasicMaterial({ color: 0xffe08a, transparent: true, opacity: 0.9 }));
  glow.rotation.x = -Math.PI / 2; glow.position.y = 0.205; root.add(glow);
  for (let i = 0; i < 14; i++) {
    const c = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 0.012, 12), goldMat);
    c.position.set(rand(-0.3, 0.3), 0.22 + rand(0, 0.05), rand(-0.17, 0.17));
    c.rotation.set(rand(-0.6, 0.6), 0, rand(-0.6, 0.6));
    root.add(c);
  }
  const pearl = new THREE.Mesh(new THREE.SphereGeometry(0.06, 20, 14), new THREE.MeshPhongMaterial({ color: 0xffffff, emissive: 0xfff1d6, emissiveIntensity: 0.45, shininess: 200, specular: 0xffffff }));
  pearl.position.set(0.05, 0.3, 0.02); root.add(pearl);

  /* God-rays & partikel kilau mutiara */
  const rayTex = canvasTex('ray', 32, 128, (g, w, h) => {
    const gr = g.createLinearGradient(0, h, 0, 0);
    gr.addColorStop(0, 'rgba(255,236,170,0.9)'); gr.addColorStop(1, 'rgba(255,236,170,0)');
    g.fillStyle = gr; g.fillRect(0, 0, w, h);
  });
  const rays = new THREE.Group(); rays.position.y = 0.22; root.add(rays);
  for (let i = 0; i < 5; i++) {
    const r = new THREE.Mesh(new THREE.PlaneGeometry(0.16, 1.3), new THREE.MeshBasicMaterial({ map: rayTex, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide, opacity: 0.55 }));
    r.position.y = 0.6; r.rotation.y = (i / 5) * Math.PI; r.rotation.z = rand(-0.25, 0.25);
    rays.add(r);
  }
  const SP = 50, spPos = new Float32Array(SP * 3), spSeed = [];
  for (let i = 0; i < SP; i++) { spSeed.push([rand(-0.35, 0.35), rand(0, 1), rand(-0.2, 0.2), rand(0.3, 0.8)]); }
  const spGeo = new THREE.BufferGeometry(); spGeo.setAttribute('position', new THREE.BufferAttribute(spPos, 3));
  const sparkles = new THREE.Points(spGeo, new THREE.PointsMaterial({ size: 0.05, map: dotTexture(), color: 0xfff3c4, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  sparkles.frustumCulled = false;
  root.add(sparkles);
  let light = null;
  if (withLight) { light = new THREE.PointLight(0xffd36b, 1.4, 3); light.position.y = 0.5; root.add(light); }

  function update(t) {
    rays.rotation.y = t * 0.35;
    rays.children.forEach((r, i) => { r.material.opacity = 0.35 + 0.25 * Math.sin(t * 2 + i); });
    lidPivot.rotation.x = -0.75 - Math.sin(t * 1.5) * 0.05;
    for (let i = 0; i < SP; i++) {
      const s = spSeed[i], ph = (s[1] + t * s[3] * 0.4) % 1;
      spPos[i * 3] = s[0] + Math.sin(t + i) * 0.03;
      spPos[i * 3 + 1] = 0.22 + ph * 1.1;
      spPos[i * 3 + 2] = s[2];
    }
    spGeo.attributes.position.needsUpdate = true;
    sparkles.material.opacity = 0.6 + 0.4 * Math.sin(t * 6);
    pearl.material.emissiveIntensity = 0.35 + 0.25 * Math.sin(t * 3);
    if (light) light.intensity = 1.2 + 0.4 * Math.sin(t * 3);
  }
  update(0);
  return { group: root, update, isChest: true };
}

/* Zonk: sepatu bot karet bekas berlumut + kaleng karatan */
function buildBoot() {
  const root = new THREE.Group();
  const rubber = new THREE.MeshPhongMaterial({ color: 0x4d5b3a, shininess: 35, specular: 0x334422 });
  const sole = new THREE.MeshPhongMaterial({ color: 0x2b2420, shininess: 10 });
  const mud = new THREE.MeshPhongMaterial({ color: 0x6b4f2a, shininess: 5 });
  const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.15, 0.62, 18, 1, true), rubber);
  leg.position.set(-0.12, 0.31, 0); root.add(leg);
  const legIn = new THREE.Mesh(new THREE.CylinderGeometry(0.145, 0.14, 0.6, 18, 1, true), new THREE.MeshPhongMaterial({ color: 0x1f2418, side: THREE.BackSide }));
  legIn.position.copy(leg.position); root.add(legIn);
  const cuff = new THREE.Mesh(new THREE.TorusGeometry(0.155, 0.025, 8, 20), rubber);
  cuff.rotation.x = Math.PI / 2; cuff.position.set(-0.12, 0.62, 0); root.add(cuff);
  const foot = new THREE.Mesh(new THREE.SphereGeometry(0.2, 18, 12), rubber);
  foot.scale.set(1.55, 0.62, 0.95); foot.position.set(0.06, 0.06, 0); root.add(foot);
  const heel = new THREE.Mesh(new THREE.CylinderGeometry(0.155, 0.155, 0.16, 18), rubber);
  heel.position.set(-0.12, 0.08, 0); root.add(heel);
  const soleM = new THREE.Mesh(new THREE.BoxGeometry(0.66, 0.05, 0.3), sole);
  soleM.position.set(0.04, -0.06, 0); root.add(soleM);
  for (let i = 0; i < 5; i++) { // lumpur & teritip
    const m = new THREE.Mesh(new THREE.SphereGeometry(rand(0.03, 0.06), 8, 6), i % 2 ? mud : new THREE.MeshPhongMaterial({ color: 0xe7e5e4 }));
    m.position.set(rand(-0.25, 0.3), rand(0.0, 0.45), rand(-0.14, 0.14) > 0 ? 0.15 : -0.15); root.add(m);
  }
  const weedMat = new THREE.MeshPhongMaterial({ color: 0x15803d, side: THREE.DoubleSide, shininess: 30 });
  const weeds = [];
  for (let i = 0; i < 4; i++) { // rumput laut menjuntai
    const w = new THREE.Mesh(new THREE.PlaneGeometry(0.05, 0.42, 1, 6), weedMat);
    const pos = w.geometry.attributes.position;
    for (let k = 0; k < pos.count; k++) pos.setX(k, pos.getX(k) + Math.sin(pos.getY(k) * 9 + i) * 0.03);
    w.position.set(-0.2 + i * 0.05, 0.55 - i * 0.04, 0.12 * (i % 2 ? 1 : -1));
    w.rotation.z = 0.5 + i * 0.15;
    root.add(w); weeds.push(w);
  }
  const can = new THREE.Mesh(new THREE.CylinderGeometry(0.07, 0.07, 0.16, 14), new THREE.MeshPhongMaterial({ color: 0xb45309, shininess: 60, specular: 0x777777 }));
  can.rotation.z = Math.PI / 2; can.position.set(0.3, 0.02, 0.16); root.add(can);
  const lbl = new THREE.Mesh(new THREE.CylinderGeometry(0.072, 0.072, 0.08, 14, 1, true), new THREE.MeshPhongMaterial({ color: 0xdc2626 }));
  lbl.rotation.z = Math.PI / 2; lbl.position.copy(can.position); root.add(lbl);
  root.position.y = -0.2;
  const wrap = new THREE.Group(); wrap.add(root);
  function update(t) { weeds.forEach((w, i) => { w.rotation.z = 0.5 + i * 0.15 + Math.sin(t * 2 + i) * 0.12; }); }
  update(0);
  return { group: wrap, update, isChest: false, isJunk: true };
}

/* Model sampah (Zonk) dari GLB — dimuat di latar. Selama belum siap, dipakai sepatu bot prosedural. */
const JunkLib = (() => {
  const cache = {};
  // Variasi warna/bahan: kresek = model kantong sampah berwarna merah tipis, gelas = plastik bening
  const TUNE = {
    bag: { src: 'trashbag', color: 0xef4444, opacity: 0.82, shininess: 90 },
    cup: { color: 0xe0f2fe, opacity: 0.55, shininess: 120 }
  };
  function toPhong(root) { // samakan gaya pencahayaan dengan ikan (Phong)
    root.traverse((o) => {
      if (!o.isMesh) return;
      const conv = (m) => {
        const p = new THREE.MeshPhongMaterial({
          color: m.color ? m.color.clone() : new THREE.Color(0xffffff), map: m.map || null,
          shininess: m.roughness !== undefined ? Math.round((1 - m.roughness) * 70) + 12 : 30,
          transparent: !!m.transparent, opacity: m.opacity == null ? 1 : m.opacity, side: m.side
        });
        if (p.map) p.map.__shared = true;
        return p;
      };
      o.material = Array.isArray(o.material) ? o.material.map(conv) : conv(o.material);
    });
  }
  function load(map) {
    if (!window.THREE || !THREE.GLTFLoader || !map) return Promise.resolve();
    const ld = new THREE.GLTFLoader();
    return Promise.all(Object.keys(map).map((k) => new Promise((res) => {
      ld.load(map[k], (g) => { toPhong(g.scene); cache[k] = g.scene; res(); }, undefined, () => res());
    })));
  }
  function build(kind) {
    const tune = TUNE[kind] || {};
    const src = cache[tune.src || kind];
    if (!src) return null;
    const obj = src.clone(true);
    const tint = (m) => {
      const c = m.clone();
      if (tune.color != null) { c.color.setHex(tune.color); c.map = null; }
      if (tune.opacity != null) { c.transparent = true; c.opacity = tune.opacity; c.depthWrite = tune.opacity > 0.7; }
      if (tune.shininess != null) { c.shininess = tune.shininess; c.specular = new THREE.Color(0xffffff); }
      return c;
    };
    obj.traverse((o) => { // salinan sendiri agar disposeTree tidak merusak cache
      if (!o.isMesh) return;
      o.geometry = o.geometry.clone();
      o.material = Array.isArray(o.material) ? o.material.map(tint) : tint(o.material);
    });
    const box = new THREE.Box3().setFromObject(obj), size = new THREE.Vector3(), ctr = new THREE.Vector3();
    box.getSize(size); box.getCenter(ctr);
    const k = 0.9 / Math.max(size.x, size.y, size.z, 1e-3);
    obj.scale.setScalar(k); obj.position.copy(ctr).multiplyScalar(-k);
    const wrap = new THREE.Group(); wrap.add(obj);
    return { group: wrap, update() {}, isChest: false, isJunk: true };
  }
  return { load, build, has: (k) => !!cache[(TUNE[k] && TUNE[k].src) || k] };
})();

function buildModel(spKey, withLight) {
  if (spKey === 'boot' || String(spKey).indexOf('junk:') === 0) {
    const kind = spKey === 'boot' ? 'boot' : spKey.slice(5);
    return JunkLib.build(kind) || buildBoot();
  }
  return spKey === 'chest' ? buildChest(withLight) : buildFish(spKey);
}
function disposeTree(obj) {
  obj.traverse((o) => {
    if (o.geometry) o.geometry.dispose();
    if (o.material) {
      (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => {
        if (m.map && !m.map.__shared) m.map.dispose();
        m.dispose();
      });
    }
  });
}


/* ───────────────────────── GELOMBANG (JS == GLSL) ───────────────────────── */
const WAVES = [[0.6, 0.8, 0.42, 0.09, 1.1], [-0.7, 0.5, 0.78, 0.055, 1.6], [0.25, -1.0, 1.6, 0.028, 2.3], [0.9, -0.3, 2.6, 0.012, 3.1]]
  .map(([dx, dz, f, a, s]) => { const l = Math.hypot(dx, dz); return { dx: dx / l, dz: dz / l, f, a, s }; });
function waveH(x, z, t) {
  let h = 0;
  for (const w of WAVES) h += w.a * Math.sin((w.dx * x + w.dz * z) * w.f + t * w.s);
  return h;
}
function waveGrad(x, z, t) {
  let gx = 0, gz = 0;
  for (const w of WAVES) { const c = w.a * w.f * Math.cos((w.dx * x + w.dz * z) * w.f + t * w.s); gx += c * w.dx; gz += c * w.dz; }
  return [gx, gz];
}
const GLSL_WAVES = WAVES.map((w) => {
  const d = 'vec2(' + w.dx.toFixed(5) + ',' + w.dz.toFixed(5) + ')';
  return 'ph = dot(' + d + ', p) * ' + w.f.toFixed(4) + ' + t * ' + w.s.toFixed(4) + '; h += ' + w.a.toFixed(5) + ' * sin(ph); g += ' + (w.a * w.f).toFixed(6) + ' * ' + d + ' * cos(ph);';
}).join('\n');



/* ───────────────────────── IKON 3D (render objek → gambar) ─────────────────────────
   Satu renderer offscreen dipakai bergantian; hasil PNG di-cache per kunci.        */
const Thumbs = (() => {
  const cache = {};
  let r = null, scene = null, cam = null, holder = null;
  function init(size) {
    if (r) return true;
    try {
      const cv = document.createElement('canvas'); cv.width = cv.height = size;
      r = new THREE.WebGLRenderer({ canvas: cv, alpha: true, antialias: true, preserveDrawingBuffer: true });
      r.setPixelRatio(1); r.setSize(size, size, false);
    } catch (e) { return false; }
    scene = new THREE.Scene();
    cam = new THREE.PerspectiveCamera(26, 1, 0.01, 100);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x7dd3fc, 0.75));
    const key = new THREE.DirectionalLight(0xfff1d6, 1.25); key.position.set(2, 3, 3); scene.add(key);
    const rim = new THREE.DirectionalLight(0xc4b5fd, 0.8); rim.position.set(-3, 1.5, -2); scene.add(rim);
    holder = new THREE.Group(); scene.add(holder);
    return true;
  }
  /** build() mengembalikan Object3D; opts.rot = [x,y,z] sudut pandang */
  function get(id, build, opts) {
    if (cache[id]) return cache[id];
    if (typeof THREE === 'undefined' || !init(128)) return null;
    opts = opts || {};
    const obj = build();
    const rot = opts.rot || [0.25, -0.65, 0.12];
    obj.rotation.set(rot[0], rot[1], rot[2]);
    holder.add(obj);
    const box = new THREE.Box3().setFromObject(obj);
    const center = box.getCenter(new THREE.Vector3()), size = box.getSize(new THREE.Vector3());
    obj.position.sub(center);
    const radius = Math.max(size.x, size.y, size.z) * 0.5 * (opts.pad || 1.12);
    const dist = radius / Math.tan((cam.fov * Math.PI / 180) / 2);
    cam.position.set(0, radius * 0.15, dist); cam.lookAt(0, 0, 0);
    if (opts.update) opts.update(obj);
    r.render(scene, cam);
    cache[id] = r.domElement.toDataURL('image/png');
    holder.remove(obj); disposeTree(obj);
    return cache[id];
  }
  return { get, has: (id) => !!cache[id] };
})();

/** Ikon 3D spesies tangkapan (badge → gambar PNG) */
function fishThumb(badge) {
  const key = BADGE_SPECIES[badge] || 'mujair';
  return Thumbs.get('fish-' + key, () => { const m = buildModel(key, false); if (m.update) m.update(0.6, 0.03); return m.group; },
    { rot: key === 'chest' || key === 'boot' ? [0.35, -0.6, 0] : [0.2, -0.35, 0.05], pad: key === 'chest' || key === 'boot' ? 1.0 : 1.02 });
}

window.Bahari = {
  clamp, lerp, rand, smooth, damp, fmtRp, Haptic, Sfx, Ambience,
  U_KNOTS, BADGES, BADGE_LABEL, BADGE_SPECIES, SPECIES, snoutX, SNOUT_TIP, profile,
  TexCache, canvasTex, dotTexture, scaleTexture, shadeFinGeometry, finMaterial, shapeFrom,
  buildFish, buildChest, buildModel, disposeTree,
  WAVES, waveH, waveGrad, GLSL_WAVES,
  Thumbs, fishThumb, JunkLib
};
})();
