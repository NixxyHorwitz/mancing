<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$cms_cfg = home_cms_config($pdo);
$fishing_cfg = $cms_cfg['fishing_engine'] ?? [];
$is_fishing_enabled = !empty($fishing_cfg['enabled']);

if (!$is_fishing_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Fitur Pemancingan Cuan sedang dinonaktifkan sementara oleh Administrator.';
    redirect('/home');
}

// Skema & ekonomi bersama (Ember, harga pasar, preferensi joran)
require_once dirname(__DIR__) . '/lib/fishing.php';
fishing_ensure_schema($pdo);

// Refresh fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

$daily_free_baits = max(0, (int)($fishing_cfg['daily_free_baits'] ?? 3));

// Auto refill daily bait if below threshold on new day
$todayStr = date('Y-m-d');
$lastPlayedDate = $user['last_plinko_claim'] ?? ''; // or check last checkin
if ($lastPlayedDate !== $todayStr && (int)($user['fishing_baits'] ?? 0) < $daily_free_baits) {
    $pdo->prepare("UPDATE users SET fishing_baits = ?, last_plinko_claim = ? WHERE id = ?")
        ->execute([$daily_free_baits, $todayStr, $user['id']]);
    $user['fishing_baits'] = $daily_free_baits;
}

// ─────────────────────────────────────────────────────────────
// KOLEKSI JORAN (SKIN PANCING)
// Syarat unlock & bonus diputuskan di server. Bonus hanya memengaruhi
// tempo permainan (kecepatan tarikan & sambaran), bukan nilai hadiah.
// tier = sort_order membership aktif (lihat user/upgrade.php)
// ─────────────────────────────────────────────────────────────
$ROD_SKINS = fishing_rod_catalog($pdo); // tabel fishing_rod_skins (dikelola di Console › Fish System)

