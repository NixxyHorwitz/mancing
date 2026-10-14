<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Inisialisasi token CSRF session di awal
$csrf_token   = csrf_token();
$checkin_min  = max(1, (float) setting($pdo, 'checkin_reward_min', '500'));
$checkin_max  = max($checkin_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$today        = date('Y-m-d');
$last_checkin = $user['last_checkin'] ?? null;
$already      = ($last_checkin === $today);

// Hitung streak check-in / aktivitas
$streak = 0;
if ($last_checkin) {
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
        $sq = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $sq->execute([$user['id']]);
        $streak = max(1, (int)$sq->fetchColumn());
    }
}

$flash = $flashType = '';
$reward_given = 0;

// Ambil reward dari session jika ada (untuk display setelah form submit / redirect)
if (!empty($_SESSION['checkin_reward_display']) && $already) {
    $reward_given = (int)$_SESSION['checkin_reward_display'];
    unset($_SESSION['checkin_reward_display']);
    $flash = 'checkin_ok';
    $flashType = 'success';
}

// ── PROSES KLAIM CHECK-IN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
               || isset($_POST['ajax']);

    // Validasi token CSRF (menerima _csrf, csrf_token, atau HTTP_X_CSRF_TOKEN)
    $submitted_token = (string)($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    $is_valid_csrf = !empty($submitted_token) && hash_equals($csrf_token, $submitted_token);

    // Keamanan: Pastikan user login valid. Jika auth_user/guard valid, proses secara aman & idempotent
    if (!$is_valid_csrf && empty($user['id'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login kembali.']);
            exit;
        }
        $flash = 'Sesi tidak valid. Silakan coba lagi.';
        $flashType = 'error';
    } elseif ($already && $flash !== 'checkin_ok') {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka kotak hadiah hari ini. Kembali besok!']);
            exit;
        }
        $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
        $flashType = 'warn';
    } else {
        // Generate reward acak server-side
        $reward_given = rand((int)$checkin_min, (int)$checkin_max);
        try {
            $pdo->beginTransaction();
            // CRITICAL: Hadiah uang tunai langsung masuk ke Saldo Tarik (balance_wd) dan total_earned
            $stmt = $pdo->prepare(
                "UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ?, last_checkin = CURDATE()
                 WHERE id = ? AND (last_checkin IS NULL OR last_checkin < CURDATE())"
            );
            $stmt->execute([$reward_given, $reward_given, $user['id']]);

            if ($stmt->rowCount() > 0) {
                $pdo->commit();

                // Refresh saldo lokal
                $user['balance_wd'] = (float)($user['balance_wd'] ?? 0) + $reward_given;
                $user['total_earned'] = (float)($user['total_earned'] ?? 0) + $reward_given;
                $user['last_checkin'] = $today;
                $already = true;
                $streak = max(1, $streak + 1);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'reward' => $reward_given,
                        'reward_formatted' => format_rp((float)$reward_given),
                        'new_balance_wd' => $user['balance_wd'],
                        'new_balance_formatted' => format_rp((float)$user['balance_wd']),
                        'streak' => $streak
                    ]);
                    exit;
                }

                $_SESSION['checkin_reward_display'] = $reward_given;
                header('Location: /checkin');
                exit;
            } else {
                $pdo->rollBack();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka sarang hari ini!']);
                    exit;
                }
                $flash = 'Kamu sudah check-in hari ini!';
                $flashType = 'warn';
                $already = true;
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
                exit;
            }
            $flash = 'Terjadi kesalahan sistem.';
            $flashType = 'error';
        }
    }
}

$pageTitle  = 'Hadiah Check-in Harian';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';

$completed_days = $already ? ($streak % 7 ?: 7) : ($streak % 7);
if ($streak == 0 && $already) $completed_days = 1;
?>

<style>
/* ══════════════════════════════════════════════════════════════
   COMPACT LIVING CORAL REEF CHECK-IN & REVELATION MODAL
   Tema: Ocean Reef Garden (Super Padat, No-Scroll, No-Card)
   ══════════════════════════════════════════════════════════════ */

.ci-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 2px 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: relative;
  z-index: 1;
}

/* ── 1. TOP NAVIGATOR FLOATING BAR (COMPACT) ── */
.ci-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 1px 2px;
}
.ci-back-btn {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 16px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95);
  transition: transform 0.12s ease;
}
.ci-back-btn:active { transform: scale(0.92); }
.ci-nav-title-box {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  padding: 4px 12px;
  border-radius: 999px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.9);
}
.ci-nav-title-txt {
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: -0.2px;
}
.ci-status-pill-top {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #d1fae5;
  border: 1.5px solid #a7f3d0;
  color: #065f46;
  font-size: 9.5px;
  font-weight: 900;
  padding: 4px 8px;
  border-radius: 999px;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.12);
}
.ci-status-pill-top.claimed {
  background: #fef3c7;
  border-color: #fde68a;
  color: #92400e;
}
.ci-pulse-dot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #10b981;
  animation: pulseKelpDot 1.8s infinite;
}
.ci-status-pill-top.claimed .ci-pulse-dot {
  background: #f59e0b;
  animation: none;
}
@keyframes pulseKelpDot {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.4); opacity: 0.5; }
}

