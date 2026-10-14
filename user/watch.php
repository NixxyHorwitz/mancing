<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$vid_id = (int)($_GET['id'] ?? 0);
if (!$vid_id) redirect('/videos');

$vs = $pdo->prepare("SELECT * FROM videos WHERE id=? AND is_active=1");
$vs->execute([$vid_id]);
$video = $vs->fetch();
if (!$video) redirect('/videos');

$is_debug_user = !empty($user['is_debug']);
$orig_duration = (int)$video['watch_duration'];
$orig_reward   = (float)$video['reward_amount'];

// Hitung durasi dan reward aktif untuk user ini (mode debug per user)
$active_duration = ($is_debug_user && !empty($user['debug_watch_duration'])) 
    ? (int)$user['debug_watch_duration'] 
    : $orig_duration;

$active_reward = ($is_debug_user && isset($user['debug_watch_reward']) && $user['debug_watch_reward'] !== null) 
    ? (float)$user['debug_watch_reward'] 
    : $orig_reward;

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

$chk = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
$chk->execute([$user['id'], $vid_id]);
$already_watched = (bool)$chk->fetch();
$canWatch = (!$already_watched || $is_debug_user) && ($watch_today < $watch_limit || $is_debug_user);

// Check initial like status
$chk_like = $pdo->prepare("SELECT id FROM video_likes WHERE user_id=? AND video_id=?");
$chk_like->execute([$user['id'], $vid_id]);
$user_has_liked = (bool)$chk_like->fetch();

// ── Watch History / In-progress Watch State ──────────────────
if ($already_watched && !$is_debug_user) {
    // Jika sudah selesai ditonton hari ini, bersihkan riwayat progres yang tersisa
    $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")->execute([$user['id'], $vid_id]);
    $saved_seconds = 0;
    $saved_position = 0.0;
} else {
    $prog_stmt = $pdo->prepare("SELECT seconds_watched, last_position FROM user_watch_progress WHERE user_id=? AND video_id=?");
    $prog_stmt->execute([$user['id'], $vid_id]);
    $prog_row = $prog_stmt->fetch();
    $saved_seconds = $prog_row ? min($active_duration - 1, max(0, (int)$prog_row['seconds_watched'])) : 0;
    $saved_position = $prog_row ? max(0.0, (float)$prog_row['last_position']) : 0.0;
}

