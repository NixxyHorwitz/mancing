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
:root {
  --survey-gold: #f59e0b;
  --survey-gold-dark: #d97706;
  --survey-amber-glow: rgba(245, 158, 11, 0.25);
  --survey-bg-card: #ffffff;
  --survey-text: #1e293b;
  --survey-border: #fed7aa;
}

body {
  background: #f8fafc;
  font-family: 'Nunito', sans-serif;
  color: #1e293b;
  margin: 0;
  padding-bottom: 90px;
}

.survey-wrapper {
  max-width: 480px;
  margin: 0 auto;
  padding: 0 16px 30px;
}

/* TOP BAR */
.survey-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 0 12px;
}
.survey-back-btn {
  width: 38px;
  height: 38px;
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #475569;
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 2px 6px rgba(0,0,0,0.04);
  transition: all 0.15s ease;
}
.survey-back-btn:active {
  transform: scale(0.95);
  background: #f1f5f9;
}
.survey-top-title {
  font-weight: 800;
  font-size: 16px;
  color: #0f172a;
}
.survey-reward-pill {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  border: 1.5px solid #f59e0b;
  color: #92400e;
  font-weight: 800;
  font-size: 11.5px;
  padding: 5px 10px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
}

/* HERO CARD */
.survey-hero {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 50%, #fde68a 100%);
  border: 2px solid #fbbf24;
  border-radius: 20px;
  padding: 20px 18px;
  margin-bottom: 20px;
  box-shadow: 0 6px 18px -4px rgba(245, 158, 11, 0.22);
  position: relative;
  overflow: hidden;
}
.survey-hero::after {
  content: '🍯';
  position: absolute;
  right: -10px;
  bottom: -15px;
  font-size: 80px;
  opacity: 0.18;
  pointer-events: none;
}
.survey-hero__tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #f59e0b;
  color: #ffffff;
  font-weight: 900;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  padding: 3px 9px;
  border-radius: 12px;
  margin-bottom: 8px;
}
.survey-hero__h1 {
  font-size: 18px;
  font-weight: 900;
  color: #78350f;
  margin: 0 0 6px;
  line-height: 1.35;
}
.survey-hero__p {
  font-size: 12.5px;
  color: #92400e;
  margin: 0;
  line-height: 1.5;
  font-weight: 600;
}

/* QUESTION CARD */
.survey-card {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 18px;
  padding: 18px 16px;
  margin-bottom: 16px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
  transition: border-color 0.2s;
}
.survey-card:focus-within {
  border-color: #f59e0b;
}
.survey-q-num {
  width: 24px;
  height: 24px;
  background: #fef3c7;
  color: #b45309;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 900;
  margin-right: 6px;
}
.survey-q-title {
  font-weight: 800;
  font-size: 14.5px;
  color: #0f172a;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
}
.survey-q-desc {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 14px;
  line-height: 1.4;
}

/* SOURCE SELECTION GRID */
.source-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-bottom: 12px;
}
.source-chip {
  position: relative;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.18s ease;
  user-select: none;
}
.source-chip:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}
.source-chip.selected {
  background: #fef3c7;
  border-color: #f59e0b;
  box-shadow: 0 3px 10px rgba(245, 158, 11, 0.18);
}
.source-chip input[type="radio"] {
  display: none;
}
.source-chip__icon {
  font-size: 18px;
  color: #64748b;
  transition: color 0.18s;
  flex-shrink: 0;
}
.source-chip.selected .source-chip__icon {
  color: #d97706;
}
.source-chip__label {
  font-size: 12.5px;
  font-weight: 700;
  color: #334155;
  line-height: 1.2;
}
.source-chip.selected .source-chip__label {
  color: #78350f;
  font-weight: 800;
}

.custom-source-wrap {
  margin-top: 10px;
}
.survey-input {
  width: 100%;
  box-sizing: border-box;
  background: #f8fafc;
  border: 1.5px solid #cbd5e1;
  border-radius: 12px;
  padding: 11px 14px;
  font-size: 13px;
  font-family: inherit;
  color: #0f172a;
  outline: none;
  transition: all 0.2s ease;
}
.survey-input:focus {
  background: #fff;
  border-color: #f59e0b;
  box-shadow: 0 0 0 3.5px rgba(245, 158, 11, 0.15);
}
.survey-textarea {
  min-height: 85px;
  resize: vertical;
  line-height: 1.5;
}