/* ── 2. DUAL FLOATING VAULT CAPSULES (COMPACT) ── */
.ci-capsules-row {
  display: grid;
  grid-template-columns: 1.25fr 0.75fr;
  gap: 7px;
}
.ci-float-capsule {
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  background: linear-gradient(155deg, #ffffff 0%, #fff7ed 50%, #ffedd5 100%);
  padding: 6px 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow:
    0 6px 16px rgba(244, 63, 94, 0.07),
    inset 1.5px 1.5px 4px rgba(255, 255, 255, 0.95),
    inset -1.5px -1.5px 4px rgba(251, 146, 60, 0.1);
}
.ci-capsule-avatar {
  width: 30px;
  height: 30px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
  border: 1px solid #ffffff;
}
.ci-capsule-avatar.wd {
  background: linear-gradient(135deg, #34d399, #059669);
  color: #ffffff;
}
.ci-capsule-avatar.streak {
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  color: #ffffff;
}
.ci-capsule-meta {
  flex: 1;
  min-width: 0;
}
.ci-capsule-lbl {
  font-size: 8.5px;
  font-weight: 800;
  color: var(--reef-muted);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.ci-capsule-val {
  font-size: 13.5px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.15;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── 3. JALUR 7 HARI MUTIARA KARANG (COMPACT TRACK) ── */
.ci-pearl-path {
  position: relative;
  padding: 5px 8px 6px;
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(8px);
  border: 1.5px solid rgba(255, 255, 255, 0.9);
  border-radius: 14px;
  box-shadow: 0 3px 10px rgba(8, 145, 178, 0.05);
}
.ci-pearl-path-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 5px;
  padding: 0 2px;
}
.ci-pearl-path-title span {
  font-size: 10.5px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 4px;
}
.ci-pearl-path-sub {
  font-size: 9px;
  font-weight: 800;
  color: var(--reef-muted);
}
.ci-pearls-track {
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: relative;
  padding: 2px 4px 1px;
}
.ci-pearls-track::before {
  content: '';
  position: absolute;
  top: 15px;
  left: 10px;
  right: 10px;
  height: 2.5px;
  background: linear-gradient(90deg, #34d399, #fbbf24, #f43f5e);
  border-radius: 2px;
  z-index: 0;
  opacity: 0.35;
}
.ci-pearl-node {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.ci-pearl-bead {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1.5px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9.5px;
  font-weight: 900;
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
}
.ci-pearl-bead.done {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.35);
}
.ci-pearl-bead.today {
  background: linear-gradient(135deg, #fef08a, #f59e0b);
  color: #78350f;
  transform: scale(1.15);
  box-shadow: 0 0 0 2.5px rgba(245, 158, 11, 0.25), 0 3px 8px rgba(245, 158, 11, 0.4);
  animation: todayPearlPulse 2s infinite;
}
.ci-pearl-bead.future {
  background: #ffffff;
  color: #94a3b8;
  border-color: #f1f5f9;
}
@keyframes todayPearlPulse {
  0%, 100% { transform: scale(1.15); }
  50% { transform: scale(1.22); }
}
.ci-pearl-day {
  font-size: 8px;
  font-weight: 900;
  color: var(--reef-muted);
}
.ci-pearl-node.active .ci-pearl-day {
  color: #b45309;
}

/* ── 4. THE ORGANIC LIVING CORAL REEF GARDEN (COMPACT & NO CARD) ── */
.coral-garden-header {
  text-align: center;
  margin: 2px 0 0;
  position: relative;
  z-index: 2;
}
.coral-garden-heading {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: -0.2px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.coral-garden-subtext {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
  margin-top: 1px;
}

/* Panggung Dasar Laut Bebas Terumbu Karang */
.coral-reef-seabed {
  position: relative;
  padding: 4px 2px 6px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  background:
    radial-gradient(circle at 50% 20%, rgba(254, 240, 138, 0.22) 0, transparent 55%),
    radial-gradient(circle at 15% 85%, rgba(52, 211, 153, 0.15) 0, transparent 45%),
    radial-gradient(circle at 85% 75%, rgba(251, 113, 133, 0.16) 0, transparent 45%);
}

/* Floating underwater bubbles */
.coral-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.85), rgba(34, 211, 238, 0.25));
  pointer-events: none;
  animation: floatBubbleDrift 5s ease-in-out infinite;
}
.coral-bubble--1 { width: 12px; height: 12px; top: 10%; left: 8%; animation-duration: 5.5s; }
.coral-bubble--2 { width: 16px; height: 16px; top: 48%; right: 6%; animation-duration: 6.5s; animation-delay: -2s; }
.coral-bubble--3 { width: 9px; height: 9px; bottom: 15%; left: 12%; animation-duration: 4s; animation-delay: -1s; }
@keyframes floatBubbleDrift {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-14px) scale(1.1); }
}

/* Background swimming fish silhouettes */
.coral-bg-fish {
  position: absolute;
  pointer-events: none;
  opacity: 0.4;
  animation: swimAcross 12s linear infinite;
  z-index: 0;
}
.coral-bg-fish--1 { top: 15%; left: -30px; animation-duration: 14s; }
.coral-bg-fish--2 { top: 62%; left: -30px; animation-duration: 18s; animation-delay: -7s; }
@keyframes swimAcross {
  0% { transform: translateX(0) scaleX(1); }
  49% { transform: translateX(460px) scaleX(1); }
  50% { transform: translateX(460px) scaleX(-1); }
  99% { transform: translateX(0) scaleX(-1); }
  100% { transform: translateX(0) scaleX(1); }
}

/* Grid Bebas Formasi Terumbu Karang (2 Baris x 3 Kolom Alami) */
.coral-cluster-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px 6px;
  position: relative;
  z-index: 2;
  align-items: center;
}