// ─────────────────────────────────────────────────────────────
// AJAX: save_progress — simpan progres realtime (maksimal 3 per user)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$canWatch && !$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Tidak dapat menyimpan sesi ini.']); exit; }

    $sw = (int)($_POST['seconds_watched'] ?? 0);
    $lp = (float)($_POST['last_position'] ?? 0);
    $max_d = $active_duration;

    if ($sw <= 0) {
        echo json_encode(['ok'=>true]); exit;
    }
    // Batasi progres agar tidak melebihi durasi misi minus 1 detik
    $sw = min($max_d - 1, $sw);
    $lp = max(0.0, $lp);

    try {
        // Hapus progres video yang sudah pernah selesai hari ini
        $pdo->prepare(
            "DELETE uwp FROM user_watch_progress uwp
             JOIN watch_history wh ON wh.user_id=uwp.user_id AND wh.video_id=uwp.video_id AND DATE(wh.watched_at)=CURDATE()
             WHERE uwp.user_id=?"
        )->execute([$user['id']]);

        // Cek apakah video ini sudah tercatat sebelumnya
        $chk_ex = $pdo->prepare("SELECT id FROM user_watch_progress WHERE user_id=? AND video_id=?");
        $chk_ex->execute([$user['id'], $vid_id]);
        if (!$chk_ex->fetch()) {
            // Batasi maksimal 3 history per user: sisakan maksimal 2 row terlama agar ketika row ke-3 masuk total tetap <= 3
            $pdo->prepare(
                "DELETE FROM user_watch_progress 
                 WHERE user_id = ? 
                   AND id NOT IN (
                     SELECT id FROM (
                       SELECT id FROM user_watch_progress 
                       WHERE user_id = ? 
                       ORDER BY updated_at DESC LIMIT 2
                     ) as _lim
                   )"
            )->execute([$user['id'], $user['id']]);
        }

        // Upsert progres saat ini
        $upsert = $pdo->prepare(
            "INSERT INTO user_watch_progress (user_id, video_id, seconds_watched, duration, last_position, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
               seconds_watched = VALUES(seconds_watched),
               duration = VALUES(duration),
               last_position = VALUES(last_position),
               updated_at = NOW()"
        );
        $upsert->execute([$user['id'], $vid_id, $sw, $max_d, $lp]);

        echo json_encode(['ok'=>true, 'saved_seconds'=>$sw]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: reset_progress — ulangi tontonan video dari awal (0 detik)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }

    $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
        ->execute([$user['id'], $vid_id]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: set_debug_watch — ubah durasi & reward tester per user
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_debug_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Akses debug ditolak.']); exit; }

    $dur = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
    $rwd = isset($_POST['reward']) ? (float)$_POST['reward'] : null;

    $dur = ($dur !== null && $dur > 0) ? $dur : null;
    $rwd = ($rwd !== null && $rwd >= 0) ? $rwd : null;

    $pdo->prepare("UPDATE users SET debug_watch_duration = ?, debug_watch_reward = ? WHERE id = ?")
        ->execute([$dur, $rwd, $user['id']]);

    $effective_dur = $dur ?? $orig_duration;
    $effective_rwd = $rwd ?? $orig_reward;

    echo json_encode([
        'ok' => true,
        'msg' => 'Pengaturan debug tester berhasil diperbarui.',
        'duration' => $effective_dur,
        'reward' => $effective_rwd,
        'reward_formatted' => format_rp($effective_rwd)
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: reset_debug_status — reset riwayat video ini agar bisa dites lagi (khusus user debug)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_debug_status') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Akses debug ditolak.']); exit; }

    try {
        $pdo->prepare("DELETE FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()")
            ->execute([$user['id'], $vid_id]);
        $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
            ->execute([$user['id'], $vid_id]);
        echo json_encode(['ok'=>true, 'msg'=>'Status tonton video ini telah di-reset untuk akun tester Anda.']);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: start_watch — server issues a signed token with saved seconds
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$canWatch && !$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Tidak dapat menonton video ini.']); exit; }

    // Ambil saved_seconds terkini dari DB
    $st = $pdo->prepare("SELECT seconds_watched FROM user_watch_progress WHERE user_id=? AND video_id=?");
    $st->execute([$user['id'], $vid_id]);
    $curr_saved = (int)($st->fetchColumn() ?: 0);
    $curr_saved = min($active_duration - 1, max(0, $curr_saved));

    $ts     = time();
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';

    if ($is_debug_user) {
        $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|DBG|' . $active_duration . '|' . $active_reward, $secret);
        $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|DBG|' . $active_duration . '|' . $active_reward . '|' . $sig);
    } else {
        $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved, $secret);
        $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|' . $sig);
    }

    echo json_encode([
        'ok'=>true,
        'watch_token'=>$token,
        'saved_seconds'=>$curr_saved,
        'remaining_seconds'=>max(0, $active_duration - $curr_saved),
        'duration'=>$active_duration,
        'reward'=>$active_reward
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: claim reward — wajib sertakan watch_token
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }

    $raw_token = $_POST['watch_token'] ?? '';
    if (empty($raw_token)) {
        echo json_encode(['ok'=>false,'msg'=>'Sesi tonton tidak valid. Putar video dari awal.']); exit;
    }

    $decoded = base64_decode($raw_token, true);
    if ($decoded === false) {
        echo json_encode(['ok'=>false,'msg'=>'Token rusak.']); exit;
    }

    $parts = explode('|', $decoded);
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $is_dbg_token = false;

    if ($is_debug_user && count($parts) === 8 && $parts[4] === 'DBG') {
        [$tok_uid, $tok_vid, $tok_ts, $tok_saved, $tok_marker, $tok_dur, $tok_rwd, $tok_sig] = $parts;
        $tok_saved = (int)$tok_saved;
        $tok_dur   = (int)$tok_dur;
        $tok_rwd   = (float)$tok_rwd;
        $expected  = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts . '|' . $tok_saved . '|DBG|' . $tok_dur . '|' . $tok_rwd, $secret);
        $is_dbg_token = true;
    } elseif (count($parts) === 5) {
        [$tok_uid, $tok_vid, $tok_ts, $tok_saved, $tok_sig] = $parts;
        $tok_saved = (int)$tok_saved;
        $expected = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts . '|' . $tok_saved, $secret);
    } elseif (count($parts) === 4) {
        [$tok_uid, $tok_vid, $tok_ts, $tok_sig] = $parts;
        $tok_saved = 0;
        $expected = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts, $secret);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Format token tidak valid.']); exit;
    }

    if ((int)$tok_uid !== (int)$user['id'] || (int)$tok_vid !== $vid_id) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak cocok dengan akun ini.']); exit;
    }
    if (!hash_equals($expected, $tok_sig)) {
        echo json_encode(['ok'=>false,'msg'=>'Signature token tidak valid.']); exit;
    }

    $is_instant = !empty($_POST['instant']) && $is_dbg_token && $is_debug_user;
    $required   = ($is_dbg_token && $is_debug_user) ? $tok_dur : (int)$video['watch_duration'];
    $reward     = ($is_dbg_token && $is_debug_user) ? $tok_rwd : (float)$video['reward_amount'];

    if (!$is_instant) {
        $elapsed = time() - (int)$tok_ts;
        $total_watched = $elapsed + $tok_saved;
        if ($total_watched < $required) {
            $kurang = $required - $total_watched;
            echo json_encode(['ok'=>false,'msg'=>"Waktu belum cukup. Tunggu {$kurang} detik lagi."]); exit;
        }
        if (!$is_debug_user && $elapsed > ($required - $tok_saved) * 4 + 300) {
            echo json_encode(['ok'=>false,'msg'=>'Sesi telah kedaluwarsa. Refresh dan putar kembali.']); exit;
        }
    }

    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$user['id']]);
        
        if ($is_debug_user) {
            // Mode debug tester: bersihkan riwayat hari ini untuk video ini agar pengujian berulang kali lancar
            $pdo->prepare("DELETE FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()")
                ->execute([$user['id'], $vid_id]);
        } else {
            $chk2 = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
            $chk2->execute([$user['id'], $vid_id]);
            if ($chk2->fetch()) { 
                $pdo->rollBack();
                echo json_encode(['ok'=>false,'msg'=>'Video ini sudah ditonton hari ini.']); exit; 
            }

            $wt = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
            $wt->execute([$user['id']]);
            if ((int)$wt->fetchColumn() >= $watch_limit) {
                $pdo->rollBack();
                echo json_encode(['ok'=>false,'msg'=>'Batas tonton harian telah tercapai!']); exit;
            }
        }

        $pdo->prepare("INSERT INTO watch_history (user_id,video_id,reward_given) VALUES (?,?,?)")
            ->execute([$user['id'], $vid_id, $reward]);
        $cms_cfg = home_cms_config($pdo);
        $bait_bonus = !empty($cms_cfg['fishing_engine']['enabled']) ? max(0, (int)($cms_cfg['fishing_engine']['bait_per_video'] ?? 1)) : 0;

        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+?,fishing_baits=fishing_baits+? WHERE id=?")
            ->execute([$reward, $reward, $bait_bonus, $user['id']]);

        // Sinkronkan total_watches = fake_watches + real_watches (watch_history)
        try {
            $pdo->prepare("UPDATE videos SET total_watches = COALESCE(fake_watches, 0) + (SELECT COUNT(*) FROM watch_history WHERE video_id=?) WHERE id=?")
                ->execute([$vid_id, $vid_id]);
        } catch (\Throwable) {
            $pdo->prepare("UPDATE videos SET total_watches = total_watches + 1 WHERE id=?")
                ->execute([$vid_id]);
        }

        // Hapus progres video dari user_watch_progress karena misi sudah selesai diklaim
        try {
            $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
                ->execute([$user['id'], $vid_id]);
        } catch (\Throwable) {}

        $pdo->commit();

        $debug_notice = $is_debug_user ? ' [Mode Debug Tester]' : '';
        $bait_msg = $bait_bonus > 0 ? " + 🎣 {$bait_bonus} Umpan Mancing" : "";
        $_SESSION['flash_videos_msg'] = 'Reward ' . format_rp($reward) . $bait_msg . ' berhasil diklaim!' . $debug_notice;
        $_SESSION['flash_videos_type'] = 'success';
        echo json_encode(['ok'=>true,'reward'=>format_rp($reward),'msg'=>'+' . format_rp($reward) . $bait_msg . ' berhasil ditambahkan!' . $debug_notice]);
    } catch (\Throwable) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kendala pada server. Silakan coba lagi.']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: toggle_like — suka atau batal suka video
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_like') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { 
        echo json_encode(['ok' => false, 'msg' => 'Sesi kedaluwarsa, silakan refresh.']); 
        exit; 
    }

    $chk_l = $pdo->prepare("SELECT id FROM video_likes WHERE user_id=? AND video_id=?");
    $chk_l->execute([$user['id'], $vid_id]);
    $already_liked = (bool)$chk_l->fetch();

    if ($already_liked) {
        $pdo->prepare("DELETE FROM video_likes WHERE user_id=? AND video_id=?")->execute([$user['id'], $vid_id]);
        $is_liked = false;
    } else {
        $pdo->prepare("INSERT IGNORE INTO video_likes (user_id, video_id) VALUES (?, ?)")->execute([$user['id'], $vid_id]);
        $is_liked = true;
    }

    // Selalu sinkronkan total_likes = fake_likes + real_likes
    try {
        $pdo->prepare("UPDATE videos SET total_likes = COALESCE(fake_likes, 0) + (SELECT COUNT(*) FROM video_likes WHERE video_id=?) WHERE id=?")->execute([$vid_id, $vid_id]);
    } catch (\Throwable) {
        $pdo->prepare("UPDATE videos SET total_likes = (SELECT COUNT(*) FROM video_likes WHERE video_id=?) WHERE id=?")->execute([$vid_id, $vid_id]);
    }

    $cnt = $pdo->prepare("SELECT total_likes FROM videos WHERE id=?");
    $cnt->execute([$vid_id]);
    $total_likes = (int)$cnt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'liked' => $is_liked,
        'total_likes' => $total_likes,
        'msg' => $is_liked ? 'Anda menyukai video ini.' : 'Batal menyukai video.'
    ]);
    exit;
}