/* EMOJI RATING SECTION */
.emoji-rating-row {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
  margin-bottom: 8px;
}
.emoji-rating-item {
  position: relative;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 10px 4px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  user-select: none;
}
.emoji-rating-item:hover {
  transform: translateY(-2px);
  background: #f1f5f9;
}
.emoji-rating-item.selected {
  background: #fffbeb;
  border-color: #f59e0b;
  box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
  transform: translateY(-4px) scale(1.05);
}
.emoji-rating-item input[type="radio"] {
  display: none;
}
.emoji-face {
  font-size: 26px;
  line-height: 1;
  display: block;
  margin-bottom: 4px;
  transition: transform 0.2s;
}
.emoji-rating-item.selected .emoji-face {
  transform: scale(1.15);
}
.emoji-label {
  font-size: 9.5px;
  font-weight: 800;
  color: #64748b;
  line-height: 1.1;
  display: block;
}
.emoji-rating-item.selected .emoji-label {
  color: #b45309;
}
.rating-feedback-badge {
  text-align: center;
  margin-top: 8px;
  font-size: 12px;
  font-weight: 700;
  color: #b45309;
  min-height: 20px;
}

/* SUBMIT BUTTON */
.btn-submit-survey {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: none;
  border-radius: 16px;
  padding: 16px 20px;
  color: #ffffff;
  font-size: 15px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  box-shadow: 0 6px 20px rgba(217, 119, 6, 0.35), 0 3px 0 #92400e;
  transition: all 0.15s ease;
}
.btn-submit-survey:hover {
  filter: brightness(1.04);
}
.btn-submit-survey:active {
  transform: translateY(3px);
  box-shadow: 0 2px 8px rgba(217, 119, 6, 0.35), 0 0 0 #92400e;
}
.btn-submit-survey:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

/* SUCCESS / COMPLETED CARD */
.survey-success-card {
  background: #ffffff;
  border: 2px solid #10b981;
  border-radius: 20px;
  padding: 24px 20px;
  text-align: center;
  box-shadow: 0 8px 24px -4px rgba(16, 185, 129, 0.2);
  margin-bottom: 20px;
}
.survey-success-icon {
  width: 68px;
  height: 68px;
  background: #d1fae5;
  color: #059669;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 34px;
  margin: 0 auto 14px;
  border: 2px solid #34d399;
}
.survey-summary-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
  text-align: left;
  margin-top: 18px;
  font-size: 12.5px;
}
.survey-summary-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
  border-bottom: 1px dashed #e2e8f0;
  padding-bottom: 8px;
}
.survey-summary-row:last-child {
  border-bottom: none;
  margin-bottom: 0;
  padding-bottom: 0;
}
</style>