/* Setiap Entitas Karang Individual (BEBAS DARI CARD!) */
.coral-entity {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  user-select: none;
  position: relative;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.2s ease;
  padding: 4px 1px;
}
.coral-entity:nth-child(odd) { animation: naturalSwayA 4.2s ease-in-out infinite; }
.coral-entity:nth-child(even) { animation: naturalSwayB 4.8s ease-in-out infinite; }
@keyframes naturalSwayA {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-4px) rotate(1.5deg); }
}
@keyframes naturalSwayB {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-3px) rotate(-1.5deg); }
}

/* Hover & Active tactile touch */
.coral-entity.can-pick:hover {
  transform: translateY(-6px) scale(1.08);
  filter: drop-shadow(0 12px 20px rgba(244, 63, 94, 0.3));
}
.coral-entity.can-pick:active {
  transform: scale(0.92);
}

/* SVG Artwork Karang (Proporsional & Padat) */
.coral-svg-art {
  width: 68px;
  height: 68px;
  filter: drop-shadow(0 6px 10px rgba(12, 74, 96, 0.18));
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.25s ease;
  position: relative;
}

/* Label Nama Karang (Pill Kaca Mengapung) */
.coral-tag-pill {
  margin-top: 3px;
  padding: 2px 7px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.88);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
  border: 1px solid rgba(255, 255, 255, 0.95);
  font-size: 9px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.04);
  white-space: nowrap;
  transition: all 0.2s ease;
}
.coral-entity.can-pick:hover .coral-tag-pill {
  background: #ffffff;
  color: var(--reef-coral-500);
}

/* Efek saat sedang dipetik (Burst & Bloom) */
.coral-entity.picking {
  animation: coralHarvestBurst 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards !important;
  z-index: 20;
}
@keyframes coralHarvestBurst {
  0% { transform: scale(1); filter: drop-shadow(0 6px 10px rgba(12, 74, 96, 0.18)); }
  40% { transform: scale(1.24) translateY(-8px); filter: drop-shadow(0 0 20px #fbbf24); }
  75% { transform: scale(1.12) translateY(-4px); }
  100% { transform: scale(1.18) translateY(-6px); filter: drop-shadow(0 0 25px #10b981); }
}

/* Karang yang sudah dibuka */
.coral-entity.opened {
  animation: none !important;
}
.coral-entity.opened .coral-svg-art {
  filter: drop-shadow(0 0 14px rgba(16, 185, 129, 0.6));
}
.coral-entity.opened .coral-tag-pill {
  background: #d1fae5;
  color: #065f46;
  border-color: #34d399;
  font-size: 9.5px;
  font-weight: 900;
}

/* Karang lain yang teredup */
.coral-entity.dimmed {
  opacity: 0.35;
  filter: grayscale(0.4) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.05));
  pointer-events: none;
}

/* ── 5. STATUS SUMMARY CARD (DI BAWAH TAMAN KARANG - COMPACT) ── */
.ci-status-board {
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(8px);
  padding: 8px 12px;
  text-align: center;
  box-shadow: 0 4px 14px rgba(30, 27, 46, 0.04);
}
.ci-status-badge-main {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 900;
  margin-bottom: 4px;
}
.ci-status-badge-main.ready {
  background: #ecfdf5;
  color: #065f46;
  border: 1px solid #a7f3d0;
}
.ci-status-badge-main.done {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
}
.ci-status-headline {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 2px;
}
.ci-status-sub {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
  line-height: 1.3;
}

/* Tombol Aksi Cepat (Ramping) */
.ci-action-row {
  display: flex;
  gap: 7px;
  margin-top: 8px;
}
.ci-action-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 8px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  border: 1.5px solid #ffffff;
  transition: transform 0.12s ease;
  user-select: none;
}
.ci-action-btn:active { transform: scale(0.96); }
.ci-action-btn--wd {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.28), inset 1px 1px 2px rgba(255, 255, 255, 0.4);
}
.ci-action-btn--fish {
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 55%, #e11d48 100%);
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(244, 63, 94, 0.28), inset 1px 1px 2px rgba(255, 255, 255, 0.4);
}

/* ══════════════════════════════════════════════════════════════
   6. THE SUNKEN PEARL CLAM REVELATION POPUP (DESAIN POPUP BARU)
   Mekar Organik, Mutiara Emas Bersinar, God-Rays Berputar
   ══════════════════════════════════════════════════════════════ */
.ci-clam-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.82);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 100000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.ci-clam-modal-overlay.open {
  display: flex;
}

