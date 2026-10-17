<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (!staff_can('users')) staff_require('analytics');

/**
 * Peta Jaringan Referral — visual "tata surya" 2D.
 * Setiap owner uplink jadi pusat (matahari), downline mengorbit di sekelilingnya
 * dan tersambung "kabel" ke uplinknya. Downline yang punya downline sendiri
 * menjadi pusat orbit kecil (planet dengan satelit).
 */
function network_map_data(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT u.id, u.username, u.referral_code, u.referred_by, u.is_promotor, u.is_flagged_abuse,
               u.balance_dep, u.balance_wd, UNIX_TIMESTAMP(u.created_at) c, UNIX_TIMESTAMP(u.last_seen) ls,
               CASE WHEN u.membership_id IS NOT NULL AND u.membership_expires_at > NOW() THEN m.name END mem,
               COALESCE(d.s, 0) dep_sum, COALESCE(d.n, 0) dep_n, UNIX_TIMESTAMP(d.last) dep_last,
               COALESCE(w.s, 0) wd_sum
        FROM users u
        LEFT JOIN memberships m ON m.id = u.membership_id
        LEFT JOIN (SELECT user_id, SUM(amount) s, COUNT(*) n, MAX(confirmed_at) last FROM deposits WHERE status = 'confirmed' GROUP BY user_id) d ON d.user_id = u.id
        LEFT JOIN (SELECT user_id, SUM(amount) s FROM withdrawals WHERE status = 'approved' GROUP BY user_id) w ON w.user_id = u.id
    ")->fetchAll();

    $byCode = [];
    foreach ($rows as $r) {
        $code = strtoupper(trim((string)$r['referral_code']));
        if ($code !== '') $byCode[$code] = (int)$r['id'];
    }
    $parent = [];
    $hasChild = [];
    foreach ($rows as $r) {
        $ref = strtoupper(trim((string)$r['referred_by']));
        if ($ref === '' || !isset($byCode[$ref]) || $byCode[$ref] === (int)$r['id']) continue;
        $parent[(int)$r['id']] = $byCode[$ref];
        $hasChild[$byCode[$ref]] = true;
    }

    $free = get_free_tier_name($pdo);
    $nodes = [];
    $solo = 0;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (!isset($parent[$id]) && !isset($hasChild[$id])) { $solo++; continue; }
        // [id, nama, parent, depSum, depN, wdSum, flags(1=promotor,2=abuse), level, daftar, lastSeen, saldoBeli, saldoWD, depoTerakhir]
        $nodes[] = [
            $id, (string)$r['username'], $parent[$id] ?? 0, (float)$r['dep_sum'], (int)$r['dep_n'], (float)$r['wd_sum'],
            ((int)$r['is_promotor'] === 1 ? 1 : 0) | ((int)$r['is_flagged_abuse'] === 1 ? 2 : 0),
            $r['mem'] ?: $free, (int)$r['c'], (int)$r['ls'], (float)$r['balance_dep'], (float)$r['balance_wd'], (int)$r['dep_last'],
        ];
    }
    return ['nodes' => $nodes, 'solo' => $solo, 'total_users' => count($rows), 'generated' => time()];
}