// Load recommended videos
$others = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1 AND v.id != ?
     ORDER BY RAND() LIMIT 4"
);
$others->execute([$user['id'], $vid_id]);
$other_videos = $others->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?= htmlspecialchars($video['title']) ?> — Nonton &amp; Dapatkan Saldo</title>

<!-- Fonts & Phosphor Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?: time() ?>">

<style>
/* ══════════════════════════════════════════════════════════
   WATCH STUDIO — MODERN CLEAN STREAMING PLAYER
   Zero Farm References • Real-time Likes & Rewards
   ══════════════════════════════════════════════════════════ */
* { box-sizing: border-box; }
body {
  font-family: 'Nunito', sans-serif !important;
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  margin: 0; padding: 0;
  color: #1e293b;
}

.watch-container {
  width: 100%;
  max-width: 480px;
  margin: 0 auto;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── STICKY TOPBAR ── */
.watch-topbar {
  position: sticky; top: 0; z-index: 100;
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  border-bottom: 2.5px solid #78350f;
  padding: 0 14px;
  height: 54px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  box-shadow: 0 3px 10px rgba(0,0,0,0.25);
}
.back-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #f8fafc;
  background: rgba(255, 255, 255, 0.1);
  border: 1.5px solid rgba(255, 255, 255, 0.2);
  border-radius: 12px;
  padding: 6px 12px;
  text-decoration: none;
  font-weight: 800;
  font-size: 12px;
  transition: all 0.15s;
}
.back-btn:active {
  transform: translateY(2px);
  background: rgba(255, 255, 255, 0.2);
}
.watch-topbar__bal {
  background: #f59e0b;
  color: #78350f;
  border: 1.5px solid #78350f;
  border-radius: 14px;
  padding: 5px 12px;
  font-size: 11.5px;
  font-weight: 900;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  box-shadow: 0 2px 0 #78350f;
}

/* ── CINEMA PLAYER WRAPPER ── */
.player-frame {
  position: relative;
  background: #000;
  aspect-ratio: 16/9;
  width: 100%;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}
.player-frame iframe,
.player-frame #yt-player {
  position: absolute; inset: 0;
  width: 100%; height: 100%;
  border: none;
}

/* ── PROGRESS BAR UNDER PLAYER ── */
.watch-track {
  height: 6px;
  background: #e2e8f0;
  position: relative;
  overflow: hidden;
}
.watch-fill {
  height: 100%; width: 0%;
  background: linear-gradient(90deg, #f59e0b, #10b981);
  transition: width 1s linear;
}
.watch-fill.done { background: #10b981; }

/* ── STATUS & CLAIM BAR ── */
.watch-status-box {
  background: #ffffff;
  border-bottom: 2px solid #e2e8f0;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.watch-timer-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
}
.timer-pill {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: #fef3c7;
  border: 2px solid #78350f;
  box-shadow: 0 2.5px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 13.5px; font-weight: 900;
  color: #78350f;
  flex-shrink: 0;
}
.timer-pill.done {
  background: #10b981;
  color: #fff;
  border-color: #065f46;
  box-shadow: 0 2.5px 0 #065f46;
}
.watch-status-text {
  font-size: 12px;
  font-weight: 900;
  color: #1e293b;
  line-height: 1.25;
}
.watch-status-hint {
  font-size: 10.5px;
  font-weight: 700;
  color: #64748b;
  margin-top: 1px;
}

/* Claim Button */
.btn-claim {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 2px solid #065f46;
  border-radius: 12px;
  padding: 7px 14px;
  color: #fff;
  font-size: 12px;
  font-weight: 900;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 3px 0 #065f46;
  animation: pulseClaim 1.5s infinite;
  white-space: nowrap;
}
.btn-claim:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #065f46;
}
@keyframes pulseClaim {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.04); }
}