<div class="survey-wrapper">
  
  <!-- TOPBAR -->
  <div class="survey-topbar">
    <a href="/home" class="survey-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="survey-top-title">Survei Pengguna</div>
    <div class="survey-reward-pill">
      <i class="ph-bold ph-coins text-warning"></i> +Rp 15.000
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> d-flex align-items-center gap-2 mb-3" style="border-radius:14px;font-size:13px;padding:12px 14px;font-weight:700;">
      <i class="ph-bold <?= $flashType==='success'?'ph-check-circle':($flashType==='info'?'ph-info':'ph-warning-circle') ?>" style="font-size:18px;"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($existingSurvey): ?>
    <?php if (($existingSurvey['status'] ?? 'completed') === 'revoked'): ?>
      <!-- ═══════════════════════════════════════════════════ -->
      <!-- ── TAMPILAN SURVEI DIBATALKAN / DITARIK KEMBALI ── -->
      <!-- ═══════════════════════════════════════════════════ -->
      <div class="survey-success-card" style="border-color:#fecdd3;background:#fff1f2;">
        <div class="survey-success-icon" style="background:#ffe4e6;color:#e11d48;">
          <i class="ph-bold ph-warning-octagon"></i>
        </div>
        <h2 style="font-size:20px;font-weight:900;color:#9f1239;margin:0 0 6px;">Survei Dibatalkan (Takeback)</h2>
        <p style="font-size:13px;color:#be123c;margin:0 0 14px;font-weight:600;line-height:1.5;">
          Respon survei Anda telah <strong>dibatalkan</strong> dan hadiah <strong>Rp <?= number_format((float)$existingSurvey['reward_amount'], 0, ',', '.') ?></strong> Saldo Tarik telah ditarik kembali oleh Admin karena jawaban tidak memenuhi kriteria atau terindikasi diisi asal-asalan.
        </p>

        <?php if (!empty($existingSurvey['revoke_reason'])): ?>
          <div style="background:#fff;border:1.5px dashed #f43f5e;border-radius:12px;padding:12px 14px;margin-bottom:14px;text-align:left;">
            <div style="font-size:11px;font-weight:800;color:#e11d48;text-transform:uppercase;margin-bottom:3px;display:flex;align-items:center;gap:4px;">
              <i class="ph-bold ph-info"></i> Alasan Penarikan Saldo:
            </div>
            <div style="font-size:13px;color:#334155;font-weight:600;">
              <?= htmlspecialchars((string)$existingSurvey['revoke_reason']) ?>
            </div>
          </div>
        <?php endif; ?>

        <div style="background:#fff;border:1.5px solid #fecaca;padding:12px;border-radius:14px;display:flex;align-items:center;justify-content:center;gap:10px;">
          <i class="ph-fill ph-wallet" style="font-size:22px;color:#dc2626;"></i>
          <div style="text-align:left;">
            <div style="font-size:11px;color:#991b1b;font-weight:700;">Saldo Tarik Kamu Sekarang:</div>
            <div style="font-size:16px;font-weight:900;color:#7f1d1d;">Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></div>
          </div>
        </div>

        <!-- Ringkasan Jawaban User -->
        <div class="survey-summary-box" style="margin-top:16px;text-align:left;">
          <div style="font-weight:800;color:#334155;margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:6px;">
            <i class="ph-bold ph-clipboard-text text-danger"></i> Catatan Jawaban yang Pernah Dikirim:
          </div>

          <div class="survey-summary-row">
            <span style="color:#64748b;">Sumber Info:</span>
            <span style="font-weight:700;color:#0f172a;text-align:right;"><?= htmlspecialchars((string)$existingSurvey['source_info']) ?></span>
          </div>

          <div class="survey-summary-row">
            <span style="color:#64748b;">Rating Kepuasan:</span>
            <span style="font-weight:800;color:#b45309;">
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

          <div style="margin-top:8px;">
            <div style="color:#64748b;margin-bottom:2px;font-weight:700;">Pengalamanmu:</div>
            <div style="background:#fff;border:1px solid #e2e8f0;padding:8px 10px;border-radius:8px;color:#334155;font-style:italic;">
              "<?= htmlspecialchars((string)$existingSurvey['experience']) ?>"
            </div>
          </div>

          <div style="margin-top:10px;">
            <div style="color:#64748b;margin-bottom:2px;font-weight:700;">Pesan / Masukan:</div>
            <div style="background:#fff;border:1px solid #e2e8f0;padding:8px 10px;border-radius:8px;color:#334155;font-style:italic;">
              "<?= htmlspecialchars((string)$existingSurvey['feedback_message']) ?>"
            </div>
          </div>

          <div style="margin-top:10px;font-size:11px;color:#94a3b8;text-align:right;">
            Dibatalkan pada: <?= !empty($existingSurvey['revoked_at']) ? date('d M Y, H:i', strtotime($existingSurvey['revoked_at'])) . ' WIB' : '-' ?>
          </div>
        </div>

        <div style="margin-top:18px;">
          <a href="/home" class="btn btn-outline-secondary fw-bold w-100 d-flex align-items-center justify-content-center gap-1" style="border-radius:12px;padding:12px;font-size:13px;">
            <i class="ph-bold ph-house"></i> Kembali ke Beranda
          </a>
        </div>
      </div>
    <?php else: ?>
      <!-- ═══════════════════════════════════════════════════ -->
      <!-- ── TAMPILAN SUDAH MENGISI SURVEI (COMPLETED) ── -->
      <!-- ═══════════════════════════════════════════════════ -->
      <div class="survey-success-card">
        <div class="survey-success-icon">
          <i class="ph-bold ph-check"></i>
        </div>
        <h2 style="font-size:20px;font-weight:900;color:#065f46;margin:0 0 6px;">Survei Selesai! 🎉</h2>
        <p style="font-size:13px;color:#047857;margin:0 0 14px;font-weight:600;">
          Terima kasih atas partisipasi dan masukanmu. Hadiah <strong>Rp <?= number_format((float)$existingSurvey['reward_amount'], 0, ',', '.') ?></strong> Saldo Tarik telah berhasil ditambahkan ke saldo akun kamu.
        </p>

        <div style="background:#ecfdf5;border:1.5px solid #a7f3d0;padding:12px;border-radius:14px;display:flex;align-items:center;justify-content:center;gap:10px;">
          <i class="ph-fill ph-wallet" style="font-size:22px;color:#059669;"></i>
          <div style="text-align:left;">
            <div style="font-size:11px;color:#047857;font-weight:700;">Saldo Tarik Kamu Sekarang:</div>
            <div style="font-size:16px;font-weight:900;color:#065f46;">Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></div>
          </div>
        </div>

        <!-- Ringkasan Jawaban User -->
        <div class="survey-summary-box">
          <div style="font-weight:800;color:#334155;margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:6px;">
            <i class="ph-bold ph-clipboard-text text-warning"></i> Jawaban yang Kamu Kirimkan:
          </div>

          <div class="survey-summary-row">
            <span style="color:#64748b;">Sumber Info:</span>
            <span style="font-weight:700;color:#0f172a;text-align:right;"><?= htmlspecialchars((string)$existingSurvey['source_info']) ?></span>
          </div>

          <div class="survey-summary-row">
            <span style="color:#64748b;">Rating Kepuasan:</span>
            <span style="font-weight:800;color:#b45309;">
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

          <div style="margin-top:8px;">
            <div style="color:#64748b;margin-bottom:2px;font-weight:700;">Pengalamanmu:</div>
            <div style="background:#fff;border:1px solid #e2e8f0;padding:8px 10px;border-radius:8px;color:#334155;font-style:italic;">
              "<?= htmlspecialchars((string)$existingSurvey['experience']) ?>"
            </div>
          </div>

          <div style="margin-top:10px;">
            <div style="color:#64748b;margin-bottom:2px;font-weight:700;">Pesan / Masukan:</div>
            <div style="background:#fff;border:1px solid #e2e8f0;padding:8px 10px;border-radius:8px;color:#334155;font-style:italic;">
              "<?= htmlspecialchars((string)$existingSurvey['feedback_message']) ?>"
            </div>
          </div>

          <div style="margin-top:10px;font-size:11px;color:#94a3b8;text-align:right;">
            Dikirim pada: <?= date('d M Y, H:i', strtotime($existingSurvey['created_at'])) ?> WIB
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px;">
          <a href="/withdraw" class="btn btn-warning fw-bold d-flex align-items-center justify-content-center gap-1" style="border-radius:12px;padding:12px;font-size:13px;color:#78350f;">
            <i class="ph-bold ph-hand-coins"></i> Tarik Saldo
          </a>
          <a href="/home" class="btn btn-outline-secondary fw-bold d-flex align-items-center justify-content-center gap-1" style="border-radius:12px;padding:12px;font-size:13px;">
            <i class="ph-bold ph-house"></i> Ke Beranda
          </a>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ═══════════════════════════════════════════════════ -->
    <!-- ── FORM SURVEI BARU (BELUM PERNAH MENGISI) ── -->
    <!-- ═══════════════════════════════════════════════════ -->

    <!-- HERO PROMO -->
    <div class="survey-hero">
      <div class="survey-hero__tag">
        <i class="ph-bold ph-sparkle"></i> Reward Rp 15.000 Saldo Tarik
      </div>
      <h1 class="survey-hero__h1">Bantu Kami Berkembang &amp; Ambil Hadiahmu! 🐝</h1>
      <p class="survey-hero__p">
        Jawab 2 pertanyaan dan berikan pesan/keluhanmu secara jujur. Setelah selesai, <strong>Rp 15.000 Saldo Tarik</strong> langsung dikreditkan ke akunmu!
      </p>
    </div>

    <!-- ANCAMAN & PERINGATAN SISTEM TAKEBACK -->
    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:16px;padding:14px 16px;margin-bottom:18px;display:flex;gap:12px;align-items:flex-start;box-shadow:0 4px 12px rgba(245,158,11,0.08);">
      <div style="width:36px;height:36px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:20px;flex-shrink:0;">
        <i class="ph-bold ph-warning"></i>
      </div>
      <div style="font-size:12.5px;color:#78350f;line-height:1.5;">
        <strong style="color:#92400e;display:block;font-size:13px;margin-bottom:2px;">PERINGATAN PENTING &amp; KEBIJAKAN TAKEBACK SALDO:</strong>
        Harap isi survei dengan <strong>sungguh-sungguh, jujur, dan kalimat yang jelas</strong>. Admin akan meninjau setiap jawaban yang masuk. Jika terbukti <strong>mengisi asal-asalan, mengetik karakter acak, atau spam</strong>, maka status survei akan dibatalkan dan <u><strong>Saldo Rp 15.000 akan ditarik kembali (Takeback)</strong></u> dari akunmu!
      </div>
    </div>

    <form method="POST" id="surveyForm" onsubmit="return validateSurvey(event)">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- ── PERTANYAAN 1: SUMBER INFO ── -->
      <div class="survey-card">
        <div class="survey-q-title">
          <span class="survey-q-num">1</span> Darimana dapat info web ini?
        </div>
        <div class="survey-q-desc">
          Pilih salah satu media atau tuliskan sendiri jika tidak ada di opsi:
        </div>

        <div class="source-grid">
          
          <label class="source-chip" onclick="selectSource('Threads')">
            <input type="radio" name="source_choice" value="Threads" id="src-threads">
            <i class="ph-bold ph-at source-chip__icon"></i>
            <span class="source-chip__label">Threads</span>
          </label>

          <label class="source-chip" onclick="selectSource('Facebook')">
            <input type="radio" name="source_choice" value="Facebook" id="src-fb">
            <i class="ph-bold ph-facebook-logo source-chip__icon"></i>
            <span class="source-chip__label">Facebook</span>
          </label>

          <label class="source-chip" onclick="selectSource('Instagram')">
            <input type="radio" name="source_choice" value="Instagram" id="src-ig">
            <i class="ph-bold ph-instagram-logo source-chip__icon"></i>
            <span class="source-chip__label">Instagram</span>
          </label>

          <label class="source-chip" onclick="selectSource('Google')">
            <input type="radio" name="source_choice" value="Google" id="src-google">
            <i class="ph-bold ph-google-logo source-chip__icon"></i>
            <span class="source-chip__label">Google</span>
          </label>

          <label class="source-chip" onclick="selectSource('Teman')">
            <input type="radio" name="source_choice" value="Teman" id="src-teman">
            <i class="ph-bold ph-users-three source-chip__icon"></i>
            <span class="source-chip__label">Teman</span>
          </label>

          <label class="source-chip" onclick="selectSource('Lainnya')">
            <input type="radio" name="source_choice" value="Lainnya" id="src-other">
            <i class="ph-bold ph-pencil-simple-line source-chip__icon"></i>
            <span class="source-chip__label">Lainnya...</span>
          </label>

        </div>

        <!-- Custom Input / Tulis Sendiri -->
        <div class="custom-source-wrap" id="customSourceWrap">
          <input type="text" name="source_custom" id="source_custom" class="survey-input" placeholder="Tuliskan dari mana kamu tahu web ini (opsional jika memilih opsi di atas)..." maxlength="150">
        </div>
      </div>

      <!-- ── PERTANYAAN 2: PENGALAMAN ── -->
      <div class="survey-card">
        <div class="survey-q-title">
          <span class="survey-q-num">2</span> Bagaimana pengalamanmu dengan web ini?
        </div>
        <div class="survey-q-desc">
          Ceritakan secara bebas pemikiran pribadimu tentang kemudahan web, minigame, tugas video, atau fitur yang kamu gunakan:
        </div>

        <textarea name="experience" id="experience" class="survey-input survey-textarea" placeholder="Tuliskan pengalaman pribadimu secara jujur di sini (gunakan kalimat sendiri, dilarang asal-asalan)..." required></textarea>
        
        <div style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11.5px;color:#b45309;font-weight:700;">
          <i class="ph-bold ph-shield-warning" style="font-size:15px;color:#d97706;"></i>
          <span>Peringatan: Saldo Rp 15.000 bisa diambil/ditarik kembali oleh Admin jika tidak mengisi dengan benar!</span>
        </div>
      </div>

      <!-- ── RATING KEPUASAN (DENGAN EMOJI) ── -->
      <div class="survey-card">
        <div class="survey-q-title">
          <span class="survey-q-num">3</span> Rating kepuasanmu:
        </div>
        <div class="survey-q-desc">
          Pilih salah satu emoji yang paling menggambarkan kepuasanmu menggunakan web MancingCuan:
        </div>

        <div class="emoji-rating-row">
          
          <label class="emoji-rating-item" onclick="selectRating(1, '😡 Sangat Buruk / Perlu Banyak Perbaikan')">
            <input type="radio" name="satisfaction_rating" value="1" id="rate-1">
            <span class="emoji-face">😡</span>
            <span class="emoji-label">Buruk</span>
          </label>

          <label class="emoji-rating-item" onclick="selectRating(2, '🙁 Kurang Puas / Butuh Peningkatan')">
            <input type="radio" name="satisfaction_rating" value="2" id="rate-2">
            <span class="emoji-face">🙁</span>
            <span class="emoji-label">Kurang</span>
          </label>

          <label class="emoji-rating-item" onclick="selectRating(3, '😐 Biasa Saja / Cukup Standar')">
            <input type="radio" name="satisfaction_rating" value="3" id="rate-3">
            <span class="emoji-face">😐</span>
            <span class="emoji-label">Biasa</span>
          </label>

          <label class="emoji-rating-item" onclick="selectRating(4, '🙂 Puas / Pengalaman Menyenangkan')">
            <input type="radio" name="satisfaction_rating" value="4" id="rate-4">
            <span class="emoji-face">🙂</span>
            <span class="emoji-label">Puas</span>
          </label>

          <label class="emoji-rating-item" onclick="selectRating(5, '🤩 Sangat Puas / Mantap &amp; Recomended!')">
            <input type="radio" name="satisfaction_rating" value="5" id="rate-5">
            <span class="emoji-face">🤩</span>
            <span class="emoji-label">Suka Banget</span>
          </label>

        </div>

        <div class="rating-feedback-badge" id="ratingFeedbackBadge">
          (Klik emoji untuk memilih rating)
        </div>
      </div>

      <!-- ── PESAN / KELUHAN ── -->
      <div class="survey-card">
        <div class="survey-q-title">
          <span class="survey-q-num">4</span> Pesan / keluhan untuk web ini:
        </div>
        <div class="survey-q-desc">
          Tuliskan kritik, masukan fitur baru, atau kendala/keluhan yang kamu temui agar dapat kami perbaiki segera:
        </div>

        <textarea name="feedback_message" id="feedback_message" class="survey-input survey-textarea" placeholder="Tuliskan kritik, saran, atau keluhan pribadimu di sini..." required></textarea>
        
        <div style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11.5px;color:#64748b;font-weight:600;">
          <i class="ph-bold ph-lightbulb" style="font-size:15px;color:#f59e0b;"></i>
          <span>Masukan, kritik, atau keluhan kamu sangat kami hargai untuk perbaikan web ke depan.</span>
        </div>
      </div>

      <!-- KARTU PERNYATAAN / ANCAMAN SEBELUM SUBMIT -->
      <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;">
        <i class="ph-bold ph-shield-warning" style="font-size:22px;color:#dc2626;flex-shrink:0;margin-top:1px;"></i>
        <div style="font-size:12px;color:#991b1b;font-weight:700;line-height:1.45;">
          Perhatian: Saya mengisi survei ini secara sadar dan jujur. Saya mengerti bahwa <strong>Saldo Rp 15.000 bisa diambil/ditarik kembali</strong> oleh Admin sewaktu-waktu jika jawaban terbukti asal-asalan, karakter acak, atau spam.
        </div>
      </div>

      <!-- SUBMIT BUTTON -->
      <button type="submit" class="btn-submit-survey" id="btnSubmitSurvey">
        <i class="ph-bold ph-paper-plane-tilt" style="font-size:20px;"></i>
        <span>Kirim Survei &amp; Ambil Rp 15.000</span>
      </button>

      <div style="text-align:center;font-size:11.5px;color:#94a3b8;margin-top:10px;font-weight:600;">
        <i class="ph-bold ph-shield-check text-success"></i> Hadiah Rp 15.000 otomatis masuk ke Saldo Tarik (1 akun hanya bisa 1x klaim).
      </div>

    </form>

  <?php endif; ?>