.ci-clam-box {
  position: relative;
  max-width: 330px;
  width: 100%;
  border-radius: 30px;
  border: 3px solid #ffffff;
  background:
    radial-gradient(circle at 50% 25%, rgba(254, 240, 138, 0.6) 0, transparent 48%),
    radial-gradient(circle at 10% 85%, rgba(52, 211, 153, 0.25) 0, transparent 45%),
    linear-gradient(165deg, #ffffff 0%, #fff7ed 50%, #fef3c7 100%);
  box-shadow:
    0 24px 60px rgba(0, 0, 0, 0.45),
    0 8px 24px rgba(244, 63, 94, 0.2),
    inset 3px 3px 8px rgba(255, 255, 255, 0.95),
    inset -4px -5px 12px rgba(251, 146, 60, 0.2);
  padding: 22px 18px 18px;
  text-align: center;
  transform: scale(0.75);
  opacity: 0;
  transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
  overflow: hidden;
}
.ci-clam-modal-overlay.open .ci-clam-box {
  transform: scale(1);
  opacity: 1;
}

/* Background God-Rays Rotating */
.ci-clam-rays {
  position: absolute;
  top: -40px;
  left: 50%;
  width: 260px;
  height: 260px;
  margin-left: -130px;
  background: radial-gradient(circle, rgba(254, 240, 138, 0.4) 0%, transparent 65%);
  animation: rayRotate 14s linear infinite;
  pointer-events: none;
  z-index: 0;
}
@keyframes rayRotate {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* 3D Clam & Pearl Art Floating */
.ci-clam-art-wrap {
  width: 96px;
  height: 96px;
  margin: 0 auto 6px;
  position: relative;
  z-index: 1;
  filter: drop-shadow(0 10px 20px rgba(245, 158, 11, 0.4));
  animation: floatClamWin 3.2s ease-in-out infinite;
}
@keyframes floatClamWin {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-6px) scale(1.05); }
}

.ci-clam-badge-top {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #fef3c7;
  border: 1.5px solid #fde68a;
  color: #92400e;
  font-size: 10.5px;
  font-weight: 900;
  padding: 4px 12px;
  border-radius: 999px;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.15);
  position: relative;
  z-index: 1;
  margin-bottom: 6px;
}
.ci-clam-headline {
  font-size: 17px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.2;
  position: relative;
  z-index: 1;
}
.ci-clam-sub {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-muted);
  margin-top: 2px;
  margin-bottom: 8px;
  position: relative;
  z-index: 1;
}

/* Big Shiny Reward Number */
.ci-clam-amount-display {
  font-size: 34px;
  font-weight: 900;
  color: #059669;
  letter-spacing: -0.6px;
  line-height: 1.1;
  text-shadow: 0 3px 8px rgba(16, 185, 129, 0.3);
  position: relative;
  z-index: 1;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.ci-clam-dest-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 900;
  color: #065f46;
  background: #d1fae5;
  border: 1px solid #a7f3d0;
  padding: 3px 10px;
  border-radius: 8px;
  margin-bottom: 16px;
  position: relative;
  z-index: 1;
}

/* Action Buttons inside Clam Popup */
.ci-clam-buttons {
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: relative;
  z-index: 1;
}
.ci-clam-btn-main {
  width: 100%;
  border: 2px solid #ffffff;
  border-radius: 16px;
  padding: 12px 14px;
  font-size: 13.5px;
  font-weight: 900;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-decoration: none;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  box-shadow:
    0 8px 18px rgba(16, 185, 129, 0.35),
    inset 2px 2px 4px rgba(255, 255, 255, 0.5),
    inset -2px -2px 5px rgba(4, 120, 87, 0.35);
  transition: transform 0.12s ease;
}
.ci-clam-btn-main:active { transform: scale(0.97); }

.ci-clam-btn-close {
  width: 100%;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 9px 12px;
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-muted);
  background: rgba(255, 255, 255, 0.85);
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
  transition: all 0.12s ease;
}
.ci-clam-btn-close:active { transform: scale(0.97); }
</style>