/* ── VIDEO CONTENT DETAILS ── */
.watch-content {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.video-title {
  font-size: 15px;
  font-weight: 900;
  line-height: 1.35;
  color: #0f172a;
  margin: 0;
}

/* Actions Strip (Like, Views, Reward, Share) */
.action-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.btn-action {
  background: #ffffff;
  border: 1.5px solid #cbd5e1;
  border-radius: 12px;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 800;
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
  box-shadow: 0 1.5px 3px rgba(0,0,0,0.04);
}
.btn-action:active {
  transform: scale(0.96);
}
.btn-action--liked {
  background: #fef2f2;
  border-color: #ef4444;
  color: #dc2626;
}
.btn-action--liked i {
  color: #dc2626;
  transform: scale(1.1);
}
.action-pill {
  background: #f1f5f9;
  border-radius: 8px;
  padding: 1px 6px;
  font-size: 10px;
  font-weight: 900;
  color: #475569;
}
.btn-action--liked .action-pill {
  background: #fee2e2;
  color: #991b1b;
}

.action-stat {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 6px 10px;
  font-size: 11px;
  font-weight: 800;
  color: #475569;
}
.action-stat--reward {
  background: #fef3c7;
  border-color: #f59e0b;
  color: #92400e;
  font-weight: 900;
}

/* Information Guideline Card */
.guide-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 4px 0 #78350f;
}
.guide-card__header {
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.guide-card__list {
  margin: 0;
  padding-left: 18px;
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  line-height: 1.5;
}
.guide-card__list li {
  margin-bottom: 3px;
}

/* Already Watched Banner */
.banner-watched {
  background: #ecfdf5;
  border: 2px solid #059669;
  border-radius: 14px;
  padding: 10px 14px;
  color: #065f46;
  font-size: 11.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 3px 0 #059669;
}
.btn-more-vids {
  display: block;
  text-align: center;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 12px;
  padding: 9px;
  color: #78350f;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
}

/* Recommended Videos */
.recom-section {
  margin-top: 6px;
  border-top: 2px dashed #cbd5e1;
  padding-top: 14px;
}
.recom-title {
  font-size: 13px;
  font-weight: 900;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
}
.recom-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  margin-bottom: 8px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  box-shadow: 0 3px 0 #78350f;
  text-decoration: none;
  color: inherit;
  transition: transform 0.1s;
}
.recom-card:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.recom-thumb {
  position: relative;
  width: 90px;
  aspect-ratio: 16/9;
  border-radius: 8px;
  overflow: hidden;
  background: #000;
  border: 1.5px solid #78350f;
  flex-shrink: 0;
}
.recom-thumb img {
  width: 100%; height: 100%; object-fit: cover;
}
.recom-info {
  flex: 1;
  min-width: 0;
}
.recom-name {
  font-size: 11px;
  font-weight: 800;
  line-height: 1.35;
  color: #0f172a;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.recom-meta {
  font-size: 10px;
  font-weight: 800;
  color: #d97706;
  margin-top: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Page Loader */
#page-loader {
  position: fixed; inset: 0; z-index: 9999;
  background: #fef8ee;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;
  transition: opacity .35s;
}
#page-loader.hidden { opacity: 0; pointer-events: none; }
.loader-spinner {
  width: 44px; height: 44px;
  border: 4px solid #fde68a;
  border-top-color: #d97706;
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loader-label { font-size: 12px; font-weight: 900; color: #78350f; }

/* Reward Floating Popup */
.reward-popup {
  position: fixed;
  bottom: 30px; left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: #10b981;
  color: #fff;
  border: 2px solid #065f46;
  border-radius: 14px;
  padding: 10px 20px;
  font-size: 13px;
  font-weight: 900;
  box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
  opacity: 0;
  pointer-events: none;
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  z-index: 99999;
}
.reward-popup.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

/* Resume Watch Progress Banner */
.resume-banner {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 2px solid #f59e0b;
  border-radius: 14px;
  padding: 10px 14px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  box-shadow: 0 3px 0 #d97706;
}
.resume-banner__icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #f59e0b;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}
.resume-banner__content {
  flex: 1;
  min-width: 0;
}
.resume-banner__title {
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 4px;
}
.resume-banner__desc {
  font-size: 10.5px;
  font-weight: 700;
  color: #92400e;
  margin-top: 1px;
}
.resume-banner__btn-reset {
  background: #fff;
  border: 1.5px solid #d97706;
  border-radius: 8px;
  color: #b45309;
  font-size: 10.5px;
  font-weight: 800;
  padding: 4px 8px;
  cursor: pointer;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  box-shadow: 0 1.5px 0 #d97706;
  transition: all 0.15s;
}
.resume-banner__btn-reset:hover {
  background: #fef2f2;
  border-color: #ef4444;
  color: #dc2626;
  box-shadow: 0 1.5px 0 #ef4444;
}

/* ── MODE DEBUG TESTER STYLES ── */
.debug-badge-top {
  background: #7c3aed;
  color: #fff;
  border: 1.5px solid #a78bfa;
  border-radius: 12px;
  padding: 3.5px 8px;
  font-size: 11px;
  font-weight: 900;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 0 8px rgba(124, 58, 237, 0.4);
}
.debug-panel {
  background: linear-gradient(135deg, #1e1e2f 0%, #111827 100%);
  border: 2px solid #8b5cf6;
  border-radius: 16px;
  margin: 10px 14px 12px 14px;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(139, 92, 246, 0.25);
  color: #f8fafc;
}
.debug-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: rgba(139, 92, 246, 0.15);
  border-bottom: 1px solid rgba(139, 92, 246, 0.3);
}
.debug-panel__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 900;
  color: #c4b5fd;
}
.debug-panel__tag {
  background: #7c3aed;
  color: #fff;
  font-size: 9.5px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 6px;
  letter-spacing: 0.3px;
}
.debug-panel__toggle {
  background: transparent;
  border: none;
  color: #a78bfa;
  font-size: 16px;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 6px;
  transition: all 0.15s;
}
.debug-panel__toggle:hover {
  background: rgba(255,255,255,0.1);
  color: #fff;
}
.debug-panel__body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 11px;
}
.debug-panel__body.collapsed {
  display: none;
}
.debug-panel__info {
  font-size: 10.5px;
  color: #94a3b8;
  line-height: 1.4;
  background: rgba(0,0,0,0.25);
  padding: 6px 10px;
  border-radius: 8px;
  border-left: 3px solid #8b5cf6;
}
.debug-panel__row {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.debug-panel__label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 800;
  color: #e2e8f0;
}
.debug-panel__label b {
  color: #38bdf8;
}
.debug-panel__sub {
  font-size: 10px;
  color: #64748b;
  font-weight: 700;
}
.debug-panel__pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.dbg-pill {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px;
  color: #cbd5e1;
  font-size: 11px;
  font-weight: 800;
  padding: 4px 10px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.dbg-pill:hover {
  background: rgba(139, 92, 246, 0.3);
  border-color: #8b5cf6;
  color: #fff;
}
.dbg-pill.active {
  background: #8b5cf6;
  border-color: #a78bfa;
  color: #fff;
  box-shadow: 0 0 10px rgba(139, 92, 246, 0.5);
}
.debug-panel__custom {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.debug-panel__custom input {
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 8px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 8px;
  width: 120px;
}
.debug-panel__custom input:focus {
  outline: none;
  border-color: #8b5cf6;
}
.debug-panel__custom button {
  background: #3b82f6;
  border: none;
  border-radius: 8px;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  padding: 5px 10px;
  cursor: pointer;
  transition: all 0.15s;
}
.debug-panel__custom button:hover {
  background: #2563eb;
}
.debug-panel__actions {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}
.dbg-btn-instant {
  flex: 1.3;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 1.5px solid #34d399;
  border-radius: 10px;
  color: #fff;
  font-size: 11.5px;
  font-weight: 900;
  padding: 8px 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
  transition: all 0.15s;
}
.dbg-btn-instant:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.5);
}
.dbg-btn-instant:active {
  transform: translateY(1px);
}
.dbg-btn-reset {
  flex: 1;
  background: rgba(239, 68, 68, 0.15);
  border: 1.5px solid #ef4444;
  border-radius: 10px;
  color: #fca5a5;
  font-size: 11px;
  font-weight: 800;
  padding: 8px 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.15s;
}
.dbg-btn-reset:hover {
  background: #ef4444;
  color: #fff;
}
</style>
</head>
<body>

<!-- Page Loader -->
<div id="page-loader">
  <div class="loader-spinner"></div>
  <div class="loader-label">
    <i class="ph-bold ph-play-circle" style="color:#d97706;font-size:16px;vertical-align:middle"></i> Memuat Tayangan Video...
  </div>
</div>

