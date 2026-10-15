<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Cek apakah user sudah pernah mengisi survei sebelumnya
$checkStmt = $pdo->prepare("SELECT * FROM user_surveys WHERE user_id = ? LIMIT 1");
$checkStmt->execute([$user['id']]);
$existingSurvey = $checkStmt->fetch(PDO::FETCH_ASSOC);

$flash = '';
$flashType = '';

// Proses Submit Survei
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($existingSurvey) {
        $flash = 'Kamu sudah pernah mengisi survei ini dan mengklaim hadiah Rp 15.000!';
        $flashType = 'info';
    } else {
        $source_choice = trim((string)($_POST['source_choice'] ?? ''));
        $source_custom = trim((string)($_POST['source_custom'] ?? ''));
        $experience    = trim((string)($_POST['experience'] ?? ''));
        $rating        = (int)($_POST['satisfaction_rating'] ?? 0);
        $feedback      = trim((string)($_POST['feedback_message'] ?? ''));

        // Menentukan final source info
        $source_info = $source_choice;
        if ($source_choice === 'Lainnya' || empty($source_choice)) {
            $source_info = !empty($source_custom) ? $source_custom : 'Lainnya';
        } elseif (!empty($source_custom) && $source_choice !== 'Lainnya') {
            $source_info = "{$source_choice} ({$source_custom})";
        }

        // Validasi
        if (empty($source_info)) {
            $flash = 'Mohon pilih atau tuliskan dari mana kamu mengetahui website ini.';
            $flashType = 'danger';
        } elseif (mb_strlen($experience) < 5) {
            $flash = 'Mohon ceritakan sedikit pengalamanmu menggunakan web ini (minimal 5 karakter).';
            $flashType = 'danger';
        } elseif ($rating < 1 || $rating > 5) {
            $flash = 'Mohon pilih rating kepuasanmu dengan salah satu emoji di bawah.';
            $flashType = 'danger';
        } elseif (mb_strlen($feedback) < 3) {
            $flash = 'Mohon tuliskan pesan, saran, atau keluhanmu untuk kami (minimal 3 karakter).';
            $flashType = 'danger';
        } else {
            // Lakukan Transaksi Penambahan Hadiah & Penyimpanan Survei
            try {
                $pdo->beginTransaction();

                // 1. Kunci dan pastikan belum pernah ada di database
                $lockCheck = $pdo->prepare("SELECT id FROM user_surveys WHERE user_id = ? FOR UPDATE");
                $lockCheck->execute([$user['id']]);
                if ($lockCheck->fetch()) {
                    $pdo->rollBack();
                    $flash = 'Kamu sudah pernah mengklaim hadiah survei ini.';
                    $flashType = 'info';
                } else {
                    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
                    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
                    $reward = 15000.00;

                    // 2. Simpan Jawaban Survei
                    $ins = $pdo->prepare("
                        INSERT INTO user_surveys 
                        (user_id, source_info, source_other, experience, satisfaction_rating, feedback_message, reward_amount, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([
                        $user['id'],
                        $source_info,
                        $source_custom ?: null,
                        $experience,
                        $rating,
                        $feedback,
                        $reward,
                        $ip,
                        $ua
                    ]);

                    // 3. Tambahkan Saldo Tarik (balance_wd) dan total_earned
                    $updUser = $pdo->prepare("
                        UPDATE users 
                        SET balance_wd = balance_wd + ?, total_earned = total_earned + ? 
                        WHERE id = ?
                    ");
                    $updUser->execute([$reward, $reward, $user['id']]);

                    // 4. Tambahkan Notifikasi Masuk
                    $notifTitle = "Hadiah Survei Rp 15.000 Diterima! 🎉";
                    $notifMsg   = "Terima kasih sudah meluangkan waktu mengisi survei. Hadiah Rp 15.000 saldo tarik telah otomatis ditambahkan ke akunmu.";
                    $insNotif   = $pdo->prepare("
                        INSERT INTO notifications 
                        (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at)
                        VALUES (?, ?, 'congrats', '🎁', 'single', ?, '/withdraw', 'Tarik Saldo', NOW())
                    ");
                    $insNotif->execute([$notifTitle, $notifMsg, (string)$user['id']]);

                    $pdo->commit();

                    // Re-fetch survey
                    $checkStmt->execute([$user['id']]);
                    $existingSurvey = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    // Re-fetch user balance
                    $uStmt = $pdo->prepare("SELECT balance_wd, total_earned FROM users WHERE id = ?");
                    $uStmt->execute([$user['id']]);
                    $freshU = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($freshU) {
                        $user['balance_wd'] = $freshU['balance_wd'];
                        $user['total_earned'] = $freshU['total_earned'];
                    }

                    $flash = 'Terima kasih banyak! Jawabanmu tersimpan dan Saldo Tarik Rp 15.000 telah masuk ke akunmu.';
                    $flashType = 'success';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan sistem saat menyimpan jawaban. Silakan coba lagi.';
                $flashType = 'danger';
            }
        }
    }
}

$pageTitle = 'Survei Berhadiah Rp 15.000';
$activePage = 'survey';
require_once dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════
   CORAL REEF SURVEY QUEST — RISET PENJELAJAHAN SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */

.survey-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 32px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base Card */
.survey-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* Ambient Floating Marine Bubbles */
.survey-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(244, 63, 94, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  box-shadow: inset 1px 1px 2px #ffffff, 0 2px 6px rgba(8, 145, 178, 0.15);
  pointer-events: none;
  z-index: 0;
}
.survey-bubble--1 {
  width: 16px;
  height: 16px;
  top: 10px;
  right: 18px;
  animation: surveyBubbleDrift 5s infinite ease-in-out alternate;
}
.survey-bubble--2 {
  width: 26px;
  height: 26px;
  bottom: 12px;
  right: 12px;
  animation: surveyBubbleDrift 6.5s infinite ease-in-out alternate-reverse;
  opacity: 0.7;
}
.survey-bubble--3 {
  width: 12px;
  height: 12px;
  bottom: 24px;
  left: 14px;
  animation: surveyBubbleDrift 4.5s infinite ease-in-out alternate;
  opacity: 0.6;
}
@keyframes surveyBubbleDrift {
  0% { transform: translateY(0) translateX(0) scale(0.95); }
  50% { transform: translateY(-7px) translateX(4px) scale(1.04); }
  100% { transform: translateY(3px) translateX(-3px) scale(0.98); }
}

/* ── 1. TOP NAVIGATOR ── */
.survey-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.survey-back-btn {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 17px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95);
  transition: transform 0.12s ease;
}
.survey-back-btn:active { transform: scale(0.92); }

.survey-nav-title {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
}

.survey-bounty-pill {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 1.5px solid #ffffff;
  color: #78350f;
  font-weight: 900;
  font-size: 11.5px;
  padding: 6px 11px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 3px 10px rgba(245, 158, 11, 0.25), inset 1px 1px 2px rgba(255, 255, 255, 0.8);
}

/* ── 2. HERO BOUNTY CARD ── */
.survey-hero-card {
  padding: 18px 16px;
}
.survey-hero-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: linear-gradient(135deg, var(--reef-coral-500) 0%, #e11d48 100%);
  color: #ffffff;
  font-weight: 900;
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  padding: 3px 10px;
  border-radius: 10px;
  margin-bottom: 8px;
  border: 1px solid rgba(255, 255, 255, 0.6);
  box-shadow: 0 2px 6px rgba(244, 63, 94, 0.25);
}
.survey-hero-title {
  font-size: 17px;
  font-weight: 900;
  color: var(--reef-ink);
  margin: 0 0 6px;
  line-height: 1.35;
  display: flex;
  align-items: center;
  gap: 6px;
}
.survey-hero-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.45;
  margin: 0;
}

/* ── 3. LIGHTHOUSE INTEGRITY STRIP (KEBIJAKAN TAKEBACK RAMAH & TEGAS) ── */
.survey-lighthouse-strip {
  background: rgba(255, 255, 255, 0.94);
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 10px 12px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.survey-lh-icon {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
  border: 1.5px solid #ffffff;
  color: #ea580c;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(234, 88, 12, 0.15);
}
.survey-lh-text {
  font-size: 10.8px;
  font-weight: 700;
  color: #7c2d12;
  line-height: 1.4;
}
.survey-lh-text strong {
  color: #9a3412;
}

/* ── 4. QUESTION STATION CARDS ── */
.survey-station {
  padding: 16px 14px;
}
.station-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.station-badge {
  width: 24px;
  height: 24px;
  border-radius: 8px;
  background: linear-gradient(135deg, #fecdd3 0%, #fda4af 100%);
  border: 1px solid #ffffff;
  color: #be123c;
  font-size: 11.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 4px rgba(244, 63, 94, 0.15);
}
.station-title {
  font-size: 13.5px;
  font-weight: 900;
  color: var(--reef-ink);
}
.station-desc {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.35;
  margin-bottom: 12px;
  padding-left: 32px;
}

/* Grid Sumber Info */
.source-reef-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 10px;
}
.source-pearl-chip {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 9px 11px;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.16s cubic-bezier(0.34, 1.56, 0.64, 1);
  user-select: none;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
  position: relative;
}
.source-pearl-chip:hover {
  transform: translateY(-1px);
  background: #fffbf9;
}
.source-pearl-chip:active {
  transform: scale(0.96);
}
.source-pearl-chip.selected {
  background: linear-gradient(135deg, #ffffff 0%, #ffedd5 100%);
  border-color: #f97316;
  box-shadow: 0 4px 12px rgba(249, 115, 22, 0.2), inset 1px 1px 2px #ffffff;
  transform: translateY(-2px);
}
.source-pearl-chip input[type="radio"] { display: none; }
.source-pearl-icon {
  font-size: 17px;
  color: var(--reef-muted);
  transition: color 0.16s;
  flex-shrink: 0;
}
.source-pearl-chip.selected .source-pearl-icon {
  color: #ea580c;
}
.source-pearl-label {
  font-size: 11.5px;
  font-weight: 800;
  color: var(--reef-ink);
  line-height: 1.2;
}
.source-pearl-chip.selected .source-pearl-label {
  color: #7c2d12;
  font-weight: 900;
}
.source-pearl-check {
  margin-left: auto;
  font-size: 13px;
  color: #ea580c;
  display: none;
}
.source-pearl-chip.selected .source-pearl-check {
  display: inline-block;
}

/* Custom Text Input */
.survey-field-input {
  width: 100%;
  background: #ffffff;
  border: 1.5px solid #fed7aa;
  border-radius: 14px;
  padding: 10px 13px;
  font-size: 12px;
  font-weight: 700;
  color: var(--reef-ink);
  font-family: inherit;
  outline: none;
  transition: all 0.18s ease;
  box-shadow: inset 1px 1px 3px rgba(30, 27, 46, 0.03);
  box-sizing: border-box;
}
.survey-field-input:focus {
  border-color: var(--reef-coral-500);
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.16);
}
.survey-textarea {
  min-height: 80px;
  resize: vertical;
  line-height: 1.45;
}

/* Character Counter & Helper */
.field-meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 6px;
  font-size: 10.5px;
  font-weight: 700;
  color: var(--reef-muted);
}
.field-meta-counter {
  font-variant-numeric: tabular-nums;
  color: #ea580c;
  font-weight: 800;
}
.field-meta-counter.valid {
  color: #10b981;
}

/* ── 5. EMOJI RATING 5 BINTANG LAUT ── */
.rating-marine-deck {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
  margin-bottom: 6px;
}
.rating-marine-node {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 8px 3px 7px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  user-select: none;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
}
.rating-marine-node:hover {
  transform: translateY(-2px);
  background: #fff7ed;
}
.rating-marine-node:active {
  transform: scale(0.92);
}
.rating-marine-node.selected {
  background: linear-gradient(135deg, #ffffff 0%, #ffedd5 100%);
  border-color: #f59e0b;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25), inset 1px 1px 2px #ffffff;
  transform: translateY(-4px) scale(1.05);
}
.rating-marine-node input[type="radio"] { display: none; }
.rating-marine-emoji {
  font-size: 24px;
  line-height: 1;
  display: block;
  margin-bottom: 4px;
  transition: transform 0.2s;
}
.rating-marine-node.selected .rating-marine-emoji {
  transform: scale(1.18);
}
.rating-marine-lbl {
  font-size: 9px;
  font-weight: 800;
  color: var(--reef-muted);
  line-height: 1.1;
  display: block;
}
.rating-marine-node.selected .rating-marine-lbl {
  color: #b45309;
  font-weight: 900;
}
.rating-callout {
  text-align: center;
  font-size: 11px;
  font-weight: 800;
  color: #b45309;
  min-height: 18px;
  margin-top: 4px;
}

/* ── 6. SUBMIT BUTTON & HONESTY PLEDGE ── */
.honesty-pledge-strip {
  background: #fff1f2;
  border: 1.5px solid #fecdd3;
  border-radius: 14px;
  padding: 8px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 10.5px;
  font-weight: 700;
  color: #9f1239;
  line-height: 1.35;
}

.btn-survey-launch {
  width: 100%;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 50%, #e11d48 100%);
  border: 2px solid #ffffff;
  border-radius: 16px;
  padding: 13px 18px;
  color: #ffffff;
  font-size: 13.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  box-shadow: 0 6px 18px rgba(244, 63, 94, 0.35), 0 3px 0 #be123c, inset 1px 1px 3px rgba(255, 255, 255, 0.6);
  transition: all 0.15s ease;
  font-family: inherit;
}
.btn-survey-launch:hover {
  filter: brightness(1.03);
}
.btn-survey-launch:active {
  transform: translateY(3px);
  box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3), 0 0 0 #be123c;
}
.btn-survey-launch:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

/* ── 7. RESULT / SUCCESS / REVOKED CARDS ── */
.survey-outcome-card {
  padding: 24px 18px;
  text-align: center;
}
.survey-outcome-icon {
  width: 64px;
  height: 64px;
  border-radius: 20px;
  margin: 0 auto 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  border: 2px solid #ffffff;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
}
.survey-outcome-icon.success {
  background: linear-gradient(135deg, #a7f3d0 0%, #10b981 100%);
  color: #ffffff;
}
.survey-outcome-icon.revoked {
  background: linear-gradient(135deg, #fecdd3 0%, #f43f5e 100%);
  color: #ffffff;
}
.survey-outcome-title {
  font-size: 18px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 4px;
}
.survey-outcome-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.45;
  margin-bottom: 14px;
}

.survey-vault-pill {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.05);
  margin-bottom: 14px;
}
.survey-vault-val {
  font-size: 16px;
  font-weight: 900;
  color: #065f46;
}

/* Log Ringkasan Jawaban */
.survey-log-parchment {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 12px 14px;
  text-align: left;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
  font-size: 11.5px;
}
.survey-log-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  padding: 6px 0;
  border-bottom: 1px dashed rgba(251, 146, 60, 0.25);
}
.survey-log-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
.survey-log-lbl {
  color: var(--reef-muted);
  font-weight: 700;
  flex-shrink: 0;
}
.survey-log-val {
  color: var(--reef-ink);
  font-weight: 800;
  text-align: right;
}
.survey-log-quote {
  background: #fff7ed;
  border: 1px solid #ffedd5;
  border-radius: 10px;
  padding: 6px 10px;
  color: #7c2d12;
  font-style: italic;
  font-weight: 700;
  font-size: 11px;
  margin-top: 4px;
  word-break: break-word;
}

.survey-actions-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 14px;
}
.btn-marine-act {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 14px;
  padding: 10px;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  border: 1.5px solid #ffffff;
  transition: transform 0.12s ease;
  user-select: none;
}
.btn-marine-act:active { transform: scale(0.96); }
.btn-marine-act.gold {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  color: #78350f;
  box-shadow: 0 3px 10px rgba(245, 158, 11, 0.25);
}
.btn-marine-act.light {
  background: #ffffff;
  color: var(--reef-ink);
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.05);
}