<div class="ci-reef-stage">

  <!-- ── 1. TOP NAVIGATOR BAR (COMPACT) ── -->
  <div class="ci-top-nav">
    <a href="/home" class="ci-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="ci-nav-title-box">
      <i class="ph-fill ph-plant" style="color:var(--reef-coral-500);font-size:15px;"></i>
      <span class="ci-nav-title-txt">Taman Terumbu Karang</span>
    </div>
    <div class="ci-status-pill-top <?= $already ? 'claimed' : '' ?>">
      <span class="ci-pulse-dot"></span>
      <span><?= $already ? 'Sudah Dipetik' : 'Siap Panen' ?></span>
    </div>
  </div>

  <!-- ── 2. DUAL FLOATING VAULT CAPSULES (COMPACT) ── -->
  <div class="ci-capsules-row">
    <div class="ci-float-capsule">
      <div class="ci-capsule-avatar wd">
        <i class="ph-bold ph-wallet"></i>
      </div>
      <div class="ci-capsule-meta">
        <div class="ci-capsule-lbl">Saldo Tarik Tunai</div>
        <div class="ci-capsule-val" id="display-user-balance-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
      </div>
    </div>

    <div class="ci-float-capsule">
      <div class="ci-capsule-avatar streak">
        <i class="ph-fill ph-fire"></i>
      </div>
      <div class="ci-capsule-meta">
        <div class="ci-capsule-lbl">Streak Panen</div>
        <div class="ci-capsule-val"><span id="display-user-streak"><?= $streak ?></span> Hari</div>
      </div>
    </div>
  </div>

  <!-- ── 3. JALUR 7 HARI MUTIARA KARANG (COMPACT TRACK) ── -->
  <div class="ci-pearl-path">
    <div class="ci-pearl-path-title">
      <span><i class="ph-fill ph-sparkle" style="color:var(--reef-sun-500);font-size:12px;"></i> Rantai Mutiara Mingguan</span>
      <span class="ci-pearl-path-sub">Hari ke-<?= $completed_days ?> / 7</span>
    </div>
    <div class="ci-pearls-track">
      <?php for ($i = 1; $i <= 7; $i++):
        $is_done  = $i < $completed_days || ($i == $completed_days && $already);
        $is_today = !$already && $i == $completed_days + 1;
        $cls = $is_done ? 'done' : ($is_today ? 'today' : 'future');
      ?>
      <div class="ci-pearl-node <?= $is_today ? 'active' : '' ?>">
        <div class="ci-pearl-bead <?= $cls ?>" id="streak-bead-<?= $i ?>">
          <?php if ($is_done): ?>
            <i class="ph-bold ph-check"></i>
          <?php elseif ($is_today): ?>
            <i class="ph-fill ph-star"></i>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
        <span class="ci-pearl-day">H<?= $i ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- ── 4. THE ORGANIC LIVING CORAL REEF GARDEN (NO CARD WRAPPER!) ── -->
  <div class="coral-garden-header">
    <div class="coral-garden-heading">
      <i class="ph-fill ph-sparkle" style="color:var(--reef-sun-500);font-size:13px;"></i>
      <span><?= $already ? 'Mutiara Karang Hari Ini Terbuka' : 'Petik Salah Satu Karang Bawah Laut' ?></span>
    </div>
    <div class="coral-garden-subtext">
      <?= $already ? 'Kamu telah memetik mutiara karang hari ini. Datang lagi besok!' : 'Sentuh salah satu karang hidup untuk memetik mutiara uang tunai!' ?>
    </div>
  </div>

  <div class="coral-reef-seabed" id="coral-reef-stage">
    <!-- Ambient floating underwater bubbles -->
    <div class="coral-bubble coral-bubble--1"></div>
    <div class="coral-bubble coral-bubble--2"></div>
    <div class="coral-bubble coral-bubble--3"></div>

    <!-- Background swimming fish silhouettes -->
    <div class="coral-bg-fish coral-bg-fish--1">
      <svg width="22" height="13" viewBox="0 0 24 14" fill="#fb7185">
        <path d="M2 7 C5 3 14 2 18 5 L22 2 L22 12 L18 9 C14 12 5 11 2 7 Z"/>
      </svg>
    </div>
    <div class="coral-bg-fish coral-bg-fish--2">
      <svg width="18" height="11" viewBox="0 0 24 14" fill="#22d3ee">
        <path d="M2 7 C5 3 14 2 18 5 L22 2 L22 12 L18 9 C14 12 5 11 2 7 Z"/>
      </svg>
    </div>

    <!-- Formasi 6 Terumbu Karang Bebas (Grid Padat & Proporsional) -->
    <div class="coral-cluster-grid">

      <!-- Karang 1: Karang Otak Emas -->
      <div class="coral-entity <?= !$already ? 'can-pick' : ($reward_given > 0 ? 'opened' : 'dimmed') ?>" data-coral-id="1" onclick="handleCoralSelect(1, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <radialGradient id="brainGlow" cx="45%" cy="40%" r="55%">
                <stop offset="0%" stop-color="#fef08a"/>
                <stop offset="60%" stop-color="#f59e0b"/>
                <stop offset="100%" stop-color="#b45309"/>
              </radialGradient>
            </defs>
            <path d="M22 68 C15 50 20 28 42 20 C60 14 80 22 84 42 C88 60 76 75 58 80 C40 84 26 78 22 68 Z" fill="url(#brainGlow)"/>
            <path d="M30 38 Q36 28 46 34 T56 46 T42 56 T35 48" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" fill="none" opacity="0.85"/>
            <path d="M50 24 Q64 26 68 36 T58 52 T70 65" stroke="#ffffff" stroke-width="3" stroke-linecap="round" fill="none" opacity="0.8"/>
            <path d="M32 58 Q42 66 52 64 T62 74" stroke="#ffffff" stroke-width="2.8" stroke-linecap="round" fill="none" opacity="0.75"/>
            <circle cx="70" cy="28" r="3" fill="#ffffff" opacity="0.9"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Karang Otak</div>
      </div>

      <!-- Karang 2: Anemon Mawar Merah -->
      <div class="coral-entity <?= !$already ? 'can-pick' : 'dimmed' ?>" data-coral-id="2" onclick="handleCoralSelect(2, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="anemoneGrad" x1="20" y1="20" x2="80" y2="90" gradientUnits="userSpaceOnUse">
                <stop stop-color="#fb7185"/>
                <stop offset="0.6" stop-color="#f43f5e"/>
                <stop offset="1" stop-color="#9f1239"/>
              </linearGradient>
            </defs>
            <ellipse cx="50" cy="74" rx="28" ry="12" fill="#be123c"/>
            <path d="M30 72 C22 55 18 36 26 24 C30 38 34 56 36 70" fill="url(#anemoneGrad)"/>
            <path d="M40 70 C36 48 38 28 46 16 C50 32 48 54 46 68" fill="url(#anemoneGrad)"/>
            <path d="M52 68 C54 46 56 26 62 18 C62 34 58 52 56 68" fill="url(#anemoneGrad)"/>
            <path d="M62 70 C68 52 74 34 80 28 C78 44 72 58 66 72" fill="url(#anemoneGrad)"/>
            <circle cx="26" cy="24" r="4.5" fill="#fda4af"/>
            <circle cx="46" cy="16" r="5" fill="#ffffff"/>
            <circle cx="62" cy="18" r="4.5" fill="#fecdd3"/>
            <circle cx="80" cy="28" r="4" fill="#fda4af"/>
            <path d="M36 50 Q44 46 48 52 L50 48 L50 56 Z" fill="#ea580c"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Anemon Mawar</div>
      </div>

      <!-- Karang 3: Karang Tanduk Zamrud -->
      <div class="coral-entity <?= !$already ? 'can-pick' : 'dimmed' ?>" data-coral-id="3" onclick="handleCoralSelect(3, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="stagGrad" x1="30" y1="10" x2="70" y2="90" gradientUnits="userSpaceOnUse">
                <stop stop-color="#6ee7b7"/>
                <stop offset="0.5" stop-color="#10b981"/>
                <stop offset="1" stop-color="#064e3b"/>
              </linearGradient>
            </defs>
            <path d="M44 82 L46 54 L32 38 L36 34 L50 48 L56 32 L62 34 L54 52 L68 42 L72 46 L54 62 L52 82 Z" fill="url(#stagGrad)"/>
            <path d="M32 38 L22 28 L26 26 L36 34 Z" fill="url(#stagGrad)"/>
            <path d="M68 42 L80 32 L84 36 L72 46 Z" fill="url(#stagGrad)"/>
            <path d="M56 32 L58 18 L64 20 L62 34 Z" fill="url(#stagGrad)"/>
            <circle cx="22" cy="28" r="3.5" fill="#a7f3d0"/>
            <circle cx="58" cy="18" r="4" fill="#ffffff"/>
            <circle cx="80" cy="32" r="3.5" fill="#a7f3d0"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Karang Tanduk</div>
      </div>

      <!-- Karang 4: Kerang Mutiara Ratu -->
      <div class="coral-entity <?= !$already ? 'can-pick' : 'dimmed' ?>" data-coral-id="4" onclick="handleCoralSelect(4, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="clamShell" x1="20" y1="30" x2="80" y2="90" gradientUnits="userSpaceOnUse">
                <stop stop-color="#e0e7ff"/>
                <stop offset="0.5" stop-color="#c4b5fd"/>
                <stop offset="1" stop-color="#7c3aed"/>
              </linearGradient>
              <radialGradient id="clamPearl" cx="40%" cy="35%" r="60%">
                <stop offset="0%" stop-color="#ffffff"/>
                <stop offset="40%" stop-color="#fef08a"/>
                <stop offset="85%" stop-color="#f59e0b"/>
                <stop offset="100%" stop-color="#b45309"/>
              </radialGradient>
            </defs>
            <path d="M16 54 C18 76 34 84 50 84 C66 84 82 76 84 54 C76 60 62 62 50 62 C38 62 24 60 16 54 Z" fill="url(#clamShell)"/>
            <path d="M20 50 C18 28 34 14 50 14 C66 14 82 28 80 50 C70 38 60 32 50 32 C40 32 30 38 20 50 Z" fill="url(#clamShell)"/>
            <path d="M50 14 L50 32" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
            <path d="M34 18 Q40 26 44 32" stroke="#ffffff" stroke-width="1.8" opacity="0.6"/>
            <path d="M66 18 Q60 26 56 32" stroke="#ffffff" stroke-width="1.8" opacity="0.6"/>
            <circle cx="50" cy="48" r="13" fill="url(#clamPearl)"/>
            <circle cx="45" cy="43" r="4" fill="#ffffff" opacity="0.95"/>
            <path d="M66 36 L68 40 L72 42 L68 44 L66 48 L64 44 L60 42 L64 40 Z" fill="#ffffff"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Kerang Mutiara</div>
      </div>

      <!-- Karang 5: Kipas Laut Violet -->
      <div class="coral-entity <?= !$already ? 'can-pick' : 'dimmed' ?>" data-coral-id="5" onclick="handleCoralSelect(5, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="fanGrad" x1="20" y1="20" x2="80" y2="85" gradientUnits="userSpaceOnUse">
                <stop stop-color="#e9d5ff"/>
                <stop offset="0.5" stop-color="#c084fc"/>
                <stop offset="1" stop-color="#6b21a8"/>
              </linearGradient>
            </defs>
            <path d="M48 84 L50 56 C32 46 22 34 26 22 C42 16 68 18 78 30 C76 44 64 52 52 56 Z" fill="url(#fanGrad)" opacity="0.85"/>
            <path d="M50 58 Q40 40 32 26" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.75"/>
            <path d="M50 58 Q50 36 50 20" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.75"/>
            <path d="M50 58 Q60 40 70 28" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.75"/>
            <circle cx="28" cy="24" r="3" fill="#fbcfe8"/>
            <circle cx="50" cy="18" r="3.5" fill="#ffffff"/>
            <circle cx="74" cy="30" r="3" fill="#fbcfe8"/>
            <circle cx="42" cy="32" r="2" fill="#ffffff"/>
            <circle cx="60" cy="34" r="2" fill="#ffffff"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Kipas Violet</div>
      </div>

      <!-- Karang 6: Karang Meja Sunset -->
      <div class="coral-entity <?= !$already ? 'can-pick' : 'dimmed' ?>" data-coral-id="6" onclick="handleCoralSelect(6, this)">
        <div class="coral-svg-art">
          <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="tableGrad" x1="20" y1="20" x2="80" y2="85" gradientUnits="userSpaceOnUse">
                <stop stop-color="#fed7aa"/>
                <stop offset="0.5" stop-color="#fb923c"/>
                <stop offset="1" stop-color="#9a3412"/>
              </linearGradient>
            </defs>
            <path d="M44 82 L47 52 L53 52 L56 82 Z" fill="#9a3412"/>
            <ellipse cx="50" cy="60" rx="30" ry="9" fill="url(#tableGrad)"/>
            <ellipse cx="50" cy="58" rx="27" ry="7" fill="#fed7aa" opacity="0.6"/>
            <ellipse cx="50" cy="38" rx="38" ry="12" fill="url(#tableGrad)"/>
            <ellipse cx="50" cy="35" rx="35" ry="9" fill="#ffedd5" opacity="0.8"/>
            <circle cx="30" cy="36" r="2.2" fill="#ffffff" opacity="0.9"/>
            <circle cx="50" cy="34" r="2.5" fill="#ffffff" opacity="0.9"/>
            <circle cx="70" cy="36" r="2.2" fill="#ffffff" opacity="0.9"/>
          </svg>
        </div>
        <div class="coral-tag-pill">Karang Meja</div>
      </div>

    </div>
  </div>

  <!-- ── 5. STATUS SUMMARY CARD (DI BAWAH TAMAN KARANG - COMPACT) ── -->
  <div class="ci-status-board">
    <?php if (!$already): ?>
      <div class="ci-status-badge-main ready">
        <i class="ph-fill ph-plant"></i> Mutiara Karang Siap Dipetik
      </div>
      <div class="ci-status-headline">Pilih Salah Satu Karang di Atas</div>
      <div class="ci-status-sub">
        Setiap terumbu karang menyimpan mutiara hadiah tunai <strong><?= format_rp($checkin_min) ?></strong> hingga <strong><?= format_rp($checkin_max) ?></strong> langsung ke <strong>Saldo Tarik</strong>.
      </div>
    <?php else: ?>
      <div class="ci-status-badge-main done">
        <i class="ph-bold ph-check-circle"></i> Mutiara Sudah Dipetik Hari Ini
      </div>
      <div class="ci-status-headline">Mutiara Tersimpan di Saldo Tarik</div>
      <div class="ci-status-sub">
        Terumbu karang sedang meremajakan diri di dasar samudra. Mutiara baru akan siap dipetik kembali besok pukul 00:00 WIB!
      </div>
    <?php endif; ?>

    <!-- Tombol Navigasi Pintas -->
    <div class="ci-action-row">
      <a href="/withdraw" class="ci-action-btn ci-action-btn--wd">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Tunai
      </a>
      <a href="/minigame" class="ci-action-btn ci-action-btn--fish">
        <i class="ph-fill ph-fish"></i> Mancing Cuan
      </a>
    </div>
  </div>