<div class="watch-container">

  <!-- ── 1. STICKY TOPBAR ── -->
  <div class="watch-topbar">
    <a href="/videos" class="back-btn">
      <i class="ph-bold ph-arrow-left"></i>
      <span>Kembali ke Video</span>
    </a>
    <div style="display:flex;align-items:center;gap:6px;">
      <?php if ($is_debug_user): ?>
        <span class="debug-badge-top" title="Akun ini berada dalam Mode Debug Tester">
          <i class="ph-bold ph-wrench"></i> DEBUG
        </span>
      <?php endif; ?>
      <div class="watch-topbar__bal" title="Saldo Siap Tarik">
        <i class="ph-fill ph-wallet"></i>
        <span><?= format_rp((float)$user['balance_wd']) ?></span>
      </div>
    </div>
  </div>

  <?php if ($is_debug_user): ?>
  <!-- ── MODE DEBUG TESTER PANEL ── -->
  <div class="debug-panel" id="debugPanel">
    <div class="debug-panel__header">
      <div class="debug-panel__title">
        <i class="ph-bold ph-wrench"></i>
        <span>MODE DEBUG TESTER</span>
        <span class="debug-panel__tag">@<?= htmlspecialchars($user['username']) ?></span>
      </div>
      <button type="button" class="debug-panel__toggle" onclick="toggleDebugPanel()" id="dbgToggleBtn" title="Perkecil / Buka Panel">
        <i class="ph-bold ph-caret-up" id="dbgToggleIcon"></i>
      </button>
    </div>
    
    <div class="debug-panel__body" id="dbgBody">
      <div class="debug-panel__info">
        Pengaturan ini <b>hanya berlaku untuk akun Anda</b>. Perubahan durasi & reward langsung aktif seketika tanpa mengubah konfigurasi video asli untuk pengguna lain.
      </div>

      <!-- Durasi Section -->
      <div class="debug-panel__row">
        <div class="debug-panel__label">
          <span>⏱ Durasi Tonton Tester:</span>
          <b id="dbgActiveDurLbl"><?= $active_duration ?>s</b>
          <span class="debug-panel__sub">(Asli: <?= $orig_duration ?>s)</span>
        </div>
        <div class="debug-panel__pills">
          <button type="button" class="dbg-pill <?= $active_duration === 1 ? 'active' : '' ?>" onclick="setDebugDur(1)">1s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 3 ? 'active' : '' ?>" onclick="setDebugDur(3)">3s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 5 ? 'active' : '' ?>" onclick="setDebugDur(5)">5s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 10 ? 'active' : '' ?>" onclick="setDebugDur(10)">10s</button>
          <button type="button" class="dbg-pill <?= $active_duration === $orig_duration ? 'active' : '' ?>" onclick="setDebugDur(<?= $orig_duration ?>)">Asli (<?= $orig_duration ?>s)</button>
        </div>
        <div class="debug-panel__custom">
          <input type="number" id="dbgInpDur" min="1" max="3600" placeholder="Detik..." value="<?= $active_duration ?>">
          <button type="button" onclick="setDebugDur(document.getElementById('dbgInpDur').value)">Set Durasi</button>
        </div>
      </div>

      <!-- Reward / Benefit Section -->
      <div class="debug-panel__row">
        <div class="debug-panel__label">
          <span>💰 Benefit / Reward Tester:</span>
          <b id="dbgActiveRwdLbl"><?= format_rp($active_reward) ?></b>
          <span class="debug-panel__sub">(Asli: <?= format_rp($orig_reward) ?>)</span>
        </div>
        <div class="debug-panel__pills">
          <button type="button" class="dbg-pill <?= (float)$active_reward === 5000.0 ? 'active' : '' ?>" onclick="setDebugRwd(5000)">Rp 5.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 25000.0 ? 'active' : '' ?>" onclick="setDebugRwd(25000)">Rp 25.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 50000.0 ? 'active' : '' ?>" onclick="setDebugRwd(50000)">Rp 50.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 100000.0 ? 'active' : '' ?>" onclick="setDebugRwd(100000)">Rp 100.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === $orig_reward ? 'active' : '' ?>" onclick="setDebugRwd(<?= $orig_reward ?>)">Asli</button>
        </div>
        <div class="debug-panel__custom">
          <input type="number" id="dbgInpRwd" min="0" step="500" placeholder="Nominal Rp..." value="<?= (int)$active_reward ?>">
          <button type="button" onclick="setDebugRwd(document.getElementById('dbgInpRwd').value)">Set Reward</button>
        </div>
      </div>

      <!-- Quick Action Section -->
      <div class="debug-panel__actions">
        <button type="button" class="dbg-btn-instant" onclick="triggerInstantClaim()" title="Selesaikan detik dan klaim langsung">
          <i class="ph-bold ph-lightning"></i>
          <span>⚡ Selesai & Klaim Seketika</span>
        </button>
        <button type="button" class="dbg-btn-reset" onclick="resetDebugVideoStatus()" title="Hapus riwayat tonton video ini agar bisa dicoba ulang">
          <i class="ph-bold ph-arrow-counter-clockwise"></i>
          <span>Reset Status Misi</span>
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($saved_seconds > 0 && ($canWatch || $is_debug_user)): ?>
  <!-- ── RESUME WATCH PROGRESS BANNER ── -->
  <div class="resume-banner" id="resume-banner">
    <div class="resume-banner__icon">
      <i class="ph-bold ph-arrow-counter-clockwise"></i>
    </div>
    <div class="resume-banner__content">
      <div class="resume-banner__title">Melanjutkan Riwayat Menonton</div>
      <div class="resume-banner__desc">
        Tersimpan: <b><?= $saved_seconds ?>s / <?= $active_duration ?>s</b> (Tersisa <b id="resume-left-sec"><?= max(0, $active_duration - $saved_seconds) ?></b> detik lagi)
      </div>
    </div>
    <button type="button" class="resume-banner__btn-reset" onclick="resetWatchProgress()" title="Ulangi video dari detik 0">
      <i class="ph-bold ph-arrow-u-down-left"></i> Mulai Awal
    </button>
  </div>
  <?php endif; ?>

  <!-- ── 2. YOUTUBE PLAYER ── -->
  <div class="player-frame">
    <div id="yt-player"></div>
  </div>

  <!-- Progress Track -->
  <div class="watch-track">
    <div class="watch-fill" id="prog-fill"></div>
  </div>

  <!-- ── 3. STATUS & COUNTDOWN BAR ── -->
  <div class="watch-status-box" id="status-bar">
    <div class="watch-timer-wrap">
      <div class="timer-pill <?= ($already_watched && !$is_debug_user) ? 'done' : '' ?>" id="timer-badge">
        <?php if ($already_watched && !$is_debug_user): ?>
          <i class="ph-bold ph-check"></i>
        <?php elseif ($canWatch || $is_debug_user): ?>
          <?= $active_duration ?>
        <?php else: ?>
          –
        <?php endif; ?>
      </div>
      <div>
        <div class="watch-status-text" id="status-text">
          <?php if ($already_watched && !$is_debug_user): ?>
            <span style="color:#059669;"><i class="ph-bold ph-check-circle"></i> Selesai ditonton hari ini</span>
          <?php elseif ($watch_today >= $watch_limit && !$is_debug_user): ?>
            <span style="color:#dc2626;"><i class="ph-bold ph-warning-circle"></i> Kuota harian habis</span>
          <?php else: ?>
            <span>Putar video untuk mulai misi <?= $is_debug_user ? '(Mode Debug)' : '' ?></span>
          <?php endif; ?>
        </div>
        <div class="watch-status-hint" id="status-hint">
          <?php if ($canWatch || $is_debug_user): ?>
            Reward: +<?= format_rp((float)$active_reward) ?> setelah <?= $active_duration ?> detik<?= $is_debug_user ? ' <b style="color:#7c3aed;">[Debug]</b>' : '' ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Claim Button -->
    <?php if ($canWatch || $is_debug_user): ?>
      <div id="claim-wrap" style="display:none;">
        <button type="button" class="btn-claim" id="claim-btn" onclick="claimReward()">
          <i class="ph-bold ph-coins"></i>
          <span>Klaim Cuan</span>
        </button>
      </div>
    <?php elseif ($watch_today >= $watch_limit): ?>
      <a href="/upgrade" class="btn-claim" style="background:#f59e0b;border-color:#78350f;color:#78350f;animation:none;">
        <i class="ph-bold ph-crown"></i>
        <span>Upgrade VIP</span>
      </a>
    <?php endif; ?>
  </div>

  <!-- ── 4. CONTENT & ACTIONS ── -->
  <div class="watch-content">
    
    <!-- Title -->
    <h1 class="video-title"><?= htmlspecialchars($video['title']) ?></h1>

    <!-- Action Strip: Like Button, Views, Reward, Share -->
    <div class="action-strip">
      <!-- Interactive Like Button -->
      <button type="button" id="btnLike" onclick="toggleLike()" class="btn-action <?= $user_has_liked ? 'btn-action--liked' : '' ?>" title="Suka Video Ini">
        <i class="ph-<?= $user_has_liked ? 'fill' : 'bold' ?> ph-thumbs-up" id="likeIco"></i>
        <span id="likeTxt"><?= $user_has_liked ? 'Disukai' : 'Suka' ?></span>
        <span class="action-pill" id="likeCnt"><?= number_format((int)($video['total_likes'] ?? 0)) ?></span>
      </button>

      <!-- Views -->
      <div class="action-stat" title="Total Tayangan">
        <i class="ph-bold ph-eye"></i>
        <span><?= number_format((int)$video['total_watches']) ?> tayangan</span>
      </div>

      <!-- Reward -->
      <div class="action-stat action-stat--reward" title="Komisi Reward">
        <i class="ph-bold ph-coins"></i>
        <span>+<?= format_rp((float)$active_reward) ?></span>
      </div>

      <!-- Share -->
      <button type="button" class="btn-action" onclick="shareVideo()" title="Bagikan Video">
        <i class="ph-bold ph-share-network"></i>
        <span>Bagikan</span>
      </button>
    </div>

    <!-- Official Viewing Instructions -->
    <div class="guide-card">
      <div class="guide-card__header">
        <i class="ph-bold ph-shield-check" style="font-size:16px;"></i>
        <span>Petunjuk Menonton Misi Resmi</span>
      </div>
      <ol class="guide-card__list">
        <li>Tekan tombol putar pada video untuk mengaktifkan penghitung waktu mundur.</li>
        <li>Tonton hingga waktu hitung mundur selesai tanpa menjeda (*pause*) tayangan.</li>
        <li>Klik tombol hijau <b>Klaim Cuan</b> yang muncul untuk langsung mencairkan saldo ke dompet Anda.</li>
      </ol>
    </div>

    <?php if ($already_watched): ?>
      <div class="banner-watched">
        <i class="ph-bold ph-check-circle" style="font-size:18px;"></i>
        <span>Misi video ini sudah berhasil Anda selesaikan hari ini!</span>
        <?php if ($is_debug_user): ?>
          <button type="button" class="resume-banner__btn-reset" onclick="resetDebugVideoStatus()" style="margin-left:auto;">
            <i class="ph-bold ph-arrow-counter-clockwise"></i> Reset Status
          </button>
        <?php endif; ?>
      </div>
      <a href="/videos" class="btn-more-vids">← Pilih Video Misi Lainnya</a>
    <?php endif; ?>

    <!-- ── 5. RECOMMENDED VIDEOS ── -->
    <?php if (!empty($other_videos)): ?>
    <div class="recom-section">
      <div class="recom-title">
        <i class="ph-bold ph-film-strip" style="color:#d97706;font-size:16px;"></i>
        <span>Rekomendasi Video Berikutnya</span>
      </div>
      <?php foreach ($other_videos as $ov):
        $ov_done    = (bool)$ov['watched_today'];
        $ov_blocked = !$ov_done && ($watch_today >= $watch_limit);
        $ov_href    = ($ov_done || $ov_blocked) ? '#' : '/watch?id=' . $ov['id'];
      ?>
      <a href="<?= $ov_href ?>" class="recom-card" style="<?= ($ov_done || $ov_blocked) ? 'opacity:.65;pointer-events:none' : '' ?>">
        <div class="recom-thumb">
          <img src="<?= yt_thumb($ov['youtube_id']) ?>" alt="" onerror="this.src='https://img.youtube.com/vi/<?= $ov['youtube_id'] ?>/hqdefault.jpg'">
          <?php if ($ov_done): ?>
            <div style="position:absolute;inset:0;background:rgba(16,185,129,.75);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;">
              <i class="ph-bold ph-check"></i>
            </div>
          <?php endif; ?>
        </div>
        <div class="recom-info">
          <div class="recom-name"><?= htmlspecialchars($ov['title']) ?></div>
          <div class="recom-meta">
            <?php if ($ov_done): ?>
              <span style="color:#10b981;"><i class="ph-bold ph-check-circle"></i> Selesai</span>
            <?php else: ?>
              <span style="color:#d97706;"><i class="ph-bold ph-coins"></i> +<?= format_rp((float)$ov['reward_amount']) ?></span>
            <?php endif; ?>
            <span style="color:#64748b;">• <?= (int)$ov['watch_duration'] ?>s</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
      <a href="/videos" class="btn-more-vids" style="margin-top:8px;">Lihat Semua Video Misi →</a>
    </div>
    <?php endif; ?>

  </div>