if (($_GET['action'] ?? '') === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(network_map_data($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

$data = network_map_data($pdo);
$pageTitle  = 'Peta Jaringan Referral';
$activePage = 'network_map';
require __DIR__ . '/partials/header.php';
?>

<style>
.nm-wrap { display: flex; flex-direction: column; gap: 12px; }
.nm-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
.nm-head h5 { margin: 0; font-weight: 800; }
.nm-head p { margin: 2px 0 0; font-size: 12px; color: #888; }
.nm-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
@media (max-width: 900px) { .nm-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.nm-stat { background: #1a1d27; border: 1px solid #2d3149; border-radius: 12px; padding: 10px 12px; position: relative; overflow: hidden; }
.nm-stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c); }
.nm-stat small { display: block; font-size: 10.5px; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.nm-stat b { display: block; font-size: 18px; font-weight: 800; color: var(--c); }
.nm-stat span { font-size: 11px; color: #8b93a7; }
.nm-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.nm-bar input, .nm-bar select { background: #151823; border: 1px solid #2d3149; color: #e5e7eb; border-radius: 9px; padding: 7px 10px; font-size: 13px; }
.nm-bar input { min-width: 220px; flex: 1 1 220px; }
.nm-btn { background: #151823; border: 1px solid #2d3149; color: #cbd5e1; border-radius: 9px; padding: 7px 11px; font-size: 12.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.nm-btn.on { background: #0e7490; border-color: #0891b2; color: #fff; }
.nm-tops { display: flex; gap: 6px; flex-wrap: wrap; }
.nm-top { background: #151823; border: 1px solid #2d3149; color: #cbd5e1; border-radius: 999px; padding: 4px 10px; font-size: 11.5px; cursor: pointer; }
.nm-top b { color: #fbbf24; margin-left: 4px; }
.nm-stage { position: relative; display: flex; border: 1px solid #2d3149; border-radius: 14px; overflow: hidden; background: radial-gradient(ellipse at center, #0f1630 0%, #070a14 70%); height: calc(100vh - 300px); min-height: 520px; }
#nm-canvas { flex: 1; min-width: 0; display: block; cursor: grab; touch-action: none; }
#nm-canvas.drag { cursor: grabbing; }
.nm-legend { position: absolute; left: 10px; bottom: 10px; background: rgba(10, 14, 28, .82); border: 1px solid #2d3149; border-radius: 10px; padding: 8px 10px; font-size: 11px; color: #aab3c5; display: grid; gap: 4px; pointer-events: none; }
.nm-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: -1px; }
.nm-zoom { position: absolute; left: 10px; top: 10px; display: flex; flex-direction: column; gap: 4px; }
.nm-zoom button { width: 32px; height: 32px; border-radius: 9px; border: 1px solid #2d3149; background: rgba(10, 14, 28, .85); color: #e5e7eb; font-size: 16px; cursor: pointer; }
.nm-tip { position: absolute; pointer-events: none; background: rgba(10, 14, 28, .95); border: 1px solid #334155; border-radius: 9px; padding: 7px 9px; font-size: 11.5px; color: #e5e7eb; display: none; white-space: nowrap; z-index: 3; box-shadow: 0 6px 18px rgba(0,0,0,.4); }
.nm-tip b { color: #fff; }
.nm-side { width: 330px; flex-shrink: 0; background: rgba(13, 17, 32, .96); border-left: 1px solid #2d3149; overflow-y: auto; padding: 14px; font-size: 12.5px; color: #cbd5e1; display: none; }
.nm-side.on { display: block; }
@media (max-width: 900px) {
  .nm-stage { flex-direction: column; height: auto; }
  #nm-canvas { height: 60vh; }
  .nm-side { width: auto; border-left: 0; border-top: 1px solid #2d3149; max-height: 60vh; }
}
.nm-side h6 { margin: 0; font-size: 16px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px; }
.nm-av { width: 36px; height: 36px; border-radius: 11px; display: grid; place-items: center; font-weight: 800; color: #fff; flex-shrink: 0; }
.nm-badges { display: flex; gap: 4px; flex-wrap: wrap; margin: 8px 0; }
.nm-badge { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #1e2538; color: #aab3c5; }
.nm-badge.p { background: rgba(167,139,250,.18); color: #c4b5fd; } .nm-badge.a { background: rgba(239,68,68,.2); color: #fca5a5; }
.nm-badge.d { background: rgba(34,197,94,.16); color: #86efac; } .nm-badge.n { background: rgba(100,116,139,.2); color: #94a3b8; }
.nm-kv { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin: 10px 0; }
.nm-kv div { background: #151a2b; border-radius: 9px; padding: 7px 9px; }
.nm-kv small { display: block; font-size: 10px; color: #7c8699; font-weight: 700; }
.nm-kv b { font-size: 13px; color: #fff; }
.nm-sec { font-size: 11px; font-weight: 800; color: #7dd3fc; text-transform: uppercase; letter-spacing: .4px; margin: 12px 0 6px; }
.nm-chain { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; font-size: 12px; }
.nm-chain a, .nm-dl a { color: #e5e7eb; text-decoration: none; cursor: pointer; }
.nm-chain a { background: #1e2538; padding: 2px 8px; border-radius: 999px; }
.nm-dl { display: grid; gap: 3px; max-height: 320px; overflow-y: auto; }
.nm-dl a { display: flex; justify-content: space-between; gap: 6px; padding: 5px 8px; border-radius: 8px; background: #121827; }
.nm-dl a:hover, .nm-chain a:hover { background: #26304a; }
.nm-dl span { color: #7c8699; font-size: 11px; }
.nm-dl em { font-style: normal; font-weight: 700; color: #86efac; }
.nm-dl em.z { color: #64748b; font-weight: 600; }
.nm-close { margin-left: auto; background: none; border: 0; color: #94a3b8; font-size: 18px; cursor: pointer; }
</style>

<div class="nm-wrap">
  <div class="nm-head">
    <div>
      <h5>🪐 Peta Jaringan Referral</h5>
      <p>Setiap owner uplink jadi pusat orbit, downline mengorbit &amp; tersambung kabel ke uplinknya. Kabel menyala = downline sudah deposit.</p>
    </div>
    <div class="nm-bar">
      <button class="nm-btn on" id="btn-orbit" type="button">🪐 Orbit</button>
      <button class="nm-btn on" id="btn-flow" type="button">⚡ Aliran kabel</button>
      <button class="nm-btn" id="btn-labels" type="button">🏷 Nama</button>
      <button class="nm-btn" id="btn-reload" type="button">⟳ Muat ulang</button>
    </div>
  </div>

  <div class="nm-stats" id="nm-stats"></div>

  <div class="nm-bar">
    <input id="nm-search" list="nm-users" placeholder="Cari username / ID lalu Enter…" autocomplete="off">
    <datalist id="nm-users"></datalist>
    <select id="nm-filter">
      <option value="all">Tampilkan semua</option>
      <option value="dep">Sorot yang sudah deposit</option>
      <option value="nodep">Sorot yang belum deposit</option>
      <option value="promotor">Sorot jaringan promotor</option>
      <option value="abuse">Sorot akun abuse</option>
    </select>
    <select id="nm-level"><option value="">Semua level</option></select>
  </div>
  <div class="nm-tops" id="nm-tops"></div>

  <div class="nm-stage" id="nm-stage">
    <canvas id="nm-canvas"></canvas>
    <div class="nm-zoom">
      <button type="button" id="z-in" title="Perbesar">+</button>
      <button type="button" id="z-out" title="Perkecil">−</button>
      <button type="button" id="z-fit" title="Lihat semua">⤢</button>
    </div>
    <div class="nm-legend">
      <div><i style="background:#64748b"></i>Belum deposit</div>
      <div><i style="background:#22d3ee"></i>Deposit &lt; Rp50rb</div>
      <div><i style="background:#22c55e"></i>Deposit &lt; Rp200rb</div>
      <div><i style="background:#f59e0b"></i>Deposit &lt; Rp1jt</div>
      <div><i style="background:#f472b6"></i>Deposit ≥ Rp1jt</div>
      <div><i style="background:transparent;border:2px solid #a78bfa"></i>Promotor · <i style="background:transparent;border:2px solid #ef4444;margin-left:6px"></i>Abuse</div>
    </div>
    <div class="nm-tip" id="nm-tip"></div>
    <aside class="nm-side" id="nm-side"></aside>
  </div>
</div>

<script>
(() => {
  let DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
  const $ = id => document.getElementById(id);
  const rp = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
  const rpS = n => n >= 1e6 ? 'Rp ' + (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + 'jt' : n >= 1e3 ? 'Rp ' + Math.round(n / 1e3).toLocaleString('id-ID') + 'rb' : rp(n);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dt = ts => ts ? new Date(ts * 1000).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-';
  const ago = ts => {
    if (!ts) return '-';
    const s = Date.now() / 1000 - ts;
    if (s < 3600) return Math.max(1, Math.floor(s / 60)) + ' mnt lalu';
    if (s < 86400) return Math.floor(s / 3600) + ' jam lalu';
    return Math.floor(s / 86400) + ' hari lalu';
  };
  const depColor = v => v <= 0 ? '#64748b' : v < 50000 ? '#22d3ee' : v < 200000 ? '#22c55e' : v < 1e6 ? '#f59e0b' : '#f472b6';
  const AVC = ['#0891b2', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#2563eb', '#ca8a04', '#0d9488'];

  let nodes = [], byId = new Map(), roots = [];
  let selected = null, hover = null, filter = 'all', levelF = '';
  let showOrbit = true, showFlow = true, showLabels = false;

  // ── Bangun pohon + statistik ──────────────────────────────────────────
  function build() {
    nodes = DATA.nodes.map(a => ({ id: a[0], name: a[1], pid: a[2], dep: a[3], depN: a[4], wd: a[5], promotor: !!(a[6] & 1), abuse: !!(a[6] & 2),
      level: a[7], joined: a[8], seen: a[9], balDep: a[10], balWd: a[11], depLast: a[12], kids: [], parent: null }));
    byId = new Map(nodes.map(n => [n.id, n]));
    roots = [];
    nodes.forEach(n => { const p = byId.get(n.pid); if (p) { n.parent = p; p.kids.push(n); } else roots.push(n); });
    const agg = n => {
      n.size = 1; n.netDep = 0; n.netDepositors = 0;
      n.depth = n.parent ? n.parent.depth + 1 : 0;
      n.kids.forEach(k => { agg(k); n.size += k.size; n.netDep += k.dep + k.netDep; n.netDepositors += (k.dep > 0 ? 1 : 0) + k.netDepositors; });
    };
    roots.forEach(r => { r.depth = 0; agg(r); });
    nodes.forEach(n => {
      n.r = n.kids.length ? 5 + Math.min(16, Math.sqrt(n.size) * 1.1) : (n.dep > 0 ? 3.2 + Math.min(7, Math.sqrt(n.dep / 10000)) : 2.6);
      if (!n.parent) n.r += 3;
    });
    layout();
    stats();
    tops();
    const dl = $('nm-users');
    dl.innerHTML = nodes.slice().sort((a, b) => b.size - a.size || b.dep - a.dep).slice(0, 2000).map(n => `<option value="${esc(n.name)}">`).join('');
    const lv = [...new Set(nodes.map(n => n.level))].sort();
    $('nm-level').innerHTML = '<option value="">Semua level</option>' + lv.map(l => `<option ${l === levelF ? 'selected' : ''}>${esc(l)}</option>`).join('');
  }

  // ── Tata letak orbit (rekursif) ───────────────────────────────────────
  function layoutNode(n) {
    if (!n.kids.length) { n.R = n.r + 3; n.rings = []; return; }
    n.kids.forEach(layoutNode);
    const kids = n.kids.slice().sort((a, b) => (b.kids.length > 0) - (a.kids.length > 0) || b.R - a.R || b.dep - a.dep);
    const rings = [];
    let ring = null;
    for (const k of kids) {
      const need = 2 * k.R + 3;
      if (!ring || ring.used + need > 2 * Math.PI * ring.radius) {
        const radius = ring ? ring.radius + ring.maxR + k.R + 8 : n.r + k.R + 14;
        ring = { radius, used: 0, maxR: 0, items: [] };
        rings.push(ring);
      }
      ring.items.push(k);
      ring.used += need;
      ring.maxR = Math.max(ring.maxR, k.R);
    }
    rings.forEach((rg, i) => {
      const off = (n.id * 0.618 + i * 1.3) % (Math.PI * 2);
      const dir = i % 2 ? -1 : 1;
      rg.speed = dir * 0.05 / Math.pow(rg.radius / 40, 0.7);
      rg.items.forEach((k, j) => { k.ring = rg; k.a0 = off + (j / rg.items.length) * Math.PI * 2; });
    });
    n.rings = rings;
    const last = rings[rings.length - 1];
    n.R = last.radius + last.maxR + 6;
  }

  function layout() {
    roots.forEach(layoutNode);
    // Susun para "matahari" dengan spiral tanpa tumpang tindih
    const placed = [];
    roots.slice().sort((a, b) => b.R - a.R).forEach((r, i) => {
      if (i === 0) { r.x = 0; r.y = 0; placed.push(r); return; }
      for (let t = 0; ; t += 0.35) {
        const d = 30 + t * 9, x = Math.cos(t) * d, y = Math.sin(t) * d;
        if (placed.every(p => Math.hypot(p.x - x, p.y - y) > p.R + r.R + 22)) { r.x = x; r.y = y; placed.push(r); break; }
      }
    });
  }

  // Posisi tiap node pada waktu t (orbit berputar)
  function positions(t) {
    const walk = n => {
      n.kids.forEach(k => {
        const a = k.a0 + (showOrbit ? k.ring.speed * t : 0);
        k.x = n.x + Math.cos(a) * k.ring.radius;
        k.y = n.y + Math.sin(a) * k.ring.radius;
        walk(k);
      });
    };
    roots.forEach(walk);
  }

  // ── Statistik & owner teratas ─────────────────────────────────────────
  function stats() {
    const owners = nodes.filter(n => n.kids.length);
    const deps = nodes.filter(n => n.dep > 0);
    const totalDep = nodes.reduce((a, n) => a + n.dep, 0);
    const down = nodes.filter(n => n.parent);
    const downDep = down.filter(n => n.dep > 0);
    $('nm-stats').innerHTML = [
      ['#22d3ee', 'User di jaringan', nodes.length.toLocaleString('id-ID'), `dari ${DATA.total_users.toLocaleString('id-ID')} user · ${DATA.solo.toLocaleString('id-ID')} tanpa jaringan`],
      ['#a78bfa', 'Owner uplink', owners.length.toLocaleString('id-ID'), `${roots.length} pusat orbit utama`],
      ['#22c55e', 'Downline deposit', downDep.length.toLocaleString('id-ID'), `${down.length ? Math.round(downDep.length / down.length * 100) : 0}% dari ${down.length.toLocaleString('id-ID')} downline`],
      ['#64748b', 'Belum deposit', (nodes.length - deps.length).toLocaleString('id-ID'), 'user di jaringan'],
      ['#f59e0b', 'Total deposit jaringan', rpS(totalDep), `${deps.length.toLocaleString('id-ID')} user pernah deposit`],
    ].map(([c, l, v, s]) => `<div class="nm-stat" style="--c:${c}"><small>${l}</small><b>${v}</b><span>${s}</span></div>`).join('');
  }
  function tops() {
    const t = nodes.filter(n => n.kids.length).sort((a, b) => b.netDep - a.netDep || b.size - a.size).slice(0, 10);
    $('nm-tops').innerHTML = '<span style="font-size:11.5px;color:#888;align-self:center">Owner teratas:</span>' +
      t.map(n => `<span class="nm-top" data-id="${n.id}">${n.promotor ? '📣 ' : ''}${esc(n.name)} · ${n.size - 1} dl<b>${rpS(n.netDep)}</b></span>`).join('');
  }

  // ── Kanvas, kamera & interaksi ────────────────────────────────────────
  const cv = $('nm-canvas'), ctx = cv.getContext('2d'), tip = $('nm-tip'), side = $('nm-side');
  let W = 0, H = 0, dpr = 1, cam = { x: 0, y: 0, s: 1 }, camTo = null;
  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = cv.clientWidth; H = cv.clientHeight;
    cv.width = W * dpr; cv.height = H * dpr;
  }
  const toScreen = (x, y) => [(x - cam.x) * cam.s + W / 2, (y - cam.y) * cam.s + H / 2];
  const toWorld = (sx, sy) => [(sx - W / 2) / cam.s + cam.x, (sy - H / 2) / cam.s + cam.y];
  function fit(animate) {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    roots.forEach(r => { minX = Math.min(minX, r.x - r.R); maxX = Math.max(maxX, r.x + r.R); minY = Math.min(minY, r.y - r.R); maxY = Math.max(maxY, r.y + r.R); });
    if (!isFinite(minX)) return;
    const s = Math.min(W / (maxX - minX + 40), H / (maxY - minY + 40));
    const to = { x: (minX + maxX) / 2, y: (minY + maxY) / 2, s: Math.max(0.02, Math.min(4, s)) };
    if (animate) camTo = to; else cam = to;
  }
  function flyTo(n) {
    const R = n.kids.length ? n.R : (n.parent ? n.parent.R : 60);
    camTo = { x: n.x, y: n.y, s: Math.max(0.6, Math.min(4, Math.min(W, H) / (R * 2.4))) };
    if (!n.kids.length && n.parent) camTo = { x: n.parent.x, y: n.parent.y, s: Math.max(0.6, Math.min(4, Math.min(W, H) / (n.parent.R * 2.4))) };
  }

  const isDim = n => {
    if (levelF && n.level !== levelF) return true;
    if (filter === 'dep') return n.dep <= 0;
    if (filter === 'nodep') return n.dep > 0;
    if (filter === 'abuse') return !n.abuse;
    if (filter === 'promotor') { let p = n; while (p) { if (p.promotor) return false; p = p.parent; } return true; }
    return false;
  };

  let hl = null; // node yang disorot: rantai upline + downline langsung
  function highlightSet(n) {
    const s = new Set();
    if (!n) return null;
    let p = n; while (p) { s.add(p.id); p = p.parent; }
    n.kids.forEach(k => s.add(k.id));
    return s;
  }

  let t0 = performance.now(), last = t0;
  function frame(now) {
    const t = (now - t0) / 1000;
    last = now;
    if (camTo) {
      cam.x += (camTo.x - cam.x) * 0.15; cam.y += (camTo.y - cam.y) * 0.15; cam.s += (camTo.s - cam.s) * 0.15;
      if (Math.abs(camTo.s - cam.s) < 0.001 && Math.hypot(camTo.x - cam.x, camTo.y - cam.y) < 0.5) camTo = null;
    }
    positions(t);
    draw(t);
    requestAnimationFrame(frame);
  }

  function draw(t) {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);
    const s = cam.s;
    ctx.setTransform(dpr * s, 0, 0, dpr * s, dpr * (W / 2 - cam.x * s), dpr * (H / 2 - cam.y * s));
    const [vx0, vy0] = toWorld(0, 0), [vx1, vy1] = toWorld(W, H);
    const vis = (x, y, r) => x + r > vx0 && x - r < vx1 && y + r > vy0 && y - r < vy1;
    const lw = 1 / s;

    // Orbit
    ctx.lineWidth = lw;
    nodes.forEach(n => {
      if (!n.rings || !n.rings.length || !vis(n.x, n.y, n.R)) return;
      n.rings.forEach(rg => {
        if (rg.radius * s < 6) return;
        ctx.strokeStyle = hl && hl.has(n.id) ? 'rgba(125,211,252,.28)' : 'rgba(148,163,184,.09)';
        ctx.beginPath(); ctx.arc(n.x, n.y, rg.radius, 0, Math.PI * 2); ctx.stroke();
      });
    });

    // Kabel downline → uplink
    nodes.forEach(n => {
      const p = n.parent;
      if (!p || !vis((n.x + p.x) / 2, (n.y + p.y) / 2, n.ring.radius)) return;
      const dim = isDim(n) || (hl && !(hl.has(n.id) && hl.has(p.id)));
      const mx = (n.x + p.x) / 2 + (p.y - n.y) * 0.12, my = (n.y + p.y) / 2 - (p.x - n.x) * 0.12;
      ctx.beginPath(); ctx.moveTo(n.x, n.y); ctx.quadraticCurveTo(mx, my, p.x, p.y);
      if (n.dep > 0) {
        ctx.strokeStyle = dim ? 'rgba(34,197,94,.06)' : depColor(n.dep) + (hl ? 'ee' : '88');
        ctx.lineWidth = (hl && !dim ? 1.8 : 1.1) * lw;
        if (showFlow && !dim) { ctx.setLineDash([4 * lw, 6 * lw]); ctx.lineDashOffset = -t * 18 * lw; }
      } else {
        ctx.strokeStyle = dim ? 'rgba(100,116,139,.04)' : (hl ? 'rgba(148,163,184,.55)' : 'rgba(100,116,139,.22)');
        ctx.lineWidth = 0.7 * lw;
      }
      ctx.stroke();
      ctx.setLineDash([]);
    });

    // Node
    nodes.forEach(n => {
      if (!vis(n.x, n.y, n.r)) return;
      const dim = isDim(n) || (hl && !hl.has(n.id));
      const r = n.r;
      ctx.globalAlpha = dim ? 0.18 : 1;
      if (!n.parent) {
        const g = ctx.createRadialGradient(n.x, n.y, r * 0.3, n.x, n.y, r * 3.2);
        g.addColorStop(0, 'rgba(251,191,36,.45)'); g.addColorStop(1, 'rgba(251,191,36,0)');
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(n.x, n.y, r * 3.2, 0, Math.PI * 2); ctx.fill();
      }
      ctx.fillStyle = n.dep > 0 ? depColor(n.dep) : (n.kids.length ? '#334155' : '#475569');
      ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2); ctx.fill();
      if (n === selected || n === hover || ((n.promotor || n.abuse) && r * s > 1.5)) {
        ctx.lineWidth = (n === selected ? 2.4 : 1.6) * lw;
        ctx.strokeStyle = n === selected ? '#ffffff' : n.abuse ? '#ef4444' : n === hover ? '#e2e8f0' : '#a78bfa';
        ctx.beginPath(); ctx.arc(n.x, n.y, r + 2 * lw, 0, Math.PI * 2); ctx.stroke();
      }
      // Label: pusat orbit selalu, lainnya bila diperbesar / mode nama
      const big = n.kids.length && r * s > 7;
      if ((big || n === selected || n === hover || (showLabels && r * s > 3)) && !dim) {
        ctx.font = `${(n.kids.length ? 700 : 500)} ${11 / s}px system-ui, sans-serif`;
        ctx.fillStyle = n.kids.length ? '#fde68a' : '#cbd5e1';
        ctx.textAlign = 'center';
        ctx.fillText(n.name + (n.kids.length ? ` (${n.kids.length})` : ''), n.x, n.y + r + 12 / s);
      }
      ctx.globalAlpha = 1;
    });
  }

  function pick(sx, sy) {
    const [wx, wy] = toWorld(sx, sy);
    let best = null, bd = Infinity;
    for (const n of nodes) {
      const d = Math.hypot(n.x - wx, n.y - wy) - Math.max(n.r, 5 / cam.s);
      if (d < 0 && d < bd) { bd = d; best = n; }
    }
    return best;
  }

  function select(n, fly) {
    selected = n; hl = highlightSet(n);
    if (!n) { side.classList.remove('on'); return; }
    if (fly) flyTo(n);
    const chain = []; let p = n.parent; while (p) { chain.unshift(p); p = p.parent; }
    const kids = n.kids.slice().sort((a, b) => b.dep - a.dep || b.size - a.size);
    const kidsDep = kids.filter(k => k.dep > 0).length;
    side.innerHTML = `
      <h6><span class="nm-av" style="background:${AVC[n.id % AVC.length]}">${esc(n.name.slice(0, 1).toUpperCase())}</span>
        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(n.name)}<br><small style="font-size:11px;color:#7c8699;font-weight:600">ID ${n.id} · ${esc(n.level)}</small></span>
        <button class="nm-close" type="button" data-close>×</button></h6>
      <div class="nm-badges">
        ${n.dep > 0 ? `<span class="nm-badge d">Deposit ${n.depN}×</span>` : '<span class="nm-badge n">Belum deposit</span>'}
        ${n.promotor ? '<span class="nm-badge p">📣 Promotor</span>' : ''}${n.abuse ? '<span class="nm-badge a">⚠ Abuse</span>' : ''}
        ${!n.parent ? '<span class="nm-badge" style="color:#fde68a">☀ Pusat orbit</span>' : `<span class="nm-badge">Tingkat ${n.depth}</span>`}
      </div>
      <div class="nm-kv">
        <div><small>Total deposit</small><b>${rp(n.dep)}</b></div>
        <div><small>WD disetujui</small><b>${rp(n.wd)}</b></div>
        <div><small>Saldo beli</small><b>${rp(n.balDep)}</b></div>
        <div><small>Saldo WD</small><b>${rp(n.balWd)}</b></div>
        <div><small>Daftar</small><b style="font-size:11.5px">${dt(n.joined)}</b></div>
        <div><small>Terakhir online</small><b style="font-size:11.5px">${ago(n.seen)}</b></div>
        <div><small>Deposit terakhir</small><b style="font-size:11.5px">${n.depLast ? dt(n.depLast) : '-'}</b></div>
        <div><small>Selisih depo − WD</small><b style="color:${n.dep - n.wd >= 0 ? '#86efac' : '#fca5a5'}">${rpS(n.dep - n.wd)}</b></div>
      </div>
      <div class="nm-sec">Jalur uplink</div>
      <div class="nm-chain">${chain.length ? chain.map(c => `<a data-id="${c.id}">${c.promotor ? '📣 ' : ''}${esc(c.name)}</a><span style="color:#475569">›</span>`).join('') + `<b>${esc(n.name)}</b>` : '<span style="color:#7c8699">Tidak punya uplink (pusat orbit)</span>'}</div>
      ${n.kids.length ? `
      <div class="nm-sec">Jaringan di bawahnya</div>
      <div class="nm-kv">
        <div><small>Downline langsung</small><b>${n.kids.length}</b> <span style="font-size:11px;color:#7c8699">· ${kidsDep} deposit</span></div>
        <div><small>Seluruh jaringan</small><b>${n.size - 1}</b> <span style="font-size:11px;color:#7c8699">· ${n.netDepositors} deposit</span></div>
        <div style="grid-column:1/-1"><small>Total deposit seluruh jaringan</small><b style="color:#fbbf24">${rp(n.netDep)}</b></div>
      </div>
      <div class="nm-sec">Downline langsung (${kids.length})</div>
      <div class="nm-dl">${kids.map(k => `<a data-id="${k.id}"><span style="color:#e5e7eb">${k.promotor ? '📣 ' : ''}${k.abuse ? '⚠ ' : ''}${esc(k.name)}${k.kids.length ? ` <span>· ${k.size - 1} dl</span>` : ''}</span>${k.dep > 0 ? `<em>${rpS(k.dep)}</em>` : '<em class="z">belum depo</em>'}</a>`).join('')}</div>` : ''}
      <div style="margin-top:12px"><a href="/console/user_txns.php?uid=${n.id}" target="_blank" class="nm-btn" style="display:inline-block;text-decoration:none">Buka transaksi user →</a></div>`;
    side.classList.add('on');
  }

  side.addEventListener('click', e => {
    if (e.target.closest('[data-close]')) return select(null);
    const a = e.target.closest('[data-id]');
    if (a) select(byId.get(+a.dataset.id), true);
  });
  $('nm-tops').addEventListener('click', e => { const a = e.target.closest('[data-id]'); if (a) select(byId.get(+a.dataset.id), true); });

  // Pan, zoom, klik, sentuh
  const ptrs = new Map();
  let dragMoved = false, pinchD = 0;
  cv.addEventListener('pointerdown', e => { cv.setPointerCapture(e.pointerId); ptrs.set(e.pointerId, { x: e.offsetX, y: e.offsetY }); dragMoved = false; cv.classList.add('drag'); });
  cv.addEventListener('pointermove', e => {
    const prev = ptrs.get(e.pointerId);
    if (prev) {
      if (ptrs.size === 2) {
        ptrs.set(e.pointerId, { x: e.offsetX, y: e.offsetY });
        const [a, b] = [...ptrs.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (pinchD) zoomAt((a.x + b.x) / 2, (a.y + b.y) / 2, d / pinchD);
        pinchD = d; dragMoved = true; return;
      }
      const dx = e.offsetX - prev.x, dy = e.offsetY - prev.y;
      if (Math.abs(dx) + Math.abs(dy) > 2) dragMoved = true;
      cam.x -= dx / cam.s; cam.y -= dy / cam.s; camTo = null;
      ptrs.set(e.pointerId, { x: e.offsetX, y: e.offsetY });
      return;
    }
    const n = pick(e.offsetX, e.offsetY);
    hover = n;
    if (n) {
      tip.style.display = 'block';
      tip.innerHTML = `<b>${esc(n.name)}</b> · ${esc(n.level)}<br>${n.dep > 0 ? 'Deposit ' + rp(n.dep) + ' (' + n.depN + '×)' : 'Belum deposit'}${n.kids.length ? `<br>${n.kids.length} downline langsung · jaringan ${rpS(n.netDep)}` : ''}${n.parent ? `<br>Uplink: ${esc(n.parent.name)}` : ''}`;
      const x = Math.min(e.offsetX + 14, W - tip.offsetWidth - 6), y = Math.min(e.offsetY + 14, H - tip.offsetHeight - 6);
      tip.style.left = x + 'px'; tip.style.top = y + 'px';
      cv.style.cursor = 'pointer';
    } else { tip.style.display = 'none'; cv.style.cursor = ''; }
  });
  const endPtr = e => {
    const was = ptrs.has(e.pointerId);
    ptrs.delete(e.pointerId);
    if (ptrs.size < 2) pinchD = 0;
    cv.classList.remove('drag');
    if (was && !dragMoved && e.type === 'pointerup') select(pick(e.offsetX, e.offsetY), false);
  };
  cv.addEventListener('pointerup', endPtr);
  cv.addEventListener('pointercancel', endPtr);
  cv.addEventListener('pointerleave', () => { hover = null; tip.style.display = 'none'; });
  function zoomAt(sx, sy, f) {
    const [wx, wy] = toWorld(sx, sy);
    cam.s = Math.max(0.02, Math.min(8, cam.s * f));
    const [nx, ny] = toWorld(sx, sy);
    cam.x += wx - nx; cam.y += wy - ny; camTo = null;
  }
  cv.addEventListener('wheel', e => { e.preventDefault(); zoomAt(e.offsetX, e.offsetY, Math.exp(-e.deltaY * 0.0015)); }, { passive: false });
  $('z-in').onclick = () => zoomAt(W / 2, H / 2, 1.4);
  $('z-out').onclick = () => zoomAt(W / 2, H / 2, 1 / 1.4);
  $('z-fit').onclick = () => { select(null); fit(true); };

  const toggle = (id, fn) => $(id).onclick = () => { const on = !$(id).classList.contains('on'); $(id).classList.toggle('on', on); fn(on); };
  toggle('btn-orbit', v => showOrbit = v);
  toggle('btn-flow', v => showFlow = v);
  toggle('btn-labels', v => showLabels = v);
  $('btn-reload').onclick = async () => {
    $('btn-reload').textContent = '⟳ Memuat…';
    try {
      DATA = await (await fetch('?action=data', { cache: 'no-store' })).json();
      const sel = selected && selected.id;
      build(); positions((performance.now() - t0) / 1000);
      if (sel && byId.get(sel)) select(byId.get(sel), false);
    } catch (e) { alert('Gagal memuat data'); }
    $('btn-reload').textContent = '⟳ Muat ulang';
  };
  $('nm-filter').onchange = e => filter = e.target.value;
  $('nm-level').onchange = e => levelF = e.target.value;
  $('nm-search').addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const q = e.target.value.trim().toLowerCase();
    if (!q) return;
    const n = nodes.find(x => x.name.toLowerCase() === q || String(x.id) === q) || nodes.find(x => x.name.toLowerCase().includes(q));
    if (n) select(n, true); else alert('User tidak ditemukan di jaringan.');
  });
  $('nm-search').addEventListener('change', e => {
    const n = nodes.find(x => x.name === e.target.value);
    if (n) select(n, true);
  });

  // Ukuran kanvas bisa berubah (sidebar, panel detail): sesuaikan ulang; fit pertama setelah ukurannya nyata
  let fitted = false;
  new ResizeObserver(() => {
    resize();
    if (!fitted && W > 0 && H > 0) { positions(0); fit(false); fitted = true; }
  }).observe(cv);
  build();
  resize();
  positions(0);
  if (W > 0 && H > 0) { fit(false); fitted = true; }
  requestAnimationFrame(frame);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