/* Flash alerts */
.survey-flash-box {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 13px;
  border-radius: 14px;
  font-size: 11.5px;
  font-weight: 800;
  border: 1.5px solid #ffffff;
}
.survey-flash-box.danger {
  background: #ffe4e6;
  color: #9f1239;
  border-color: #fecdd3;
}
.survey-flash-box.success {
  background: #d1fae5;
  color: #065f46;
  border-color: #a7f3d0;
}
.survey-flash-box.info {
  background: #e0f2fe;
  color: #0369a1;
  border-color: #bae6fd;
}
</style>

<div class="survey-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="survey-top-nav">
    <a href="/home" class="survey-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="survey-nav-title">
      <i class="ph-fill ph-compass" style="color:var(--reef-coral-500);"></i>
      <span>Survei Samudra</span>
    </div>
    <div class="survey-bounty-pill">
      <i class="ph-bold ph-sparkle"></i>
      <span>+Rp 15.000</span>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="survey-flash-box <?= htmlspecialchars($flashType) ?>">
    <i class="ph-bold <?= $flashType==='success'?'ph-check-circle':($flashType==='info'?'ph-info':'ph-warning-circle') ?>" style="font-size:16px;"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($existingSurvey): ?>
    <?php if (($existingSurvey['status'] ?? 'completed') === 'revoked'): ?>
      <!-- ═══════════════════════════════════════════════════ -->
      <!-- ── TAMPILAN SURVEI DIBATALKAN / REVOKED (TAKEBACK) ── -->
      <!-- ═══════════════════════════════════════════════════ -->
      <div class="survey-clay survey-outcome-card">
        <div class="survey-bubble survey-bubble--1"></div>
        <div class="survey-bubble survey-bubble--2"></div>

        <div class="survey-outcome-icon revoked">
          <i class="ph-bold ph-warning-octagon"></i>
        </div>
        <div class="survey-outcome-title" style="color:#9f1239;">Survei Dibatalkan (Takeback)</div>
        <div class="survey-outcome-desc">
          Respon survei telah <strong>dibatalkan</strong> dan saldo hadiah <strong>Rp <?= number_format((float)$existingSurvey['reward_amount'], 0, ',', '.') ?></strong> ditarik kembali oleh Admin karena indikasi pengisian asal-asalan/spam.
        </div>

        <?php if (!empty($existingSurvey['revoke_reason'])): ?>
        <div style="background:#fff1f2;border:1.5px dashed #f43f5e;border-radius:14px;padding:10px 12px;margin-bottom:12px;text-align:left;">
          <div style="font-size:10.5px;font-weight:900;color:#e11d48;text-transform:uppercase;margin-bottom:2px;display:flex;align-items:center;gap:4px;">
            <i class="ph-bold ph-info"></i> Alasan Penarikan Saldo:
          </div>
          <div style="font-size:11.5px;color:#334155;font-weight:700;">
            <?= htmlspecialchars((string)$existingSurvey['revoke_reason']) ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="survey-vault-pill" style="border-color:#fecdd3;background:#fff5f5;">
          <i class="ph-fill ph-wallet" style="font-size:20px;color:#dc2626;"></i>
          <div style="text-align:left;">
            <div style="font-size:10px;color:#991b1b;font-weight:800;">Saldo Tarik Sekarang:</div>
            <div class="survey-vault-val" style="color:#991b1b;">Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></div>
          </div>
        </div>

        <!-- Log Catatan Jawaban -->
        <div class="survey-log-parchment">
          <div style="font-weight:900;color:var(--reef-ink);margin-bottom:8px;font-size:12px;display:flex;align-items:center;gap:6px;">
            <i class="ph-bold ph-clipboard-text" style="color:#e11d48;"></i> Rekam Jawaban Terdahulu:
          </div>

          <div class="survey-log-row">
            <span class="survey-log-lbl">Sumber Info:</span>
            <span class="survey-log-val"><?= htmlspecialchars((string)$existingSurvey['source_info']) ?></span>
          </div>

          <div class="survey-log-row">
            <span class="survey-log-lbl">Rating Kepuasan:</span>
            <span class="survey-log-val">
              <?php
                $r = (int)$existingSurvey['satisfaction_rating'];
                $emojiMap = [
                  1 => '😡 Sangat Buruk (1/5)',
                  2 => '🙁 Kurang Puas (2/5)',
                  3 => '😐 Biasa Saja (3/5)',
                  4 => '🙂 Puas (4/5)',
                  5 => '🤩 Sangat Puas (5/5)'
                ];
                echo $emojiMap[$r] ?? "{$r}/5";
              ?>
            </span>
          </div>

          <div style="margin-top:6px;">
            <div class="survey-log-lbl">Pengalamanmu:</div>
            <div class="survey-log-quote">"<?= htmlspecialchars((string)$existingSurvey['experience']) ?>"</div>
          </div>

          <div style="margin-top:6px;">
            <div class="survey-log-lbl">Pesan / Masukan:</div>
            <div class="survey-log-quote">"<?= htmlspecialchars((string)$existingSurvey['feedback_message']) ?>"</div>
          </div>
        </div>

        <div class="survey-actions-grid" style="grid-template-columns:1fr;margin-top:12px;">
          <a href="/home" class="btn-marine-act light">
            <i class="ph-bold ph-house"></i> Kembali ke Beranda
          </a>
        </div>
      </div>

    <?php else: ?>
      <!-- ═══════════════════════════════════════════════════ -->
      <!-- ── TAMPILAN SUDAH MENGISI SURVEI (COMPLETED) ── -->
      <!-- ═══════════════════════════════════════════════════ -->
      <div class="survey-clay survey-outcome-card">
        <div class="survey-bubble survey-bubble--1"></div>
        <div class="survey-bubble survey-bubble--2"></div>

        <div class="survey-outcome-icon success">
          <i class="ph-bold ph-check"></i>
        </div>
        <div class="survey-outcome-title" style="color:#065f46;">Misi Survei Selesai! 🎉</div>
        <div class="survey-outcome-desc">
          Terima kasih atas kontribusi berhargamu untuk ekosistem MancingCuan. Hadiah <strong>Rp <?= number_format((float)$existingSurvey['reward_amount'], 0, ',', '.') ?></strong> Saldo Tarik telah aman berada di dompet akunmu.
        </div>

        <div class="survey-vault-pill">
          <i class="ph-fill ph-wallet" style="font-size:22px;color:#10b981;"></i>
          <div style="text-align:left;">
            <div style="font-size:10px;color:#047857;font-weight:800;">Saldo Tarik Kamu Sekarang:</div>
            <div class="survey-vault-val">Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></div>
          </div>
        </div>

        <!-- Log Catatan Jawaban -->
        <div class="survey-log-parchment">
          <div style="font-weight:900;color:var(--reef-ink);margin-bottom:8px;font-size:12px;display:flex;align-items:center;gap:6px;">
            <i class="ph-fill ph-notebook" style="color:var(--reef-coral-500);"></i> Jurnal Respon yang Dikirim:
          </div>

          <div class="survey-log-row">
            <span class="survey-log-lbl">Sumber Info:</span>
            <span class="survey-log-val"><?= htmlspecialchars((string)$existingSurvey['source_info']) ?></span>
          </div>

          <div class="survey-log-row">
            <span class="survey-log-lbl">Rating Kepuasan:</span>
            <span class="survey-log-val">
              <?php
                $r = (int)$existingSurvey['satisfaction_rating'];
                $emojiMap = [
                  1 => '😡 Sangat Buruk (1/5)',
                  2 => '🙁 Kurang Puas (2/5)',
                  3 => '😐 Biasa Saja (3/5)',
                  4 => '🙂 Puas (4/5)',
                  5 => '🤩 Sangat Puas (5/5)'
                ];
                echo $emojiMap[$r] ?? "{$r}/5";
              ?>
            </span>
          </div>

          <div style="margin-top:6px;">
            <div class="survey-log-lbl">Pengalamanmu:</div>
            <div class="survey-log-quote">"<?= htmlspecialchars((string)$existingSurvey['experience']) ?>"</div>
          </div>

          <div style="margin-top:6px;">
            <div class="survey-log-lbl">Pesan / Masukan:</div>
            <div class="survey-log-quote">"<?= htmlspecialchars((string)$existingSurvey['feedback_message']) ?>"</div>
          </div>

          <div style="margin-top:8px;font-size:10px;color:var(--reef-muted);text-align:right;">
            Dikirim pada: <?= date('d M Y, H:i', strtotime($existingSurvey['created_at'])) ?> WIB
          </div>
        </div>

        <div class="survey-actions-grid">
          <a href="/withdraw" class="btn-marine-act gold">
            <i class="ph-bold ph-hand-coins"></i> Tarik Saldo
          </a>
          <a href="/home" class="btn-marine-act light">
            <i class="ph-bold ph-house"></i> Ke Beranda
          </a>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ═══════════════════════════════════════════════════ -->
    <!-- ── FORM SURVEI BARU (BELUM MENGISI) — CORAL QUEST ── -->
    <!-- ═══════════════════════════════════════════════════ -->

    <!-- HERO BOUNTY CARD -->
    <div class="survey-clay survey-hero-card">
      <div class="survey-bubble survey-bubble--1"></div>
      <div class="survey-bubble survey-bubble--2"></div>
      
      <div class="survey-hero-tag">
        <i class="ph-bold ph-sparkle"></i> Petualangan Riset Bahari
      </div>
      <h1 class="survey-hero-title">
        <span>Bantu Riset, Ambil Rp 15.000!</span>
        <span>🌊💎</span>
      </h1>
      <p class="survey-hero-desc">
        Jawab 4 pertanyaan singkat mengenai penjelajahanmu di MancingCuan. Hadiah <strong>Rp 15.000 Saldo Tarik</strong> langsung dikreditkan instan ke dompetmu!
      </p>
    </div>

    <!-- LIGHTHOUSE INTEGRITY STRIP -->
    <div class="survey-lighthouse-strip">
      <div class="survey-lh-icon">
        <i class="ph-fill ph-shield-check"></i>
      </div>
      <div class="survey-lh-text">
        <strong>PENTING & KEBIJAKAN INTEGRITAS:</strong> Harap mengisi dengan kalimat yang jelas dan jujur. Admin meninjau berkala seluruh respon; respon asal-asalan/spam berakibat <u>saldo ditarik kembali (takeback)</u>.
      </div>
    </div>

    <form method="POST" id="surveyForm" onsubmit="return validateSurvey(event)">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- ── STATION 1: RADAR SUMBER NAVIGASI ── -->
      <div class="survey-clay survey-station" style="margin-bottom:12px;">
        <div class="station-header">
          <div class="station-badge">1</div>
          <div class="station-title">Darimana kamu tahu MancingCuan?</div>
        </div>
        <div class="station-desc">Pilih media pertama kali kamu menemukan kami:</div>

        <div class="source-reef-grid">
          <label class="source-pearl-chip" onclick="selectSource('Threads')">
            <input type="radio" name="source_choice" value="Threads" id="src-threads">
            <i class="ph-bold ph-at source-pearl-icon"></i>
            <span class="source-pearl-label">Threads</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>

          <label class="source-pearl-chip" onclick="selectSource('Facebook')">
            <input type="radio" name="source_choice" value="Facebook" id="src-fb">
            <i class="ph-bold ph-facebook-logo source-pearl-icon"></i>
            <span class="source-pearl-label">Facebook</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>

          <label class="source-pearl-chip" onclick="selectSource('Instagram')">
            <input type="radio" name="source_choice" value="Instagram" id="src-ig">
            <i class="ph-bold ph-instagram-logo source-pearl-icon"></i>
            <span class="source-pearl-label">Instagram</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>

          <label class="source-pearl-chip" onclick="selectSource('Google')">
            <input type="radio" name="source_choice" value="Google" id="src-google">
            <i class="ph-bold ph-google-logo source-pearl-icon"></i>
            <span class="source-pearl-label">Google</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>

          <label class="source-pearl-chip" onclick="selectSource('Teman')">
            <input type="radio" name="source_choice" value="Teman" id="src-teman">
            <i class="ph-bold ph-users-three source-pearl-icon"></i>
            <span class="source-pearl-label">Teman Nelayan</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>

          <label class="source-pearl-chip" onclick="selectSource('Lainnya')">
            <input type="radio" name="source_choice" value="Lainnya" id="src-other">
            <i class="ph-bold ph-pencil-simple-line source-pearl-icon"></i>
            <span class="source-pearl-label">Lainnya...</span>
            <i class="ph-bold ph-check-circle source-pearl-check"></i>
          </label>
        </div>

        <input type="text" name="source_custom" id="source_custom" class="survey-field-input" placeholder="Tuliskan keterangan sumber lainnya (opsional)..." maxlength="150">
      </div>

      <!-- ── STATION 2: JURNAL PENGALAMAN BERLAYAR ── -->
      <div class="survey-clay survey-station" style="margin-bottom:12px;">
        <div class="station-header">
          <div class="station-badge">2</div>
          <div class="station-title">Pengalamanmu selama di website ini?</div>
        </div>
        <div class="station-desc">Ceritakan kesanmu mencoba minigame, tugas video, deposit/wd:</div>

        <textarea name="experience" id="experience" class="survey-field-input survey-textarea" placeholder="Tuliskan pengalaman pribadimu di sini (gunakan kalimat sendiri, min. 10 karakter)..." required oninput="updateCharCount('experience', 'exp-counter', 10)"></textarea>

        <div class="field-meta-row">
          <span><i class="ph-bold ph-pen-nib"></i> Kalimat jelas & jujur</span>
          <span class="field-meta-counter" id="exp-counter">0 / 10 karakter</span>
        </div>
      </div>

      <!-- ── STATION 3: KOMPAS KEPUASAN (5 BINTANG LAUT) ── -->
      <div class="survey-clay survey-station" style="margin-bottom:12px;">
        <div class="station-header">
          <div class="station-badge">3</div>
          <div class="station-title">Seberapa puas kamu berlayar di sini?</div>
        </div>
        <div class="station-desc">Pilih ekspresi yang paling sesuai dengan perasaanmu:</div>

        <div class="rating-marine-deck">
          <label class="rating-marine-node" onclick="selectRating(1, '😡 Sangat Buruk / Butuh Banyak Perbaikan')">
            <input type="radio" name="satisfaction_rating" value="1" id="rate-1">
            <span class="rating-marine-emoji">😡</span>
            <span class="rating-marine-lbl">Buruk</span>
          </label>

          <label class="rating-marine-node" onclick="selectRating(2, '🙁 Kurang Puas / Butuh Peningkatan')">
            <input type="radio" name="satisfaction_rating" value="2" id="rate-2">
            <span class="rating-marine-emoji">🙁</span>
            <span class="rating-marine-lbl">Kurang</span>
          </label>

          <label class="rating-marine-node" onclick="selectRating(3, '😐 Biasa Saja / Cukup Standar')">
            <input type="radio" name="satisfaction_rating" value="3" id="rate-3">
            <span class="rating-marine-emoji">😐</span>
            <span class="rating-marine-lbl">Biasa</span>
          </label>

          <label class="rating-marine-node" onclick="selectRating(4, '🙂 Puas / Pengalaman Seru')">
            <input type="radio" name="satisfaction_rating" value="4" id="rate-4">
            <span class="rating-marine-emoji">🙂</span>
            <span class="rating-marine-lbl">Puas</span>
          </label>

          <label class="rating-marine-node" onclick="selectRating(5, '🤩 Sangat Puas / Rekomen Banget!')">
            <input type="radio" name="satisfaction_rating" value="5" id="rate-5">
            <span class="rating-marine-emoji">🤩</span>
            <span class="rating-marine-lbl">Mantap!</span>
          </label>
        </div>

        <div class="rating-callout" id="ratingCallout">
          (Ketuk salah satu emoji untuk memberi rating)
        </div>
      </div>

      <!-- ── STATION 4: PESAN DALAM BOTOL (KRITIK & SARAN) ── -->
      <div class="survey-clay survey-station" style="margin-bottom:12px;">
        <div class="station-header">
          <div class="station-badge">4</div>
          <div class="station-title">Pesan dalam botol untuk kami:</div>
        </div>
        <div class="station-desc">Tuliskan masukan fitur baru, keluhan teknis, atau ide:</div>

        <textarea name="feedback_message" id="feedback_message" class="survey-field-input survey-textarea" placeholder="Tuliskan pesan, saran, atau masukan berhargamu di sini..." required oninput="updateCharCount('feedback_message', 'fb-counter', 5)"></textarea>

        <div class="field-meta-row">
          <span><i class="ph-bold ph-chat-teardrop-text"></i> Pendapatmu sangat kami hargai</span>
          <span class="field-meta-counter" id="fb-counter">0 / 5 karakter</span>
        </div>
      </div>

      <!-- HONESTY PLEDGE STRIP -->
      <div class="honesty-pledge-strip" style="margin-bottom:12px;">
        <i class="ph-bold ph-shield-check" style="font-size:18px;color:#e11d48;flex-shrink:0;"></i>
        <span>Saya mengonfirmasi bahwa data ini diisi secara sadar & jujur. Saldo Rp 15.000 dapat dibatalkan bila terbukti spam.</span>
      </div>

      <!-- SUBMIT BUTTON -->
      <button type="submit" class="btn-survey-launch" id="btnSubmitSurvey">
        <i class="ph-bold ph-paper-plane-tilt" style="font-size:18px;"></i>
        <span>Kirim Jawaban & Klaim Rp 15.000</span>
      </button>

      <div style="text-align:center;font-size:11px;color:var(--reef-muted);margin-top:10px;font-weight:700;">
        <i class="ph-bold ph-check-circle" style="color:#10b981;"></i> Saldo Tarik otomatis masuk ke akunmu (1x klaim per user).
      </div>
    </form>
  <?php endif; ?>