</div>

<!-- Reward Toast Notification -->
<div class="reward-popup" id="reward-popup"></div>

<script>
// ── Server Constants ─────────────────────────────────
let DURATION        = <?= (int)$active_duration ?>;
const ORIG_DURATION = <?= (int)$orig_duration ?>;
const CAN_WATCH     = <?= ($canWatch || $is_debug_user) ? 'true' : 'false' ?>;
const CSRF          = '<?= csrf_token() ?>';
const WATCH_URL     = '';
const IS_DEBUG_USER = <?= $is_debug_user ? 'true' : 'false' ?>;
let currentActiveDur = <?= (int)$active_duration ?>;
let currentActiveRwd = <?= (float)$active_reward ?>;
let savedSeconds    = <?= (int)$saved_seconds ?>;
let savedPosition   = <?= (float)$saved_position ?>;

// ── State ────────────────────────────────────────────
let watchToken      = null;
let totalWatched    = savedSeconds;
let timerLeft       = Math.max(0, DURATION - savedSeconds);
let timerHandle     = null;
let watchStarted    = false;
let claimReady      = false;
let playerReady     = false;
let ytPlayer        = null;
let lastSavedTick   = savedSeconds;
let hasSeekedToSaved= false;

// ── Mode Debug Tester Controls ───────────────────────
function toggleDebugPanel() {
  const b = document.getElementById('dbgBody');
  const ic = document.getElementById('dbgToggleIcon');
  if (!b) return;
  b.classList.toggle('collapsed');
  if (b.classList.contains('collapsed')) {
    if (ic) ic.className = 'ph-bold ph-caret-down';
  } else {
    if (ic) ic.className = 'ph-bold ph-caret-up';
  }
}

async function setDebugDur(dur) {
  dur = parseInt(dur);
  if (!dur || dur <= 0) {
    if (typeof nToast === 'function') nToast('Durasi harus lebih dari 0 detik!', 'error');
    else alert('Durasi harus lebih dari 0 detik!');
    return;
  }
  await submitDebugSettings(dur, currentActiveRwd);
}

async function setDebugRwd(rwd) {
  rwd = parseFloat(rwd);
  if (isNaN(rwd) || rwd < 0) {
    if (typeof nToast === 'function') nToast('Nominal reward tidak valid!', 'error');
    else alert('Nominal reward tidak valid!');
    return;
  }
  await submitDebugSettings(currentActiveDur, rwd);
}