</div>

<!-- HIDDEN CSRF & FORM FOR FALLBACK -->
<form method="POST" id="checkin-fallback-form" style="display:none;">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="action" value="checkin">
</form>

<!-- ══════════════════════════════════════════════════════════════
   6. THE SUNKEN PEARL CLAM REVELATION POPUP (DESAIN POPUP BARU)
   Mekar Organik, Mutiara Emas Bersinar, God-Rays Berputar
   ══════════════════════════════════════════════════════════════ -->
<div id="ci-clam-modal" class="ci-clam-modal-overlay">
  <div class="ci-clam-box">
    <!-- God-Rays Rotation Background -->
    <div class="ci-clam-rays"></div>

    <!-- 3D Clam & Golden Pearl Art -->
    <div class="ci-clam-art-wrap">
      <svg viewBox="0 0 100 100" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="popShellGrad" x1="10" y1="10" x2="90" y2="90" gradientUnits="userSpaceOnUse">
            <stop stop-color="#ffffff"/>
            <stop offset="0.4" stop-color="#fde68a"/>
            <stop offset="0.8" stop-color="#fb923c"/>
            <stop offset="1" stop-color="#ea580c"/>
          </linearGradient>
          <radialGradient id="popPearlGrad" cx="35%" cy="35%" r="65%">
            <stop offset="0%" stop-color="#ffffff"/>
            <stop offset="35%" stop-color="#fef08a"/>
            <stop offset="75%" stop-color="#f59e0b"/>
            <stop offset="100%" stop-color="#b45309"/>
          </radialGradient>
        </defs>
        <!-- Lower Shell -->
        <path d="M12 56 C14 80 32 90 50 90 C68 90 86 80 88 56 C80 62 66 65 50 65 C34 65 20 62 12 56 Z" fill="url(#popShellGrad)" filter="drop-shadow(0 4px 6px rgba(180,83,9,0.3))"/>
        <!-- Upper Shell Open High -->
        <path d="M16 48 C14 20 32 6 50 6 C68 6 86 20 84 48 C74 34 62 26 50 26 C38 26 26 34 16 48 Z" fill="url(#popShellGrad)"/>
        <!-- Shell Ridges -->
        <path d="M50 6 L50 26" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity="0.85"/>
        <path d="M32 12 Q40 20 44 26" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.75"/>
        <path d="M68 12 Q60 20 56 26" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.75"/>
        <!-- Radiant Giant Pearl -->
        <circle cx="50" cy="52" r="16" fill="url(#popPearlGrad)" filter="drop-shadow(0 0 10px rgba(245,158,11,0.6))"/>
        <circle cx="44" cy="46" r="5" fill="#ffffff" opacity="0.95"/>
        <!-- Sparkle Star Burst -->
        <path d="M68 38 L71 43 L76 45 L71 47 L68 52 L65 47 L60 45 L65 43 Z" fill="#ffffff"/>
        <circle cx="28" cy="40" r="2.5" fill="#ffffff"/>
      </svg>
    </div>

    <div class="ci-clam-badge-top">
      <i class="ph-fill ph-sparkle" style="color:#d97706;"></i>
      <span>MUTIARA KARANG DITEMUKAN</span>
    </div>

    <div class="ci-clam-headline">Selamat! Panen Berhasil</div>
    <div class="ci-clam-sub">Mutiara terumbu karang telah mekar dan menghasilkan:</div>

    <!-- Big Amount -->
    <div class="ci-clam-amount-display" id="clam-modal-amt">+Rp 0</div>

    <div class="ci-clam-dest-tag">
      <i class="ph-bold ph-check-circle" style="color:#059669;font-size:13px;"></i>
      <span>Langsung masuk ke Saldo Tarik Tunai</span>
    </div>

    <!-- Action Buttons -->
    <div class="ci-clam-buttons">
      <a href="/withdraw" class="ci-clam-btn-main">
        <i class="ph-bold ph-arrow-up-right" style="font-size:16px;"></i>
        <span>Tarik Saldo Sekarang</span>
      </a>
      <button type="button" onclick="closeClamModal()" class="ci-clam-btn-close">
        Simpan &amp; Lanjut Berlayar
      </button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>';