</div>

<script>
let currentSelectedSource = '';
let currentSelectedRating = 0;

function selectSource(val) {
  currentSelectedSource = val;
  document.querySelectorAll('.source-pearl-chip').forEach(el => el.classList.remove('selected'));
  
  const radio = document.querySelector(`input[name="source_choice"][value="${val}"]`);
  if (radio) {
    radio.checked = true;
    radio.closest('.source-pearl-chip').classList.add('selected');
  }

  const customInput = document.getElementById('source_custom');
  if (customInput) {
    if (val === 'Lainnya') {
      customInput.placeholder = "Ketikkan dari mana kamu tahu web ini...";
      customInput.focus();
    } else {
      customInput.placeholder = `Atau tuliskan catatan tambahan tentang ${val} (opsional)...`;
    }
  }
}

function selectRating(val, label) {
  currentSelectedRating = val;
  document.querySelectorAll('.rating-marine-node').forEach(el => el.classList.remove('selected'));

  const radio = document.getElementById(`rate-${val}`);
  if (radio) {
    radio.checked = true;
    radio.closest('.rating-marine-node').classList.add('selected');
  }

  const callout = document.getElementById('ratingCallout');
  if (callout) {
    callout.innerHTML = `Pilihanmu: <strong>${label}</strong>`;
  }
}