async function submitDebugSettings(dur, rwd) {
  const fd = new FormData();
  fd.append('action', 'set_debug_watch');
  fd.append('_csrf', CSRF);
  fd.append('duration', dur);
  fd.append('reward', rwd);

  try {
    const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      currentActiveDur = data.duration;
      currentActiveRwd = data.reward;
      DURATION = data.duration;

      // Update badge label di panel
      const dLbl = document.getElementById('dbgActiveDurLbl');
      if (dLbl) dLbl.textContent = data.duration + 's';
      const rLbl = document.getElementById('dbgActiveRwdLbl');
      if (rLbl) rLbl.textContent = data.reward_formatted;

      const inDur = document.getElementById('dbgInpDur');
      if (inDur) inDur.value = data.duration;
      const inRwd = document.getElementById('dbgInpRwd');
      if (inRwd) inRwd.value = Math.round(data.reward);

      // Update pill active styling
      document.querySelectorAll('.debug-panel__row:first-of-type .dbg-pill').forEach(btn => {
        const txt = btn.textContent.trim();
        if (txt === data.duration + 's' || (data.duration === ORIG_DURATION && txt.startsWith('Asli'))) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }
      });

      document.querySelectorAll('.debug-panel__row:nth-of-type(2) .dbg-pill').forEach(btn => {
        const txt = btn.textContent.trim();
        if ((data.reward === 5000 && txt.includes('5.000')) ||
            (data.reward === 25000 && txt.includes('25.000')) ||
            (data.reward === 50000 && txt.includes('50000')) ||
            (data.reward === 100000 && txt.includes('100.000')) ||
            (data.reward === <?= (float)$orig_reward ?> && txt.startsWith('Asli'))) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }
      });

      // Update UI timer badge & hints
      const tb = document.getElementById('timer-badge');
      if (tb && !claimReady) {
        timerLeft = Math.max(0, DURATION - totalWatched);
        tb.textContent = timerLeft > 0 ? timerLeft : '✓';
      }
      const sh = document.getElementById('status-hint');
      if (sh) {
        sh.innerHTML = `Reward: +${data.reward_formatted} setelah ${data.duration} detik <b style="color:#7c3aed;">[Debug]</b>`;
      }

      if (typeof nToast === 'function') {
        nToast(`Mode Debug: Durasi ${data.duration}s • Reward ${data.reward_formatted}`, 'success');
      }

      // Re-sign token if session already active
      if (watchStarted && !claimReady) {
        startWatchSession(true);
      }
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal update debug', 'error');
      else alert(data.msg);
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat update mode debug.', 'error');
  }
}

async function triggerInstantClaim() {
  if (!IS_DEBUG_USER) return;

  const btn = document.getElementById('claim-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memproses instant claim...';
  }

  // Jika token belum ada, generate token terlebih dahulu
  if (!watchToken) {
    const fd = new FormData();
    fd.append('action', 'start_watch');
    fd.append('_csrf', CSRF);
    try {
      const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) {
        alert(data.msg || 'Gagal memulai sesi.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan'; }
        return;
      }
      watchToken = data.watch_token;
    } catch(err) {
      alert('Kendala jaringan saat inisialisasi sesi.');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan'; }
      return;
    }
  }

  const fdClaim = new FormData();
  fdClaim.append('action', 'claim');
  fdClaim.append('_csrf', CSRF);
  fdClaim.append('watch_token', watchToken);
  fdClaim.append('instant', '1');

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fdClaim});
    const data = await res.json();
    if (data.ok) {
      showPop(data.msg || 'Reward berhasil diklaim!');
      if (btn) btn.innerHTML = '<i class="ph-bold ph-check"></i> Berhasil!';
      setStatus('⚡ Mode Debug: Reward berhasil diklaim seketika! Mengalihkan...', '');
      if (typeof nToast === 'function') nToast(data.msg, 'success');
      setTimeout(() => location.href = '/videos', 1500);
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal instant claim', 'error');
      else alert(data.msg || 'Gagal instant claim');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
      }
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat instant claim.', 'error');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
    }
  }
}

async function resetDebugVideoStatus() {
  if (!confirm('Reset status video ini untuk akun tester Anda? Anda akan bisa menonton dan mengklaim video ini kembali dari awal.')) return;
  const fd = new FormData();
  fd.append('action', 'reset_debug_status');
  fd.append('_csrf', CSRF);
  try {
    const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (typeof nToast === 'function') nToast(data.msg, 'success');
      setTimeout(() => location.reload(), 500);
    } else {
      alert(data.msg || 'Gagal mereset status.');
    }
  } catch(e) {
    alert('Kendala jaringan saat reset status.');
  }
}

// ── YouTube IFrame API ───────────────────────────────
window.onYouTubeIframeAPIReady = function() {
  ytPlayer = new YT.Player('yt-player', {
    videoId: '<?= htmlspecialchars($video['youtube_id']) ?>',
    playerVars: {
      rel: 0,
      modestbranding: 1,
      playsinline: 1,
      autoplay: 1,
      origin: window.location.origin
    },
    events: {
      onReady: onPlayerReady,
      onStateChange: onPlayerStateChange,
      onError: function(e) {
        setStatus('Kendala pemutaran video', 'ID: <?= htmlspecialchars($video['youtube_id']) ?>');
      }
    }
  });
};

const tag = document.createElement('script');
tag.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(tag);

function onPlayerReady(e) {
  playerReady = true;
  const loader = document.getElementById('page-loader');
  if (loader) {
    loader.classList.add('hidden');
    setTimeout(() => loader.remove(), 400);
  }

  // Jika ada riwayat tontonan, langsung seek ke detik tontonan terakhir
  if (savedPosition > 0 && !hasSeekedToSaved) {
    try {
      ytPlayer.seekTo(savedPosition, true);
      hasSeekedToSaved = true;
    } catch(err) {}
  }
}

function onPlayerStateChange(e) {
  if (!CAN_WATCH) return;

  if (e.data === YT.PlayerState.PLAYING) {
    if (!hasSeekedToSaved && savedPosition > 0) {
      try {
        ytPlayer.seekTo(savedPosition, true);
        hasSeekedToSaved = true;
      } catch(err) {}
    }

    if (!watchStarted) {
      startWatchSession();
    } else if (timerHandle === null && !claimReady) {
      resumeCountdown();
    }
  } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.BUFFERING) {
    pauseCountdown();
  }
}

// ── Request Watch Token (Sertakan Saved Seconds) ─────
async function startWatchSession(forceRestart = false) {
  const fd = new FormData();
  fd.append('action', 'start_watch');
  fd.append('_csrf', CSRF);
  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();
    if (!data.ok) {
      setStatus(data.msg || 'Gagal memulai sesi', '');
      return false;
    }
    watchToken   = data.watch_token;
    watchStarted = true;
    if (data.saved_seconds !== undefined && !forceRestart) {
      savedSeconds = data.saved_seconds;
      totalWatched = savedSeconds;
      timerLeft    = Math.max(0, DURATION - totalWatched);
    }
    if (data.duration) {
      DURATION = data.duration;
      timerLeft = Math.max(0, DURATION - totalWatched);
    }
    startCountdown();
    return true;
  } catch(e) {
    setStatus('Error jaringan saat memulai sesi.', '');
    return false;
  }
}