let isProcessing = false;
let alreadyClaimed = <?= $already ? 'true' : 'false' ?>;

function handleCoralSelect(id, coralEl) {
  if (alreadyClaimed || isProcessing) return;
  isProcessing = true;

  // 1. Efek burst pada karang yang disentuh
  coralEl.classList.add('picking');

  // Redupkan karang lainnya
  document.querySelectorAll('.coral-entity').forEach(c => {
    if (c !== coralEl) c.classList.add('dimmed');
  });

  // 2. Request AJAX ke endpoint checkin
  const formData = new FormData();
  formData.append('action', 'checkin');
  formData.append('_csrf', CSRF_TOKEN);
  formData.append('csrf_token', CSRF_TOKEN);
  formData.append('ajax', '1');

  fetch(window.location.pathname, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': CSRF_TOKEN,
      'Accept': 'application/json'
    },
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alreadyClaimed = true;

      setTimeout(() => {
        coralEl.classList.remove('picking');
        coralEl.classList.add('opened');

        // Update pill nama karang dengan nominal hadiah
        const pill = coralEl.querySelector('.coral-tag-pill');
        if (pill) {
          pill.textContent = data.reward_formatted;
        }

        // Update realtime saldo tarik & streak di baris atas
        const balEl = document.getElementById('display-user-balance-wd');
        if (balEl && data.new_balance_formatted) {
          balEl.textContent = data.new_balance_formatted;
        }
        const streakEl = document.getElementById('display-user-streak');
        if (streakEl && data.streak) {
          streakEl.textContent = data.streak;
        }

        // Tampilkan Modal Pemenang Desain Baru
        showClamModal(data.reward_formatted);
      }, 600);

    } else {
      isProcessing = false;
      coralEl.classList.remove('picking');
      document.querySelectorAll('.coral-entity').forEach(c => c.classList.remove('dimmed'));
      alert(data.message || 'Gagal memetik mutiara karang.');
    }
  })
  .catch(err => {
    console.warn('Checkin AJAX fallback ke form submit:', err);
    const fallbackForm = document.getElementById('checkin-fallback-form');
    if (fallbackForm) {
      fallbackForm.submit();
    } else {
      isProcessing = false;
      coralEl.classList.remove('picking');
      document.querySelectorAll('.coral-entity').forEach(c => c.classList.remove('dimmed'));
    }
  });
}

function showClamModal(amtFormatted) {
  const modal = document.getElementById('ci-clam-modal');
  const amtEl = document.getElementById('clam-modal-amt');
  if (amtEl) amtEl.textContent = '+' + amtFormatted;
  if (!modal) return;

  modal.classList.add('open');
}

function closeClamModal() {
  const modal = document.getElementById('ci-clam-modal');
  if (modal) {
    modal.classList.remove('open');
  }
}

<?php if ($flash === 'checkin_ok' && $reward_given > 0): ?>
document.addEventListener('DOMContentLoaded', () => {
  showClamModal('<?= format_rp((float)$reward_given) ?>');
});
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