</div>

<script>
let currentSelectedSource = '';
let currentSelectedRating = 0;

function selectSource(val) {
  currentSelectedSource = val;
  document.querySelectorAll('.source-chip').forEach(el => el.classList.remove('selected'));
  
  const radio = document.querySelector(`input[name="source_choice"][value="${val}"]`);
  if (radio) {
    radio.checked = true;
    radio.closest('.source-chip').classList.add('selected');
  }

  const customInput = document.getElementById('source_custom');
  if (val === 'Lainnya') {
    customInput.placeholder = "Ketikkan dari mana kamu tahu web ini...";
    customInput.focus();
  } else {
    customInput.placeholder = `Atau tuliskan keterangan tambahan tentang ${val} (opsional)...`;
  }
}

function selectRating(val, label) {
  currentSelectedRating = val;
  document.querySelectorAll('.emoji-rating-item').forEach(el => el.classList.remove('selected'));

  const radio = document.getElementById(`rate-${val}`);
  if (radio) {
    radio.checked = true;
    radio.closest('.emoji-rating-item').classList.add('selected');
  }

  const badge = document.getElementById('ratingFeedbackBadge');
  if (badge) {
    badge.innerHTML = `Pilihanmu: <strong>${label}</strong>`;
  }
}

function validateSurvey(e) {
  const custom = document.getElementById('source_custom').value.trim();
  if (!currentSelectedSource && !custom) {
    alert('Mohon pilih salah satu sumber info atau ketikkan sendiri di kotak pertanyaan 1.');
    e.preventDefault();
    return false;
  }

  const exp = document.getElementById('experience').value.trim();
  if (exp.length < 10) {
    alert('Mohon ceritakan pengalamanmu dengan kalimat yang jelas (minimal 10 karakter). Ingat: dilarang mengisi asal-asalan, saldo Rp 15.000 bisa diambil lagi!');
    document.getElementById('experience').focus();
    e.preventDefault();
    return false;
  }

  if (currentSelectedRating < 1 || currentSelectedRating > 5) {
    alert('Mohon pilih salah satu rating emoji kepuasan di pertanyaan 3.');
    e.preventDefault();
    return false;
  }

  const fb = document.getElementById('feedback_message').value.trim();
  if (fb.length < 5) {
    alert('Mohon tuliskan pesan atau keluhanmu di pertanyaan 4 (minimal 5 karakter).');
    document.getElementById('feedback_message').focus();
    e.preventDefault();
    return false;
  }

  const btn = document.getElementById('btnSubmitSurvey');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Menyimpan & Mengkreditkan Saldo Rp 15.000...';
  return true;
}
</script>

<?php require_once dirname(__DIR__) . '/partials/footer.php'; ?>