// ── Countdown & Progress Tracker ────────────────────
function startCountdown() {
  updateTimerUI();
  if (timerHandle) clearInterval(timerHandle);

  timerHandle = setInterval(() => {
    totalWatched++;
    timerLeft = Math.max(0, DURATION - totalWatched);
    updateTimerUI();

    // Simpan progres ke server secara berkala tiap 4 detik (agar refresh tidak reset)
    if (totalWatched - lastSavedTick >= 4 && totalWatched < DURATION) {
      lastSavedTick = totalWatched;
      saveProgressToServer(totalWatched, ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    }

    if (totalWatched >= DURATION || timerLeft <= 0) {
      clearInterval(timerHandle);
      timerHandle = null;
      claimReady  = true;
      const rb = document.getElementById('resume-banner');
      if (rb) rb.style.display = 'none';
      showClaimButton();
    }
  }, 1000);
}

function pauseCountdown() {
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  if (!claimReady) {
    setStatus('Video dijeda — lanjutkan pemutaran untuk lanjut', '');
    // Simpan progres saat dijeda
    if (totalWatched > 0 && totalWatched < DURATION) {
      saveProgressToServer(totalWatched, ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    }
  }
}

function resumeCountdown() {
  startCountdown();
}

function updateTimerUI() {
  const badge = document.getElementById('timer-badge');
  const fill  = document.getElementById('prog-fill');
  const pct   = Math.min(100, (totalWatched / DURATION) * 100);

  if (badge) {
    badge.textContent = timerLeft > 0 ? timerLeft : '✓';
    if (timerLeft <= 0) badge.classList.add('done');
  }
  if (fill) {
    fill.style.width = pct + '%';
    if (timerLeft <= 0) fill.classList.add('done');
  }

  const rLeft = document.getElementById('resume-left-sec');
  if (rLeft) rLeft.textContent = timerLeft;

  setStatus(
    timerLeft > 0 ? `Menonton: ${timerLeft} detik lagi... (${Math.round(pct)}%)` : 'Misi selesai! Klaim saldo Anda sekarang',
    timerLeft > 0 ? 'Jangan jeda atau tutup halaman' : ''
  );
}

function showClaimButton() {
  const w = document.getElementById('claim-wrap');
  if (w) w.style.display = 'block';
  const fill = document.getElementById('prog-fill');
  if (fill) fill.classList.add('done');
}

function setStatus(text, hint) {
  const el = document.getElementById('status-text');
  const eh = document.getElementById('status-hint');
  if (el) el.textContent = text;
  if (eh) eh.textContent = hint;
}

// ── Auto Save Progress ke Server ─────────────────────
function saveProgressToServer(sec, pos) {
  if (!CAN_WATCH || sec <= 0 || sec >= DURATION || claimReady) return;
  try {
    const fd = new FormData();
    fd.append('action', 'save_progress');
    fd.append('_csrf', CSRF);
    fd.append('seconds_watched', sec);
    fd.append('last_position', pos || 0);
    fetch(WATCH_URL, { method: 'POST', body: fd, keepalive: true }).catch(() => {});
  } catch(e) {}
}

// Simpan progres saat halaman direfresh atau ditutup mendadak
window.addEventListener('beforeunload', () => {
  if (watchStarted && !claimReady && totalWatched > 0 && totalWatched < DURATION) {
    const fd = new FormData();
    fd.append('action', 'save_progress');
    fd.append('_csrf', CSRF);
    fd.append('seconds_watched', totalWatched);
    fd.append('last_position', ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    navigator.sendBeacon(WATCH_URL, fd);
  }
});

// ── Reset Watch Progress ─────────────────────────────
async function resetWatchProgress() {
  if (!confirm('Ulangi tontonan video ini dari awal (0 detik)?')) return;
  try {
    const fd = new FormData();
    fd.append('action', 'reset_progress');
    fd.append('_csrf', CSRF);
    await fetch(WATCH_URL, { method: 'POST', body: fd });
  } catch(e) {}

  savedSeconds     = 0;
  savedPosition    = 0;
  totalWatched     = 0;
  lastSavedTick    = 0;
  timerLeft        = DURATION;
  watchStarted     = false;
  claimReady       = false;
  hasSeekedToSaved = true;
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }

  const rb = document.getElementById('resume-banner');
  if (rb) rb.remove();

  if (ytPlayer && typeof ytPlayer.seekTo === 'function') {
    try { ytPlayer.seekTo(0, true); } catch(e) {}
  }
  updateTimerUI();
  setStatus('Progres diulang dari awal. Putar video untuk mulai misi.', '');
  if (typeof nToast === 'function') nToast('Progres diulang dari 0 detik.', 'info');

  if (ytPlayer && ytPlayer.getPlayerState && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING) {
    startWatchSession();
  }
}

// ── Claim Reward ─────────────────────────────────────
async function claimReward() {
  if (!watchToken) {
    if (typeof nToast === 'function') nToast('Sesi tidak ditemukan. Putar video dari awal.', 'error');
    return;
  }
  const btn = document.getElementById('claim-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memproses...';
  }

  const fd = new FormData();
  fd.append('action', 'claim');
  fd.append('_csrf', CSRF);
  fd.append('watch_token', watchToken);

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();

    if (data.ok) {
      showPop(data.msg || 'Reward berhasil diklaim!');
      if (btn) btn.innerHTML = '<i class="ph-bold ph-check"></i> Berhasil!';
      setStatus('Reward berhasil diklaim! Mengalihkan...', '');
      setTimeout(() => location.href = '/videos', 2000);
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal klaim', 'error');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
      }
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Error jaringan saat mengklaim saldo.', 'error');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
    }
  }
}

// ── Toggle Like Feature ──────────────────────────────
async function toggleLike() {
  const btn = document.getElementById('btnLike');
  const ico = document.getElementById('likeIco');
  const txt = document.getElementById('likeTxt');
  const cnt = document.getElementById('likeCnt');
  if (!btn) return;

  btn.style.transform = 'scale(1.15)';
  setTimeout(() => btn.style.transform = '', 200);

  const fd = new FormData();
  fd.append('action', 'toggle_like');
  fd.append('_csrf', CSRF);

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();
    if (data.ok) {
      if (data.liked) {
        btn.classList.add('btn-action--liked');
        if (ico) ico.className = 'ph-fill ph-thumbs-up';
        if (txt) txt.textContent = 'Disukai';
      } else {
        btn.classList.remove('btn-action--liked');
        if (ico) ico.className = 'ph-bold ph-thumbs-up';
        if (txt) txt.textContent = 'Suka';
      }
      if (cnt && data.total_likes !== undefined) {
        cnt.textContent = Number(data.total_likes).toLocaleString('id-ID');
      }
      if (typeof nToast === 'function') {
        nToast(data.msg, 'success');
      }
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal menyukai video', 'error');
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat like video.', 'error');
  }
}

// ── Share Video ──────────────────────────────────────
function shareVideo() {
  const url = window.location.href;
  const title = <?= json_encode($video['title']) ?>;
  if (navigator.share) {
    navigator.share({ title: title, url: url }).catch(() => {});
  } else {
    navigator.clipboard.writeText(url).then(() => {
      if (typeof nToast === 'function') nToast('Tautan video berhasil disalin ke clipboard!', 'success');
    }).catch(() => {
      prompt('Salin tautan video berikut:', url);
    });
  }
}

// ── Reward Popup ─────────────────────────────────────
function showPop(msg) {
  const el = document.getElementById('reward-popup');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 3000);
}
</script>
<script src="/assets/js/toast.js"></script>
</body>
</html>