function updateCharCount(inputId, counterId, minLen) {
  const input = document.getElementById(inputId);
  const counter = document.getElementById(counterId);
  if (!input || !counter) return;

  const len = input.value.trim().length;
  counter.textContent = `${len} / ${minLen} karakter`;
  if (len >= minLen) {
    counter.classList.add('valid');
  } else {
    counter.classList.remove('valid');
  }
}

function validateSurvey(e) {
  const custom = (document.getElementById('source_custom')?.value || '').trim();
  if (!currentSelectedSource && !custom) {
    alert('Mohon pilih salah satu sumber info di Stasiun 1.');
    e.preventDefault();
    return false;
  }

  const exp = (document.getElementById('experience')?.value || '').trim();
  if (exp.length < 10) {
    alert('Mohon ceritakan pengalamanmu secara jelas di Stasiun 2 (minimal 10 karakter). Dilarang mengisi asal-asalan.');
    document.getElementById('experience')?.focus();
    e.preventDefault();
    return false;
  }

  if (currentSelectedRating < 1 || currentSelectedRating > 5) {
    alert('Mohon tentukan rating kepuasan emoji di Stasiun 3.');
    e.preventDefault();
    return false;
  }

  const fb = (document.getElementById('feedback_message')?.value || '').trim();
  if (fb.length < 5) {
    alert('Mohon tuliskan pesan atau keluhanmu di Stasiun 4 (minimal 5 karakter).');
    document.getElementById('feedback_message')?.focus();
    e.preventDefault();
    return false;
  }

  const btn = document.getElementById('btnSubmitSurvey');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengirim & Mengkreditkan Saldo...';
  }
  return true;
}
</script>

<?php require_once dirname(__DIR__) . '/partials/footer.php'; ?>