// ─────────────────────────────────────────────────────────────
// AJAX API HANDLER
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // 1. LEMPAR KAIL (CAST ROD)
    if ($action === 'cast_rod') {
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'msg' => 'Sesi keamanan kedaluwarsa. Silakan refresh halaman!']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Lock user row
            $s = $pdo->prepare("SELECT id, balance_wd, total_earned, fishing_baits FROM users WHERE id = ? FOR UPDATE");
            $s->execute([$user['id']]);
            $dbUser = $s->fetch(PDO::FETCH_ASSOC);

            if (!$dbUser) {
                throw new Exception('Pengguna tidak ditemukan.');
            }

            $current_baits = (int)($dbUser['fishing_baits'] ?? 0);
            if ($current_baits < 1) {
                $pdo->rollBack();
                echo json_encode([
                    'ok' => false,
                    'msg' => 'Umpan pancingmu habis! Tonton video atau beli paket umpan untuk melanjutkan.',
                    'out_of_baits' => true
                ]);
                exit;
            }

            // Deduct 1 bait
            $new_baits = $current_baits - 1;

            // Undi tangkapan: peluang, berat & harga dasar dari tabel fishing_species (Console › Fish System › Harga Ikan)
            $fish = fishing_roll_catch($pdo);

            // Ikan masuk Ember (belum menjadi saldo). Saldo Tarik bertambah saat dijual di Pasar Ikan (Ember).
            $pdo->prepare("UPDATE users SET fishing_baits = ? WHERE id = ?")->execute([$new_baits, $user['id']]);

            // Insert into fishing_logs
            $logIns = $pdo->prepare("
                INSERT INTO fishing_logs (user_id, fish_name, fish_icon, rarity, weight_kg, reward)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $logIns->execute([
                $user['id'],
                $fish['name'],
                $fish['icon'],
                $fish['rarity'],
                $fish['weight'],
                $fish['reward']
            ]);

            $logId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $market = fishing_market_today($pdo);
            $price  = fishing_sell_price((float)$fish['reward'], $fish['badge'], $market);

            echo json_encode([
                'ok'             => true,
                'fish'           => $fish,
                'remaining_baits'=> $new_baits,
                'log_id'         => $logId,
                'price'          => $price,
                'price_rp'       => format_rp($price),
                'market_trend'   => $market[$fish['badge']]['trend'] ?? 'flat',
            ]);
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'msg' => 'Gagal memancing: ' . $e->getMessage()]);
            exit;
        }
    }

    // 3. PILIH JORAN (SKIN PANCING)
    if ($action === 'select_rod') {
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'msg' => 'Sesi keamanan kedaluwarsa.']);
            exit;
        }
        $key = (string)($_POST['rod'] ?? '');
        if (!isset($ROD_SKINS[$key])) {
            echo json_encode(['ok' => false, 'msg' => 'Joran tidak dikenal.']);
            exit;
        }
        $state = fishing_rod_state($pdo, $user, $ROD_SKINS);
        $row = null;
        foreach ($state['rods'] as $r) { if ($r['key'] === $key) { $row = $r; break; } }
        if (!$row || !$row['unlocked']) {
            echo json_encode(['ok' => false, 'msg' => 'Joran ini masih terkunci: ' . ($row['req'] ?? '-'), 'locked' => true]);
            exit;
        }
        fishing_prefs_save($pdo, (int)$user['id'], ['rod_skin' => $key]);
        echo json_encode(['ok' => true, 'msg' => $row['name'] . ' siap dipakai!', 'rod' => $row]);
        exit;
    }

    // 4. JUAL IKAN DARI EMBER (harga pasar dihitung server)
    if ($action === 'sell_fish') {
        echo json_encode(fishing_sell($pdo, (int)$user['id'], explode(',', (string)($_POST['ids'] ?? ''))));
        exit;
    }

    // 5. BELI PAKET UMPAN (Saldo Beli)
    if ($action === 'buy_baits') {
        echo json_encode(fishing_buy_bait($pdo, (int)$user['id'], (int)($_POST['pack_id'] ?? 0)));
        exit;
    }

    // 6. SIMPAN PREFERENSI (suara, panduan yang sudah dilihat)
    if ($action === 'save_prefs') {
        $f = [];
        if (isset($_POST['sound_muted'])) $f['sound_muted'] = $_POST['sound_muted'] === '1';
        if (!empty($_POST['tutorial_seen'])) $f['tutorial_seen'] = explode(',', (string)$_POST['tutorial_seen']);
        fishing_prefs_save($pdo, (int)$user['id'], $f);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// DATA UNTUK TAMPILAN
// ─────────────────────────────────────────────────────────────
$market = fishing_market_today($pdo);
$todayCatches = fishing_unsold($pdo, (int)$user['id'], 40);
$todayCatchesTotal = 0.0; // nilai Ember dengan harga pasar hari ini
foreach ($todayCatches as $tc) {
    $todayCatchesTotal += fishing_sell_price((float)$tc['reward'], fishing_badge_of((string)$tc['rarity']), $market);
}

// ─────────────────────────────────────────────────────────────
// DATA UNTUK HALAMAN GAME LAYAR PENUH
// ─────────────────────────────────────────────────────────────
$inv_catches = array_map(function ($tc) use ($market) {
    $badge = fishing_badge_of((string)$tc['rarity']);
    return [
        'id'     => (int)$tc['id'],
        'name'   => (string)$tc['fish_name'],
        'badge'  => $badge,
        'weight' => (float)$tc['weight_kg'],
        'reward' => fishing_sell_price((float)$tc['reward'], $badge, $market),
        'time'   => date('d/m H:i', strtotime((string)$tc['created_at'])),
    ];
}, $todayCatches);

// SFX laut: pakai file rekaman bila tersedia, jika tidak, suara laut disintesis WebAudio
$ambience_url = '';
foreach (['ocean-waves-seagulls.ogg', 'ocean-waves-seagulls.mp3'] as $af) {
    if (is_file(dirname(__DIR__) . '/assets/audio/' . $af)) { $ambience_url = '/assets/audio/' . $af; break; }
}

$rod_state = fishing_rod_state($pdo, $user, $ROD_SKINS);
$prefs = fishing_prefs($pdo, (int)$user['id']);
$bait_packs = array_map(fn($p) => [
    'id' => (int)$p['id'], 'name' => (string)$p['name'], 'qty' => (int)$p['qty'], 'price' => (float)$p['price'],
    'tag' => (string)($p['tag'] ?? ''), 'desc' => (string)($p['description'] ?? ''), 'color' => (string)$p['color'],
], fishing_bait_packs($pdo));

$_site_name = setting($pdo, 'site_name', 'MancingCuan');
$_favicon   = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_fav_url   = preg_match('~^https?://~', (string)$_favicon) ? $_favicon : '/' . ltrim((string)$_favicon, '/');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b6e7f">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Pemancingan Bahari Nusantara — <?= htmlspecialchars($_site_name) ?></title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($_fav_url) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="preload" as="script" href="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
/* ══════════════════════════════════════════════════════════
   PEMANCINGAN BAHARI NUSANTARA — GAME 3D LAYAR PENUH
   ══════════════════════════════════════════════════════════ */
:root {
  --reef-ink: #1e1b2e;
  --reef-muted: #6b6478;
  --reef-card: linear-gradient(160deg, #ffffff 0%, #fff7ed 58%, #ffedd5 100%);
  --reef-clay-shadow: 0 14px 30px rgba(244, 63, 94, 0.10), 0 6px 14px rgba(8, 145, 178, 0.10), inset 3px 3px 6px rgba(255, 255, 255, 0.95), inset -4px -4px 9px rgba(251, 146, 60, 0.16);
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
}
*, *::before, *::after { box-sizing: border-box; }
html, body {
  margin: 0; padding: 0;
  width: 100%; height: 100%;
  overflow: hidden;
  overscroll-behavior: none;
  background: #0b6e7f;
  font-family: 'Nunito', -apple-system, sans-serif;
  color: var(--reef-ink);
  -webkit-font-smoothing: antialiased;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
  -webkit-user-select: none;
}
i[class^="ph-"] { display: inline-flex; align-items: center; justify-content: center; }
button { font-family: inherit; }

/* ── STAGE LAYAR PENUH ── */
.mc3d-stage {
  position: fixed;
  inset: 0;
  height: 100vh;
  height: 100dvh;
  overflow: hidden;
  background: linear-gradient(180deg, #8ec5fc 0%, #ffd6a5 48%, #1fb5b0 52%, #0b6e7f 100%);
  touch-action: none;
}
#mc3d-canvas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
.mc3d-nogl {
  position: absolute; inset: 0;
  display: none;
  align-items: center; justify-content: center;
  padding: 24px;
  text-align: center;
  color: #ffffff;
  font-size: 13px;
  font-weight: 800;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
.mc3d-stage.no-gl .mc3d-nogl { display: flex; }

.glass {
  background: rgba(15, 23, 42, 0.32);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border: 1.5px solid rgba(255, 255, 255, 0.35);
  color: #ffffff;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18), inset 1px 1px 2px rgba(255, 255, 255, 0.25);
}

/* Widget atas */
.hud-top {
  position: absolute;
  top: calc(var(--safe-t) + 10px);
  left: 10px; right: 10px;
  display: flex;
  align-items: center;
  gap: 6px;
  z-index: 3;
  pointer-events: none;
}
.hud-top > * { pointer-events: auto; }
.hud-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  height: 36px;
  padding: 0 11px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 900;
  white-space: nowrap;
}
.hud-chip i { font-size: 16px; }
.hud-chip--bait i { color: #fde68a; }
.hud-chip--cuan i { color: #6ee7b7; }
.hud-chip-add {
  margin-left: 2px;
  width: 22px; height: 22px;
  border: none;
  border-radius: 50%;
  background: #ffffff;
  color: #e11d48;
  font-size: 12px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.hud-spacer { flex: 1; }
.hud-icon-btn {
  position: relative;
  width: 40px; height: 40px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  cursor: pointer;
  text-decoration: none;
  flex-shrink: 0;
}
.hud-icon-btn--inv {
  width: 46px; height: 46px;
  border-radius: 16px;
  border: 2px solid rgba(255, 255, 255, 0.9);
  font-size: 22px;
  background: linear-gradient(145deg, #fde68a 0%, #fbbf24 45%, #d97706 100%);
  color: #78350f;
  box-shadow: 0 6px 16px rgba(180, 83, 9, 0.4), inset 2px 2px 3px rgba(255, 255, 255, 0.6), inset -2px -2px 4px rgba(120, 53, 15, 0.3);
}
.hud-badge {
  position: absolute;
  top: -5px; right: -5px;
  min-width: 19px; height: 19px;
  padding: 0 5px;
  border-radius: 999px;
  background: #f43f5e;
  border: 2px solid #ffffff;
  color: #ffffff;
  font-size: 10px;
  font-weight: 900;
  display: flex; align-items: center; justify-content: center;
}
.hud-badge.bump { animation: badgeBump 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes badgeBump { 50% { transform: scale(1.5); } }

/* Pesan fase */
.hud-msg {
  position: absolute;
  top: calc(var(--safe-t) + 58px);
  left: 0; right: 0;
  width: fit-content;
  margin: 0 auto;
  max-width: min(420px, calc(100% - 24px));
  padding: 7px 15px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 800;
  text-align: center;
  z-index: 3;
  pointer-events: none;
  transition: background 0.2s;
}
.hud-msg.warn { background: rgba(225, 29, 72, 0.55); }
.hud-msg.good { background: rgba(5, 150, 105, 0.55); }

.hud-strike {
  position: absolute;
  top: 36%; left: 50%;
  transform: translate(-50%, -50%) scale(0.4);
  font-size: clamp(44px, 12vw, 72px);
  font-weight: 900;
  font-style: italic;
  letter-spacing: -1px;
  color: #fde68a;
  -webkit-text-stroke: 2px #e11d48;
  text-shadow: 0 6px 0 #be123c, 0 12px 24px rgba(0, 0, 0, 0.35);
  opacity: 0;
  z-index: 4;
  pointer-events: none;
  white-space: nowrap;
}
.hud-strike.show { animation: strikePop 1.1s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
@keyframes strikePop {
  0% { opacity: 0; transform: translate(-50%, -50%) scale(0.4) rotate(-8deg); }
  25% { opacity: 1; transform: translate(-50%, -50%) scale(1.12) rotate(-4deg); }
  70% { opacity: 1; transform: translate(-50%, -50%) scale(1) rotate(-4deg); }
  100% { opacity: 0; transform: translate(-50%, -60%) scale(1) rotate(-4deg); }
}
.hud-flash { position: absolute; inset: 0; background: #ffffff; opacity: 0; z-index: 5; pointer-events: none; }
.hud-flash.go { animation: flashGo 0.6s ease-out; }
@keyframes flashGo { 0% { opacity: 0; } 20% { opacity: 0.9; } 100% { opacity: 0; } }

/* Panel kontrol bawah */
.hud-bottom {
  position: absolute;
  left: 0; right: 0;
  bottom: calc(var(--safe-b) + 14px);
  width: min(400px, calc(100% - 24px));
  margin: 0 auto;
  z-index: 3;
}
.hud-panel { display: none; }
.hud-panel.active { display: block; }
.cast-card { border-radius: 22px; padding: 8px 12px 12px; text-align: center; }
.pw-arc { display: block; width: 200px; height: 110px; margin: 0 auto 2px; overflow: visible; }
.pw-track { stroke: rgba(255, 255, 255, 0.25); }
.pw-fill { stroke: url(#pwGrad); }
.pw-label { font-size: 11px; font-weight: 900; margin-bottom: 8px; min-height: 14px; }
.pw-label.sweet { color: #fde68a; }
.btn-hold {
  width: 100%;
  height: 54px;
  border: 2px solid rgba(255, 255, 255, 0.85);
  border-radius: 17px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  font-size: 14.5px;
  font-weight: 900;
  color: #ffffff;
  cursor: pointer;
  touch-action: none;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
  transition: transform 0.1s;
}
.btn-hold:active, .btn-hold.held { transform: scale(0.97); }
.btn-hold i { font-size: 20px; }
.btn-cast {
  background: linear-gradient(145deg, #fdba74 0%, #fb7185 45%, #f43f5e 100%);
  box-shadow: 0 8px 18px rgba(244, 63, 94, 0.4), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.55), inset -3px -3px 6px rgba(190, 18, 60, 0.35);
}
.btn-cast.held { background: linear-gradient(145deg, #fde68a 0%, #fbbf24 45%, #f43f5e 100%); }
.btn-cast.empty { background: linear-gradient(145deg, #a8a29e, #78716c); }
.hook-wrap { display: flex; flex-direction: column; align-items: center; gap: 8px; }
.btn-hook {
  position: relative;
  width: 116px; height: 116px;
  border: 3px solid #ffffff;
  border-radius: 50%;
  font-size: 25px;
  font-weight: 900;
  font-style: italic;
  color: #ffffff;
  cursor: pointer;
  touch-action: none;
  background: radial-gradient(circle at 35% 30%, #fde68a 0%, #fb7185 45%, #e11d48 100%);
  box-shadow: 0 10px 26px rgba(225, 29, 72, 0.55), inset 3px 3px 6px rgba(255, 255, 255, 0.55), inset -4px -4px 8px rgba(159, 18, 57, 0.4);
  text-shadow: 0 2px 3px rgba(159, 18, 57, 0.5);
  animation: hookPulse 0.5s ease-in-out infinite alternate;
}
@keyframes hookPulse { from { transform: scale(1); } to { transform: scale(1.07); } }
.hook-ring { position: absolute; inset: -9px; width: calc(100% + 18px); height: calc(100% + 18px); transform: rotate(-90deg); }
.hook-ring circle { fill: none; stroke: #fde68a; stroke-width: 5; stroke-linecap: round; }
.hook-hint { font-size: 11px; font-weight: 900; padding: 5px 12px; border-radius: 999px; }
.fight-card { border-radius: 22px; padding: 10px 12px 12px; }
.ft-row { display: flex; align-items: center; justify-content: space-between; font-size: 10.5px; font-weight: 900; margin-bottom: 5px; }
.ft-row span:first-child { display: inline-flex; align-items: center; gap: 4px; }
.ft-dist-track, .ft-ten-track { position: relative; height: 10px; border-radius: 999px; overflow: hidden; background: rgba(255, 255, 255, 0.2); margin-bottom: 9px; }
.ft-dist-fill { height: 100%; width: 100%; border-radius: 999px; background: linear-gradient(90deg, #22d3ee, #34d399); transform-origin: left center; }
.ft-ten-track { height: 14px; background: linear-gradient(90deg, #34d399 0%, #34d399 55%, #fbbf24 55%, #fbbf24 80%, #f43f5e 80%, #f43f5e 100%); opacity: 0.95; }
.ft-ten-fill { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.55); transform-origin: right center; }
.ft-needle { position: absolute; top: -3px; bottom: -3px; left: 0; width: 4px; margin-left: -2px; border-radius: 2px; background: #ffffff; box-shadow: 0 0 6px rgba(255, 255, 255, 0.9); }
.ft-ten-txt.danger { color: #fda4af; animation: dangerBlink 0.3s steps(2) infinite; }
@keyframes dangerBlink { 50% { opacity: 0.4; } }
.btn-reel {
  background: linear-gradient(145deg, #6ee7b7 0%, #10b981 45%, #047857 100%);
  box-shadow: 0 8px 18px rgba(5, 150, 105, 0.4), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.5), inset -3px -3px 6px rgba(6, 78, 59, 0.35);
}
.btn-reel.held { background: linear-gradient(145deg, #a7f3d0 0%, #34d399 45%, #059669 100%); }
.btn-reel.locked { filter: grayscale(0.7) brightness(0.85); }

/* ── LOADER ── */
.mc-loader {
  position: fixed;
  inset: 0;
  z-index: 20000;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  overflow: hidden;
  background:
    radial-gradient(circle at 50% 42%, rgba(255, 240, 194, 0.85) 0, rgba(255, 240, 194, 0) 32%),
    linear-gradient(180deg, #8ec5fc 0%, #ffd6a5 55%, #fb9f7a 100%);
  transition: opacity 0.6s ease, visibility 0.6s;
}
.mc-loader.done { opacity: 0; visibility: hidden; }
.ld-sun {
  position: absolute;
  top: 34%; left: 50%;
  width: 120px; height: 120px;
  margin: -60px 0 0 -60px;
  border-radius: 50%;
  background: radial-gradient(circle, #fff7d6 0%, #ffd27a 60%, rgba(255, 190, 110, 0) 72%);
}
.ld-sea {
  position: absolute;
  left: 0; right: 0; bottom: 0;
  height: 44%;
  background: linear-gradient(180deg, #1fb5b0 0%, #0b6e7f 100%);
}
.ld-wave {
  position: absolute;
  left: 0; width: 200%;
  height: 40px;
  background-repeat: repeat-x;
  background-size: 50% 100%;
  animation: ldWave 6s linear infinite;
}
.ld-wave--1 { top: -26px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 40' preserveAspectRatio='none'%3E%3Cpath d='M0 20 Q50 0 100 20 T200 20 T300 20 T400 20 V40 H0Z' fill='%231fb5b0'/%3E%3C/svg%3E"); }
.ld-wave--2 { top: -14px; opacity: 0.7; animation-duration: 9s; animation-direction: reverse; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 40' preserveAspectRatio='none'%3E%3Cpath d='M0 20 Q50 40 100 20 T200 20 T300 20 T400 20 V40 H0Z' fill='%23a5f3fc'/%3E%3C/svg%3E"); }
@keyframes ldWave { from { transform: translateX(0); } to { transform: translateX(-50%); } }
.ld-boat {
  position: absolute;
  bottom: calc(44% - 8px);
  left: 50%;
  width: 120px;
  margin-left: -60px;
  animation: ldBob 3s ease-in-out infinite;
}
@keyframes ldBob { 0%, 100% { transform: translateY(0) rotate(-2deg); } 50% { transform: translateY(-6px) rotate(2deg); } }
.ld-card {
  position: absolute;
  z-index: 2;
  left: 0; right: 0;
  bottom: calc(var(--safe-b) + 28px);
  margin: 0 auto;
  width: min(340px, calc(100% - 32px));
  padding: 16px 16px 14px;
  border: 2px solid #ffffff;
  border-radius: 24px;
  background: var(--reef-card);
  box-shadow: 0 20px 44px rgba(11, 110, 127, 0.3), inset 3px 3px 6px #ffffff, inset -4px -4px 9px rgba(251, 146, 60, 0.16);
  text-align: center;
}
.ld-title { font-size: 17px; font-weight: 900; letter-spacing: -0.3px; }
.ld-sub { font-size: 11px; font-weight: 700; color: var(--reef-muted); margin-bottom: 12px; }
.ld-bar {
  height: 12px;
  border-radius: 999px;
  overflow: hidden;
  background: #ffedd5;
  box-shadow: inset 2px 2px 4px rgba(251, 146, 60, 0.2);
}
.ld-bar-fill {
  height: 100%;
  width: 0%;
  border-radius: 999px;
  background: linear-gradient(90deg, #22d3ee, #34d399 50%, #fbbf24);
  transition: width 0.35s ease;
  position: relative;
  overflow: hidden;
}
.ld-bar-fill::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
  animation: ldShine 1.2s linear infinite;
}
@keyframes ldShine { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.ld-status { display: flex; justify-content: space-between; margin-top: 6px; font-size: 10.5px; font-weight: 900; color: #9a3412; }
.ld-tip { margin-top: 10px; min-height: 30px; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.4; }
.ld-start {
  display: none;
  width: 100%;
  height: 50px;
  margin-top: 10px;
  border: 2px solid #ffffff;
  border-radius: 16px;
  align-items: center; justify-content: center; gap: 8px;
  background: linear-gradient(145deg, #fdba74 0%, #fb7185 45%, #f43f5e 100%);
  color: #ffffff;
  font-size: 15px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(244, 63, 94, 0.4), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.55), inset -3px -3px 6px rgba(190, 18, 60, 0.35);
  animation: hookPulse 0.8s ease-in-out infinite alternate;
}
.ld-start.show { display: flex; }
.ld-start i { font-size: 20px; }

/* ── MODAL ── */
.mc-modal {
  position: fixed; inset: 0;
  z-index: 10050;
  display: none;
  align-items: center; justify-content: center;
  padding: 16px;
  background: rgba(30, 27, 46, 0.55);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
.mc-modal.open { display: flex; }
.mc-modal-card {
  width: 100%;
  max-width: 380px;
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  border: 2px solid #ffffff;
  border-radius: 26px;
  background: var(--reef-card);
  box-shadow: 0 24px 50px rgba(30, 27, 46, 0.3), inset 3px 3px 6px #ffffff, inset -4px -4px 9px rgba(251, 146, 60, 0.16);
  padding: 14px;
  position: relative;
  animation: cardIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes cardIn { from { transform: scale(0.85) translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }
.modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.modal-title { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 900; }
.modal-close {
  width: 32px; height: 32px;
  border: none;
  border-radius: 50%;
  background: #ffffff;
  color: var(--reef-muted);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  box-shadow: inset -1px -1px 2px rgba(251, 146, 60, 0.15), 0 2px 6px rgba(0, 0, 0, 0.06);
}
.m-ico {
  width: 32px; height: 32px;
  border-radius: 11px;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: #ffffff;
  font-size: 16px;
  box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -1.5px -1.5px 3px rgba(0, 0, 0, 0.14);
}
.m-ico--gold { background: linear-gradient(145deg, #fde68a, #d97706); color: #78350f; }
.rb-biasa   { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.rb-bagus   { background: linear-gradient(145deg, #6ee7b7, #059669); }
.rb-langka  { background: linear-gradient(145deg, #fda4af, #e11d48); }
.rb-super   { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.rb-jackpot { background: linear-gradient(145deg, #fef08a, #f59e0b 50%, #d97706); color: #78350f; }

/* Showcase */
.show-stage, .inv-stage {
  position: relative;
  height: 220px;
  border-radius: 18px;
  overflow: hidden;
  background:
    radial-gradient(circle at 50% 38%, rgba(255, 255, 255, 0.95) 0, rgba(255, 255, 255, 0) 55%),
    radial-gradient(circle at 50% 100%, rgba(34, 211, 238, 0.35) 0, transparent 60%),
    linear-gradient(180deg, #fff7ed 0%, #ffe4e6 100%);
  box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.08);
}
.show-stage.is-jackpot {
  background: radial-gradient(circle at 50% 40%, rgba(254, 240, 138, 0.95) 0, rgba(254, 240, 138, 0) 55%), linear-gradient(180deg, #3b0764 0%, #7c2d12 100%);
}
#show-canvas, #inv-canvas { width: 100%; height: 100%; display: block; touch-action: none; }
.show-fallback {
  position: absolute; inset: 0;
  display: none;
  align-items: center; justify-content: center;
  font-size: 64px;
  color: #f43f5e;
}
.show-stage.no-gl .show-fallback, .inv-stage.no-gl .show-fallback { display: flex; }
.show-badge {
  position: absolute;
  top: 10px; left: 10px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 900;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: #ffffff;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15), inset 1px 1px 2px rgba(255, 255, 255, 0.5);
}
.show-badge.rb-jackpot { animation: jackShine 1.2s ease-in-out infinite alternate; }
@keyframes jackShine { to { box-shadow: 0 0 18px rgba(253, 224, 71, 0.9), inset 1px 1px 2px rgba(255, 255, 255, 0.6); } }
.show-info { text-align: center; padding: 12px 4px 4px; }
.show-name { font-size: 17px; font-weight: 900; letter-spacing: -0.3px; }
.show-latin { font-size: 10.5px; font-weight: 700; font-style: italic; color: var(--reef-muted); margin-bottom: 10px; }
.show-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
.show-stats--3 { grid-template-columns: repeat(3, 1fr); }
.show-stat {
  padding: 8px;
  border-radius: 14px;
  background: #ffffff;
  text-align: center;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(251, 146, 60, 0.12);
}
.show-stat small { display: block; font-size: 9px; font-weight: 900; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.show-stat b { display: block; font-size: 15px; font-weight: 900; }
.show-stat--rp b { color: #059669; }
.show-note { font-size: 10px; font-weight: 700; color: var(--reef-muted); margin-bottom: 10px; }
.btn-save, .btn-soft {
  width: 100%;
  height: 48px;
  border: 2px solid #ffffff;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  font-size: 13.5px;
  font-weight: 900;
  cursor: pointer;
}
.btn-save {
  background: linear-gradient(145deg, #fde68a 0%, #fbbf24 40%, #f59e0b 100%);
  color: #78350f;
  box-shadow: 0 8px 18px rgba(245, 158, 11, 0.4), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.6), inset -3px -3px 6px rgba(180, 83, 9, 0.3);
}
.btn-save:disabled { opacity: 0.7; }
.btn-save i, .btn-soft i { font-size: 19px; }
.btn-soft {
  height: 42px;
  background: #ffffff;
  color: #e11d48;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 5px rgba(244, 63, 94, 0.14), 0 4px 10px rgba(244, 63, 94, 0.08);
}
.fly-coin {
  position: fixed;
  z-index: 10060;
  width: 22px; height: 22px;
  margin: -11px 0 0 -11px;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 30%, #fef9c3 0%, #fbbf24 45%, #d97706 100%);
  border: 2px solid #fef3c7;
  box-shadow: 0 3px 8px rgba(180, 83, 9, 0.45);
  pointer-events: none;
}

/* Ember Cuan (inventory 3D) */
.inv-stage { height: 250px; background: radial-gradient(circle at 50% 30%, rgba(255, 255, 255, 0.9) 0, rgba(255, 255, 255, 0) 55%), linear-gradient(180deg, #a5f3fc 0%, #22d3ee 45%, #0e7490 100%); }
.inv-nav {
  position: absolute;
  top: 50%;
  width: 38px; height: 38px;
  margin-top: -19px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  cursor: pointer;
  z-index: 2;
}
.inv-nav--prev { left: 8px; }
.inv-nav--next { right: 8px; }
.inv-pos {
  position: absolute;
  bottom: 8px; left: 50%;
  transform: translateX(-50%);
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 900;
  z-index: 2;
}
.inv-empty {
  position: absolute;
  left: 0; right: 0; bottom: 40px;
  text-align: center;
  font-size: 12px;
  font-weight: 900;
  color: #ffffff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
  display: none;
  z-index: 2;
}
.inv-stage.is-empty .inv-empty { display: block; }
.inv-stage.is-empty .inv-nav, .inv-stage.is-empty .inv-pos, .inv-stage.is-empty .show-badge { display: none; }
.inv-strip {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 2px 2px 8px;
  margin-bottom: 6px;
  scrollbar-width: none;
}
.inv-strip::-webkit-scrollbar { display: none; }
.inv-chip {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px 4px 4px;
  border: 2px solid transparent;
  border-radius: 999px;
  background: #ffffff;
  font-size: 10px;
  font-weight: 900;
  color: var(--reef-ink);
  cursor: pointer;
  box-shadow: inset -1px -1px 3px rgba(251, 146, 60, 0.15);
}
.inv-chip.active { border-color: #f43f5e; }
.inv-chip .m-ico { width: 22px; height: 22px; border-radius: 50%; font-size: 12px; }

/* Toko umpan */
.shop-dep {
  padding: 9px 11px;
  border-radius: 14px;
  margin-bottom: 10px;
  background: #ffffff;
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(251, 146, 60, 0.12);
}
.shop-dep strong { color: #059669; }
.shop-pack {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  margin-bottom: 8px;
  border-radius: 16px;
  background: #ffffff;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 5px rgba(251, 146, 60, 0.12);
}
.shop-pack-info { flex: 1; }
.shop-pack-info strong { display: block; font-size: 12px; font-weight: 900; }
.shop-pack-info small { display: block; font-size: 10px; font-weight: 700; color: var(--reef-muted); }
.shop-tag { font-size: 8.5px; font-weight: 900; padding: 1px 6px; border-radius: 6px; background: #d1fae5; color: #047857; margin-left: 4px; }
.shop-buy {
  border: none;
  border-radius: 11px;
  padding: 7px 13px;
  font-size: 11px;
  font-weight: 900;
  color: #ffffff;
  cursor: pointer;
  background: linear-gradient(145deg, #fb7185, #f43f5e);
  box-shadow: 0 4px 10px rgba(244, 63, 94, 0.3), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5);
}
.shop-buy:disabled { opacity: 0.6; }
.shop-free {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 10px;
  margin-bottom: 8px;
  border-radius: 14px;
  background: #faf5ff;
  text-decoration: none;
  font-size: 11px; font-weight: 800; color: #6b21a8;
}
.shop-topup { display: block; text-align: center; margin-top: 4px; font-size: 11px; font-weight: 900; color: #e11d48; text-decoration: none; }


/* ── TOKEN UMPAN (desain baru) ── */
.bait-token {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  height: 40px;
  padding: 0 5px 0 4px;
  border-radius: 999px;
}
.bait-ico {
  width: 32px; height: 32px;
  border-radius: 50%;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: radial-gradient(circle at 35% 30%, #fdba74 0%, #f97316 55%, #c2410c 100%);
  border: 2px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 3px 8px rgba(194, 65, 12, 0.45), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5);
}
.bait-ico svg { width: 24px; height: 24px; filter: drop-shadow(0 1px 1px rgba(0, 0, 0, 0.3)); }
.bait-txt { display: flex; flex-direction: column; line-height: 1; }
.bait-txt small { font-size: 8.5px; font-weight: 900; letter-spacing: 0.6px; text-transform: uppercase; opacity: 0.8; }
.bait-txt b { font-size: 15px; font-weight: 900; }
.bait-txt b.bump { animation: badgeBump 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); display: inline-block; }
.bait-add {
  width: 26px; height: 26px;
  border: none;
  border-radius: 50%;
  background: linear-gradient(145deg, #fb7185, #e11d48);
  color: #ffffff;
  font-size: 13px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.5), 0 2px 6px rgba(225, 29, 72, 0.4);
}

/* ── DOK SAMPING (joran & ember) ── */
.hud-side {
  position: absolute;
  right: 10px;
  top: calc(var(--safe-t) + 118px);
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 3;
}
.side-btn {
  position: relative;
  width: 54px;
  padding: 6px 0 5px;
  border: 2px solid rgba(255, 255, 255, 0.9);
  border-radius: 17px;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  cursor: pointer;
  color: #ffffff;
}
.side-ico { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
.side-ico svg { width: 26px; height: 26px; }
.side-lbl { font-size: 8.5px; font-weight: 900; max-width: 50px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); }
.side-btn--rod {
  background: linear-gradient(145deg, #c084fc 0%, #8b5cf6 50%, #6d28d9 100%);
  box-shadow: 0 6px 16px rgba(109, 40, 217, 0.45), inset 2px 2px 3px rgba(255, 255, 255, 0.45), inset -2px -2px 4px rgba(46, 16, 101, 0.35);
}
.side-btn--inv {
  background: linear-gradient(145deg, #fde68a 0%, #fbbf24 45%, #d97706 100%);
  color: #78350f;
  box-shadow: 0 6px 16px rgba(180, 83, 9, 0.4), inset 2px 2px 3px rgba(255, 255, 255, 0.6), inset -2px -2px 4px rgba(120, 53, 15, 0.3);
}
.side-btn--inv .side-lbl { text-shadow: none; }
.side-btn--dock {
  text-decoration: none;
  background: linear-gradient(145deg, #67e8f9 0%, #0891b2 55%, #155e75 100%);
  box-shadow: 0 6px 16px rgba(8, 145, 178, 0.4), inset 2px 2px 3px rgba(255, 255, 255, 0.45), inset -2px -2px 4px rgba(8, 51, 68, 0.35);
}
.side-btn:active { transform: scale(0.94); }

/* ── PANDUAN ── */
.help-tabs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; padding: 4px; border-radius: 14px; background: #ffffff; margin-bottom: 10px; box-shadow: inset 2px 2px 4px rgba(251, 146, 60, 0.12); }
.help-tab { height: 32px; border: none; border-radius: 10px; background: transparent; font-size: 11px; font-weight: 900; color: var(--reef-muted); cursor: pointer; }
.help-tab.active { background: linear-gradient(145deg, #c084fc, #7c3aed); color: #ffffff; box-shadow: 0 3px 8px rgba(124, 58, 237, 0.3); }
.help-diag { display: block; width: 100%; height: 76px; margin: 0 0 8px; }
.help-steps { list-style: none; padding: 0; margin: 0 0 10px; display: flex; flex-direction: column; gap: 6px; }
.help-steps li { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border-radius: 13px; background: #ffffff; font-size: 11.5px; font-weight: 700; line-height: 1.45; color: #44403c; box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.help-steps li > span { width: 20px; height: 20px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: linear-gradient(145deg, #fb7185, #e11d48); color: #ffffff; font-size: 10px; font-weight: 900; }
.help-steps b { color: var(--reef-ink); font-weight: 900; }
.help-tip { display: flex; gap: 6px; align-items: flex-start; padding: 8px 10px; margin-bottom: 8px; border-radius: 13px; background: #faf5ff; font-size: 11px; font-weight: 800; color: #6b21a8; }
.help-tip i { color: #a855f7; font-size: 15px; }
.help-pause { display: flex; align-items: center; justify-content: center; gap: 4px; font-size: 10px; font-weight: 800; color: var(--reef-muted); margin-bottom: 8px; }

/* ── KOLEKSI JORAN ── */
.rod-stage {
  position: relative;
  height: 190px;
  border-radius: 18px;
  overflow: hidden;
  background:
    radial-gradient(circle at 50% 45%, rgba(255, 255, 255, 0.9) 0, rgba(255, 255, 255, 0) 55%),
    linear-gradient(160deg, #ede9fe 0%, #fce7f3 55%, #ffedd5 100%);
  box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.08);
}
#rod-canvas { width: 100%; height: 100%; display: block; }
.rod-stage.no-gl .show-fallback { display: flex; }
.rod-lock-ov {
  position: absolute; inset: 0;
  display: none;
  align-items: center; justify-content: center;
  background: rgba(30, 27, 46, 0.35);
  color: #ffffff;
  font-size: 40px;
  backdrop-filter: grayscale(0.8);
  -webkit-backdrop-filter: grayscale(0.8);
}
.rod-stage.is-locked .rod-lock-ov { display: flex; }
.rod-desc { font-style: normal; line-height: 1.4; }
.rod-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
.rod-stat { padding: 8px 9px; border-radius: 13px; background: #ffffff; box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.rod-stat-head { display: flex; justify-content: space-between; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 900; color: var(--reef-muted); margin-bottom: 5px; }
.rod-stat-head span { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.rod-stat-head b { color: var(--reef-ink); font-size: 10px; white-space: nowrap; }
.rod-bar { height: 7px; border-radius: 999px; background: #f1f5f9; overflow: hidden; }
.rod-bar i { display: block; height: 100%; border-radius: 999px; transition: width 0.3s; }
.rod-bar--reel { background: linear-gradient(90deg, #34d399, #059669); }
.rod-bar--bite { background: linear-gradient(90deg, #fbbf24, #f43f5e); }
.rod-action { margin-bottom: 10px; font-size: 12.5px; }
.rod-action.is-locked { background: linear-gradient(145deg, #e7e5e4, #a8a29e); color: #44403c; box-shadow: inset 2px 2px 4px rgba(255, 255, 255, 0.6); }
.rod-action.is-active { background: linear-gradient(145deg, #6ee7b7, #059669); color: #ffffff; }
.rod-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; margin-bottom: 8px; }
.rod-card {
  position: relative;
  aspect-ratio: 1 / 1;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px;
  padding: 6px 4px;
  border: 2px solid transparent;
  border-radius: 15px;
  background: #ffffff;
  cursor: pointer;
  overflow: hidden;
  box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1);
}
.rod-card.viewing { border-color: #a855f7; box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15); }
.rod-card.locked .rod-card-ico { filter: grayscale(0.9) brightness(0.92); opacity: 0.75; }
.rod-card.locked .rod-card-name { color: var(--reef-muted); }
.rod-card-ico { width: 40px; height: 40px; border-radius: 13px; display: flex; align-items: center; justify-content: center; color: #ffffff; box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), 0 3px 8px rgba(0, 0, 0, 0.1); }
.rod-card-ico svg { width: 28px; height: 28px; }
.rod-card-name { font-size: 9.5px; font-weight: 900; color: var(--reef-ink); line-height: 1.15; text-align: center; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.rod-card-flag {
  position: absolute; top: 5px; right: 5px;
  width: 18px; height: 18px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; color: #ffffff;
  background: #78716c;
}
.rod-card-flag.ok { background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.rod-card-prog { position: absolute; left: 8px; right: 8px; bottom: 6px; height: 3px; border-radius: 999px; background: #f1f5f9; overflow: hidden; }
.rod-card-prog i { display: block; height: 100%; background: #a855f7; }
.rod-note { font-size: 9.5px; font-weight: 700; color: var(--reef-muted); text-align: center; }


/* ── Dialog tema ── */
.confirm-card { max-width: 320px; text-align: center; padding: 18px 16px 14px; }
.confirm-ico { width: 52px; height: 52px; border-radius: 18px; font-size: 26px; margin: 0 auto 10px; }
.confirm-title { font-size: 16px; font-weight: 900; margin-bottom: 4px; }
.confirm-msg { font-size: 12px; font-weight: 700; color: var(--reef-muted); line-height: 1.5; margin-bottom: 14px; }
.confirm-actions { display: grid; grid-template-columns: 1fr 1.4fr; gap: 8px; }
.confirm-actions .btn-save, .confirm-actions .btn-soft { height: 44px; font-size: 12.5px; text-decoration: none; }


/* ── Pasar Ikan (di dalam Ember) ── */
.mk-head { display: flex; align-items: baseline; justify-content: space-between; margin: 4px 2px 6px; font-size: 11.5px; font-weight: 900; }
.mk-head span { display: inline-flex; align-items: center; gap: 4px; }
.mk-head i { color: #0891b2; font-size: 15px; }
.mk-head small { font-size: 9.5px; font-weight: 800; color: var(--reef-muted); }
.mk-board { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 5px; margin-bottom: 10px; }
.mk-cell { padding: 5px 3px; border-radius: 12px; background: #fff; text-align: center; box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.mk-cell small { display: block; font-size: 8px; font-weight: 900; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mk-cell b { display: inline-flex; align-items: center; gap: 1px; font-size: 10.5px; font-weight: 900; }
.tr-up { color: #059669; } .tr-down { color: #e11d48; } .tr-flat { color: #78716c; }
.ft-thumb, .mk-thumb, .pk-thumb { display: flex; align-items: center; justify-content: center; border-radius: 12px; }
.mk-thumb { width: 100%; height: 28px; margin-bottom: 1px; background: radial-gradient(ellipse at 50% 60%, var(--rt, #e0f2fe) 0%, rgba(255,255,255,0) 72%); }
.mk-thumb img { height: 30px; max-width: 100%; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(30, 27, 46, 0.2)); }
.rt-biasa { --rt: #cffafe; } .rt-bagus { --rt: #d1fae5; } .rt-langka { --rt: #ffe4e6; } .rt-super { --rt: #ede9fe; } .rt-jackpot { --rt: #fef3c7; }
.fish-tools { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; font-size: 11px; font-weight: 900; color: var(--reef-muted); }
.fish-tools button { border: none; background: #fff; border-radius: 999px; padding: 5px 11px; font-size: 10.5px; font-weight: 900; color: #0e7490; cursor: pointer; box-shadow: inset -1px -1px 3px rgba(8, 145, 178, 0.15); }
.fish-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; max-height: 300px; overflow-y: auto; padding: 2px; }
.fish-tile { position: relative; aspect-ratio: 1 / 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; padding: 6px 4px; border: 2px solid transparent; border-radius: 15px; background: #fff; cursor: pointer; box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.fish-tile .ft-thumb { width: 100%; height: 46px; margin-top: 8px; background: radial-gradient(ellipse at 50% 60%, var(--rt, #e0f2fe) 0%, rgba(255,255,255,0) 70%); }
.fish-tile .ft-thumb img { height: 50px; max-width: 100%; object-fit: contain; filter: drop-shadow(0 4px 4px rgba(30, 27, 46, 0.22)); }
.fish-tile .ft-name { font-size: 9.5px; font-weight: 900; line-height: 1.1; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.fish-tile .ft-w { font-size: 8.5px; font-weight: 800; color: var(--reef-muted); }
.fish-tile .ft-p { font-size: 10px; font-weight: 900; color: #059669; }
.fish-tile .ft-check { position: absolute; top: 5px; right: 5px; width: 18px; height: 18px; border-radius: 6px; border: 2px solid #e7e5e4; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; }
.fish-tile .ft-rar { position: absolute; top: 5px; left: 5px; padding: 1px 6px; border-radius: 999px; font-size: 7.5px; font-weight: 900; color: #fff; text-transform: uppercase; letter-spacing: 0.4px; }
.fish-tile .ft-rar.rb-jackpot { color: #78350f; }
.fish-tile.sel { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
.fish-tile.sel .ft-check { background: #10b981; border-color: #10b981; }
.fish-tile.gone { animation: tileGone 0.45s ease forwards; }
@keyframes tileGone { to { transform: scale(0.6) translateY(-20px); opacity: 0; } }
.fish-empty { grid-column: 1 / -1; padding: 16px 8px; text-align: center; font-size: 11.5px; font-weight: 800; color: var(--reef-muted); }
.sell-bar { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.sell-sum { flex: 1; min-width: 0; }
.sell-sum small { display: block; font-size: 9.5px; font-weight: 900; color: var(--reef-muted); text-transform: uppercase; }
.sell-sum b { display: block; font-size: 17px; font-weight: 900; color: #059669; }
.btn-sell { height: 46px; padding: 0 20px; border: 2px solid #fff; border-radius: 16px; display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 900; color: #fff; cursor: pointer; background: linear-gradient(145deg, #6ee7b7, #059669); box-shadow: 0 8px 18px rgba(5, 150, 105, 0.35), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.5), inset -3px -3px 6px rgba(6, 95, 70, 0.3); }
.btn-sell i { font-size: 19px; }
.btn-sell:disabled { filter: grayscale(0.7); opacity: 0.6; }
.sell-wallet { margin-top: 6px; text-align: right; font-size: 10.5px; font-weight: 800; color: var(--reef-muted); }
.sell-wallet b { color: #059669; }

/* ── Toko umpan ── */
.m-ico--shop { background: linear-gradient(145deg, #fdba74, #c2410c); }
.dep-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 11px; margin-bottom: 9px; border-radius: 14px; background: #fff; font-size: 11px; font-weight: 800; color: var(--reef-muted); box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.dep-row b { color: #7c3aed; font-size: 13px; }
.dep-row a { font-size: 10.5px; font-weight: 900; color: #e11d48; text-decoration: none; white-space: nowrap; }
.pack-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; margin-bottom: 10px; }
.pack-card { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 5px; border: 2px solid transparent; border-radius: 16px; background: #fff; cursor: pointer; text-align: center; box-shadow: inset -2px -2px 4px rgba(251, 146, 60, 0.1); }
.pack-card.sel { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15); }
.pk-thumb { width: 100%; height: 56px; background: radial-gradient(ellipse at 50% 60%, #ffedd5 0%, rgba(255,255,255,0) 70%); }
.pk-thumb img { height: 58px; object-fit: contain; filter: drop-shadow(0 4px 4px rgba(30, 27, 46, 0.22)); }
.pack-card b { font-size: 10.5px; font-weight: 900; line-height: 1.1; }
.pack-card small { font-size: 9px; font-weight: 800; color: var(--reef-muted); }
.pack-card .pk-price { font-size: 11px; font-weight: 900; color: #c2410c; }
.pack-tag { position: absolute; top: -7px; left: 50%; transform: translateX(-50%); padding: 1px 6px; border-radius: 6px; background: #10b981; color: #fff; font-size: 7.5px; font-weight: 900; white-space: nowrap; }
.pack-desc { padding: 8px 10px; margin-bottom: 10px; border-radius: 12px; background: #fff7ed; font-size: 11px; font-weight: 700; color: #9a3412; text-align: center; }
.shop-free { display: flex; align-items: center; gap: 8px; padding: 9px 10px; margin-top: 8px; border-radius: 14px; background: #faf5ff; text-decoration: none; font-size: 11px; font-weight: 800; color: #6b21a8; }
.side-btn--shop {
  background: linear-gradient(145deg, #fdba74 0%, #f97316 55%, #c2410c 100%);
  box-shadow: 0 6px 16px rgba(194, 65, 12, 0.4), inset 2px 2px 3px rgba(255, 255, 255, 0.45), inset -2px -2px 4px rgba(124, 45, 18, 0.35);
}

@media (min-width: 900px) {
  .hud-top { left: 18px; right: 18px; top: calc(var(--safe-t) + 16px); }
}
@media (prefers-reduced-motion: reduce) {
  .btn-hook, .hud-strike.show, .show-badge.rb-jackpot, .ld-wave, .ld-boat, .ld-start { animation: none !important; }
  .hud-strike.show { opacity: 1; transform: translate(-50%, -50%); }
}
</style>
</head>
<body>

<!-- ══════════ LOADER ══════════ -->
<div class="mc-loader" id="mc-loader" role="status" aria-live="polite">
  <div class="ld-sun"></div>
  <svg class="ld-boat" viewBox="0 0 120 50" aria-hidden="true">
    <path d="M10 28 Q60 50 110 28 L104 36 Q60 52 16 36 Z" fill="#f8fafc"/>
    <path d="M12 30 Q60 48 108 30" stroke="#dc2626" stroke-width="3" fill="none"/>
    <path d="M14 33 Q60 50 106 33" stroke="#2563eb" stroke-width="2" fill="none"/>
    <path d="M110 28 L118 14" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
    <path d="M10 28 L2 14" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
    <path d="M78 26 L98 2" stroke="#1e1b2e" stroke-width="2"/>
    <path d="M98 2 Q104 20 100 38" stroke="#ffffff" stroke-width="1" fill="none" opacity="0.8"/>
  </svg>
  <div class="ld-sea"><div class="ld-wave ld-wave--1"></div><div class="ld-wave ld-wave--2"></div></div>
  <div class="ld-card">
    <div class="ld-title">Pemancingan Bahari Nusantara</div>
    <div class="ld-sub">Menyiapkan jukung, joran, dan lautan...</div>
    <div class="ld-bar"><div class="ld-bar-fill" id="ld-bar"></div></div>
    <div class="ld-status"><span id="ld-text">Menyiapkan jukung...</span><span id="ld-pct">0%</span></div>
    <div class="ld-tip" id="ld-tip">Lempar kail tepat di titik riak agar ikan lebih cepat menyambar.</div>
    <button type="button" class="ld-start" id="ld-start"><i class="ph-fill ph-sailboat"></i> Mulai Berlayar</button>
  </div>
</div>

<!-- ══════════ DUNIA 3D LAYAR PENUH ══════════ -->
<div class="mc3d-stage" id="mc3d-stage">
  <canvas id="mc3d-canvas"></canvas>
  <div class="mc3d-nogl">Perangkatmu belum mendukung tampilan 3D (WebGL). Permainan tetap bisa dimainkan dengan panel di bawah.</div>

  <div class="hud-top">
    <a href="/home" class="hud-icon-btn glass" aria-label="Kembali ke Beranda"><i class="ph-bold ph-arrow-left"></i></a>
    <div class="bait-token glass" title="Sisa umpan">
      <span class="bait-ico" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="none">
          <path d="M21 3.5v13.5a6 6 0 0 1-12 0v-2.5" stroke="#e2e8f0" stroke-width="2.4" stroke-linecap="round"/>
          <path d="M9 14.5l-2.6 2.4" stroke="#e2e8f0" stroke-width="2.2" stroke-linecap="round"/>
          <circle cx="21" cy="3.6" r="1.8" stroke="#e2e8f0" stroke-width="1.6"/>
          <path d="M8.5 9.5c3-2.6 5.6 2.4 9.4-0.4c3.2-2.4 6.6 1 4.6 4.4c-1.8 3-5.6 1.4-8.2 4.4" stroke="#fb7185" stroke-width="4.2" stroke-linecap="round"/>
          <path d="M11.5 8.6l0.6 1.8M15.2 10.6l0.2 1.6M19.4 8.6l-0.4 1.7M22.4 12.6l-1.6 0.4" stroke="#fecdd3" stroke-width="1.1" stroke-linecap="round"/>
          <circle cx="8.6" cy="9.4" r="0.9" fill="#1e1b2e"/>
        </svg>
      </span>
      <span class="bait-txt"><small>Umpan</small><b id="hud-baits"><?= (int)($user['fishing_baits'] ?? 0) ?></b></span>
      <button type="button" class="bait-add" onclick="MancingUI.openShop()" aria-label="Beli umpan"><i class="ph-bold ph-plus"></i></button>
    </div>
    <span class="hud-chip hud-chip--cuan glass" id="hud-cuan" title="Nilai ikan di Ember (harga pasar hari ini)">
      <i class="ph-fill ph-coins"></i> <span id="hud-cuan-val"><?= format_rp($todayCatchesTotal) ?></span>
    </span>
    <span class="hud-spacer"></span>
    <button type="button" class="hud-icon-btn glass" id="hud-help" onclick="MancingUI.openHelp()" aria-label="Panduan bermain"><i class="ph-bold ph-info"></i></button>
    <button type="button" class="hud-icon-btn glass" id="hud-mute" aria-label="Suara"><i class="ph-fill ph-speaker-high"></i></button>
  </div>

  <div class="hud-side">
    <button type="button" class="side-btn side-btn--rod" id="hud-rod" onclick="MancingUI.openRods()" aria-label="Koleksi Joran">
      <span class="side-ico"><svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M5 28L26 5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><path d="M5 28l5.5-6" stroke="#fde68a" stroke-width="4.4" stroke-linecap="round"/><circle cx="11.5" cy="21.5" r="3.1" fill="#fde68a" stroke="#fff" stroke-width="1.4"/><path d="M26 5v14" stroke="#fff" stroke-width="1" stroke-dasharray="2 1.6"/><circle cx="26" cy="21" r="2.3" fill="#fb7185" stroke="#fff" stroke-width="1"/></svg></span>
      <span class="side-lbl" id="hud-rod-name">Joran</span>
    </button>
    <button type="button" class="side-btn side-btn--shop" onclick="MancingUI.openShop()" aria-label="Toko Umpan">
      <span class="side-ico"><i class="ph-fill ph-storefront"></i></span>
      <span class="side-lbl">Toko</span>
    </button>
    <button type="button" class="side-btn side-btn--inv" id="hud-inv" onclick="MancingUI.openInventory()" aria-label="Ember Cuan">
      <span class="side-ico"><i class="ph-fill ph-basket"></i></span>
      <span class="side-lbl">Ember</span>
      <span class="hud-badge" id="hud-inv-count"><?= count($todayCatches) ?></span>
    </button>
  </div>

  <div class="hud-msg glass" id="hud-msg">Tahan tombol untuk mengisi tenaga, lepas untuk melempar kail.</div>
  <div class="hud-strike" id="hud-strike">STRIKE!!</div>
  <div class="hud-flash" id="hud-flash"></div>

  <div class="hud-bottom">
    <div class="hud-panel active" id="pnl-cast">
      <div class="cast-card glass">
        <svg class="pw-arc" viewBox="0 0 200 110" aria-hidden="true">
          <defs>
            <linearGradient id="pwGrad" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stop-color="#34d399"/>
              <stop offset="55%" stop-color="#fbbf24"/>
              <stop offset="100%" stop-color="#f43f5e"/>
            </linearGradient>
          </defs>
          <path class="pw-track" d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke-width="12" stroke-linecap="round"/>
          <path class="pw-fill" id="pw-fill" d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke-width="12" stroke-linecap="round" pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"/>
          <g id="pw-marks"></g>
          <text x="20" y="110" text-anchor="middle" font-size="8" font-weight="900" fill="#ffffff" opacity="0.8">6m</text>
          <text x="180" y="110" text-anchor="middle" font-size="8" font-weight="900" fill="#ffffff" opacity="0.8">28m</text>
        </svg>
        <div class="pw-label" id="pw-label">Titik riak = ikan sedang makan</div>
        <button type="button" class="btn-hold btn-cast" id="btn-cast">
          <i class="ph-fill ph-fish-simple" id="btn-cast-ico"></i> <span id="btn-cast-lbl">Tahan untuk Lempar</span>
        </button>
      </div>
    </div>

    <div class="hud-panel" id="pnl-hook">
      <div class="hook-wrap">
        <button type="button" class="btn-hook" id="btn-hook">
          <svg class="hook-ring" viewBox="0 0 100 100" aria-hidden="true"><circle id="hook-ring" cx="50" cy="50" r="46" pathLength="100" stroke-dasharray="100" stroke-dashoffset="0"/></svg>
          HOOK!
        </button>
        <span class="hook-hint glass" id="hook-hint">Tunggu pelampung tenggelam...</span>
      </div>
    </div>

    <div class="hud-panel" id="pnl-fight">
      <div class="fight-card glass">
        <div class="ft-row"><span><i class="ph-bold ph-ruler"></i> Jarak Ikan</span><span id="ft-dist-txt">0 m</span></div>
        <div class="ft-dist-track"><div class="ft-dist-fill" id="ft-dist-fill"></div></div>
        <div class="ft-row"><span><i class="ph-bold ph-gauge"></i> Tegangan Senar</span><span class="ft-ten-txt" id="ft-ten-txt">0%</span></div>
        <div class="ft-ten-track">
          <div class="ft-ten-fill" id="ft-ten-fill"></div>
          <div class="ft-needle" id="ft-needle"></div>
        </div>
        <button type="button" class="btn-hold btn-reel" id="btn-reel">
          <i class="ph-bold ph-arrows-clockwise"></i> <span>Tahan untuk Gulung</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ SHOWCASE TANGKAPAN ══════════ -->
<div class="mc-modal" id="mc-show" role="dialog" aria-modal="true" aria-labelledby="show-name">
  <div class="mc-modal-card">
    <div class="show-stage" id="show-stage">
      <canvas id="show-canvas"></canvas>
      <div class="show-fallback"><i class="ph-fill ph-fish-simple"></i></div>
      <span class="show-badge" id="show-badge">Biasa</span>
    </div>
    <div class="show-info">
      <div class="show-name" id="show-name">Ikan</div>
      <div class="show-latin" id="show-latin">-</div>
      <div class="show-stats">
        <div class="show-stat"><small>Berat Tangkapan</small><b id="show-weight">0.00 Kg</b></div>
        <div class="show-stat show-stat--rp"><small>Harga Pasar Hari Ini</small><b id="show-reward">Rp 0</b></div>
      </div>
      <div class="show-note">Ikan masuk Ember. Jual di Pasar Ikan (tombol Ember) untuk mencairkannya jadi Saldo Tarik.</div>
      <button type="button" class="btn-save" id="btn-save"><i class="ph-fill ph-basket"></i> Simpan ke Ember Cuan</button>
    </div>
  </div>
</div>

<!-- ══════════ EMBER CUAN (INVENTORY 3D) ══════════ -->
<div class="mc-modal" id="mc-inv" role="dialog" aria-modal="true" aria-labelledby="inv-title">
  <div class="mc-modal-card">
    <div class="modal-head">
      <div class="modal-title" id="inv-title"><span class="m-ico m-ico--gold"><i class="ph-fill ph-basket"></i></span> Ember &amp; Pasar Ikan</div>
      <button type="button" class="modal-close" onclick="MancingUI.closeInventory()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    </div>
    <div class="inv-stage" id="inv-stage">
      <canvas id="inv-canvas"></canvas>
      <div class="show-fallback"><i class="ph-fill ph-basket"></i></div>
      <span class="show-badge" id="inv-badge">Biasa</span>
      <button type="button" class="inv-nav inv-nav--prev glass" id="inv-prev" aria-label="Sebelumnya"><i class="ph-bold ph-caret-left"></i></button>
      <button type="button" class="inv-nav inv-nav--next glass" id="inv-next" aria-label="Berikutnya"><i class="ph-bold ph-caret-right"></i></button>
      <span class="inv-pos glass" id="inv-pos">1 / 1</span>
      <div class="inv-empty">Ember masih kosong — yuk lempar kail!</div>
    </div>
    <div class="show-info">
      <div class="show-name" id="inv-name">-</div>
      <div class="show-latin" id="inv-meta">-</div>
    </div>
    <div class="mk-head"><span><i class="ph-fill ph-scales"></i> Harga Pasar Hari Ini</span><small>dibanding kemarin</small></div>
    <div class="mk-board" id="mk-board"></div>
    <div class="fish-tools"><span id="fish-count-lbl">0 ikan di Ember</span><button type="button" id="btn-sel-all">Pilih Semua</button></div>
    <div class="fish-grid" id="fish-grid"></div>
    <div class="sell-bar">
      <div class="sell-sum"><small id="sell-sel-lbl">0 dipilih</small><b id="sell-total">Rp 0</b></div>
      <button type="button" class="btn-sell" id="btn-sell" disabled><i class="ph-fill ph-hand-coins"></i> Jual</button>
    </div>
    <div class="sell-wallet">Saldo Tarik: <b id="sell-wallet"><?= format_rp((float)($user['balance_wd'] ?? 0)) ?></b></div>
  </div>
</div>

<!-- ══════════ PANDUAN BERMAIN (TOMBOL i) ══════════ -->
<div class="mc-modal" id="mc-help" role="dialog" aria-modal="true" aria-labelledby="help-title">
  <div class="mc-modal-card">
    <div class="modal-head">
      <div class="modal-title" id="help-title">Panduan</div>
      <button type="button" class="modal-close" onclick="MancingUI.closeHelp()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    </div>
    <div class="help-tabs">
      <button type="button" class="help-tab" data-ph="cast">Lempar</button>
      <button type="button" class="help-tab" data-ph="hook">Hook</button>
      <button type="button" class="help-tab" data-ph="fight">Tarik</button>
      <button type="button" class="help-tab" data-ph="result">Hasil</button>
    </div>
    <div id="help-diag"></div>
    <ol class="help-steps" id="help-steps"></ol>
    <div class="help-tip" id="help-tip"><i class="ph-fill ph-sparkle"></i><span></span></div>
    <div class="help-pause"><i class="ph-fill ph-pause-circle"></i> Permainan dijeda selama panduan terbuka</div>
    <button type="button" class="btn-save" onclick="MancingUI.closeHelp()"><i class="ph-fill ph-play"></i> Lanjut Mancing</button>
  </div>
</div>

<!-- ══════════ KOLEKSI JORAN ══════════ -->
<div class="mc-modal" id="mc-rods" role="dialog" aria-modal="true" aria-labelledby="rods-title">
  <div class="mc-modal-card">
    <div class="modal-head">
      <div class="modal-title" id="rods-title"><span class="m-ico rb-super"><svg viewBox="0 0 32 32" width="18" height="18" fill="none" aria-hidden="true"><path d="M5 28L26 5" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><circle cx="11.5" cy="21.5" r="3.2" fill="#fde68a"/></svg></span> Koleksi Joran</div>
      <button type="button" class="modal-close" onclick="MancingUI.closeRods()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    </div>
    <div class="rod-stage" id="rod-stage">
      <canvas id="rod-canvas"></canvas>
      <div class="show-fallback"><i class="ph-fill ph-magic-wand"></i></div>
      <span class="show-badge" id="rod-badge">Biasa</span>
      <span class="rod-lock-ov"><i class="ph-fill ph-lock-simple"></i></span>
    </div>
    <div class="show-info">
      <div class="show-name" id="rod-name">-</div>
      <div class="show-latin rod-desc" id="rod-desc">-</div>
    </div>
    <div class="rod-stats">
      <div class="rod-stat">
        <div class="rod-stat-head"><span><i class="ph-fill ph-arrows-in-line-horizontal"></i> Tarikan</span><b id="rod-reel-txt">+0%</b></div>
        <div class="rod-bar"><i id="rod-reel" class="rod-bar--reel"></i></div>
      </div>
      <div class="rod-stat">
        <div class="rod-stat-head"><span><i class="ph-fill ph-lightning"></i> Gacor</span><b id="rod-bite-txt">Normal</b></div>
        <div class="rod-bar"><i id="rod-bite" class="rod-bar--bite"></i></div>
      </div>
    </div>
    <button type="button" class="btn-save rod-action" id="rod-action" onclick="MancingUI.rodAction()">Pakai</button>
    <div class="rod-grid" id="rod-grid"></div>
    <div class="rod-note">Bonus joran hanya memengaruhi kecepatan permainan. Nilai cuan tetap ditentukan server.</div>
  </div>
</div>

<!-- ══════════ TOKO UMPAN ══════════ -->
<div class="mc-modal" id="mc-shop" role="dialog" aria-modal="true" aria-labelledby="shop-title">
  <div class="mc-modal-card">
    <div class="modal-head">
      <div class="modal-title" id="shop-title"><span class="m-ico m-ico--shop"><i class="ph-fill ph-storefront"></i></span> Toko Umpan</div>
      <button type="button" class="modal-close" onclick="MancingUI.closeShop()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    </div>
    <div class="dep-row"><span>Saldo Beli: <b id="shop-dep"><?= format_rp((float)($user['balance_dep'] ?? 0)) ?></b></span><a href="/deposit">+ Top Up</a></div>
    <div class="pack-grid" id="pack-grid"></div>
    <div class="pack-desc" id="pack-desc">Pilih paket umpan di atas</div>
    <button type="button" class="btn-save" id="btn-buy" disabled><i class="ph-fill ph-shopping-bag"></i> Beli Paket</button>
    <a href="/videos" class="shop-free"><span class="m-ico rb-super"><i class="ph-fill ph-film-strip"></i></span> Umpan gratis: +1 umpan tiap video ditonton sampai tuntas</a>
  </div>
</div>

<!-- ══════════ DIALOG TEMA (pengganti alert/confirm bawaan) ══════════ -->
<div class="mc-modal" id="mc-confirm" role="alertdialog" aria-modal="true" aria-labelledby="cf-title">
  <div class="mc-modal-card confirm-card">
    <span class="m-ico m-ico--gold confirm-ico" id="cf-ico"><i class="ph-fill ph-anchor-simple"></i></span>
    <div class="confirm-title" id="cf-title">Judul</div>
    <div class="confirm-msg" id="cf-msg">Pesan</div>
    <div class="confirm-actions">
      <button type="button" class="btn-soft" id="cf-no">Nanti</button>
      <a class="btn-save" id="cf-yes" href="#">Lanjut</a>
    </div>
  </div>
</div>

<script src="/assets/js/toast.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/toast.js') ?>"></script>
<script src="/assets/js/bahari-core.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/bahari-core.js') ?>"></script>
<script>
window.MANCING_CFG = {
  endpoint: '/minigame',
  csrf: <?= json_encode(csrf_token()) ?>,
  baits: <?= (int)($user['fishing_baits'] ?? 0) ?>,
  todayTotal: <?= json_encode((float)$todayCatchesTotal) ?>,
  catches: <?= json_encode($inv_catches, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  rods: <?= json_encode($rod_state['rods'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  rodSelected: <?= json_encode($rod_state['selected']) ?>,
  market: <?= json_encode($market) ?>,
  packs: <?= json_encode($bait_packs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  balanceDep: <?= json_encode(format_rp((float)($user['balance_dep'] ?? 0))) ?>,
  balanceWd: <?= json_encode(format_rp((float)($user['balance_wd'] ?? 0))) ?>,
  prefs: <?= json_encode($prefs) ?>,
  splash: { small: ['/assets/audio/splash/small-1.ogg', '/assets/audio/splash/small-3.ogg', '/assets/audio/splash/small-6.ogg'], big: ['/assets/audio/splash/big-1.ogg', '/assets/audio/splash/big-3.ogg'] },
  ambience: <?= json_encode($ambience_url) ?>,
  three: 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js'
};
</script>

<script>
/* ══════════════════════════════════════════════════════════════════
   PEMANCINGAN BAHARI NUSANTARA — Mesin Game 3D (Three.js r128)
   Modul: Util · Haptic/Sfx · API & Hooks · Model Ikan · Dunia 3D ·
          Showcase · State Machine Gameplay · UI
   ══════════════════════════════════════════════════════════════════ */
window.__startMancing = function () {
'use strict';
if (window.__mancingStarted) return;
window.__mancingStarted = true;

const CFG = window.MANCING_CFG;
const $ = (id) => document.getElementById(id);
const {
  clamp, lerp, rand, smooth, damp, fmtRp, Haptic, Sfx, Ambience,
  U_KNOTS, BADGES, BADGE_LABEL, BADGE_SPECIES, SPECIES, snoutX, SNOUT_TIP, profile,
  TexCache, canvasTex, dotTexture, scaleTexture, shadeFinGeometry, finMaterial, shapeFrom,
  buildFish, buildChest, buildModel, disposeTree,
  WAVES, waveH, waveGrad, GLSL_WAVES, Thumbs, fishThumb
} = window.Bahari; // modul bersama: /assets/js/bahari-core.js

/* ───────────────────────── API BACKEND & HOOKS ─────────────────────────
   Server = sumber kebenaran: cast_rod memotong 1 umpan, mengundi ikan,
   dan langsung mengkredit Saldo Tarik. Klien TIDAK pernah mengirim berat
   atau nominal hadiah (agar tidak bisa dimanipulasi).
   Integrasi tambahan bisa memasang callback di window.MancingHooks atau
   mendengarkan event DOM: mancing:onCast, mancing:onStrike,
   mancing:onLanded, mancing:onSaved.                                     */
const MancingAPI = {
  post(action, extra) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', CFG.csrf);
    if (extra) Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
    return fetch(CFG.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf } })
      .then((r) => r.json());
  },
  castRod() { return this.post('cast_rod'); }
};
window.MancingAPI = MancingAPI;
window.MancingHooks = Object.assign({ onCast: null, onStrike: null, onLanded: null, onSaved: null }, window.MancingHooks || {});
function emit(name, payload) {
  try { const fn = window.MancingHooks[name]; if (typeof fn === 'function') fn(payload); } catch (e) { console.warn(e); }
  document.dispatchEvent(new CustomEvent('mancing:' + name, { detail: payload }));
}
function toast(msg, type) {
  if (typeof window.nToast === 'function') window.nToast(msg, type || 'info', 3500);
  else UI.msg(msg, type === 'error' ? 'warn' : '');
}

/* ───────────────────────── PEMBUAT MODEL 3D (dari bahari-core) ───────────────────────── */
const HAS_THREE = typeof window.THREE !== 'undefined';
const HAS_GL = HAS_THREE && (() => {
  try { const c = document.createElement('canvas'); return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl'))); }
  catch (e) { return false; }
})();

/* ───────────────────────── JORAN 3D (SKIN) ─────────────────────────
   Setiap skin = joran 3D sendiri dengan ornamen khas Nusantara.
   buildRod(key) → { root, segs, tip, update(t) }                        */
function rodTextures() {
  return {
    bamboo: canvasTex('rod-bamboo', 64, 256, (g, w, h) => {
      const gr = g.createLinearGradient(0, 0, w, 0);
      gr.addColorStop(0, '#b8913f'); gr.addColorStop(0.5, '#e9cd7f'); gr.addColorStop(1, '#b8913f');
      g.fillStyle = gr; g.fillRect(0, 0, w, h);
      for (let y = 0; y < h; y += 64) { g.fillStyle = '#7a5a22'; g.fillRect(0, y, w, 5); g.fillStyle = '#f3dc9c'; g.fillRect(0, y + 5, w, 2); }
      for (let i = 0; i < 14; i++) { g.strokeStyle = 'rgba(120,85,30,0.25)'; g.beginPath(); const x = Math.random() * w; g.moveTo(x, 0); g.lineTo(x + rand(-3, 3), h); g.stroke(); }
    }, true),
    weave: canvasTex('rod-weave', 64, 64, (g, w, h) => {
      g.fillStyle = '#8b5a2b'; g.fillRect(0, 0, w, h);
      for (let y = 0; y < h; y += 8) for (let x = 0; x < w; x += 8) {
        g.fillStyle = ((x + y) / 8) % 2 ? '#a8733c' : '#6f4520'; g.fillRect(x, y, 8, 8);
      }
    }, true),
    pamor: canvasTex('rod-pamor', 64, 256, (g, w, h) => {
      g.fillStyle = '#2b2d33'; g.fillRect(0, 0, w, h);
      for (let k = 0; k < 9; k++) {
        g.strokeStyle = 'rgba(210,214,222,' + rand(0.35, 0.7).toFixed(2) + ')'; g.lineWidth = rand(0.8, 2);
        g.beginPath();
        for (let y = 0; y <= h; y += 4) { const x = (k / 9) * w + Math.sin(y * 0.06 + k) * 6; y === 0 ? g.moveTo(x, y) : g.lineTo(x, y); }
        g.stroke();
      }
    }, true),
    batik: canvasTex('rod-batik', 128, 128, (g, w, h) => {
      g.fillStyle = '#f3e3c3'; g.fillRect(0, 0, w, h);
      // motif parang: pita diagonal bergelombang
      for (let d = -h; d < w + h; d += 32) {
        g.fillStyle = '#6b3a1e';
        g.beginPath();
        for (let t = 0; t <= 1; t += 0.05) { const x = d + t * h, y = h - t * h + Math.sin(t * Math.PI * 4) * 5; t === 0 ? g.moveTo(x, y) : g.lineTo(x, y); }
        for (let t = 1; t >= 0; t -= 0.05) { const x = d + 12 + t * h, y = h - t * h + Math.sin(t * Math.PI * 4) * 5; g.lineTo(x, y); }
        g.fill();
        g.fillStyle = '#1e3a5f';
        for (let t = 0.1; t < 1; t += 0.2) { g.beginPath(); g.arc(d + 20 + t * h, h - t * h, 3, 0, Math.PI * 2); g.fill(); }
      }
    }, true),
    jade: canvasTex('rod-jade', 64, 256, (g, w, h) => {
      const gr = g.createLinearGradient(0, 0, w, 0);
      gr.addColorStop(0, '#065f46'); gr.addColorStop(0.5, '#34d399'); gr.addColorStop(1, '#065f46');
      g.fillStyle = gr; g.fillRect(0, 0, w, h);
      for (let y = 0; y < h; y += 10) for (let x = (y / 10) % 2 ? 0 : 6; x < w; x += 12) {
        g.strokeStyle = 'rgba(253,224,71,0.55)'; g.beginPath(); g.arc(x, y, 6, 0.15 * Math.PI, 0.85 * Math.PI); g.stroke();
      }
    }, true),
    lacquer: canvasTex('rod-lacquer', 64, 256, (g, w, h) => {
      g.fillStyle = '#1a1423'; g.fillRect(0, 0, w, h);
      for (let i = 0; i < 40; i++) { g.fillStyle = 'rgba(251,191,36,' + rand(0.25, 0.7).toFixed(2) + ')'; g.fillRect(rand(0, w), rand(0, h), rand(2, 6), rand(1, 3)); }
      for (let y = 0; y < h; y += 32) { g.strokeStyle = 'rgba(251,191,36,0.7)'; g.lineWidth = 1.5; g.beginPath(); g.moveTo(0, y); g.bezierCurveTo(w * 0.3, y + 8, w * 0.6, y - 8, w, y); g.stroke(); }
    }, true)
  };
}

const ROD_LOOKS = {
  /* Bambu: batang tebal beruas, pegangan lilitan tali, gulungan kayu (kili-kili) */
  bambu:  { blank: 'bamboo', blankRep: 1, segR: 0.03, handle: 0x8b6a3e, wraps: [], shin: 25, nodes: true, ropeGrip: true, reelType: 'spool', tipKnot: true },
  /* Rotan: gagang melengkung seperti tongkat, ikatan merah putih, pita & panji di ujung */
  rotan:  { blank: 0x7a4a24, handleTex: 'weave', wraps: [0xdc2626, 0xf8fafc, 0xdc2626], shin: 40, crook: true, ribbon: true, pennant: true },
  /* Keris: bilah pipih berluk, warangka melintang, gagang ukiran */
  keris:  { blank: 'pamor', blankRep: 1, handle: 0x5b3a1e, wraps: [], shin: 120, luk: true, blade: true, warangka: true, kerisHilt: true, tipColor: 0xfbbf24, noReelGuides: true },
  /* Batik: batang bermotif parang, rumbai manik-manik, reel besar & tutup pangkal emas */
  batik:  { blank: 'batik', blankRep: 2, segR: 0.024, handle: 0x3b2314, wraps: [0xd4a017], shin: 30, tassel: true, fightingButt: true, reelType: 'big', kawung: true },
  /* Wayang: pernis hitam prada emas, ukiran sulur emas, gunungan ganda, mahkota di ujung */
  wayang: { blank: 'lacquer', blankRep: 1, handle: 0xd4a017, handleShine: true, wraps: [0xfbbf24], shin: 140, gunungan: true, filigree: true, crownTip: true },
  /* Naga: batang giok dililit tubuh naga, kepala naga di pangkal, mustika di ujung */
  naga:   { blank: 'jade', blankRep: 1, segR: 0.023, handle: 0xb45309, wraps: [0xfbbf24], shin: 160, glow: 0x34d399, naga: true, coil: true, pearl: true, finGuides: true }
};

function buildRod(key) {
  const L = ROD_LOOKS[key] || ROD_LOOKS.bambu;
  const T = rodTextures();
  const V = (x, y, z) => new THREE.Vector3(x, y, z);
  const root = new THREE.Group();
  const anim = [];
  const gold = new THREE.MeshPhongMaterial({ color: 0xfbbf24, specular: 0xfff3c4, shininess: 140, emissive: 0x3a2400 });
  const tex = (name, rx, ry) => { const t = T[name].clone(); t.needsUpdate = true; t.repeat.set(rx, ry); return t; };
  const jade = new THREE.MeshPhongMaterial({ color: 0x059669, emissive: 0x064e3b, emissiveIntensity: 0.4, shininess: 120, specular: 0xffffff });

  /* ── GAGANG ── */
  const handleMat = L.handleTex
    ? new THREE.MeshPhongMaterial({ map: tex(L.handleTex, 3, 3), shininess: 15 })
    : new THREE.MeshPhongMaterial({ color: L.handle, shininess: L.handleShine ? 140 : 12, specular: L.handleShine ? 0xfff3c4 : 0x222222 });
  if (L.crook) {
    const curve = new THREE.CatmullRomCurve3([V(0, 0.46, 0), V(0, 0.22, 0), V(0, 0.03, 0), V(-0.03, -0.07, 0), V(-0.11, -0.1, 0), V(-0.17, -0.04, 0), V(-0.16, 0.03, 0)]);
    root.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 32, 0.031, 10, false), handleMat));
    const tieMat = [new THREE.MeshPhongMaterial({ color: 0xdc2626 }), new THREE.MeshPhongMaterial({ color: 0xffffff })];
    [0.1, 0.16, 0.22, 0.28].forEach((y, i) => {
      const r = new THREE.Mesh(new THREE.TorusGeometry(0.033, 0.006, 6, 16), tieMat[i % 2]); r.rotation.x = Math.PI / 2; r.position.y = y; root.add(r);
    });
  } else {
    const handleG = new THREE.CylinderGeometry(0.03, 0.036, 0.45, 12); handleG.translate(0, 0.225, 0);
    root.add(new THREE.Mesh(handleG, handleMat));
    if (!L.kerisHilt && !L.naga) { const butt = new THREE.Mesh(new THREE.SphereGeometry(0.038, 12, 8), handleMat); root.add(butt); }
  }
  if (L.ropeGrip) { // lilitan tali ijuk pada gagang bambu
    const rope = new THREE.MeshLambertMaterial({ color: 0xc9a46a });
    for (let i = 0; i < 9; i++) { const r = new THREE.Mesh(new THREE.TorusGeometry(0.036, 0.007, 6, 14), rope); r.rotation.x = Math.PI / 2; r.position.y = 0.05 + i * 0.04; root.add(r); }
  } else if (!L.crook) {
    [0.02, 0.43].forEach((y) => { const r = new THREE.Mesh(new THREE.CylinderGeometry(0.039, 0.039, 0.025, 12), gold); r.position.y = y; root.add(r); });
  }
  if (L.fightingButt) { // tutup pangkal emas besar
    const cap = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.052, 0.07, 14), gold); cap.position.y = -0.02; root.add(cap);
    const ball = new THREE.Mesh(new THREE.SphereGeometry(0.05, 14, 10), gold); ball.position.y = -0.06; ball.scale.y = 0.6; root.add(ball);
  }

  /* ── GULUNGAN (REEL) ── */
  const reelMount = new THREE.Group(); reelMount.position.set(0.075, 0.29, 0); root.add(reelMount);
  if (L.reelType === 'spool') { // kili-kili kayu tradisional
    const wood = new THREE.MeshLambertMaterial({ color: 0x7c4a22 });
    [-0.03, 0.03].forEach((x) => { const d = new THREE.Mesh(new THREE.CylinderGeometry(0.075, 0.075, 0.012, 18), wood); d.rotation.z = Math.PI / 2; d.position.x = x; reelMount.add(d); });
    const core = new THREE.Mesh(new THREE.CylinderGeometry(0.045, 0.045, 0.05, 14), new THREE.MeshLambertMaterial({ color: 0xf5f5f4 })); core.rotation.z = Math.PI / 2; reelMount.add(core);
    const arm = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.014, 0.014), wood); arm.position.set(-0.05, 0, 0); reelMount.add(arm);
    const peg = new THREE.Mesh(new THREE.CylinderGeometry(0.008, 0.008, 0.05, 6), wood); peg.position.set(0.045, 0.05, 0); peg.rotation.z = Math.PI / 2; reelMount.add(peg);
    anim.push((t) => { core.rotation.x = t * 2; });
  } else if (L.reelType === 'big') { // reel spinning besar berlapis emas
    const body = new THREE.Mesh(new THREE.SphereGeometry(0.055, 14, 10), new THREE.MeshPhongMaterial({ color: 0x3b2314, shininess: 80 })); body.scale.set(1, 0.8, 1.2); reelMount.add(body);
    const spool = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 0.05, 18), gold); spool.position.set(0, 0.06, 0); reelMount.add(spool);
    const bail = new THREE.Mesh(new THREE.TorusGeometry(0.06, 0.005, 6, 20, Math.PI), gold); bail.position.y = 0.08; bail.rotation.y = Math.PI / 2; reelMount.add(bail);
    const crank = new THREE.Mesh(new THREE.BoxGeometry(0.09, 0.012, 0.012), gold); crank.position.set(0.05, 0, 0.04); reelMount.add(crank);
    const knob = new THREE.Mesh(new THREE.SphereGeometry(0.016, 10, 8), new THREE.MeshPhongMaterial({ color: 0x3b2314 })); knob.position.set(0.095, 0, 0.04); reelMount.add(knob);
    anim.push((t) => { spool.rotation.y = t * 3; });
  } else if (!L.noReelGuides) {
    const reel = new THREE.Mesh(new THREE.CylinderGeometry(0.058, 0.058, 0.05, 18), new THREE.MeshPhongMaterial({ color: L.naga ? 0xfbbf24 : 0xe5e7eb, shininess: 130, specular: 0xffffff }));
    reel.rotation.z = Math.PI / 2; reelMount.add(reel);
    const knob = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.012, 0.06, 8), handleMat); knob.rotation.z = Math.PI / 2; knob.position.set(0.045, 0, 0.03); reelMount.add(knob);
  } else { // keris: gulungan tersembunyi berupa cincin emas kecil
    const ring = new THREE.Mesh(new THREE.TorusGeometry(0.035, 0.012, 8, 18), gold); ring.rotation.y = Math.PI / 2; reelMount.add(ring);
  }

  /* ── BATANG BERSEGMEN ── */
  let blankMat;
  if (typeof L.blank === 'string') {
    blankMat = new THREE.MeshPhongMaterial({ map: tex(L.blank, 1, L.blankRep || 2), shininess: L.shin, specular: 0x888888, emissive: L.glow || 0x000000, emissiveIntensity: L.glow ? 0.12 : 0 });
  } else {
    blankMat = new THREE.MeshPhongMaterial({ color: L.blank, shininess: L.shin, specular: 0x666666 });
  }
  const wrapMats = L.wraps.map((c) => new THREE.MeshPhongMaterial({ color: c, shininess: 80, specular: 0xffffff }));
  const segLens = L.luk ? [0.45, 0.42, 0.4, 0.4, 0.38, 0.4] : [0.7, 0.65, 0.6, 0.5];
  const nodeMat = new THREE.MeshPhongMaterial({ color: 0x9a7a3a, shininess: 30 });
  const segs = [];
  let parent = root, r0 = L.segR || 0.021;
  segLens.forEach((len, i) => {
    const r1 = r0 * (L.luk ? 0.8 : (L.nodes ? 0.78 : 0.7));
    let g;
    if (L.blade) { // bilah pipih bersudut (penampang belah ketupat)
      g = new THREE.CylinderGeometry(r1, r0, len, 4); g.rotateY(Math.PI / 4); g.scale(2.6, 1, 0.55);
    } else {
      g = new THREE.CylinderGeometry(r1, r0, len, 12);
    }
    g.translate(0, len / 2, 0);
    const s = new THREE.Group(); s.position.y = i === 0 ? 0.45 : segLens[i - 1];
    if (L.luk) s.userData.lukZ = (i % 2 ? 1 : -1) * 0.08;
    s.add(new THREE.Mesh(g, blankMat));

    if (L.nodes) { // ruas bambu menonjol
      [0.02, len * 0.5].forEach((y) => {
        const rr = lerp(r0, r1, y / len);
        const n = new THREE.Mesh(new THREE.TorusGeometry(rr * 1.02, rr * 0.28, 6, 14), nodeMat); n.rotation.x = Math.PI / 2; n.position.y = y; s.add(n);
      });
    }
    wrapMats.forEach((m, k) => {
      const w = new THREE.Mesh(new THREE.CylinderGeometry(r0 * 1.22, r0 * 1.22, 0.022, 12), m); w.position.y = 0.02 + k * 0.026; s.add(w);
    });
    if (L.finGuides) { // pemandu senar berbentuk sirip naga
      const fin = new THREE.Mesh(new THREE.ConeGeometry(r0 * 1.3, r0 * 4, 4), gold); fin.position.set(0, len * 0.85, r0 * 2.2); fin.rotation.x = -0.6; s.add(fin);
    } else if (!L.blade) {
      const gd = new THREE.Mesh(new THREE.TorusGeometry(r0 * 1.6, 0.0035, 6, 12), gold); gd.position.set(0, len * 0.85, r0 * 2.2); s.add(gd);
    }
    if (L.filigree) { // sulur emas ukiran wayang
      [-1, 1].forEach((sd) => {
        const c = new THREE.Mesh(new THREE.TorusGeometry(r0 * 2.6, 0.0035, 6, 18, Math.PI * 1.4), gold);
        c.position.set(sd * r0 * 2.4, len * 0.45, 0); c.rotation.set(0, Math.PI / 2, sd * 1.2); s.add(c);
      });
      const bead = new THREE.Mesh(new THREE.SphereGeometry(r0 * 1.4, 10, 8), new THREE.MeshPhongMaterial({ color: 0xdc2626, emissive: 0x7f1d1d, shininess: 120 }));
      bead.position.y = len * 0.45; s.add(bead);
    }
    if (L.kawung && i < 3) { // cincin kawung & manik
      const ring = new THREE.Mesh(new THREE.TorusGeometry(r0 * 1.5, r0 * 0.35, 6, 14), gold); ring.rotation.x = Math.PI / 2; ring.position.y = len * 0.5; s.add(ring);
      [0, 1, 2, 3].forEach((k) => { const b = new THREE.Mesh(new THREE.SphereGeometry(r0 * 0.5, 8, 6), new THREE.MeshPhongMaterial({ color: 0x1e3a5f })); const a = k * Math.PI / 2; b.position.set(Math.cos(a) * r0 * 1.5, len * 0.5, Math.sin(a) * r0 * 1.5); s.add(b); });
    }
    if (L.coil && i < 3) { // tubuh naga melilit batang
      const turns = 1.25, pts = [];
      const thick = [0.014, 0.011, 0.007][i];
      for (let k = 0; k <= 24; k++) {
        const f = k / 24, a = f * Math.PI * 2 * turns + i * 1.3, rr = lerp(r0, r1, f) + thick * 1.3;
        pts.push(V(Math.cos(a) * rr, f * len, Math.sin(a) * rr));
      }
      const body = new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts), 40, thick, 8, false), jade);
      s.add(body);
      for (let k = 2; k < 24; k += 4) { // duri punggung emas
        const p = pts[k];
        const sp = new THREE.Mesh(new THREE.ConeGeometry(thick * 0.7, thick * 2.4, 5), gold);
        sp.position.copy(p).multiplyScalar(1.15); sp.position.y = p.y;
        sp.lookAt(V(p.x * 3, p.y, p.z * 3)); sp.rotateX(Math.PI / 2); s.add(sp);
      }
    }
    parent.add(s); parent = s; segs.push(s); r0 = r1;
  });
  const tip = new THREE.Object3D(); tip.position.y = segLens[segLens.length - 1]; parent.add(tip);

  /* ── UJUNG JORAN ── */
  if (L.tipKnot) { const k = new THREE.Mesh(new THREE.SphereGeometry(0.012, 8, 6), new THREE.MeshPhongMaterial({ color: 0xdc2626 })); tip.add(k); }
  if (L.tipColor) { const c = new THREE.Mesh(new THREE.ConeGeometry(0.012, 0.06, 8), gold); c.position.y = 0.02; tip.add(c); }
  if (L.crownTip) { // mahkota emas kecil
    const crown = new THREE.Group(); crown.position.y = 0.01;
    crown.add(new THREE.Mesh(new THREE.CylinderGeometry(0.018, 0.014, 0.02, 10), gold));
    for (let k = 0; k < 5; k++) { const sp = new THREE.Mesh(new THREE.ConeGeometry(0.006, 0.03, 5), gold); const a = k / 5 * Math.PI * 2; sp.position.set(Math.cos(a) * 0.014, 0.022, Math.sin(a) * 0.014); crown.add(sp); }
    const gem = new THREE.Mesh(new THREE.SphereGeometry(0.009, 8, 6), new THREE.MeshPhongMaterial({ color: 0xdc2626, emissive: 0x991b1b, shininess: 150 })); gem.position.y = 0.03; crown.add(gem);
    tip.add(crown);
  }
  if (L.pennant) { // panji merah putih kecil di ujung
    const pen = new THREE.Group(); pen.position.y = -0.02;
    const sR = new THREE.Shape(); sR.moveTo(0, 0); sR.lineTo(0.13, 0.02); sR.lineTo(0, 0.04); sR.closePath();
    const red = new THREE.Mesh(new THREE.ShapeGeometry(sR), new THREE.MeshLambertMaterial({ color: 0xdc2626, side: THREE.DoubleSide })); red.position.y = 0.02;
    const white = new THREE.Mesh(new THREE.ShapeGeometry(sR), new THREE.MeshLambertMaterial({ color: 0xffffff, side: THREE.DoubleSide })); white.position.y = -0.02;
    pen.add(red, white); tip.add(pen);
    anim.push((t) => { pen.rotation.y = Math.sin(t * 6) * 0.5; });
  }
  if (L.pearl) { // mustika bercahaya
    const pearl = new THREE.Mesh(new THREE.SphereGeometry(0.02, 12, 10), new THREE.MeshPhongMaterial({ color: 0xecfeff, emissive: 0x34d399, emissiveIntensity: 0.8, shininess: 200 }));
    tip.add(pearl);
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: dotTexture(), color: 0x6ee7b7, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    halo.scale.set(0.12, 0.12, 1); tip.add(halo);
    anim.push((t) => { const sc = 0.1 + Math.sin(t * 4) * 0.03; halo.scale.set(sc, sc, 1); pearl.material.emissiveIntensity = 0.6 + Math.sin(t * 4) * 0.3; });
  }
  if (!L.tipKnot && !L.tipColor && !L.crownTip && !L.pearl) {
    tip.add(new THREE.Mesh(new THREE.SphereGeometry(0.009, 8, 6), new THREE.MeshPhongMaterial({ color: 0xf43f5e, emissive: 0xf43f5e, emissiveIntensity: 0.4 })));
  }

  /* ── ORNAMEN DEKAT GAGANG ── */
  if (L.ribbon) { // pita merah putih berkibar
    const rib = new THREE.Group(); rib.position.set(0, 0.47, 0.03);
    const red = new THREE.Mesh(new THREE.PlaneGeometry(0.18, 0.035, 6, 1), new THREE.MeshLambertMaterial({ color: 0xdc2626, side: THREE.DoubleSide }));
    const wht = new THREE.Mesh(new THREE.PlaneGeometry(0.18, 0.035, 6, 1), new THREE.MeshLambertMaterial({ color: 0xffffff, side: THREE.DoubleSide }));
    red.position.set(0.09, 0.0175, 0); wht.position.set(0.09, -0.0175, 0);
    rib.add(red, wht); root.add(rib);
    anim.push((t) => { rib.rotation.y = Math.sin(t * 5) * 0.35; rib.rotation.z = -0.3 + Math.sin(t * 7) * 0.1; });
  }
  if (L.warangka) { // warangka keris: palang melintang berbentuk perahu
    const sh = new THREE.Shape();
    sh.moveTo(-0.13, 0.02); sh.quadraticCurveTo(-0.15, 0.07, -0.1, 0.08); sh.lineTo(0.07, 0.06);
    sh.quadraticCurveTo(0.16, 0.07, 0.14, 0.0); sh.quadraticCurveTo(0, -0.03, -0.13, 0.02);
    const war = new THREE.Mesh(new THREE.ExtrudeGeometry(sh, { depth: 0.035, bevelEnabled: true, bevelSize: 0.006, bevelThickness: 0.006, bevelSegments: 2 }), new THREE.MeshPhongMaterial({ color: 0x6b3f1d, shininess: 60 }));
    war.position.set(0, 0.42, -0.0175); root.add(war);
    const rim = new THREE.Mesh(new THREE.BoxGeometry(0.2, 0.008, 0.05), gold); rim.position.set(0.005, 0.425, 0); root.add(rim);
  }
  if (L.kerisHilt) { // gagang ukiran keris + mendak emas
    const hilt = new THREE.Mesh(new THREE.SphereGeometry(0.048, 12, 10), handleMat); hilt.scale.set(1, 1.6, 0.8); hilt.position.y = -0.04; root.add(hilt);
    const curl = new THREE.Mesh(new THREE.TorusGeometry(0.032, 0.011, 6, 12, Math.PI * 1.3), handleMat); curl.position.set(0.035, -0.09, 0); curl.rotation.z = 1; root.add(curl);
    const mendak = new THREE.Mesh(new THREE.TorusGeometry(0.04, 0.009, 6, 16), gold); mendak.rotation.x = Math.PI / 2; mendak.position.y = 0.455; root.add(mendak);
  }
  if (L.tassel) { // rumbai manik-manik bergoyang
    const tas = new THREE.Group(); tas.position.set(0.04, 0.44, 0); root.add(tas);
    const str = new THREE.Mesh(new THREE.CylinderGeometry(0.003, 0.003, 0.14, 4), new THREE.MeshLambertMaterial({ color: 0xd4a017 })); str.position.y = -0.07; tas.add(str);
    [[-0.06, 0xd4a017], [-0.09, 0x6b3a1e], [-0.12, 0xd4a017]].forEach(([y, c]) => { const b = new THREE.Mesh(new THREE.SphereGeometry(0.012, 8, 6), new THREE.MeshPhongMaterial({ color: c, shininess: 90 })); b.position.y = y; tas.add(b); });
    const fringe = new THREE.Mesh(new THREE.ConeGeometry(0.03, 0.1, 10, 1, true), new THREE.MeshLambertMaterial({ color: 0xb91c1c, side: THREE.DoubleSide })); fringe.position.y = -0.18; tas.add(fringe);
    anim.push((t) => { tas.rotation.z = Math.sin(t * 2.4) * 0.25; tas.rotation.x = Math.cos(t * 1.9) * 0.15; });
  }
  if (L.gunungan) { // gunungan wayang emas ganda
    const sh = new THREE.Shape();
    sh.moveTo(-0.06, 0); sh.quadraticCurveTo(-0.075, 0.09, 0, 0.2); sh.quadraticCurveTo(0.075, 0.09, 0.06, 0); sh.closePath();
    const hole = new THREE.Path(); hole.absarc(0, 0.075, 0.02, 0, Math.PI * 2, true); sh.holes.push(hole);
    const gMat = new THREE.MeshPhongMaterial({ color: 0xfbbf24, emissive: 0x4a2c00, specular: 0xffffff, shininess: 150, side: THREE.DoubleSide });
    [-1, 1].forEach((sd) => {
      const gm = new THREE.Mesh(new THREE.ShapeGeometry(sh, 12), gMat); gm.position.set(0, 0.07, sd * 0.042); root.add(gm);
      const kala = new THREE.Mesh(new THREE.SphereGeometry(0.015, 10, 8), new THREE.MeshPhongMaterial({ color: 0xdc2626, emissive: 0x7f1d1d })); kala.position.set(0, 0.145, sd * 0.047); root.add(kala);
    });
  }
  if (L.naga) { // kepala naga di pangkal joran
    const naga = new THREE.Group(); naga.position.y = -0.03; naga.rotation.z = Math.PI;
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.052, 14, 10), jade); head.scale.set(1, 1.4, 1); naga.add(head);
    const snout = new THREE.Mesh(new THREE.ConeGeometry(0.036, 0.1, 10), jade); snout.position.y = 0.095; naga.add(snout);
    const jaw = new THREE.Mesh(new THREE.ConeGeometry(0.025, 0.07, 8), jade); jaw.position.set(0, 0.07, -0.03); jaw.rotation.x = 0.4; naga.add(jaw);
    [-1, 1].forEach((sd) => {
      const horn = new THREE.Mesh(new THREE.ConeGeometry(0.012, 0.1, 6), gold); horn.position.set(sd * 0.03, -0.03, -0.03); horn.rotation.set(-0.8, 0, sd * 0.4); naga.add(horn);
      const eye = new THREE.Mesh(new THREE.SphereGeometry(0.01, 8, 6), new THREE.MeshPhongMaterial({ color: 0xef4444, emissive: 0xdc2626, emissiveIntensity: 0.9 })); eye.position.set(sd * 0.032, 0.03, 0.03); naga.add(eye);
      const whisk = new THREE.Mesh(new THREE.CylinderGeometry(0.003, 0.001, 0.14, 4), gold); whisk.position.set(sd * 0.045, 0.1, 0.02); whisk.rotation.z = sd * 0.9; naga.add(whisk);
    });
    for (let i = 0; i < 6; i++) { const m = new THREE.Mesh(new THREE.ConeGeometry(0.01, 0.06, 5), gold); m.position.set(0, -0.02 - i * 0.015, -0.045); m.rotation.x = -2.2; naga.add(m); }
    root.add(naga);
    const neckPts = []; // leher naga melilit gagang
    for (let k = 0; k <= 30; k++) { const f = k / 30, a = f * Math.PI * 4; neckPts.push(V(Math.cos(a) * 0.044, 0.0 + f * 0.45, Math.sin(a) * 0.044)); }
    root.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(neckPts), 60, 0.016, 8, false), jade));
  }
  return { root, segs, tip, update(t) { for (let i = 0; i < anim.length; i++) anim[i](t); } };
}

/* ───────────────────────── DUNIA 3D ───────────────────────── */
const FEED_SPOTS = [{ x: -2.4, d: 11 }, { x: 2.0, d: 17.5 }, { x: -0.6, d: 24 }];
const SUN_DIR = HAS_THREE ? new THREE.Vector3(-0.42, 0.2, -1).normalize() : null;

function createWorld(G) {
  const stage = $('mc3d-stage'), canvas = $('mc3d-canvas');
  const dpr = Math.min(window.devicePixelRatio || 1, window.innerWidth > 900 ? 1.25 : 1.75);
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: dpr < 2, powerPreference: 'high-performance' });
  renderer.setPixelRatio(dpr);
  const scene = new THREE.Scene();
  const HORIZON = new THREE.Color(0xffd9b0);
  scene.fog = new THREE.Fog(HORIZON, 90, 430);
  const camera = new THREE.PerspectiveCamera(55, 1, 0.05, 700);
  const CAM_BASE = new THREE.Vector3(0.22, 1.75, 2.6);
  camera.position.copy(CAM_BASE);
  const camLook = new THREE.Vector3(0, 0.4, -14);

  /* Langit senja keemasan + matahari */
  const sky = new THREE.Mesh(new THREE.SphereGeometry(400, 32, 16), new THREE.ShaderMaterial({
    side: THREE.BackSide, depthWrite: false, fog: false,
    uniforms: { uSun: { value: SUN_DIR }, uZenith: { value: new THREE.Color(0x8ec5fc) }, uHorizon: { value: HORIZON }, uSunCol: { value: new THREE.Color(0xfff0c2) } },
    vertexShader: 'varying vec3 vDir; void main(){ vDir = normalize(position); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
    fragmentShader: [
      'uniform vec3 uSun, uZenith, uHorizon, uSunCol; varying vec3 vDir;',
      'void main(){ float y = vDir.y;',
      ' vec3 col = mix(uHorizon, uZenith, smoothstep(0.0, 0.55, y));',
      ' col = mix(col, vec3(1.0,0.78,0.62), smoothstep(0.12, 0.0, abs(y)) * 0.35);',
      ' float s = max(dot(normalize(vDir), uSun), 0.0);',
      ' col += uSunCol * (pow(s, 900.0) * 2.5 + pow(s, 14.0) * 0.45 + pow(s, 3.0) * 0.12);',
      ' gl_FragColor = vec4(col, 1.0); }'
    ].join('\n')
  }));
  scene.add(sky);

  /* ── PEMANDANGAN NUSANTARA: gunung api, pegunungan, pulau kelapa, phinisi, awan, camar ── */
  const ambient = []; // fungsi animasi dekorasi: (t, dt) => void
  {
    const hash2 = (x, y) => { const s = Math.sin(x * 127.1 + y * 311.7) * 43758.5453; return s - Math.floor(s); };
    const vnoise = (x, y) => {
      const xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
      const u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
      const a = hash2(xi, yi), b = hash2(xi + 1, yi), c = hash2(xi, yi + 1), d = hash2(xi + 1, yi + 1);
      return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
    };
    const fbm = (x, y) => { let f = 0, amp = 0.5; for (let i = 0; i < 4; i++) { f += amp * vnoise(x, y); x *= 2.03; y *= 2.03; amp *= 0.5; } return f; };

    const C = (h) => new THREE.Color(h);
    const SAND = C(0xe9d3a2), JUNGLE = C(0x2f6b3a), JUNGLE2 = C(0x4a8a4a), ROCK = C(0x6e6152), ASH = C(0x8b8178), DARK = C(0x3b342e);

    const RICE = C(0x8fbf4a);
    const mtnTex = canvasTex('mtn', 512, 256, (g, w, h) => {
      g.fillStyle = '#ffffff'; g.fillRect(0, 0, w, h);
      for (let i = 0; i < 2600; i++) {   // tajuk pepohonan
        const x = Math.random() * w, y = Math.random() * h * 0.8, r = rand(2, 6);
        g.fillStyle = Math.random() < 0.55 ? 'rgba(0,30,0,' + rand(0.08, 0.24).toFixed(2) + ')' : 'rgba(255,255,220,' + rand(0.08, 0.2).toFixed(2) + ')';
        g.beginPath(); g.arc(x, y, r, 0, Math.PI * 2); g.fill();
      }
      for (let i = 0; i < 140; i++) {    // guratan lereng
        const x = Math.random() * w; g.strokeStyle = 'rgba(60,40,20,0.14)'; g.lineWidth = rand(1, 3);
        g.beginPath(); g.moveTo(x, 0); g.lineTo(x + rand(-6, 6), h * rand(0.2, 0.55)); g.stroke();
      }
      for (let y = h * 0.66; y < h; y += 5) { // sawah terasering di kaki gunung
        g.strokeStyle = 'rgba(40,70,10,0.38)'; g.lineWidth = 1.4; g.beginPath();
        for (let x = 0; x <= w; x += 8) { const yy = y + Math.sin(x * 0.03 + y * 0.1) * 3; x === 0 ? g.moveTo(x, yy) : g.lineTo(x, yy); }
        g.stroke();
      }
    }, true);
    mtnTex.repeat.set(8, 1);

    /* Gunung prosedural: punggungan (ridge), lapisan warna pantai → sawah → hutan → batu → abu */
    function buildMountain(o) {
      const g = new THREE.CylinderGeometry(o.r * (o.top || 0.02), o.r, o.h, o.seg || 96, 24, true);
      const p = g.attributes.position, cols = new Float32Array(p.count * 3), c = new THREE.Color();
      for (let i = 0; i < p.count; i++) {
        const x = p.getX(i), y = p.getY(i), z = p.getZ(i);
        const t = (y + o.h / 2) / o.h, a = Math.atan2(z, x);
        const cx = Math.cos(a), cz = Math.sin(a);
        const n = fbm(cx * 2.2 + o.seed, cz * 2.2 + t * 3.5);
        const ridge = 1 - Math.abs(fbm(cx * 5 + o.seed * 2, cz * 5 + t * 6) * 2 - 1);
        const k = 1 + (n - 0.5) * 0.5 + (ridge - 0.5) * 0.22 * t;
        p.setXYZ(i, x * k, y + (n - 0.5) * o.h * 0.12 * (1 - t), z * k);
        const treeLine = (o.volcano ? 0.5 : 0.68) + (n - 0.5) * 0.18;
        if (o.beach && t < 0.07) c.copy(SAND);
        else if (o.terrace && t < 0.3) c.copy(RICE).lerp(JUNGLE2, clamp((t - 0.2) * 10, 0, 1) * 0.6);
        else if (t < treeLine) c.copy(JUNGLE).lerp(JUNGLE2, clamp(ridge * 1.2 - 0.2, 0, 1));
        else if (t < treeLine + 0.22) c.copy(JUNGLE).lerp(ROCK, (t - treeLine) / 0.22);
        else c.copy(ROCK).lerp(o.volcano ? ASH : ROCK, clamp((t - treeLine - 0.22) * 4, 0, 1));
        c.multiplyScalar(0.85 + ridge * 0.3);
        cols[i * 3] = c.r; cols[i * 3 + 1] = c.g; cols[i * 3 + 2] = c.b;
      }
      g.setAttribute('color', new THREE.BufferAttribute(cols, 3));
      g.translate(0, o.h / 2, 0);
      g.computeVertexNormals();
      const m = new THREE.Mesh(g, new THREE.MeshLambertMaterial({ vertexColors: true, flatShading: true, map: mtnTex }));
      m.position.set(o.x, o.y || -1.2, o.z);
      m.scale.z = o.sz || 1;
      m.rotation.y = o.rot || 0;
      if (o.volcano) {
        const crater = new THREE.Mesh(new THREE.CircleGeometry(o.r * o.top * 1.1, 24), new THREE.MeshLambertMaterial({ color: DARK }));
        crater.rotation.x = -Math.PI / 2; crater.position.y = o.h - 0.4; m.add(crater);
      }
      scene.add(m);
      return m;
    }

    const cloudTex = canvasTex('cloud', 256, 128, (g, w, h) => {
      for (let i = 0; i < 9; i++) {
        const x = 40 + Math.random() * 176, y = 60 + Math.random() * 30, r = 26 + Math.random() * 30;
        const gr = g.createRadialGradient(x, y, 0, x, y, r);
        gr.addColorStop(0, 'rgba(255,255,255,0.95)'); gr.addColorStop(1, 'rgba(255,255,255,0)');
        g.fillStyle = gr; g.beginPath(); g.arc(x, y, r, 0, Math.PI * 2); g.fill();
      }
    });

    /* Gunung api aktif + asap */
    const volcano = buildMountain({ x: -55, z: -205, r: 62, h: 52, top: 0.13, seed: 3.1, volcano: true, sz: 0.75, rot: 0.4 });
    const smoke = [];
    for (let i = 0; i < 7; i++) {
      const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: cloudTex, color: 0xd9d2c8, transparent: true, opacity: 0.5, depthWrite: false }));
      s.userData.ph = i / 7;
      scene.add(s); smoke.push(s);
    }
    ambient.push((t) => {
      smoke.forEach((s) => {
        const ph = (s.userData.ph + t * 0.02) % 1;
        s.position.set(volcano.position.x + ph * 26, volcano.position.y + 51 + ph * 24, volcano.position.z + ph * 4);
        s.scale.set(10 + ph * 26, 6 + ph * 14, 1);
        s.material.opacity = 0.55 * (1 - ph);
      });
    });

    /* Barisan pegunungan berlapis (perspektif atmosfer via kabut) */
    buildMountain({ x: 45, z: -250, r: 70, h: 34, seed: 7.7, sz: 0.6, terrace: true });
    buildMountain({ x: 130, z: -225, r: 52, h: 27, seed: 11.2, sz: 0.65, rot: 1.2, terrace: true });
    buildMountain({ x: -150, z: -235, r: 58, h: 30, seed: 5.4, sz: 0.6, rot: 2.1, terrace: true });
    buildMountain({ x: -5, z: -290, r: 90, h: 40, seed: 9.9, sz: 0.5 });

    /* Pohon kelapa */
    const trunkMat = new THREE.MeshLambertMaterial({ color: 0x8b6b4a });
    const frondMat = new THREE.MeshLambertMaterial({ color: 0x3f8f3a, side: THREE.DoubleSide });
    const nutMat = new THREE.MeshLambertMaterial({ color: 0x5b4a2a });
    const frondGeo = (() => {
      const s = new THREE.Shape();
      s.moveTo(0, 0); s.quadraticCurveTo(1.4, 0.55, 2.9, -0.7); s.quadraticCurveTo(1.4, -0.05, 0, 0);
      return new THREE.ShapeGeometry(s, 6);
    })();
    function buildPalm(h, lean) {
      const g = new THREE.Group();
      const curve = new THREE.QuadraticBezierCurve3(new THREE.Vector3(0, 0, 0), new THREE.Vector3(lean * 0.35, h * 0.55, 0), new THREE.Vector3(lean, h, 0));
      g.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 8, 0.16, 6, false), trunkMat));
      const crown = new THREE.Group(); crown.position.set(lean, h, 0); g.add(crown);
      for (let i = 0; i < 8; i++) {
        const f = new THREE.Mesh(frondGeo, frondMat);
        f.rotation.y = (i / 8) * Math.PI * 2 + rand(-0.2, 0.2);
        f.rotation.z = rand(-0.15, 0.2);
        crown.add(f);
      }
      for (let i = 0; i < 3; i++) { const n = new THREE.Mesh(new THREE.SphereGeometry(0.18, 6, 5), nutMat); n.position.set(Math.cos(i * 2.1) * 0.2, -0.15, Math.sin(i * 2.1) * 0.2); crown.add(n); }
      g.userData.crown = crown;
      return g;
    }

    /* Pulau kelapa dekat dengan pantai pasir */
    const isle = buildMountain({ x: 32, z: -88, r: 16, h: 5.5, top: 0.35, seed: 2.2, beach: true, sz: 0.55, seg: 48 });
    const palms = [];
    [[-6, 0.5, 6.5, 1.2], [-3, -1.5, 7.4, -0.8], [0.5, 1, 6.2, 1.5], [3.5, -0.5, 7.8, 0.9], [6.5, 1.2, 6, -1.3], [9, -1, 5.6, 1.1]].forEach(([dx, dz, h, lean]) => {
      const p = buildPalm(h, lean);
      p.position.set(isle.position.x + dx, isle.position.y + 4.6 - Math.abs(dx) * 0.28, isle.position.z + dz);
      p.rotation.y = rand(0, Math.PI * 2);
      scene.add(p); palms.push(p);
    });
    /* Pulau karang kecil */
    const islet = buildMountain({ x: -24, z: -62, r: 3.2, h: 3.4, top: 0.25, seed: 4.8, beach: true, seg: 32 });
    [[-0.6, 0.2, 4.2, 0.8], [0.8, -0.3, 3.6, -0.9]].forEach(([dx, dz, h, lean]) => {
      const p = buildPalm(h, lean);
      p.scale.setScalar(0.75);
      p.position.set(islet.position.x + dx, islet.position.y + 3.1, islet.position.z + dz);
      scene.add(p); palms.push(p);
    });
    ambient.push((t) => palms.forEach((p, i) => { p.userData.crown.rotation.z = Math.sin(t * 1.2 + i) * 0.06; }));

    /* Kapal phinisi di kejauhan */
    const phinisi = new THREE.Group();
    {
      const hullMat = new THREE.MeshLambertMaterial({ color: 0x6b3f22 });
      const hull = new THREE.Mesh(new THREE.BoxGeometry(9, 1.6, 2.2), hullMat); hull.position.y = 0.6; phinisi.add(hull);
      const bow = new THREE.Mesh(new THREE.ConeGeometry(1.1, 3.2, 4), hullMat); bow.rotation.z = -Math.PI / 2; bow.position.set(6, 0.9, 0); bow.scale.z = 0.9; phinisi.add(bow);
      const stern = new THREE.Mesh(new THREE.BoxGeometry(2, 1.2, 2.2), new THREE.MeshLambertMaterial({ color: 0x8a5a33 })); stern.position.set(-3.8, 1.9, 0); phinisi.add(stern);
      const mastMat = new THREE.MeshLambertMaterial({ color: 0x4a2e18 });
      const sailMat = new THREE.MeshLambertMaterial({ color: 0xfff4e0, side: THREE.DoubleSide });
      [[-1.2, 10], [2.2, 11]].forEach(([x, h]) => {
        const mast = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.14, h, 6), mastMat); mast.position.set(x, h / 2 + 1, 0); phinisi.add(mast);
        const s = new THREE.Shape(); s.moveTo(0, 0); s.lineTo(3.4, 0.6); s.lineTo(0.2, h * 0.75); s.closePath();
        const sail = new THREE.Mesh(new THREE.ShapeGeometry(s), sailMat); sail.position.set(x + 0.15, 2, 0); phinisi.add(sail);
      });
      const jib = new THREE.Shape(); jib.moveTo(0, 0); jib.lineTo(4.2, 0); jib.lineTo(0, 8); jib.closePath();
      const jibM = new THREE.Mesh(new THREE.ShapeGeometry(jib), sailMat); jibM.position.set(2.4, 1.8, 0.05); phinisi.add(jibM);
    }
    phinisi.position.set(-34, 0, -128);
    phinisi.rotation.y = 0.35;
    scene.add(phinisi);
    ambient.push((t) => {
      phinisi.position.x = -34 + Math.sin(t * 0.02) * 6;
      phinisi.position.y = waveH(phinisi.position.x, -128, t) * 0.6 - 0.2;
      phinisi.rotation.z = Math.sin(t * 0.8) * 0.03;
    });

    /* Awan senja */
    for (let i = 0; i < 9; i++) {
      const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: cloudTex, color: i % 3 ? 0xffe9d6 : 0xffd2c2, transparent: true, opacity: 0.85, depthWrite: false, fog: false }));
      s.position.set(-220 + i * 55 + rand(-15, 15), rand(38, 75), -330 + rand(-30, 30));
      s.scale.set(rand(70, 120), rand(22, 36), 1);
      s.userData.vx = rand(0.3, 0.8);
      scene.add(s);
      ambient.push((t, dt) => { s.position.x += s.userData.vx * dt; if (s.position.x > 280) s.position.x = -280; });
    }

    /* Burung camar */
    const wingGeo = (() => { const s = new THREE.Shape(); s.moveTo(0, 0); s.quadraticCurveTo(0.5, 0.25, 1.1, 0.05); s.lineTo(0.6, -0.05); s.closePath(); const g = new THREE.ShapeGeometry(s); g.rotateX(-Math.PI / 2); return g; })();
    const gullMat = new THREE.MeshBasicMaterial({ color: 0x3b3b46, side: THREE.DoubleSide });
    for (let i = 0; i < 6; i++) {
      const b = new THREE.Group();
      const L = new THREE.Mesh(wingGeo, gullMat), R = new THREE.Mesh(wingGeo, gullMat);
      R.scale.x = -1; b.add(L, R);
      b.scale.setScalar(rand(0.8, 1.2));
      const rad = rand(18, 34), cy = rand(12, 22), cz = rand(-70, -45), sp = rand(0.12, 0.2) * (i % 2 ? 1 : -1), ph = rand(0, 6.28);
      scene.add(b);
      ambient.push((t) => {
        const a = ph + t * sp;
        b.position.set(Math.cos(a) * rad, cy + Math.sin(t * 0.7 + ph) * 1.5, cz + Math.sin(a) * rad * 0.4);
        b.rotation.y = -a + (sp > 0 ? -Math.PI / 2 : Math.PI / 2);
        const flap = Math.sin(t * 7 + ph) * 0.5;
        L.rotation.z = flap; R.rotation.z = -flap;
      });
    }

    /* ── SENTUHAN NUSANTARA ── */
    const woodMat = new THREE.MeshLambertMaterial({ color: 0x7c4a22 });
    const bambooMat2 = new THREE.MeshLambertMaterial({ color: 0xd9b26b });
    const ijukMat = new THREE.MeshLambertMaterial({ color: 0x2b2420 });
    const thatchMat = new THREE.MeshLambertMaterial({ color: 0x9c7a45 });

    /* Bagan apung: platform bambu penangkap ikan dengan lampu petromaks */
    const bagan = new THREE.Group();
    {
      const S = 3.6;
      [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([sx, sz]) => {
        const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.07, 0.07, 3.2, 6), bambooMat2);
        pole.position.set(sx * S / 2, 0.6, sz * S / 2); bagan.add(pole);
      });
      [[0, -S / 2, S, 0.1], [0, S / 2, S, 0.1]].forEach(([x, z, w]) => {
        const b = new THREE.Mesh(new THREE.BoxGeometry(w, 0.1, 0.1), bambooMat2); b.position.set(x, 1.1, z); bagan.add(b);
        const b2 = b.clone(); b2.rotation.y = Math.PI / 2; b2.position.set(z, 1.1, x); bagan.add(b2);
      });
      const deck = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.08, 1.6), woodMat); deck.position.y = 1.15; bagan.add(deck);
      const hut = new THREE.Mesh(new THREE.BoxGeometry(1.2, 0.8, 1.2), new THREE.MeshLambertMaterial({ color: 0xc19a6b })); hut.position.y = 1.6; bagan.add(hut);
      const roof = new THREE.Mesh(new THREE.ConeGeometry(1.1, 0.7, 4), thatchMat); roof.position.y = 2.35; roof.rotation.y = Math.PI / 4; bagan.add(roof);
      const lampMat = new THREE.MeshBasicMaterial({ color: 0xfff3c4 });
      [[-1, -1], [1, 1], [1, -1], [-1, 1]].forEach(([sx, sz]) => {
        const l = new THREE.Mesh(new THREE.SphereGeometry(0.09, 8, 6), lampMat); l.position.set(sx * S / 2, 1.0, sz * S / 2); bagan.add(l);
        const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: dotTexture(), color: 0xffd27a, transparent: true, opacity: 0.6, depthWrite: false, blending: THREE.AdditiveBlending }));
        glow.scale.set(0.9, 0.9, 1); glow.position.copy(l.position); bagan.add(glow);
      });
      [[-S / 2, 0], [S / 2, 0]].forEach(([x]) => { // jaring
        const net = new THREE.Mesh(new THREE.PlaneGeometry(S, 0.9, 6, 2), new THREE.MeshBasicMaterial({ color: 0x334155, wireframe: true, transparent: true, opacity: 0.35 }));
        net.position.set(x * 0, 0.5, x); bagan.add(net);
      });
    }
    bagan.position.set(13, 0, -44); bagan.rotation.y = 0.5;
    scene.add(bagan);
    ambient.push((t) => { bagan.position.y = waveH(13, -44, t) * 0.5 - 0.25; bagan.rotation.z = Math.sin(t * 0.7) * 0.015; });

    /* Penjor Bali di pantai pulau kelapa */
    const janurMat = new THREE.MeshLambertMaterial({ color: 0xf5e6a8, side: THREE.DoubleSide });
    function buildPenjor(h) {
      const g = new THREE.Group();
      const curve = new THREE.QuadraticBezierCurve3(new THREE.Vector3(0, 0, 0), new THREE.Vector3(0.2, h * 0.8, 0), new THREE.Vector3(h * 0.35, h, 0));
      g.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 12, 0.07, 5, false), new THREE.MeshLambertMaterial({ color: 0xe0c57a })));
      for (let i = 0; i < 9; i++) {
        const p = curve.getPoint(0.45 + i * 0.06);
        const leaf = new THREE.Mesh(new THREE.PlaneGeometry(0.12, 0.6), janurMat);
        leaf.position.copy(p); leaf.position.y -= 0.3; leaf.rotation.y = i; g.add(leaf);
      }
      const end = curve.getPoint(1);
      const sampian = new THREE.Mesh(new THREE.ConeGeometry(0.18, 0.7, 8, 1, true), janurMat);
      sampian.position.set(end.x, end.y - 0.45, end.z); g.add(sampian);
      return g;
    }
    [[-9, 2.5, 7], [-6.5, 3.5, 6.2], [12, 2.6, 6.8]].forEach(([dx, dz, h]) => {
      const p = buildPenjor(h);
      p.position.set(isle.position.x + dx, isle.position.y + 1.6, isle.position.z + dz);
      p.rotation.y = rand(-0.5, 0.5);
      scene.add(p);
    });

    /* Rumah panggung nelayan beratap jerami */
    {
      const house = new THREE.Group();
      [[-0.9, -0.6], [0.9, -0.6], [-0.9, 0.6], [0.9, 0.6]].forEach(([x, z]) => {
        const s = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 1.4, 6), woodMat); s.position.set(x, 0.7, z); house.add(s);
      });
      const floor = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.12, 1.6), woodMat); floor.position.y = 1.4; house.add(floor);
      const wall = new THREE.Mesh(new THREE.BoxGeometry(2, 1.0, 1.4), new THREE.MeshLambertMaterial({ color: 0xd6b88a })); wall.position.y = 1.95; house.add(wall);
      const roofShape = new THREE.Shape(); roofShape.moveTo(-1.5, 0); roofShape.lineTo(0, 1.2); roofShape.lineTo(1.5, 0); roofShape.closePath();
      const roof = new THREE.Mesh(new THREE.ExtrudeGeometry(roofShape, { depth: 2.0, bevelEnabled: false }), thatchMat);
      roof.position.set(0, 2.45, -1.0); house.add(roof);
      house.position.set(isle.position.x + 2, isle.position.y + 1.2, isle.position.z + 3.2);
      house.rotation.y = -0.4;
      scene.add(house);
    }

    /* Pura meru bertingkat di pulau karang */
    {
      const meru = new THREE.Group();
      const base = new THREE.Mesh(new THREE.BoxGeometry(1.1, 0.7, 1.1), new THREE.MeshLambertMaterial({ color: 0xb4532a })); base.position.y = 0.35; meru.add(base);
      for (let i = 0; i < 5; i++) {
        const w = 1.6 - i * 0.26;
        const r = new THREE.Mesh(new THREE.ConeGeometry(w * 0.72, 0.4, 4), ijukMat);
        r.position.y = 0.85 + i * 0.42; r.rotation.y = Math.PI / 4; meru.add(r);
      }
      const top = new THREE.Mesh(new THREE.SphereGeometry(0.08, 8, 6), new THREE.MeshLambertMaterial({ color: 0xd4a017 })); top.position.y = 3.0; meru.add(top);
      meru.position.set(islet.position.x + 0.2, islet.position.y + 3.1, islet.position.z - 0.6);
      meru.scale.setScalar(0.85);
      scene.add(meru);
    }

    /* Jukung layar warna-warni di kejauhan */
    function buildSailJukung(c1, c2) {
      const g = new THREE.Group();
      const hull = new THREE.Mesh(new THREE.SphereGeometry(1, 12, 6, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2), new THREE.MeshLambertMaterial({ color: 0xffffff }));
      hull.scale.set(0.35, 0.3, 2.2); hull.position.y = 0.2; g.add(hull);
      [-1, 1].forEach((sd) => { const f = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 3.4, 6), bambooMat2); f.rotation.x = Math.PI / 2; f.position.set(sd * 1.1, 0.05, 0); g.add(f); });
      const mast = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 2.6, 5), woodMat); mast.position.y = 1.4; g.add(mast);
      const s = new THREE.Shape(); s.moveTo(0, 0); s.lineTo(1.6, 0.2); s.lineTo(0, 2.3); s.closePath();
      const sailG = new THREE.ShapeGeometry(s);
      const cols = new Float32Array(sailG.attributes.position.count * 3);
      const a = new THREE.Color(c1), b = new THREE.Color(c2), tmp = new THREE.Color();
      for (let i = 0; i < sailG.attributes.position.count; i++) {
        const y = sailG.attributes.position.getY(i);
        tmp.copy(Math.floor(y * 3) % 2 ? a : b);
        cols[i * 3] = tmp.r; cols[i * 3 + 1] = tmp.g; cols[i * 3 + 2] = tmp.b;
      }
      sailG.setAttribute('color', new THREE.BufferAttribute(cols, 3));
      const sail = new THREE.Mesh(sailG, new THREE.MeshLambertMaterial({ vertexColors: true, side: THREE.DoubleSide }));
      sail.position.set(0.03, 0.4, 0); g.add(sail);
      return g;
    }
    [[-15, -72, 0xdc2626, 0xfacc15, 0.6], [22, -112, 0x2563eb, 0xffffff, -0.4]].forEach(([x, z, c1, c2, ry], k) => {
      const j = buildSailJukung(c1, c2);
      j.position.set(x, 0, z); j.rotation.y = ry; scene.add(j);
      ambient.push((t) => {
        j.position.x = x + Math.sin(t * 0.03 + k) * 4;
        j.position.y = waveH(j.position.x, z, t) * 0.7 - 0.1;
        j.rotation.z = Math.sin(t * 0.9 + k) * 0.05;
      });
    });
  }

  /* Lampu */
  scene.add(new THREE.HemisphereLight(0xfff1dc, 0x2a8a8a, 0.75));
  const sun = new THREE.DirectionalLight(0xffd9a8, 1.15);
  sun.position.copy(SUN_DIR).multiplyScalar(50);
  scene.add(sun);

  /* Air laut tropis (shader) */
  const ripples = [0, 1, 2, 3].map(() => new THREE.Vector3(0, 0, -99));
  let rippleIdx = 0;
  const waterU = {
    uTime: { value: 0 }, uSunDir: { value: SUN_DIR }, uSunColor: { value: new THREE.Color(0xfff0c8) },
    uDeep: { value: new THREE.Color(0x0a4658) }, uShallow: { value: new THREE.Color(0x14727a) },
    uHorizon: { value: HORIZON }, uZenith: { value: new THREE.Color(0x8ec5fc) },
    uFogNear: { value: 45 }, uFogFar: { value: 260 },
    uBobber: { value: new THREE.Vector4(0, 0, 0, 0) },
    uRipples: { value: ripples },
    uFeed: { value: FEED_SPOTS.map((s, i) => new THREE.Vector3(s.x, -s.d, i * 0.33)) },
    uEntry: { value: new THREE.Vector3(0, 0, 0) }
  };
  const waterGeo = new THREE.PlaneGeometry(320, 320, 150, 150);
  waterGeo.rotateX(-Math.PI / 2);
  const water = new THREE.Mesh(waterGeo, new THREE.ShaderMaterial({
    uniforms: waterU,
    vertexShader: [
      'uniform float uTime; varying vec3 vWorld; varying vec3 vNormalW;',
      'float wave(vec2 p, float t, inout vec2 g){ float h = 0.0; float ph;', GLSL_WAVES, 'return h; }',
      'void main(){ vec4 wp = modelMatrix * vec4(position, 1.0); vec2 g = vec2(0.0);',
      ' wp.y += wave(wp.xz, uTime, g);',
      ' vNormalW = normalize(vec3(-g.x, 1.0, -g.y)); vWorld = wp.xyz;',
      ' gl_Position = projectionMatrix * viewMatrix * wp; }'
    ].join('\n'),
    fragmentShader: [
      'uniform float uTime, uFogNear, uFogFar; uniform vec3 uSunDir, uSunColor, uDeep, uShallow, uHorizon, uZenith;',
      'uniform vec4 uBobber; uniform vec3 uRipples[4]; uniform vec3 uFeed[3]; uniform vec3 uEntry;',
      'varying vec3 vWorld; varying vec3 vNormalW;',
      'float hash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }',
      'float noise(vec2 p){ vec2 i = floor(p), f = fract(p); vec2 u = f*f*(3.0-2.0*f);',
      ' return mix(mix(hash(i), hash(i+vec2(1.0,0.0)), u.x), mix(hash(i+vec2(0.0,1.0)), hash(i+vec2(1.0,1.0)), u.x), u.y); }',
      'void main(){',
      ' float t = uTime; vec2 q = vWorld.xz;',
      ' vec3 n = normalize(vNormalW);',
      ' n.x += (noise(q*1.7 + vec2(t*0.6, 0.0)) - 0.5) * 0.22; n.z += (noise(q*2.1 - vec2(0.0, t*0.5)) - 0.5) * 0.22; n = normalize(n);',
      ' vec3 V = normalize(cameraPosition - vWorld);',
      ' float dist = length(cameraPosition.xz - q);',
      ' float fres = pow(1.0 - max(dot(n, V), 0.0), 3.0);',
      ' vec3 water = mix(uShallow, uDeep, clamp(dist / 55.0, 0.0, 1.0));',
      ' vec3 R = reflect(-V, n);',
      ' vec3 sky = mix(uHorizon, uZenith, clamp(R.y * 2.0, 0.0, 1.0));',
      ' vec3 col = mix(water, sky * 0.82, clamp(fres * 0.6 + 0.03, 0.0, 0.75));',
      ' float spec = pow(max(dot(R, uSunDir), 0.0), 480.0);',
      ' col += uSunColor * spec * 1.1;',
      ' float gl = noise(q*7.0 + t*1.5) * noise(q*5.3 - t*1.1);',
      ' col += uSunColor * smoothstep(0.62, 0.9, gl) * 0.12 * (1.0 - clamp(dist/45.0, 0.0, 1.0));',
      ' float c1 = abs(sin(q.x*2.3 + sin(q.y*1.7 + t)*1.5 + t*0.8) * sin(q.y*2.1 + sin(q.x*1.3 - t*0.7)*1.5));',
      ' col += vec3(0.9, 1.0, 0.95) * pow(1.0 - c1, 8.0) * 0.05 * (1.0 - clamp(dist/30.0, 0.0, 1.0));',
      /* buih di sekitar lambung jukung & cadik */
      ' vec2 bp = q; float be = length(vec2(bp.x/0.72, bp.y/3.6));',
      ' if (length(vec2(bp.x/0.6, bp.y/3.33)) < 1.0) discard;',
      ' float foam = smoothstep(1.3, 1.0, be) * smoothstep(0.86, 1.02, be);',
      ' for (int s = 0; s < 2; s++){ float sx = s == 0 ? -1.85 : 1.85; vec2 fp = q - vec2(sx, -0.3); float fe = length(vec2(fp.x/0.14, fp.y/2.7)); foam += smoothstep(1.5, 1.0, fe) * 0.7; }',
      ' foam *= 0.55 + 0.45 * noise(q*9.0 + t);',
      /* cincin pelampung */
      ' if (uBobber.z > 0.5){ float d = length(q - uBobber.xy); foam += smoothstep(0.24, 0.12, d) * smoothstep(0.03, 0.1, d) * 0.8; }',
      /* riak yang menyebar */
      ' for (int i = 0; i < 4; i++){ float age = t - uRipples[i].z; if (age > 0.0 && age < 2.6){ float d = length(q - uRipples[i].xy); float r = age * 1.6; foam += smoothstep(0.13, 0.0, abs(d - r)) * (1.0 - age/2.6) * 0.9; } }',
      /* titik riak ikan makan */
      ' for (int i = 0; i < 3; i++){ float d = length(q - uFeed[i].xy); float ph = fract(t*0.45 + uFeed[i].z); float r = ph * 1.5;',
      '   foam += smoothstep(0.09, 0.0, abs(d - r)) * (1.0 - ph) * 0.6;',
      '   float ph2 = fract(ph + 0.5); foam += smoothstep(0.08, 0.0, abs(d - ph2*1.5)) * (1.0 - ph2) * 0.4;',
      '   foam += smoothstep(0.4, 0.0, d) * 0.2 * noise(q*12.0 + t*3.0); }',
      /* olakan saat ikan berontak */
      ' if (uEntry.z > 0.5){ float d = length(q - uEntry.xy); foam += smoothstep(0.7, 0.0, d) * (0.45 + 0.55 * noise(q*10.0 + t*6.0)); }',
      ' col = mix(col, vec3(1.0), clamp(foam, 0.0, 1.0) * 0.85);',
      ' col = mix(col, uHorizon, smoothstep(uFogNear, uFogFar, dist));',
      ' gl_FragColor = vec4(col, 1.0); }'
    ].join('\n')
  }));
  scene.add(water);

  /* Perahu jukung tradisional dengan cadik */
  const boat = new THREE.Group();
  {
    const hullGeo = new THREE.SphereGeometry(1, 28, 14, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2);
    hullGeo.scale(0.62, 0.5, 3.4);
    const outer = new THREE.Mesh(hullGeo, new THREE.MeshPhongMaterial({ color: 0xf8fafc, shininess: 50, specular: 0x666666 }));
    const inner = new THREE.Mesh(hullGeo, new THREE.MeshLambertMaterial({ color: 0x9a6b3f, side: THREE.BackSide }));
    outer.position.y = inner.position.y = 0.3;
    boat.add(outer, inner);
    const mkBand = (sx, sz, y, tube, color) => {
      const g = new THREE.TorusGeometry(1, tube, 6, 56); g.rotateX(Math.PI / 2);
      const m = new THREE.Mesh(g, new THREE.MeshPhongMaterial({ color, shininess: 40 }));
      m.scale.set(sx, 1, sz); m.position.y = y; boat.add(m);
    };
    mkBand(0.62, 3.4, 0.3, 0.035, 0xdc2626);
    mkBand(0.59, 3.28, 0.2, 0.022, 0x2563eb);
    mkBand(0.55, 3.05, 0.1, 0.018, 0xfacc15);
    const beakMat = new THREE.MeshPhongMaterial({ color: 0xffffff, shininess: 40 });
    [-1, 1].forEach((dir) => {
      const beak = new THREE.Mesh(new THREE.ConeGeometry(0.07, 0.95, 8), beakMat);
      beak.position.set(0, 0.48, dir * 3.32); beak.rotation.x = dir * 1.15;
      const tip = new THREE.Mesh(new THREE.ConeGeometry(0.072, 0.25, 8), new THREE.MeshPhongMaterial({ color: 0xdc2626 }));
      tip.position.y = 0.36; beak.add(tip);
      boat.add(beak);
    });
    const plankMat = new THREE.MeshLambertMaterial({ color: 0x7c4a22 });
    [-1.3, 0.5].forEach((z) => { const p = new THREE.Mesh(new THREE.BoxGeometry(1.08, 0.04, 0.2), plankMat); p.position.set(0, 0.25, z); boat.add(p); });
    const bambooMat = new THREE.MeshPhongMaterial({ color: 0xd9b26b, shininess: 30 });
    [-1, 1].forEach((side) => {
      const fl = new THREE.Mesh(new THREE.CylinderGeometry(0.07, 0.07, 5.4, 10), bambooMat);
      fl.rotation.x = Math.PI / 2; fl.position.set(side * 1.85, 0.05, -0.3); boat.add(fl);
      [-1.6, 1.0].forEach((z) => {
        const curve = new THREE.QuadraticBezierCurve3(new THREE.Vector3(side * 0.5, 0.32, z), new THREE.Vector3(side * 1.25, 0.6, z), new THREE.Vector3(side * 1.85, 0.1, z));
        boat.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 12, 0.03, 6, false), bambooMat));
      });
    });
  }
  /* Bendera Merah Putih berkibar di haluan jukung */
  {
    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.014, 0.014, 1.35, 6), new THREE.MeshLambertMaterial({ color: 0xe5e7eb }));
    pole.position.set(0.14, 0.95, -2.55); boat.add(pole);
    const mkFlag = (color, y) => {
      const g = new THREE.PlaneGeometry(0.6, 0.19, 14, 1); g.translate(0.3, 0, 0);
      const m = new THREE.Mesh(g, new THREE.MeshLambertMaterial({ color, side: THREE.DoubleSide }));
      m.position.set(0.15, y, -2.55); boat.add(m);
      return { g, base: Float32Array.from(g.attributes.position.array) };
    };
    const flags = [mkFlag(0xdc2626, 1.52), mkFlag(0xf8fafc, 1.33)];
    ambient.push((t) => flags.forEach(({ g, base }) => {
      const p = g.attributes.position;
      for (let i = 0; i < p.count; i++) {
        const x = base[i * 3];
        p.setZ(i, Math.sin(x * 9 - t * 7) * 0.05 * (x / 0.6));
        p.setY(i, base[i * 3 + 1] - x * 0.05);
      }
      p.needsUpdate = true;
    }));
  }
  scene.add(boat);

  /* Joran pancing (skin bisa diganti dari Koleksi Joran) */
  const rod = new THREE.Group();
  let rodParts = null;
  function setRodSkin(key) {
    if (rodParts) { rod.remove(rodParts.root); disposeTree(rodParts.root); }
    rodParts = buildRod(key);
    rodParts.root.rotation.x = -(Math.PI / 2 - 0.42);
    rodParts.root.rotation.z = -0.08;
    rod.add(rodParts.root);
  }
  setRodSkin('bambu');
  scene.add(rod);

  /* Senar */
  const LINE_N = 32;
  const lineGeo = new THREE.BufferGeometry();
  lineGeo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(LINE_N * 3), 3));
  const fishLine = new THREE.Line(lineGeo, new THREE.LineBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.85 }));
  fishLine.frustumCulled = false;
  scene.add(fishLine);

  /* Pelampung */
  const bobber = new THREE.Group();
  bobber.add(new THREE.Mesh(new THREE.SphereGeometry(0.075, 16, 8, 0, Math.PI * 2, 0, Math.PI / 2), new THREE.MeshPhongMaterial({ color: 0xffffff, shininess: 80 })));
  bobber.add(new THREE.Mesh(new THREE.SphereGeometry(0.075, 16, 8, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2), new THREE.MeshPhongMaterial({ color: 0xef4444, shininess: 80 })));
  const ant = new THREE.Mesh(new THREE.CylinderGeometry(0.008, 0.008, 0.14, 6), new THREE.MeshPhongMaterial({ color: 0x1e1b2e })); ant.position.y = 0.1; bobber.add(ant);
  const antTip = new THREE.Mesh(new THREE.SphereGeometry(0.018, 10, 8), new THREE.MeshPhongMaterial({ color: 0xfb923c, emissive: 0xf97316, emissiveIntensity: 0.6 })); antTip.position.y = 0.18; bobber.add(antTip);
  scene.add(bobber);

  /* Partikel cipratan bertekstur (Kenney Particle Pack, CC0):
     tetesan air (point sprite ber-ukuran), kabut semburan, cincin buih di permukaan, kilau. */
  const texLoader = new THREE.TextureLoader();
  const TEX_DROP = texLoader.load('/assets/textures/particles/droplet.png');
  const TEX_MIST = texLoader.load('/assets/textures/particles/mist.png');
  const TEX_FOAM = texLoader.load('/assets/textures/particles/foam.png');
  const TEX_GLINT = texLoader.load('/assets/textures/particles/glint.png');
  const SPL = 360;
  const splPos = new Float32Array(SPL * 3).fill(-999), splVel = new Float32Array(SPL * 3), splLife = new Float32Array(SPL), splMax = new Float32Array(SPL).fill(1);
  const splSize = new Float32Array(SPL), splAlpha = new Float32Array(SPL);
  const splGeo = new THREE.BufferGeometry();
  splGeo.setAttribute('position', new THREE.BufferAttribute(splPos, 3));
  splGeo.setAttribute('aSize', new THREE.BufferAttribute(splSize, 1));
  splGeo.setAttribute('aAlpha', new THREE.BufferAttribute(splAlpha, 1));
  const splMat = new THREE.ShaderMaterial({
    uniforms: { uTex: { value: TEX_DROP }, uScale: { value: 400 } },
    vertexShader: 'attribute float aSize; attribute float aAlpha; varying float vA; uniform float uScale;\n' +
      'void main(){ vA = aAlpha; vec4 mv = modelViewMatrix * vec4(position, 1.0); gl_PointSize = aSize * uScale / max(0.1, -mv.z); gl_Position = projectionMatrix * mv; }',
    fragmentShader: 'uniform sampler2D uTex; varying float vA;\n' +
      'void main(){ vec4 c = texture2D(uTex, gl_PointCoord); float a = c.a * vA; if (a < 0.02) discard; gl_FragColor = vec4(vec3(0.86, 0.97, 1.0) * (0.85 + 0.15 * c.r), a); }',
    transparent: true, depthWrite: false
  });
  const splash = new THREE.Points(splGeo, splMat);
  splash.frustumCulled = false; scene.add(splash);
  let splCursor = 0;

  const MIST_N = 14, mists = [];
  for (let i = 0; i < MIST_N; i++) {
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: TEX_MIST, color: 0xf0fbff, transparent: true, opacity: 0, depthWrite: false }));
    sp.visible = false; scene.add(sp); mists.push({ sp, life: 0, max: 1, s0: 1, s1: 2, vy: 0.4 });
  }
  const FOAM_N = 10, foams = [];
  for (let i = 0; i < FOAM_N; i++) {
    const m = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: TEX_FOAM, color: 0xffffff, transparent: true, opacity: 0, depthWrite: false }));
    m.rotation.x = -Math.PI / 2; m.visible = false; scene.add(m); foams.push({ m, life: 0, max: 1, s0: 0.3, s1: 2 });
  }
  const glint = new THREE.Sprite(new THREE.SpriteMaterial({ map: TEX_GLINT, color: 0xfff7d6, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
  glint.visible = false; scene.add(glint);
  let glintLife = 0, mistCur = 0, foamCur = 0;

  function spawnSplash(x, y, z, n, power, spread) {
    for (let k = 0; k < n; k++) {
      const i = splCursor; splCursor = (splCursor + 1) % SPL;
      const crown = k % 3 === 0; // sebagian tetes membentuk mahkota cipratan
      const a = Math.random() * Math.PI * 2, sp = (spread || 1) * (crown ? rand(0.9, 1.4) : rand(0.2, 1.1)) * power;
      splPos[i * 3] = x + Math.cos(a) * 0.05; splPos[i * 3 + 1] = y; splPos[i * 3 + 2] = z + Math.sin(a) * 0.05;
      splVel[i * 3] = Math.cos(a) * sp; splVel[i * 3 + 1] = (crown ? rand(1.8, 3.2) : rand(1.0, 2.4)) * power; splVel[i * 3 + 2] = Math.sin(a) * sp;
      splLife[i] = splMax[i] = rand(0.55, 1.15);
      splSize[i] = rand(0.05, 0.13) * (0.7 + power * 0.35);
    }
    if (n >= 8) {
      const f = foams[foamCur]; foamCur = (foamCur + 1) % FOAM_N;
      f.life = f.max = 0.9 + power * 0.5; f.s0 = 0.25 + power * 0.15; f.s1 = 1.2 + power * 1.4;
      f.m.position.set(x, waveH(x, z, G.time) + 0.03, z); f.m.visible = true;
      const mc = Math.min(3, 1 + Math.floor(power * 1.5));
      for (let k = 0; k < mc; k++) {
        const m = mists[mistCur]; mistCur = (mistCur + 1) % MIST_N;
        m.life = m.max = rand(0.6, 1.0) + power * 0.25; m.s0 = 0.3 + power * 0.2; m.s1 = 1.1 + power * 1.1; m.vy = rand(0.3, 0.8);
        m.sp.position.set(x + rand(-0.15, 0.15), y + 0.15 + k * 0.1, z + rand(-0.15, 0.15));
        m.sp.material.rotation = rand(0, Math.PI * 2); m.sp.visible = true;
      }
    }
    if (power >= 1.2) { glintLife = 0.35; glint.position.set(x, y + 0.5, z); glint.visible = true; }
  }
  /* Plankton bercahaya */
  const PK = 160, pkPos = new Float32Array(PK * 3), pkBase = [], pkCol = new Float32Array(PK * 3);
  const pkPalette = [new THREE.Color(0xfde68a), new THREE.Color(0x67e8f9), new THREE.Color(0xf9a8d4), new THREE.Color(0x86efac)];
  for (let i = 0; i < PK; i++) {
    pkBase.push([rand(-14, 14), rand(0.25, 4), rand(-34, 1), rand(0, 6.28)]);
    const c = pkPalette[i % 4]; pkCol[i * 3] = c.r; pkCol[i * 3 + 1] = c.g; pkCol[i * 3 + 2] = c.b;
  }
  const pkGeo = new THREE.BufferGeometry();
  pkGeo.setAttribute('position', new THREE.BufferAttribute(pkPos, 3));
  pkGeo.setAttribute('color', new THREE.BufferAttribute(pkCol, 3));
  const plankton = new THREE.Points(pkGeo, new THREE.PointsMaterial({ size: 0.07, map: dotTexture(), vertexColors: true, transparent: true, opacity: 0.8, depthWrite: false, blending: THREE.AdditiveBlending }));
  plankton.frustumCulled = false; scene.add(plankton);

  /* Model tangkapan di dunia (lompatan & pendaratan) */
  let catchModel = null;
  const tmpV = new THREE.Vector3(), tipV = new THREE.Vector3(), endV = new THREE.Vector3(), ctrlV = new THREE.Vector3();
  let feedTimer = 1.5;

  function setCatch(spKey, weight) {
    clearCatch();
    catchModel = buildModel(spKey, false);
    const s = spKey === 'chest' ? 0.75 : clamp(0.35 + weight * 0.065, 0.38, 1.0);
    catchModel.group.scale.setScalar(s);
    catchModel.group.rotation.order = 'YZX';
    catchModel.group.visible = false;
    scene.add(catchModel.group);
  }
  function clearCatch() {
    if (catchModel) { scene.remove(catchModel.group); disposeTree(catchModel.group); catchModel = null; }
  }
  function addRipple(x, z) { ripples[rippleIdx % 4].set(x, z, G.time); rippleIdx++; }

  function resize() {
    const w = stage.clientWidth, h = stage.clientHeight;
    if (!w || !h) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h; camera.updateProjectionMatrix();
  }
  resize();
  if (window.ResizeObserver) new ResizeObserver(resize).observe(stage); else window.addEventListener('resize', resize);

  function lineTo(p0, p2, sag) {
    const arr = lineGeo.attributes.position.array;
    ctrlV.copy(p0).add(p2).multiplyScalar(0.5); ctrlV.y -= sag;
    for (let i = 0; i < LINE_N; i++) {
      const t = i / (LINE_N - 1), a = (1 - t) * (1 - t), b = 2 * (1 - t) * t, c = t * t;
      arr[i * 3] = a * p0.x + b * ctrlV.x + c * p2.x;
      arr[i * 3 + 1] = a * p0.y + b * ctrlV.y + c * p2.y;
      arr[i * 3 + 2] = a * p0.z + b * ctrlV.z + c * p2.z;
    }
    lineGeo.attributes.position.needsUpdate = true;
  }

  function update(dt) {
    const t = G.time;
    waterU.uTime.value = t;

    /* Perahu terayun ombak */
    const bh = waveH(0, -0.4, t) * 0.7;
    const [gx, gz] = waveGrad(0, -0.4, t);
    boat.position.y = bh + 0.1;
    boat.rotation.x = gz * 0.35; boat.rotation.z = -gx * 0.5;

    /* Pelampung & target senar */
    const B = G.bob;
    let lineEnd = null, sag = 0.05;
    rod.position.set(0.5, 1.1 + bh * 0.9, 2.15);
    rod.updateMatrixWorld(true);
    rodParts.tip.getWorldPosition(tipV);

    if (B.mode === 'hang') {
      bobber.visible = true;
      const swing = G.state === 'aiming' ? -0.25 * G.power / 100 : 0;
      bobber.position.set(tipV.x + Math.sin(t * 2) * 0.03, tipV.y - 0.55, tipV.z + 0.15 - swing);
      bobber.rotation.set(0, 0, 0);
      lineEnd = endV.copy(bobber.position); lineEnd.y += 0.18; sag = 0.0;
    } else if (B.mode === 'fly') {
      bobber.visible = true;
      const k = B.flyT;
      bobber.position.set(lerp(B.fx, B.x, k), lerp(B.fy, waveH(B.x, B.z, t), k) + 4 * B.arc * k * (1 - k), lerp(B.fz, B.z, k));
      bobber.rotation.x += dt * 8;
      lineEnd = endV.copy(bobber.position); sag = 0.15 * k;
    } else if (B.mode === 'float' || B.mode === 'plunge') {
      bobber.visible = true;
      const wy = waveH(B.x, B.z, t);
      const target = B.mode === 'plunge' ? wy - 0.32 : wy + 0.015 - B.dip;
      bobber.position.y = lerp(bobber.position.y, target, damp(dt, B.mode === 'plunge' ? 18 : 10));
      bobber.position.x = B.x; bobber.position.z = B.z;
      bobber.rotation.set(Math.sin(t * 1.3) * 0.08 + (B.mode === 'plunge' ? 0.5 : 0), 0, Math.cos(t * 1.1) * 0.08);
      lineEnd = endV.copy(bobber.position); lineEnd.y += 0.12;
      sag = Math.min(1.2, tipV.distanceTo(lineEnd) * 0.05);
    } else {
      bobber.visible = false;
    }

    /* Ikan saat bertarung */
    const F = G.fight;
    if (G.state === 'fighting') {
      const ez = -(2.8 + F.dist * 0.75), ex = F.x;
      const ey = waveH(ex, ez, t);
      waterU.uEntry.value.set(ex, ez, 1);
      lineEnd = endV.set(ex, ey - 0.05, ez);
      sag = lerp(0.9, 0.02, F.tension / 100) * Math.min(1, F.dist / 10 + 0.2);
      if (F.leap > 0 && catchModel) {
        const k = 1 - F.leap / F.leapDur;
        catchModel.group.visible = true;
        catchModel.group.position.set(ex + F.leapDx * k, ey - 0.15 + Math.sin(k * Math.PI) * 0.85, ez);
        catchModel.group.rotation.y = F.leapDx > 0 ? 0 : Math.PI;
        catchModel.group.rotation.z = Math.cos(k * Math.PI) * 0.9;
        catchModel.update(t, 0.09);
        if (Math.random() < 0.5) spawnSplash(catchModel.group.position.x, catchModel.group.position.y, catchModel.group.position.z, 1, 0.4, 0.4);
      } else if (catchModel && G.state === 'fighting') {
        catchModel.group.visible = false;
      }
      if (Math.random() < dt * (F.surge > 0 ? 18 : 4)) spawnSplash(ex + rand(-0.2, 0.2), ey, ez + rand(-0.2, 0.2), 1, F.surge > 0 ? 0.8 : 0.35, 0.6);
    } else {
      waterU.uEntry.value.z = 0;
    }

    /* Pendaratan: ikan melompat keluar air dengan slow-motion */
    const L = G.land;
    if (G.state === 'landing' && catchModel) {
      const s = L.s;
      const g = catchModel.group;
      g.visible = true;
      if (catchModel.isChest) {
        g.position.set(lerp(L.x0, 0, s), lerp(-0.6, 1.25, Math.sin(s * Math.PI / 2)), lerp(L.z0, -2.4, s));
        g.rotation.y = s * Math.PI * 1.5; g.rotation.z = 0;
        catchModel.update(t);
      } else {
        const px = lerp(L.x0, L.x0 * 0.4, s), pz = lerp(L.z0, -1.9, s), py = -0.1 + Math.sin(s * Math.PI) * 2.3;
        const vy = Math.cos(s * Math.PI);
        g.position.set(px, py, pz);
        g.rotation.y = Math.atan2(-(-1.9 - L.z0), (L.x0 * 0.4 - L.x0)) ;
        g.rotation.z = vy * 1.1;
        catchModel.update(t * (G.slowmo ? 0.4 : 1) * 3, 0.1);
        if (s < 0.5 && Math.random() < 0.7) spawnSplash(px, py, pz, 1, 0.3, 0.3);
      }
      lineEnd = endV.copy(g.position);
      sag = 0.05;
    }

    /* Joran: arah & lengkungan */
    const aimed = lineEnd && B.mode !== 'hang';
    const aim = aimed ? lineEnd : tmpV.set(0, 0, -12);
    const yaw = Math.atan2(-(aim.x - rod.position.x), -(aim.z - rod.position.z));
    rod.rotation.y = lerp(rod.rotation.y, clamp(yaw * 0.6, -0.45, 0.3), damp(dt, 6));
    const bend = G.state === 'fighting' ? 0.12 + F.tension / 100 * 0.9 : (G.state === 'strike' ? 0.45 : (B.mode === 'float' ? 0.12 : 0.04));
    G.rodBend = lerp(G.rodBend || 0, bend, damp(dt, 8));
    rodParts.segs.forEach((s, i) => {
      s.rotation.x = -G.rodBend * (0.08 + i * 0.12) + (G.state === 'fighting' ? Math.sin(t * 20) * 0.004 * F.tension / 100 : 0);
      s.rotation.z = s.userData.lukZ || 0;
    });
    rodParts.update(t);
    rod.updateMatrixWorld(true);
    rodParts.tip.getWorldPosition(tipV);

    if (lineEnd) { fishLine.visible = true; lineTo(tipV, lineEnd, sag); } else fishLine.visible = false;
    waterU.uBobber.value.set(B.x, B.z, (B.mode === 'float' || B.mode === 'plunge') ? 1 : 0, 0);

    /* Riak ikan makan: cipratan kecil sesekali */
    for (let i = 0; i < ambient.length; i++) ambient[i](t, dt);

    feedTimer -= dt;
    if (feedTimer <= 0) {
      const f = FEED_SPOTS[Math.floor(Math.random() * FEED_SPOTS.length)];
      spawnSplash(f.x + rand(-0.3, 0.3), 0.02, -f.d + rand(-0.3, 0.3), 5, 0.55, 0.6);
      feedTimer = rand(1.2, 3.2);
    }
    if ((B.mode === 'float') && Math.random() < dt * 2) spawnSplash(B.x + rand(-0.15, 0.15), waveH(B.x, B.z, t), B.z + rand(-0.15, 0.15), 1, 0.2, 0.3);

    /* Fisika cipratan */
    splMat.uniforms.uScale.value = renderer.domElement.height / (2 * Math.tan(camera.fov * Math.PI / 360));
    for (let i = 0; i < SPL; i++) {
      if (splLife[i] <= 0) continue;
      splLife[i] -= dt;
      splVel[i * 3 + 1] -= 9.8 * dt;
      splVel[i * 3] *= 0.995; splVel[i * 3 + 2] *= 0.995;
      splPos[i * 3] += splVel[i * 3] * dt; splPos[i * 3 + 1] += splVel[i * 3 + 1] * dt; splPos[i * 3 + 2] += splVel[i * 3 + 2] * dt;
      splAlpha[i] = clamp(splLife[i] / splMax[i] * 1.6, 0, 0.95);
      if (splLife[i] <= 0 || splPos[i * 3 + 1] < waveH(splPos[i * 3], splPos[i * 3 + 2], t) - 0.05) { splLife[i] = 0; splAlpha[i] = 0; splPos[i * 3 + 1] = -999; }
    }
    splGeo.attributes.position.needsUpdate = true;
    splGeo.attributes.aAlpha.needsUpdate = true;
    splGeo.attributes.aSize.needsUpdate = true;
    mists.forEach((m) => {
      if (m.life <= 0) return;
      m.life -= dt;
      const k = 1 - Math.max(0, m.life) / m.max;
      const sc = lerp(m.s0, m.s1, 1 - Math.pow(1 - k, 2));
      m.sp.scale.set(sc, sc, 1);
      m.sp.position.y += m.vy * dt * (1 - k);
      m.sp.material.opacity = 0.5 * Math.sin(Math.PI * Math.min(1, k * 1.15));
      if (m.life <= 0) { m.sp.visible = false; m.sp.material.opacity = 0; }
    });
    foams.forEach((f) => {
      if (f.life <= 0) return;
      f.life -= dt;
      const k = 1 - Math.max(0, f.life) / f.max;
      const sc = lerp(f.s0, f.s1, 1 - Math.pow(1 - k, 3));
      f.m.scale.set(sc, sc, 1);
      f.m.position.y = waveH(f.m.position.x, f.m.position.z, t) + 0.03;
      f.m.material.opacity = 0.75 * (1 - k);
      if (f.life <= 0) { f.m.visible = false; }
    });
    if (glintLife > 0) {
      glintLife -= dt;
      const k = 1 - glintLife / 0.35, sc = 0.6 + k * 1.8;
      glint.scale.set(sc, sc, 1); glint.material.opacity = 0.9 * (1 - k);
      if (glintLife <= 0) glint.visible = false;
    }

    /* Plankton bercahaya melayang */
    for (let i = 0; i < PK; i++) {
      const b = pkBase[i];
      pkPos[i * 3] = b[0] + Math.sin(t * 0.3 + b[3]) * 0.4;
      pkPos[i * 3 + 1] = b[1] + Math.sin(t * 0.5 + b[3] * 2) * 0.25;
      pkPos[i * 3 + 2] = b[2] + Math.cos(t * 0.25 + b[3]) * 0.4;
    }
    pkGeo.attributes.position.needsUpdate = true;
    plankton.material.opacity = 0.55 + 0.25 * Math.sin(t * 1.7);

    /* Kamera sinematik per fase */
    let camPos = CAM_BASE, look = tmpV.set(0, 0.4, -14), fov = 55;
    const camTmp = new THREE.Vector3();
    if (G.state === 'casting' && B.mode === 'fly') { look = tmpV.copy(bobber.position).lerp(new THREE.Vector3(0, 0.4, -14), 0.4); fov = 50; }
    else if (B.mode === 'float' || B.mode === 'plunge') {
      camPos = camTmp.set(B.x * 0.75 + 0.5, 1.45, B.z + 4.6);
      look = tmpV.set(B.x, 0.05, B.z); fov = 40;
    } else if (G.state === 'fighting') {
      look = tmpV.set(F.x, 0, -(2.8 + F.dist * 0.75)); fov = 46;
    } else if (G.state === 'landing' && catchModel) {
      look = tmpV.copy(catchModel.group.position); fov = 42;
    }
    camera.position.lerp(camPos, damp(dt, 2.2));
    camera.position.y += bh * 0.25 * dt * 10 * 0.1;
    camLook.lerp(look, damp(dt, 3.5));
    camera.lookAt(camLook);
    if (Math.abs(camera.fov - fov) > 0.05) { camera.fov = lerp(camera.fov, fov, damp(dt, 2.5)); camera.updateProjectionMatrix(); }
    sky.position.copy(camera.position);

    renderer.render(scene, camera);
  }

  return {
    update, spawnSplash, addRipple, setCatch, clearCatch, setRodSkin,
    waveH: (x, z) => waveH(x, z, G.time),
    tipPos: () => tipV.clone()
  };
}

/* Dunia "kosong" untuk perangkat tanpa WebGL: logika tetap berjalan */
function createNullWorld() {
  return { update() {}, spawnSplash() {}, addRipple() {}, setCatch() {}, clearCatch() {}, setRodSkin() {}, waveH: () => 0, tipPos: () => ({ x: 0, y: 1.6, z: 1.5 }) };
}

/* ───────────────────────── SHOWCASE 3D (studio) ───────────────────────── */
const Showcase = (() => {
  let renderer = null, scene = null, cam = null, holder = null, model = null, ok = HAS_GL;
  function init() {
    if (renderer || !ok) return;
    const canvas = $('show-canvas');
    try {
      renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
    } catch (e) { ok = false; $('show-stage').classList.add('no-gl'); return; }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    scene = new THREE.Scene();
    cam = new THREE.PerspectiveCamera(30, 1, 0.05, 50);
    cam.position.set(0, 0.25, 2.4); cam.lookAt(0, 0, 0);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x7dd3fc, 0.65));
    const key = new THREE.DirectionalLight(0xfff1d6, 1.25); key.position.set(2, 2.2, 2); scene.add(key);
    const rim = new THREE.DirectionalLight(0x93c5fd, 1.0); rim.position.set(-2, 1, -2); scene.add(rim);
    const fill = new THREE.DirectionalLight(0xfda4af, 0.45); fill.position.set(-1.5, -0.6, 2); scene.add(fill);
    holder = new THREE.Group(); scene.add(holder);
  }
  function resize() {
    if (!renderer) return;
    const st = $('show-stage'), w = st.clientWidth, h = st.clientHeight;
    renderer.setSize(w, h, false); cam.aspect = w / h; cam.updateProjectionMatrix();
  }
  function show(spKey) {
    if (!ok) { $('show-stage').classList.add('no-gl'); return; }
    init(); if (!ok) return;
    if (model) { holder.remove(model.group); disposeTree(model.group); }
    model = buildModel(spKey, true);
    model.group.scale.setScalar(spKey === 'chest' ? 1.05 : 1.45);
    model.group.position.y = spKey === 'chest' ? -0.12 : 0;
    holder.rotation.set(0.15, -0.6, 0);
    holder.add(model.group);
    resize();
  }
  function frame(dt, t) {
    if (!renderer || !model) return;
    holder.rotation.y += dt * 0.9;
    model.update(t, 0.035);
    renderer.render(scene, cam);
  }
  return { show, frame };
})();

/* ───────────────────────── EMBER CUAN 3D (INVENTORY) ─────────────────────────
   Korsel 3D: semua tangkapan hari ini berdiri di atas alas karang,
   mengelilingi keranjang anyaman bambu. Geser / tombol panah untuk memilih. */
const Inventory = (() => {
  const items = (CFG.catches || []).slice();
  let renderer = null, scene = null, cam = null, ring = null, ok = HAS_GL, dirty = true, isOpen = false;
  let entries = [], idx = 0, rot = 0, targetRot = 0, drag = null;

  function buildBasket() {
    const g = new THREE.Group();
    const weave = canvasTex('weave', 128, 128, (c, w, h) => {
      c.fillStyle = '#c99a5b'; c.fillRect(0, 0, w, h);
      for (let y = 0; y < h; y += 16) for (let x = 0; x < w; x += 16) {
        const alt = ((x + y) / 16) % 2;
        c.fillStyle = alt ? '#b5844a' : '#deb27a';
        c.fillRect(x + 1, y + 1, 14, 14);
        c.fillStyle = 'rgba(80,45,15,0.35)'; c.fillRect(x, y + (alt ? 7 : 0), 16, 2);
      }
    }, true);
    weave.repeat.set(6, 2);
    const mat = new THREE.MeshPhongMaterial({ map: weave, side: THREE.DoubleSide, shininess: 10 });
    const body = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.38, 0.45, 28, 1, true), mat);
    body.position.y = 0.225; g.add(body);
    const bottom = new THREE.Mesh(new THREE.CircleGeometry(0.38, 28), mat); bottom.rotation.x = -Math.PI / 2; bottom.position.y = 0.01; g.add(bottom);
    const rimG = new THREE.TorusGeometry(0.5, 0.03, 8, 40); rimG.rotateX(Math.PI / 2);
    const rim = new THREE.Mesh(rimG, new THREE.MeshPhongMaterial({ color: 0x8a5a2b, shininess: 20 })); rim.position.y = 0.45; g.add(rim);
    const water = new THREE.Mesh(new THREE.CircleGeometry(0.47, 28), new THREE.MeshPhongMaterial({ color: 0x22d3ee, emissive: 0x0e7490, emissiveIntensity: 0.35, transparent: true, opacity: 0.85, shininess: 120, specular: 0xffffff }));
    water.rotation.x = -Math.PI / 2; water.position.y = 0.36; g.add(water);
    const handleG = new THREE.TorusGeometry(0.42, 0.025, 8, 24, Math.PI);
    const handle = new THREE.Mesh(handleG, new THREE.MeshPhongMaterial({ color: 0x8a5a2b }));
    handle.position.y = 0.45; handle.rotation.y = 0.55; g.add(handle);
    g.userData.water = water;
    return g;
  }
  function pedestal() {
    const g = new THREE.Group();
    const rock = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.26, 0.1, 14), new THREE.MeshPhongMaterial({ color: 0xfde7c8, shininess: 15 }));
    rock.position.y = 0.05; g.add(rock);
    const coral = new THREE.MeshPhongMaterial({ color: 0xfb7185 });
    [[0.17, 0.4], [-0.15, -0.5]].forEach(([x, a]) => {
      const c = new THREE.Mesh(new THREE.CylinderGeometry(0.01, 0.015, 0.12, 6), coral);
      c.position.set(x, 0.14, 0.05); c.rotation.z = a; g.add(c);
    });
    return g;
  }
  function init() {
    if (renderer || !ok) return;
    try { renderer = new THREE.WebGLRenderer({ canvas: $('inv-canvas'), alpha: true, antialias: true }); }
    catch (e) { ok = false; $('inv-stage').classList.add('no-gl'); return; }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    scene = new THREE.Scene();
    cam = new THREE.PerspectiveCamera(34, 1, 0.05, 50);
    cam.position.set(0, 1.05, 4.1); cam.lookAt(0, 0.3, 0.4);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x0e7490, 0.7));
    const key = new THREE.DirectionalLight(0xfff1d6, 1.15); key.position.set(2, 3, 2.5); scene.add(key);
    const rim = new THREE.DirectionalLight(0x93c5fd, 0.8); rim.position.set(-2, 1.5, -2); scene.add(rim);
    scene.add(buildBasket());
    ring = new THREE.Group(); scene.add(ring);
    const cv = $('inv-canvas');
    cv.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, moved: false }; try { cv.setPointerCapture(e.pointerId); } catch (_) {} });
    cv.addEventListener('pointermove', (e) => {
      if (!drag || entries.length < 2) return;
      const dx = e.clientX - drag.x;
      if (Math.abs(dx) > 40) { select(idx + (dx < 0 ? 1 : -1)); drag.x = e.clientX; drag.moved = true; }
    });
    ['pointerup', 'pointercancel'].forEach((ev) => cv.addEventListener(ev, () => { drag = null; }));
  }
  function rebuild() {
    if (!ok || !ring) return;
    entries.forEach((en) => { ring.remove(en.holder); disposeTree(en.holder); });
    entries = [];
    const n = Math.min(items.length, 12);
    const R = n <= 1 ? 1.05 : Math.max(1.25, n * 0.24);
    for (let i = 0; i < n; i++) {
      const it = items[i];
      const spKey = BADGE_SPECIES[it.badge] || 'mujair';
      const model = buildModel(spKey, false);
      const holder = new THREE.Group();
      const a = (i / n) * Math.PI * 2;
      holder.position.set(Math.sin(a) * R, 0, Math.cos(a) * R);
      holder.rotation.y = a;
      holder.add(pedestal());
      model.group.scale.setScalar(spKey === 'chest' ? 0.42 : 0.62);
      model.group.position.y = spKey === 'chest' ? 0.2 : 0.3;
      holder.add(model.group);
      ring.add(holder);
      entries.push({ holder, model, a });
    }
    dirty = false;
  }
  /* ── Pasar Ikan: jual ikan dari Ember (harga dihitung server) ── */
  const sel = new Set();
  let busy = false;
  const SHORT = { biasa: 'Mujair', bagus: 'Gurame', langka: 'Kakap', super: 'Barramundi', jackpot: 'Peti Harta' };
  const cleanName = (n) => String(n || '').replace(/^Ikan\s+/i, '').replace(/[^\p{L}\p{N}\s()\/.-]/gu, '').trim();
  function renderMarket() {
    const board = $('mk-board'); board.innerHTML = '';
    BADGES.forEach((b) => {
      const m = (CFG.market || {})[b] || { mult: 1, trend: 'flat' };
      const ic = m.trend === 'up' ? 'ph-trend-up' : (m.trend === 'down' ? 'ph-trend-down' : 'ph-minus');
      const th = fishThumb(b);
      const cell = document.createElement('div'); cell.className = 'mk-cell';
      cell.innerHTML = (th ? '<span class="mk-thumb rt-' + b + '"><img src="' + th + '" alt=""></span>' : '') +
        '<small>' + SHORT[b] + '</small><b class="tr-' + m.trend + '"><i class="ph-bold ' + ic + '"></i>' + Math.round(m.mult * 100) + '%</b>';
      board.appendChild(cell);
    });
  }
  function renderGrid() {
    const grid = $('fish-grid'); grid.innerHTML = '';
    $('fish-count-lbl').textContent = items.length + ' ikan di Ember';
    $('btn-sel-all').style.display = items.length ? '' : 'none';
    if (!items.length) grid.innerHTML = '<div class="fish-empty">Ember kosong. Lempar kail dulu, lalu jual hasilnya di sini!</div>';
    items.forEach((f, i) => {
      const t = document.createElement('button'); t.type = 'button';
      t.className = 'fish-tile' + (sel.has(f.id) ? ' sel' : ''); t.dataset.id = f.id;
      const th = fishThumb(f.badge);
      t.innerHTML = '<span class="ft-check"><i class="ph-bold ph-check"></i></span><span class="ft-rar rb-' + f.badge + '">' + BADGE_LABEL[f.badge] + '</span>'
        + (th ? '<span class="ft-thumb rt-' + f.badge + '"><img src="' + th + '" alt=""></span>' : '<span class="m-ico rb-' + f.badge + '"><i class="ph-fill ph-fish-simple"></i></span>')
        + '<span class="ft-name"></span><span class="ft-w">' + Number(f.weight).toFixed(2) + ' Kg</span><span class="ft-p">' + fmtRp(f.reward) + '</span>';
      t.querySelector('.ft-name').textContent = cleanName(f.name);
      t.title = f.name + ' · ' + f.time;
      t.addEventListener('click', () => {
        if (sel.has(f.id)) sel.delete(f.id); else sel.add(f.id);
        t.classList.toggle('sel'); Sfx.tick(); syncSell();
        if (i < 12) select(i);
      });
      grid.appendChild(t);
    });
    syncSell();
  }
  function syncSell() {
    let total = 0; items.forEach((f) => { if (sel.has(f.id)) total += Number(f.reward) || 0; });
    $('sell-sel-lbl').textContent = sel.size + ' dipilih';
    $('sell-total').textContent = fmtRp(total);
    $('btn-sell').disabled = !sel.size || busy;
    $('btn-sel-all').textContent = sel.size && sel.size === items.length ? 'Batal Pilih' : 'Pilih Semua';
  }
  function toggleAll() {
    if (sel.size === items.length) sel.clear(); else items.forEach((f) => sel.add(f.id));
    renderGrid();
  }
  function sell() {
    if (!sel.size || busy) return;
    busy = true; syncSell();
    const ids = Array.from(sel);
    MancingAPI.post('sell_fish', { ids: ids.join(',') }).then((res) => {
      if (!res.ok) { toast(res.msg, 'error'); return; }
      const sold = res.sold_ids || [];
      Sfx.cash(); Haptic.buzz([30, 30, 60]);
      document.querySelectorAll('#fish-grid .fish-tile').forEach((el) => { if (sold.includes(Number(el.dataset.id))) el.classList.add('gone'); });
      let soldValue = 0;
      items.forEach((f) => { if (sold.includes(f.id)) soldValue += Number(f.reward) || 0; });
      for (let i = items.length - 1; i >= 0; i--) if (sold.includes(items[i].id)) items.splice(i, 1);
      sold.forEach((id) => sel.delete(id));
      G.todayTotal = Math.max(0, G.todayTotal - soldValue);
      $('hud-cuan-val').textContent = fmtRp(G.todayTotal);
      $('hud-inv-count').textContent = items.length;
      flyCoins($('btn-sell'), $('sell-wallet'), Math.min(12, 4 + sold.length), () => { $('sell-wallet').textContent = res.balance_wd; });
      toast(res.msg + ' Saldo Tarik: ' + res.balance_wd, 'success');
      setTimeout(() => {
        dirty = true;
        if (ok && ring) rebuild();
        idx = 0; targetRot = rot = 0;
        $('inv-stage').classList.toggle('is-empty', items.length === 0);
        if (items.length) select(0); else { $('inv-name').textContent = 'Ember kosong'; $('inv-meta').textContent = 'Semua ikan sudah terjual'; }
        renderGrid();
      }, 500);
    }).catch(() => toast('Gagal terhubung ke pasar. Coba lagi ya.', 'error'))
      .finally(() => { busy = false; syncSell(); });
  }
  function select(i) {
    const n = Math.min(items.length, 12);
    if (!n) return;
    idx = ((i % n) + n) % n;
    const want = -(idx / n) * Math.PI * 2;
    let d = want - targetRot;
    d = Math.atan2(Math.sin(d), Math.cos(d));
    targetRot += d;
    const it = items[idx];
    $('inv-name').textContent = String(it.name || '').replace(/[^\p{L}\p{N}\s()\/.-]/gu, '').trim();
    $('inv-meta').textContent = BADGE_LABEL[it.badge] + ' · ' + Number(it.weight).toFixed(2) + ' Kg · ' + fmtRp(it.reward) + ' · ' + it.time;
    const b = $('inv-badge'); b.className = 'show-badge rb-' + it.badge; b.textContent = BADGE_LABEL[it.badge];
    $('inv-pos').textContent = (idx + 1) + ' / ' + n;
  }
  function resize() {
    if (!renderer) return;
    const st = $('inv-stage'), w = st.clientWidth, h = st.clientHeight;
    renderer.setSize(w, h, false); cam.aspect = w / h; cam.updateProjectionMatrix();
  }
  return {
    get isOpen() { return isOpen; },
    add(it) { items.unshift(it); dirty = true; },
    count() { return items.length; },
    open() {
      isOpen = true;
      $('mc-inv').classList.add('open');
      const st = $('inv-stage');
      st.classList.toggle('is-empty', items.length === 0);
      if (!ok) st.classList.add('no-gl');
      init();
      if (dirty) rebuild();
      idx = 0; targetRot = rot = 0;
      renderMarket(); renderGrid();
      if (items.length) select(0); else { $('inv-name').textContent = 'Belum ada tangkapan'; $('inv-meta').textContent = 'Lempar kail untuk mengisi ember'; }
      resize();
    },
    close() { isOpen = false; $('mc-inv').classList.remove('open'); },
    prev() { select(idx - 1); },
    sell,
    toggleAll,
    next() { select(idx + 1); },
    frame(dt, t) {
      if (!renderer) return;
      rot += (targetRot - rot) * damp(dt, 6);
      ring.rotation.y = rot;
      entries.forEach((en, i) => {
        const front = i === idx;
        en.model.update(t + i, front ? 0.05 : 0.025);
        en.model.group.rotation.y = front ? Math.sin(t * 0.8) * 0.5 : 0;
        en.model.group.position.y = (en.model.isChest ? 0.2 : 0.3) + (front ? Math.sin(t * 2) * 0.03 : 0);
      });
      renderer.render(scene, cam);
    }
  };
})();

const ROD_ICON_SVG = '<svg viewBox="0 0 32 32" fill="none"><path d="M5 28L26 5" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><circle cx="11.5" cy="21.5" r="3.4" fill="#fde68a" stroke="#fff" stroke-width="1.2"/><circle cx="26" cy="20" r="2.4" fill="#fb7185"/></svg>';

/* ───────────────────────── KOLEKSI JORAN (UI + PREVIEW 3D) ───────────────────────── */
const RodCollection = (() => {
  const rods = (CFG.rods || []).slice();
  let renderer = null, scene = null, cam = null, holder = null, current = null, ok = HAS_GL, isOpen = false, viewKey = null;
  function find(key) { return rods.find((r) => r.key === key) || rods[0]; }
  function init() {
    if (renderer || !ok) return;
    try { renderer = new THREE.WebGLRenderer({ canvas: $('rod-canvas'), alpha: true, antialias: true }); }
    catch (e) { ok = false; $('rod-stage').classList.add('no-gl'); return; }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    scene = new THREE.Scene();
    cam = new THREE.PerspectiveCamera(32, 1, 0.05, 50);
    cam.position.set(0, 0.05, 4.0); cam.lookAt(0, 0, 0);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x7dd3fc, 0.7));
    const key = new THREE.DirectionalLight(0xfff1d6, 1.2); key.position.set(2, 3, 3); scene.add(key);
    const rim = new THREE.DirectionalLight(0xc4b5fd, 0.9); rim.position.set(-3, 1, -2); scene.add(rim);
    holder = new THREE.Group(); scene.add(holder);
  }
  function resize() {
    if (!renderer) return;
    const st = $('rod-stage'), w = st.clientWidth, h = st.clientHeight;
    renderer.setSize(w, h, false); cam.aspect = w / h; cam.updateProjectionMatrix();
  }
  function preview(key) {
    viewKey = key;
    const r = find(key);
    $('rod-name').textContent = r.name;
    $('rod-desc').textContent = r.desc;
    const b = $('rod-badge'); b.className = 'show-badge rb-' + r.rarity; b.textContent = BADGE_LABEL[r.rarity] || r.rarity;
    $('rod-reel').style.width = Math.min(100, 20 + r.reel * 2.2) + '%';
    $('rod-bite').style.width = Math.min(100, 20 + r.bite * 2) + '%';
    $('rod-reel-txt').textContent = '+' + r.reel + '%';
    $('rod-bite-txt').textContent = r.bite ? '-' + r.bite + '% tunggu' : 'Normal';
    const act = $('rod-action');
    act.disabled = false;
    act.className = 'btn-save rod-action';
    if (!r.unlocked) {
      act.innerHTML = '<i class="ph-fill ph-lock-simple"></i> ' + r.req;
      act.classList.add('is-locked');
      act.dataset.mode = r.needUpgrade ? 'upgrade' : 'locked';
    } else if (r.key === G.rodKey) {
      act.innerHTML = '<i class="ph-fill ph-check-circle"></i> Sedang Dipakai';
      act.classList.add('is-active'); act.dataset.mode = 'active';
    } else {
      act.innerHTML = '<i class="ph-fill ph-hand-grabbing"></i> Pakai Joran Ini';
      act.dataset.mode = 'select';
    }
    document.querySelectorAll('.rod-card').forEach((c) => c.classList.toggle('viewing', c.dataset.key === key));
    $('rod-stage').classList.toggle('is-locked', !r.unlocked);
    if (!ok) return;
    init();
    if (current) { holder.remove(current.root); disposeTree(current.root); }
    current = buildRod(key);
    // gagang condong ke arah kamera agar ornamen di pangkal terlihat jelas
    current.root.rotation.set(-1.0, 0, -0.95);
    current.root.scale.setScalar(1.35);
    current.root.position.set(-1.05, -0.62, 1.25);
    holder.add(current.root);
    resize();
  }
  /* Ikon mini khas tiap joran (SVG putih) */
  const ROD_GLYPH = {
    bambu: '<svg viewBox="0 0 32 32" fill="none"><path d="M6 27L26 5" stroke="#fff" stroke-width="3.4" stroke-linecap="round"/><path d="M10 20l3 3M15 15l3 3M20 10l3 3" stroke="#fde68a" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="24" r="3.6" fill="#fff" opacity=".85"/></svg>',
    rotan: '<svg viewBox="0 0 32 32" fill="none"><path d="M26 5L12 23c-2 3-6 4-7 1s2-3 3-1" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/><path d="M26 5l4 2-4 2z" fill="#fff"/><path d="M26 7l4 0-4 2z" fill="#fecaca"/></svg>',
    keris: '<svg viewBox="0 0 32 32" fill="none"><path d="M10 22c2-2 1-4 3-6s3-1 4-3 1-4 3-5 3-2 5-4" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="M5 21c3 1 6 3 8 6" stroke="#fde68a" stroke-width="3" stroke-linecap="round"/><circle cx="6" cy="27" r="2.6" fill="#fff"/></svg>',
    batik: '<svg viewBox="0 0 32 32" fill="none"><path d="M6 26L26 5" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><path d="M12 19v5" stroke="#fde68a" stroke-width="1.6"/><circle cx="12" cy="25" r="1.6" fill="#fde68a"/><path d="M9.5 26.5h5l-1 4h-3z" fill="#fff"/><circle cx="8" cy="24" r="3" fill="#fde68a"/></svg>',
    wayang: '<svg viewBox="0 0 32 32" fill="none"><path d="M16 4c-6 6-8 12-7 22h14c1-10-1-16-7-22z" fill="#fff"/><circle cx="16" cy="16" r="3" fill="#fbbf24"/><path d="M12 22h8" stroke="#fbbf24" stroke-width="1.6"/></svg>',
    naga: '<svg viewBox="0 0 32 32" fill="none"><path d="M6 27L26 5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><path d="M8 21c4 1 3-4 7-3s2-5 6-4 2-4 5-3" stroke="#bbf7d0" stroke-width="2.4" stroke-linecap="round"/><circle cx="26" cy="5" r="3" fill="#ecfeff"/><circle cx="7" cy="25" r="3" fill="#fde68a"/></svg>'
  };
  function renderGrid() {
    const grid = $('rod-grid'); grid.innerHTML = '';
    rods.forEach((r) => {
      const c = document.createElement('button');
      c.type = 'button';
      const equipped = r.key === G.rodKey;
      c.className = 'rod-card' + (r.unlocked ? '' : ' locked') + (r.key === viewKey ? ' viewing' : '');
      c.dataset.key = r.key;
      c.title = r.name + (r.unlocked ? '' : ' — ' + r.req);
      c.innerHTML = '<span class="rod-card-ico rb-' + r.rarity + '">' + (ROD_GLYPH[r.key] || ROD_ICON_SVG) + '</span><span class="rod-card-name"></span>'
        + (!r.unlocked ? '<span class="rod-card-flag"><i class="ph-fill ph-lock-simple"></i></span>' : (equipped ? '<span class="rod-card-flag ok"><i class="ph-fill ph-check-circle"></i></span>' : ''))
        + (!r.unlocked && r.progress > 0 && r.progress < 1 ? '<span class="rod-card-prog"><i style="width:' + Math.round(r.progress * 100) + '%"></i></span>' : '');
      c.querySelector('.rod-card-name').textContent = r.name.replace('Joran ', '');
      c.addEventListener('click', () => preview(r.key));
      grid.appendChild(c);
    });
  }
  function act() {
    const btn = $('rod-action'), r = find(viewKey);
    if (btn.dataset.mode === 'upgrade') { window.location.href = '/upgrade'; return; }
    if (btn.dataset.mode !== 'select') { if (btn.dataset.mode === 'locked') toast(r.req, 'info'); return; }
    btn.disabled = true;
    MancingAPI.post('select_rod', { rod: r.key }).then((res) => {
      if (!res.ok) { toast(res.msg, 'error'); return; }
      equip(r.key);
      toast(res.msg, 'success');
      renderGrid(); preview(r.key);
    }).catch(() => toast('Gagal mengganti joran.', 'error'))
      .finally(() => { btn.disabled = false; });
  }
  function equip(key) {
    G.rodKey = key;
    const r = find(key);
    G.rodBonus = { reel: r.reel / 100, bite: r.bite / 100 };
    World.setRodSkin(key);
    $('hud-rod-name').textContent = r.name.replace('Joran ', '');
  }
  return {
    get isOpen() { return isOpen; },
    init() { equip(CFG.rodSelected || 'bambu'); },
    current() { return find(G.rodKey); },
    open() {
      isOpen = true;
      $('mc-rods').classList.add('open');
      if (!ok) $('rod-stage').classList.add('no-gl');
      renderGrid(); preview(G.rodKey);
    },
    close() { isOpen = false; $('mc-rods').classList.remove('open'); },
    act,
    frame(dt, t) {
      if (!renderer || !current) return;
      holder.rotation.y = Math.sin(t * 0.6) * 0.32;
      holder.rotation.x = 0.12;
      current.update(t);
      renderer.render(scene, cam);
    }
  };
})();

/* ───────────────────────── PANDUAN (TOMBOL i) ─────────────────────────
   Isi panduan mengikuti fase permainan saat ini. Saat panduan terbuka
   permainan dijeda, jadi aman dibuka kapan saja (termasuk saat STRIKE).  */
const Help = (() => {
  let isOpen = false;
  const seenKey = 'mc_tut_v1';
  const seen = {}; // tersimpan di tabel fishing_prefs.tutorial_seen
  ((CFG.prefs && CFG.prefs.tutorial_seen) || []).forEach((k) => { seen[k] = 1; });
  function phase() {
    if (['floating', 'strike', 'casting'].includes(G.state)) return 'hook';
    if (G.state === 'fighting') return 'fight';
    if (['landing', 'showcase'].includes(G.state)) return 'result';
    return 'cast';
  }
  function rodLine(kind) {
    const r = RodCollection.current();
    if (!r) return '';
    if (kind === 'bite') return r.bite ? r.name + ' mempercepat sambaran ikan ' + r.bite + '%.' : 'Ganti joran di Koleksi Joran agar ikan menyambar lebih cepat.';
    return r.reel ? r.name + ' menambah kecepatan tarikan +' + r.reel + '%.' : 'Joran yang lebih bagus membuat ikan lebih cepat mendekat.';
  }
  const DIAG = {
    cast: '<svg viewBox="0 0 220 70" class="help-diag"><path d="M60 62 A50 50 0 0 1 160 62" fill="none" stroke="#ffe4e6" stroke-width="10" stroke-linecap="round"/><path d="M60 62 A50 50 0 0 1 125 14.3" fill="none" stroke="#fb7185" stroke-width="10" stroke-linecap="round"/><circle cx="125" cy="14.3" r="6" fill="#fff" stroke="#0891b2" stroke-width="3"/><text x="110" y="58" text-anchor="middle" font-size="9" font-weight="900" fill="#9a3412">Lepas di titik riak</text></svg>',
    hook: '<svg viewBox="0 0 220 70" class="help-diag"><rect x="0" y="38" width="220" height="32" rx="8" fill="#a5f3fc"/><g transform="translate(40 30)"><circle r="9" fill="#fff"/><path d="M-9 0 A9 9 0 0 0 9 0Z" fill="#ef4444"/></g><text x="40" y="66" text-anchor="middle" font-size="9" font-weight="900" fill="#0e7490">Mengapung</text><g transform="translate(110 36)"><circle r="9" fill="#fff"/><path d="M-9 0 A9 9 0 0 0 9 0Z" fill="#ef4444"/><path d="M-16 -12 l4 4 M16 -12 l-4 4" stroke="#0e7490" stroke-width="2"/></g><text x="110" y="66" text-anchor="middle" font-size="9" font-weight="900" fill="#0e7490">Nibble: tunggu</text><g transform="translate(180 50)"><circle r="9" fill="#fff" opacity="0.5"/><path d="M-9 0 A9 9 0 0 0 9 0Z" fill="#ef4444" opacity="0.5"/></g><text x="180" y="28" text-anchor="middle" font-size="11" font-weight="900" fill="#e11d48">HOOK!</text></svg>',
    fight: '<svg viewBox="0 0 220 56" class="help-diag"><rect x="10" y="16" width="110" height="16" rx="8" fill="#34d399"/><rect x="120" y="16" width="50" height="16" fill="#fbbf24"/><rect x="170" y="16" width="40" height="16" rx="8" fill="#f43f5e"/><rect x="10" y="16" width="200" height="16" rx="8" fill="none" stroke="#fff" stroke-width="2"/><text x="65" y="48" text-anchor="middle" font-size="9" font-weight="900" fill="#047857">Aman: gulung</text><text x="145" y="48" text-anchor="middle" font-size="9" font-weight="900" fill="#b45309">Waspada</text><text x="190" y="48" text-anchor="middle" font-size="9" font-weight="900" fill="#e11d48">LEPAS!</text><text x="110" y="10" text-anchor="middle" font-size="9" font-weight="900" fill="#6b6478">Tegangan Senar</text></svg>',
    result: ''
  };
  function content(ph) {
    if (ph === 'hook') return {
      title: 'Cara Menyentak (HOOK)', icon: 'ph-lightning',
      steps: [
        'Setelah kail mendarat, pelampung mengapung. Tunggu dengan sabar.',
        'Pelampung bergetar kecil (nibble) = ikan baru mencicipi umpan. <b>Jangan tekan dulu.</b>',
        'Saat muncul <b>STRIKE!!</b> dan pelampung tenggelam, tekan <b>HOOK</b> dalam <b>1,5 detik</b>. Cincin kuning di tombol = sisa waktu.',
        'Terlalu cepat menekan → ikan menjauh sebentar lalu menyambar lagi.',
        'Terlambat → ikan melepas umpan, tapi akan kembali menyambar. Umpanmu tidak hilang.'
      ], tip: rodLine('bite')
    };
    if (ph === 'fight') return {
      title: 'Cara Menarik Ikan', icon: 'ph-arrows-clockwise',
      steps: [
        '<b>Tahan tombol Gulung</b> untuk mendekatkan ikan. Bar biru = sisa jarak ikan.',
        'Selama menggulung, <b>tegangan senar naik</b>. Hijau aman, kuning waspada, merah bahaya.',
        '<b>Lepas tombol</b> saat jarum masuk zona merah. Tegangan turun, tapi ikan menjauh pelan-pelan.',
        'Saat muncul <b>"Ikan berontak!"</b>, tegangan melonjak cepat. Sebaiknya lepas sebentar.',
        'Tegangan <b>100%</b> = senar hampir putus: ikan kabur 4 m dan gulungan terkunci 0,7 detik.',
        'Jarak 0 m → ikan melompat keluar air. Tangkapan berhasil!'
      ], tip: rodLine('reel')
    };
    if (ph === 'result') return {
      title: 'Hasil Tangkapan', icon: 'ph-trophy',
      steps: [
        'Ikan hasil pancingan masuk <b>Ember</b> dulu — belum menjadi saldo.',
        'Buka <b>Ember &amp; Pasar Ikan</b> (tombol Ember) untuk menjual jadi <b>Saldo Tarik</b>. Harga pasar naik-turun setiap hari (85%–115%), pilih waktu jual terbaik!',
        'Kelangkaan: Biasa (Mujair) · Bagus (Gurame) · Langka (Kakap Merah) · Super (Barramundi) · Jackpot (Peti Harta Karun).',
        'Menemukan Peti Harta Karun membuka <b>Joran Pusaka Keris</b>.'
      ], tip: ''
    };
    return {
      title: 'Cara Melempar Kail', icon: 'ph-target',
      steps: [
        '<b>Tahan</b> tombol "Tahan untuk Lempar" — busur tenaga akan naik-turun.',
        '<b>Titik putih</b> di busur = titik riak tempat ikan sedang makan.',
        '<b>Lepas</b> tepat di titik putih → "Tepat di titik riak!" dan ikan menyambar lebih cepat.',
        'Setiap lemparan memakai <b>1 umpan</b>. Beli umpan di <b>Toko Umpan</b> (tombol Toko), atau dapatkan gratis dari menonton video.',
        'Ganti joran di <b>Koleksi Joran</b> (tombol joran di kanan atas) untuk tarikan & sambaran lebih gacor.'
      ], tip: rodLine('bite')
    };
  }
  function render(ph) {
    const c = content(ph);
    $('help-title').innerHTML = '<span class="m-ico rb-super"><i class="ph-fill ' + c.icon + '"></i></span> ' + c.title;
    $('help-diag').innerHTML = DIAG[ph] || '';
    $('help-steps').innerHTML = c.steps.map((s, i) => '<li><span>' + (i + 1) + '</span><div>' + s + '</div></li>').join('');
    const tip = $('help-tip');
    tip.style.display = c.tip ? 'flex' : 'none';
    tip.querySelector('span').textContent = c.tip;
    document.querySelectorAll('.help-tab').forEach((b) => b.classList.toggle('active', b.dataset.ph === ph));
  }
  return {
    get isOpen() { return isOpen; },
    open(ph) {
      isOpen = true; G.paused = true;
      G.reeling = false; $('btn-reel').classList.remove('held');
      if (G.state === 'aiming') { G.state = 'idle'; G.power = 0; $('btn-cast').classList.remove('held'); updatePowerUI(); $('btn-cast-lbl').textContent = 'Tahan untuk Lempar'; }
      ph = ph || phase();
      if (!seen[ph]) { seen[ph] = 1; MancingAPI.post('save_prefs', { tutorial_seen: ph }).catch(() => {}); }
      render(ph);
      $('mc-help').classList.add('open');
    },
    show(ph) { render(ph); },
    close() { isOpen = false; G.paused = false; last = performance.now(); $('mc-help').classList.remove('open'); },
    firstTime(ph) { if (!seen[ph]) this.open(ph); }
  };
})();

/* ───────────────────────── TOKO UMPAN (ikon toples 3D) ───────────────────────── */
const Shop = (() => {
  const packs = (CFG.packs || []).slice();
  let selId = 0, busy = false, isOpen = false;
  function buildJar(color, qty) {
    const g = new THREE.Group();
    const fillH = 0.25 + Math.min(1, qty / 35) * 0.35;
    const fill = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.3, fillH, 20), new THREE.MeshPhongMaterial({ color, shininess: 20 }));
    fill.position.y = fillH / 2 + 0.02; g.add(fill);
    for (let i = 0; i < 6; i++) { // butiran umpan di permukaan
      const b = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 6), new THREE.MeshPhongMaterial({ color: new THREE.Color(color).offsetHSL(0, 0, -0.12), shininess: 40 }));
      const a = i / 6 * Math.PI * 2; b.position.set(Math.cos(a) * 0.16, fillH + 0.03, Math.sin(a) * 0.16); g.add(b);
    }
    const glass = new THREE.Mesh(new THREE.CylinderGeometry(0.34, 0.34, 0.7, 24, 1, true), new THREE.MeshPhongMaterial({ color: 0xe0f2fe, transparent: true, opacity: 0.32, shininess: 140, specular: 0xffffff, side: THREE.DoubleSide }));
    glass.position.y = 0.35; g.add(glass);
    const lid = new THREE.Mesh(new THREE.CylinderGeometry(0.37, 0.37, 0.12, 24), new THREE.MeshPhongMaterial({ color: 0xe11d48, shininess: 70 }));
    lid.position.y = 0.76; g.add(lid);
    const knob = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.12, 0.08, 16), new THREE.MeshPhongMaterial({ color: 0xbe123c }));
    knob.position.y = 0.86; g.add(knob);
    const label = new THREE.Mesh(new THREE.CylinderGeometry(0.345, 0.345, 0.2, 24, 1, true, -0.9, 1.8), new THREE.MeshPhongMaterial({ color: 0xfff7ed, side: THREE.DoubleSide }));
    label.position.y = 0.42; g.add(label);
    return g;
  }
  function jarThumb(p) {
    return Thumbs.get('jar-' + p.id + '-' + p.color + '-' + p.qty, () => buildJar(p.color, p.qty), { rot: [0.32, -0.4, 0], pad: 1.0 });
  }
  function render() {
    const grid = $('pack-grid'); grid.innerHTML = '';
    if (!packs.length) grid.innerHTML = '<div class="fish-empty">Belum ada paket umpan yang dijual.</div>';
    packs.forEach((p) => {
      const c = document.createElement('button'); c.type = 'button';
      c.className = 'pack-card' + (selId === p.id ? ' sel' : '');
      const th = jarThumb(p);
      c.innerHTML = (p.tag ? '<span class="pack-tag"></span>' : '') + (th ? '<span class="pk-thumb"><img src="' + th + '" alt=""></span>' : '') +
        '<b></b><small>' + p.qty + ' umpan</small><span class="pk-price">' + fmtRp(p.price) + '</span>';
      if (p.tag) c.querySelector('.pack-tag').textContent = p.tag;
      c.querySelector('b').textContent = p.name;
      c.addEventListener('click', () => { selId = p.id; Sfx.tick(); render(); });
      grid.appendChild(c);
    });
    const p = packs.find((x) => x.id === selId);
    $('pack-desc').textContent = p ? (p.desc || p.name) + ' · ' + fmtRp(Math.round(p.price / Math.max(1, p.qty))) + ' per umpan' : 'Pilih paket umpan di atas';
    $('btn-buy').disabled = !p || busy;
    $('btn-buy').innerHTML = '<i class="ph-fill ph-shopping-bag"></i> ' + (p ? 'Beli ' + p.name + ' · ' + fmtRp(p.price) : 'Beli Paket');
  }
  function buy() {
    const p = packs.find((x) => x.id === selId);
    if (!p || busy) return;
    busy = true; render();
    MancingAPI.post('buy_baits', { pack_id: p.id }).then((res) => {
      if (!res.ok) {
        if (res.need_deposit) MancingUI.ask({ title: 'Saldo Beli Kurang', msg: res.msg, icon: 'ph-coins', yes: 'Top Up Sekarang', yesIcon: 'ph-plus-circle', href: '/deposit' });
        else toast(res.msg, 'error');
        return;
      }
      Sfx.cash(); Haptic.buzz([25, 30, 25]);
      $('shop-dep').textContent = res.balance_dep;
      UI.baits(res.new_baits);
      toast(res.msg, 'success');
    }).catch(() => toast('Gagal memproses pembelian umpan.', 'error'))
      .finally(() => { busy = false; render(); });
  }
  return {
    get isOpen() { return isOpen; },
    open() { isOpen = true; render(); $('mc-shop').classList.add('open'); },
    close() { isOpen = false; $('mc-shop').classList.remove('open'); },
    buy
  };
})();

/* ───────────────────────── STATE MACHINE GAMEPLAY ───────────────────────── */
const G = {
  time: 0, state: 'idle', power: 0, holdT: 0, baits: CFG.baits,
  todayTotal: Number(CFG.todayTotal) || 0, catchCount: CFG.catchCount || 0,
  bob: { mode: 'hang', x: 0, z: -10, fx: 0, fy: 0, fz: 0, flyT: 0, arc: 2, dip: 0 },
  res: null, castDone: false, sweet: false, timers: [], rodBend: 0,
  fight: { dist: 0, D0: 20, tension: 0, x: 0, tx: 0, surge: 0, surgeCd: 1.5, reelLock: 0, leap: 0, leapDur: 0.8, leapDx: 0.5, pull: 1, redT: 0, buzzT: 0, tickT: 0 },
  land: { s: 0, x0: 0, z0: -4 }, slowmo: false, reeling: false, rarityIdx: 0,
  rodKey: 'bambu', rodBonus: { reel: 0, bite: 0 }, paused: false
};
const World = HAS_GL ? (() => { try { return createWorld(G); } catch (e) { console.error(e); return null; } })() || createNullWorld() : createNullWorld();
if (!HAS_GL) $('mc3d-stage').classList.add('no-gl');

function after(sec, fn) { const t = { at: G.time + sec, fn }; G.timers.push(t); return t; }
function clearTimers() { G.timers.length = 0; }
function runTimers() {
  for (let i = G.timers.length - 1; i >= 0; i--) {
    if (G.time >= G.timers[i].at) { const t = G.timers.splice(i, 1)[0]; t.fn(); }
  }
}

const UI = {
  msg(text, tone) { const el = $('hud-msg'); el.textContent = text; el.className = 'hud-msg glass' + (tone ? ' ' + tone : ''); },
  panel(name) { ['cast', 'hook', 'fight'].forEach((p) => $('pnl-' + p).classList.toggle('active', p === name)); },
  baits(n) {
    const el = $('hud-baits');
    if (String(n) !== el.textContent) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
    G.baits = n; el.textContent = n;
    const btn = $('btn-cast');
    btn.classList.toggle('empty', n <= 0);
    if (G.state === 'idle') $('btn-cast-lbl').textContent = n > 0 ? 'Tahan untuk Lempar' : 'Umpan Habis — Beli Umpan';
  },
  strikeText(text) {
    const el = $('hud-strike'); el.textContent = text;
    el.classList.remove('show'); void el.offsetWidth; el.classList.add('show');
  },
  flash() { const el = $('hud-flash'); el.classList.remove('go'); void el.offsetWidth; el.classList.add('go'); }
};

/* Penanda titik riak pada busur tenaga */
(function drawPowerMarks() {
  const g = $('pw-marks');
  FEED_SPOTS.forEach((s) => {
    const p = (s.d - 6) / 22, th = Math.PI * (1 - p);
    const x = 100 + 80 * Math.cos(th), y = 100 - 80 * Math.sin(th);
    const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('cx', x.toFixed(1)); c.setAttribute('cy', y.toFixed(1)); c.setAttribute('r', '5');
    c.setAttribute('fill', '#ffffff'); c.setAttribute('stroke', '#0891b2'); c.setAttribute('stroke-width', '2.5');
    g.appendChild(c);
  });
})();

function powerToDist(p) { return 6 + (p / 100) * 22; }
function nearestSpot(d) {
  let best = FEED_SPOTS[0];
  FEED_SPOTS.forEach((s) => { if (Math.abs(s.d - d) < Math.abs(best.d - d)) best = s; });
  return best;
}

/* Fase 1 — Casting */
function aimStart() {
  if (G.state !== 'idle') return;
  Sfx.unlock();
  if (G.baits <= 0) { MancingUI.openShop(); return; }
  G.state = 'aiming'; G.holdT = 0; G.power = 0;
  $('btn-cast').classList.add('held');
  $('btn-cast-lbl').textContent = 'Lepas untuk Lempar!';
  UI.msg('Lepas tepat di titik riak agar ikan cepat menyambar.');
}
function aimRelease() {
  if (G.state !== 'aiming') return;
  $('btn-cast').classList.remove('held');
  if (G.power < 4) { // ketukan singkat → batal
    G.state = 'idle'; G.power = 0; updatePowerUI();
    $('btn-cast-lbl').textContent = 'Tahan untuk Lempar';
    UI.msg('Tahan tombolnya sedikit lebih lama untuk mengisi tenaga.');
    return;
  }
  doCast(G.power);
}
function updatePowerUI() {
  $('pw-fill').setAttribute('stroke-dashoffset', (100 - G.power).toFixed(1));
  const d = powerToDist(G.power), s = nearestSpot(d), sweet = Math.abs(s.d - d) < 1.6;
  const lbl = $('pw-label');
  if (G.state === 'aiming') {
    lbl.textContent = sweet ? 'Tepat di titik riak! (' + d.toFixed(0) + ' m)' : 'Jarak lempar: ' + d.toFixed(0) + ' m';
    lbl.classList.toggle('sweet', sweet);
  } else { lbl.textContent = 'Titik riak = ikan sedang makan'; lbl.classList.remove('sweet'); }
}
function doCast(power) {
  G.state = 'casting';
  const d = powerToDist(power), s = nearestSpot(d);
  const near = clamp(1 - Math.abs(d - s.d) / 4, 0, 1);
  const x = s.x * near + rand(-0.6, 0.6);
  G.sweet = Math.hypot(x - s.x, d - s.d) < 1.6;
  const tip = World.tipPos();
  Object.assign(G.bob, { mode: 'fly', fx: tip.x, fy: tip.y, fz: tip.z, x, z: -d, flyT: 0, arc: 1.6 + d * 0.08, dip: 0 });
  G.res = null; G.castDone = false;
  UI.panel('hook'); $('hook-hint').textContent = 'Kail meluncur...'; $('btn-hook').style.visibility = 'hidden';
  UI.msg('Wuuush! Kail meluncur ' + d.toFixed(0) + ' meter...');
  Haptic.buzz(25); Sfx.whoosh();

  MancingAPI.castRod().then((res) => {
    if (!res || !res.ok) { castFailed(res || {}); return; }
    G.res = res;
    UI.baits(res.remaining_baits);
    const badge = BADGES.indexOf(res.fish.badge) >= 0 ? res.fish.badge : 'biasa';
    G.rarityIdx = BADGES.indexOf(badge);
    World.setCatch(BADGE_SPECIES[badge], Number(res.fish.weight) || 1);
    emit('onCast', res);
  }).catch(() => castFailed({ msg: 'Kendala jaringan saat melempar kail. Coba lagi ya.' }));
}
function castFailed(res) {
  clearTimers();
  G.state = 'busy';
  toast(res.msg || 'Gagal melempar kail.', 'error');
  UI.msg(res.msg || 'Gagal melempar kail.', 'warn');
  if (res.out_of_baits) { UI.baits(0); MancingUI.openShop(); }
  after(0.6, resetToIdle);
}
function onBobberLanded() {
  const B = G.bob;
  B.mode = 'float';
  World.spawnSplash(B.x, 0.02, B.z, 22, 1.0, 0.9);
  World.addRipple(B.x, B.z);
  Sfx.plop(); Haptic.buzz(15);
  G.state = 'floating';
  $('btn-hook').style.visibility = 'visible';
  $('hook-hint').textContent = 'Tunggu pelampung tenggelam...';
  UI.msg(G.sweet ? 'Tepat di titik riak! Ikan mulai mendekat...' : 'Pelampung mengapung... tunggu sambaran.', G.sweet ? 'good' : '');
  Help.firstTime('hook');
}

/* Fase 2 — Floating, nibble & STRIKE */
function scheduleBite(shorter) {
  clearTimers();
  const gacor = 1 - G.rodBonus.bite; // joran gacor = waktu tunggu lebih singkat
  const wait = (shorter ? rand(0.8, 1.5) : (G.sweet ? rand(0.9, 1.6) : rand(2.0, 3.4))) * gacor;
  const nib = 1 + Math.floor(Math.random() * 3);
  let t = wait;
  for (let i = 0; i < nib; i++) {
    after(t, nibble);
    t += rand(0.45, 0.85) * gacor;
  }
  after(t, strike);
}
function nibble() {
  if (G.state !== 'floating') return;
  G.bob.dip = 0.07;
  after(0.18, () => { G.bob.dip = 0; });
  World.addRipple(G.bob.x, G.bob.z);
  Haptic.buzz(18); Sfx.nibble();
  UI.msg('Ada yang mencicipi umpan... siap-siap!');
}
function strike() {
  if (G.state !== 'floating') return;
  G.state = 'strike';
  G.bob.mode = 'plunge';
  G.hookLeft = 1.5;
  World.spawnSplash(G.bob.x, 0.05, G.bob.z, 34, 1.4, 1.1);
  World.addRipple(G.bob.x, G.bob.z);
  UI.strikeText('STRIKE!!');
  UI.msg('Ikan menyambar! Tekan HOOK sekarang!', 'warn');
  $('hook-hint').textContent = 'Cepat! 1,5 detik!';
  Haptic.buzz([80, 40, 140]); Sfx.strike();
  emit('onStrike', G.res);
}
function pressHook() {
  Sfx.unlock();
  if (G.state === 'strike') { startFight(); return; }
  if (G.state === 'floating') {
    UI.msg('Terlalu cepat! Tunggu pelampung benar-benar tenggelam.', 'warn');
    Haptic.buzz(40);
    if (G.res) scheduleBite(false);
  }
}
function hookMissed() {
  G.state = 'floating';
  G.bob.mode = 'float';
  UI.msg('Ikan melepas umpan... tetap fokus, ia akan kembali menyambar!', 'warn');
  Sfx.splash(0.35);
  $('hook-hint').textContent = 'Tunggu pelampung tenggelam...';
  Haptic.buzz(60);
  scheduleBite(true);
}

/* Fase 3 — Tarik-menarik (tension fight) */
function startFight() {
  clearTimers();
  const r = G.rarityIdx;
  const F = G.fight;
  F.D0 = [14, 17, 21, 25, 28][r]; F.dist = F.D0;
  F.pull = [0.55, 0.75, 0.95, 1.2, 1.05][r];
  F.tension = 30; F.x = G.bob.x; F.tx = G.bob.x; F.surge = 0; F.surgeCd = 1.2;
  F.reelLock = 0; F.leap = 0; F.redT = 0;
  G.state = 'fighting'; G.bob.mode = 'hidden';
  UI.panel('fight');
  UI.strikeText('HOOKED!');
  UI.msg('Tahan GULUNG untuk menarik, lepas saat jarum masuk zona merah!');
  Haptic.buzz([40, 30, 40]);
  after(0.4, () => Help.firstTime('fight'));
}
function updateFight(dt) {
  const F = G.fight, isChest = G.rarityIdx === 4;
  F.surgeCd -= dt;
  if (F.surgeCd <= 0) {
    F.surge = rand(0.45, 0.9);
    F.surgeCd = rand(1.0, 2.4) / (0.6 + F.pull * 0.5);
    const dir = Math.random() < 0.5 ? -1 : 1;
    const lim = 1 + F.dist * 0.22;
    F.tx = clamp(F.x + dir * rand(1.4, 3.2), -lim, lim);
    UI.msg(isChest ? 'Peti tersangkut karang! Hati-hati!' : 'Ikan berontak ke ' + (dir < 0 ? 'kiri' : 'kanan') + '!', 'warn');
    Haptic.buzz(35);
    if (!isChest && Math.random() < 0.35 && F.dist > 3) { F.leap = F.leapDur = 0.8; F.leapDx = dir * 0.6; Sfx.splash(0.9); }
    else Sfx.splash(0.5);
  }
  const surging = F.surge > 0;
  if (surging) F.surge -= dt;
  if (F.leap > 0) { F.leap -= dt; if (F.leap <= 0) { World.spawnSplash(F.x + F.leapDx, 0.02, -(2.8 + F.dist * 0.75), 18, 1.0, 0.8); Sfx.splash(1.0); } }
  F.churnT = (F.churnT || 0) - dt;
  if (F.churnT <= 0) { Sfx.churn(); F.churnT = surging ? rand(0.3, 0.5) : rand(0.8, 1.4); }
  F.x += (F.tx - F.x) * damp(dt, surging ? 3 : 1);
  if (F.reelLock > 0) F.reelLock -= dt;
  const reeling = G.reeling && F.reelLock <= 0;
  $('btn-reel').classList.toggle('locked', F.reelLock > 0);

  let dT;
  if (reeling) {
    dT = (12 + F.pull * 10) * dt + (surging ? 26 * F.pull * dt : 0);
    F.dist -= (2.4 - F.pull * 0.35) * (1 + G.rodBonus.reel) * (surging ? 0.45 : 1) * dt;
    F.tickT -= dt; if (F.tickT <= 0) { Sfx.tick(); F.tickT = 0.11; }
  } else {
    dT = -26 * dt + (surging ? 12 * F.pull * dt : 0);
    F.dist = Math.min(F.D0, F.dist + (surging ? 1.2 : 0.25) * F.pull * dt);
  }
  F.tension = clamp(F.tension + dT, 0, 100);

  if (F.tension >= 80) {
    F.redT += dt; F.buzzT -= dt;
    if (F.buzzT <= 0) { Haptic.buzz(15); F.buzzT = 0.25; }
  } else F.redT = 0;

  if (F.tension >= 100) {
    F.tension = 48; F.dist = Math.min(F.D0, F.dist + 4); F.reelLock = 0.7;
    UI.msg('Senar hampir putus! Ikan kabur menjauh 4 m — lepas tombol di zona merah!', 'warn');
    Haptic.buzz([200, 60, 200]); Sfx.snap(); Sfx.splash(0.8);
    World.spawnSplash(F.x, 0.05, -(2.8 + F.dist * 0.75), 26, 1.2, 1);
  }

  /* HUD fight */
  $('ft-dist-txt').textContent = Math.max(0, F.dist).toFixed(1) + ' m';
  $('ft-dist-fill').style.transform = 'scaleX(' + clamp(F.dist / F.D0, 0, 1).toFixed(3) + ')';
  $('ft-ten-fill').style.transform = 'scaleX(' + (1 - F.tension / 100).toFixed(3) + ')';
  $('ft-needle').style.left = F.tension.toFixed(1) + '%';
  const tt = $('ft-ten-txt');
  tt.textContent = Math.round(F.tension) + '%' + (F.tension >= 80 ? ' BAHAYA' : '');
  tt.classList.toggle('danger', F.tension >= 80);

  if (F.dist <= 0) { F.dist = 0; startLanding(); }
}

/* Fase 4 — Landing & showcase */
function startLanding() {
  G.state = 'landing';
  G.reeling = false;
  $('btn-reel').classList.remove('held');
  UI.panel(null);
  const F = G.fight;
  G.land.s = 0; G.land.x0 = F.x; G.land.z0 = -2.8;
  World.spawnSplash(F.x, 0.05, -2.8, 46, 1.8, 1.2);
  World.addRipple(F.x, -2.8);
  UI.msg(G.rarityIdx === 4 ? 'Peti harta karun muncul dari dasar laut!' : 'Ikan melompat keluar air!', 'good');
  Haptic.buzz([60, 40, 60, 40, 120]); Sfx.splash(1.6);
  emit('onLanded', G.res);
}
function updateLanding(dt) {
  const L = G.land;
  G.slowmo = L.s > 0.3 && L.s < 0.6;
  L.s = Math.min(1, L.s + dt / 1.5 * (G.slowmo ? 0.22 : 1));
  if (L.s >= 0.62 && !L.shown) { L.shown = true; UI.flash(); after(0.25, openShowcase); }
}
function openShowcase() {
  G.state = 'showcase';
  const f = G.res.fish;
  const badge = BADGES[G.rarityIdx];
  const spKey = BADGE_SPECIES[badge];
  const st = $('show-stage');
  st.classList.toggle('is-jackpot', badge === 'jackpot');
  const b = $('show-badge'); b.className = 'show-badge rb-' + badge; b.textContent = BADGE_LABEL[badge];
  $('show-name').textContent = String(f.name || '').replace(/[^\p{L}\p{N}\s()\/.-]/gu, '').trim();
  $('show-latin').textContent = spKey === 'chest' ? 'Relik kuno berbingkai tembaga & emas' : SPECIES[spKey].latin;
  $('show-weight').textContent = Number(f.weight).toFixed(2) + ' Kg';
  $('show-reward').textContent = G.res.price_rp || fmtRp(G.res.price || f.reward);
  $('btn-save').disabled = false;
  $('mc-show').classList.add('open');
  Showcase.show(spKey);
}
function saveCatch() {
  const btn = $('btn-save');
  if (btn.disabled) return;
  if (!G.res) { $('mc-show').classList.remove('open'); resetToIdle(); return; }
  btn.disabled = true;
  const res = G.res, f = res.fish, badge = BADGES[G.rarityIdx];
  flyCoins(btn, $('hud-inv'), 10, () => {
    G.todayTotal += Number(res.price || f.reward) || 0;
    $('hud-cuan-val').textContent = fmtRp(G.todayTotal);
    prependCatchRow(f, badge);
    emit('onSaved', { fish: f, todayTotal: G.todayTotal });
  });
  after(0.75, () => {
    $('mc-show').classList.remove('open');
    resetToIdle();
    toast('Tangkapan disimpan ke Ember Cuan!', 'success');
  });
}
function resetToIdle() {
  clearTimers();
  G.state = 'idle'; G.power = 0; G.res = null; G.reeling = false; G.land.shown = false;
  G.bob.mode = 'hang';
  World.clearCatch();
  UI.panel('cast');
  $('btn-hook').style.visibility = 'visible';
  $('btn-cast').classList.remove('held');
  updatePowerUI();
  UI.baits(G.baits);
  UI.msg(G.baits > 0 ? 'Tahan tombol untuk mengisi tenaga, lepas untuk melempar kail.' : 'Umpan habis. Beli umpan atau tonton video untuk umpan gratis.');
}

function flyCoins(fromEl, toEl, n, done) {
  const a = fromEl.getBoundingClientRect(), b = toEl.getBoundingClientRect();
  const sx = a.left + a.width / 2, sy = a.top + a.height / 2, ex = b.left + b.width / 2, ey = b.top + b.height / 2;
  let finished = 0;
  for (let i = 0; i < n; i++) {
    const c = document.createElement('div');
    c.className = 'fly-coin';
    c.style.left = sx + 'px'; c.style.top = sy + 'px';
    document.body.appendChild(c);
    const mx = (ex - sx) * 0.5 + rand(-80, 80), my = Math.min(sy, ey) - rand(60, 140) - sy;
    const anim = c.animate([
      { transform: 'translate(0,0) scale(0.6)', opacity: 1 },
      { transform: 'translate(' + mx + 'px,' + my + 'px) scale(1.15)', opacity: 1, offset: 0.45 },
      { transform: 'translate(' + (ex - sx) + 'px,' + (ey - sy) + 'px) scale(0.5)', opacity: 0.2 }
    ], { duration: 750 + i * 40, delay: i * 55, easing: 'cubic-bezier(.45,.05,.55,.95)', fill: 'forwards' });
    anim.onfinish = () => {
      c.remove(); Sfx.coin();
      if (++finished === n && done) done();
    };
  }
}
function prependCatchRow(fish, badge) {
  const now = new Date();
  Inventory.add({
    name: fish.name, badge, weight: Number(fish.weight), reward: Number((G.res && G.res.price) || fish.reward),
    time: String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')
  });
  const b = $('hud-inv-count');
  b.textContent = Inventory.count();
  b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
}

/* ───────────────────────── LOOP UTAMA ───────────────────────── */
let last = performance.now(), visible = true, onScreen = true;
document.addEventListener('visibilitychange', () => { visible = !document.hidden; last = performance.now(); });
if (window.IntersectionObserver) new IntersectionObserver((e) => { onScreen = e[0].isIntersecting; }).observe($('mc3d-stage'));

function tick(now) {
  requestAnimationFrame(tick);
  if (!visible) return;
  const dt = Math.min(0.05, (now - last) / 1000); last = now;
  if (Help.isOpen) return; // permainan dijeda
  const menuOpen = Inventory.isOpen || RodCollection.isOpen || Shop.isOpen || $('mc-confirm').classList.contains('open');
  if (menuOpen) {
    if (Inventory.isOpen) Inventory.frame(dt, performance.now() / 1000);
    else if (RodCollection.isOpen) RodCollection.frame(dt, performance.now() / 1000);
    return;
  }
  G.time += dt;
  runTimers();

  if (G.state === 'aiming') {
    G.holdT += dt;
    G.power = (1 - Math.cos(G.holdT * 2.6)) / 2 * 100;
    updatePowerUI();
  }
  if (G.state === 'casting' && G.bob.mode === 'fly') {
    G.bob.flyT = Math.min(1, G.bob.flyT + dt / 0.9);
    if (G.bob.flyT >= 1) onBobberLanded();
  }
  if (G.state === 'floating' && G.res && !G.castDone) { G.castDone = true; scheduleBite(false); }
  if (G.state === 'strike') {
    G.hookLeft -= dt;
    $('hook-ring').setAttribute('stroke-dashoffset', (100 - clamp(G.hookLeft / 1.5, 0, 1) * 100).toFixed(1));
    if (G.hookLeft <= 0) hookMissed();
  } else if (G.state !== 'strike') {
    $('hook-ring').setAttribute('stroke-dashoffset', '0');
  }
  if (G.state === 'fighting') updateFight(dt);
  if (G.state === 'landing') updateLanding(dt);

  if (G.state === 'showcase') Showcase.frame(dt, G.time);
  else if (onScreen) World.update(dt);
}
requestAnimationFrame(tick);

/* ───────────────────────── INPUT (sentuh, mouse, keyboard) ───────────────────────── */
function bindHold(el, down, up) {
  el.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    try { el.setPointerCapture(e.pointerId); } catch (_) {}
    down();
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((ev) => el.addEventListener(ev, up));
  el.addEventListener('contextmenu', (e) => e.preventDefault());
}
bindHold($('btn-cast'), aimStart, aimRelease);
bindHold($('btn-reel'),
  () => { if (G.state === 'fighting') { G.reeling = true; $('btn-reel').classList.add('held'); } },
  () => { G.reeling = false; $('btn-reel').classList.remove('held'); });
$('btn-hook').addEventListener('pointerdown', (e) => { e.preventDefault(); pressHook(); });
$('btn-save').addEventListener('click', saveCatch);
$('hud-mute').addEventListener('click', () => {
  const m = Sfx.toggle();
  Ambience.setMuted(m);
  MancingAPI.post('save_prefs', { sound_muted: m ? '1' : '0' }).catch(() => {});
  $('hud-mute').innerHTML = '<i class="ph-fill ph-speaker-' + (m ? 'slash' : 'high') + '"></i>';
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { MancingUI.closeInventory(); MancingUI.closeShop(); MancingUI.closeRods(); if (Help.isOpen) Help.close(); return; }
  if (e.repeat || e.target.closest('input, textarea') || document.querySelector('.mc-modal.open')) return;
  if (e.code === 'Space') {
    e.preventDefault();
    if (G.state === 'idle') aimStart();
    else if (G.state === 'fighting') { G.reeling = true; $('btn-reel').classList.add('held'); }
    else if (G.state === 'strike' || G.state === 'floating') pressHook();
  }
});
document.addEventListener('keyup', (e) => {
  if (e.code !== 'Space') return;
  if (G.state === 'aiming') aimRelease();
  G.reeling = false; $('btn-reel').classList.remove('held');
});

/* ───────────────────────── UI PUBLIK (toko umpan) ───────────────────────── */
window.MancingAudio = { start() { Sfx.unlock(); Ambience.start(CFG.ambience, 0.32); setTimeout(() => Help.firstTime('cast'), 700); } };
window.MancingUI = {
  openHelp() { Help.open(); },
  closeHelp() { Help.close(); },
  openRods() {
    if (!['idle', 'busy'].includes(G.state)) { toast('Selesaikan pancinganmu dulu sebelum ganti joran.', 'info'); return; }
    RodCollection.open();
  },
  closeRods() { RodCollection.close(); },
  rodAction() { RodCollection.act(); },
  openInventory() { Inventory.open(); },
  closeInventory() { Inventory.close(); },
  /** Dialog bertema (pengganti alert/confirm bawaan browser) */
  ask(o) {
    $('cf-title').textContent = o.title || '';
    $('cf-msg').textContent = o.msg || '';
    $('cf-ico').innerHTML = '<i class="ph-fill ' + (o.icon || 'ph-anchor-simple') + '"></i>';
    const yes = $('cf-yes');
    yes.innerHTML = '<i class="ph-fill ' + (o.yesIcon || 'ph-arrow-right') + '"></i> ' + (o.yes || 'Lanjut');
    yes.href = o.href || '#';
    $('cf-no').textContent = o.no || 'Nanti';
    $('mc-confirm').classList.add('open');
  },
  openShop() { Shop.open(); },
  closeShop() { Shop.close(); this.closeAsk(); },
  closeAsk() { $('mc-confirm').classList.remove('open'); }
};
$('mc-confirm').addEventListener('click', (e) => { if (e.target.id === 'mc-confirm') MancingUI.closeAsk(); });
$('cf-no').addEventListener('click', () => MancingUI.closeAsk());
$('mc-shop').addEventListener('click', (e) => { if (e.target.id === 'mc-shop') MancingUI.closeShop(); });
$('btn-buy').addEventListener('click', () => Shop.buy());
$('btn-sell').addEventListener('click', () => Inventory.sell());
$('btn-sel-all').addEventListener('click', () => Inventory.toggleAll());
$('mc-inv').addEventListener('click', (e) => { if (e.target.id === 'mc-inv') MancingUI.closeInventory(); });
$('inv-prev').addEventListener('click', () => Inventory.prev());
$('mc-rods').addEventListener('click', (e) => { if (e.target.id === 'mc-rods') MancingUI.closeRods(); });
document.querySelectorAll('.help-tab').forEach((b) => b.addEventListener('click', () => Help.show(b.dataset.ph)));
RodCollection.init();
$('inv-next').addEventListener('click', () => Inventory.next());

UI.baits(G.baits);
updatePowerUI();
Sfx.useSamples(CFG.splash);
if (CFG.prefs && CFG.prefs.sound_muted) { Sfx.toggle(); $('hud-mute').innerHTML = '<i class="ph-fill ph-speaker-slash"></i>'; }
};
</script>
<script>
/* ══════════ LOADER GAME ══════════
   1) unduh Three.js  2) bangun dunia 3D  3) siapkan suara  4) tombol mulai
   (tombol mulai sekaligus membuka kunci audio & getar sesuai aturan browser) */
(function () {
  var bar = document.getElementById('ld-bar'), txt = document.getElementById('ld-text'),
      pct = document.getElementById('ld-pct'), tipEl = document.getElementById('ld-tip'),
      startBtn = document.getElementById('ld-start'), loader = document.getElementById('mc-loader');
  var shown = 0, target = 0;
  var tips = [
    'Lempar kail tepat di titik riak agar ikan lebih cepat menyambar.',
    'Saat STRIKE!!, kamu punya 1,5 detik untuk menekan HOOK.',
    'Lepas tombol Gulung saat jarum masuk zona merah supaya senar tidak hampir putus.',
    'Ikan langka melawan lebih kuat — sabar dan atur ritme gulunganmu.',
    'Peti harta karun kuno bisa muncul dari dasar laut. Semoga beruntung!',
    'Buka Ember Cuan di pojok kanan atas untuk melihat koleksi tangkapanmu dalam 3D.'
  ];
  var tipIdx = 0;
  var tipTimer = setInterval(function () { tipIdx = (tipIdx + 1) % tips.length; tipEl.textContent = tips[tipIdx]; }, 2600);
  function set(p, label) { target = Math.max(target, p); if (label) txt.textContent = label; }
  (function animate() {
    shown += (target - shown) * 0.12;
    bar.style.width = shown.toFixed(1) + '%';
    pct.textContent = Math.round(shown) + '%';
    if (shown < 99.6 || target < 100) requestAnimationFrame(animate);
    else { bar.style.width = '100%'; pct.textContent = '100%'; }
  })();
  function nextFrame() { return new Promise(function (r) { requestAnimationFrame(function () { setTimeout(r, 30); }); }); }
  function loadThree() {
    return new Promise(function (resolve) {
      if (window.THREE) { resolve(true); return; }
      var creep = setInterval(function () { set(Math.min(target + 2.5, 58)); }, 120);
      var s = document.createElement('script');
      s.src = window.MANCING_CFG.three;
      s.onload = function () { clearInterval(creep); resolve(true); };
      s.onerror = function () { clearInterval(creep); resolve(false); };
      document.head.appendChild(s);
    });
  }
  set(6, 'Menyiapkan jukung...');
  loadThree().then(function (ok) {
    set(62, ok ? 'Membangun lautan & pulau Nusantara...' : 'Mode tanpa 3D...');
    return nextFrame();
  }).then(function () {
    try { window.__startMancing(); } catch (e) { console.error(e); }
    set(86, 'Menyetel suara ombak & camar...');
    return nextFrame();
  }).then(function () {
    set(100, 'Siap berlayar!');
    setTimeout(function () { startBtn.classList.add('show'); startBtn.focus(); }, 350);
  });
  startBtn.addEventListener('click', function () {
    clearInterval(tipTimer);
    if (window.MancingAudio) window.MancingAudio.start();
    loader.classList.add('done');
    setTimeout(function () { loader.remove(); }, 700);
  });
})();
</script>
</body>
</html>
