<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$campaign_enabled = setting($pdo, 'threads_campaign_enabled', '1') === '1';
if (!$campaign_enabled) {
    $_SESSION['flash_home_err'] = 'Kampanye Threads saat ini sedang tidak aktif.';
    redirect('/home');
}

$reward_amount  = (float)setting($pdo, 'threads_campaign_reward', '25000');
$reward_amount2 = (float)setting($pdo, 'threads_campaign_reward_step2', '50000');

$instructions = setting($pdo, 'threads_campaign_instructions', "Promosikan MancingCuan di Threads dan dapatkan cuan tambahan Rp 25.000! \n\nKriteria Postingan Langkah 1:\n1. Postingan harus menyertakan gambar (screenshot/bukti bayar/foto aplikasi).\n2. Di dalam gambar screenshot, HARUS tertera jelas Nama/Username Threads kalian.\n3. Teks postingan berupa kalimat ajakan atau cerita pengalaman positif kamu mendapatkan cuan di MancingCuan.\n4. Berikan komentar atau caption positif tentang MancingCuan.\n\nCara Klaim:\n1. Buat postingan sesuai kriteria di atas pada akun Threads kamu.\n2. Ambil screenshot postingan tersebut (harus terlihat username kalian).\n3. Upload screenshot di form bawah ini.");
$instructions2 = setting($pdo, 'threads_campaign_instructions_step2', "Promosikan MancingCuan di Threads - Langkah 2 (Dapatkan Rp 50.000, Gaji Pasti & Chat Langsung WhatsApp Admin!)\n\nKriteria Postingan Langkah 2:\n1. Kamu telah mengundang minimal 10 referral bergabung di MancingCuan.\n2. Postingan Threads kamu viral / ramai dengan minimal 5.000 (5K) views / tayangan.\n3. Berikan screenshot (bukti SS) bahwa postingan Threads kamu tembus minimal 5.000 views (bisa kirim hingga 3 screenshot bukti statistik & interaksi).");

$admin_contact = trim((string)setting($pdo, 'threads_admin_contact', ''));
if (empty($admin_contact)) {
    $admin_contact = '/livechat';
}

// Referral stats
$stmtRefCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$stmtRefCount->execute([$user['referral_code']]);
$user_referral_count = (int)$stmtRefCount->fetchColumn();

// Step 1 Requests
$stmtPending1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='pending' LIMIT 1");
$stmtPending1->execute([$user['id']]);
$pendingRequest = $stmtPending1->fetch();

$stmtApproved1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='approved' LIMIT 1");
$stmtApproved1->execute([$user['id']]);
$approvedRequest = $stmtApproved1->fetch();

$stmtRejected1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected1->execute([$user['id']]);
$rejectedRequest = $stmtRejected1->fetch();

// Step 2 Requests
$stmtPending2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='pending' LIMIT 1");
$stmtPending2->execute([$user['id']]);
$pendingRequest2 = $stmtPending2->fetch();

$stmtApproved2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='approved' LIMIT 1");
$stmtApproved2->execute([$user['id']]);
$approvedRequest2 = $stmtApproved2->fetch();

$stmtRejected2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected2->execute([$user['id']]);
$rejectedRequest2 = $stmtRejected2->fetch();

$is_promotor_vip = !empty($approvedRequest2);

$flash = $flashType = '';
if ($_SESSION['flash_threads_msg'] ?? null) {
    $flash = $_SESSION['flash_threads_msg'];
    $flashType = $_SESSION['flash_threads_type'] ?? 'success';
    unset($_SESSION['flash_threads_msg'], $_SESSION['flash_threads_type']);
}

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $step = $_POST['step'] ?? 'step1';
    
    $checkType = $step === 'step2' ? 'threads_campaign_step2' : 'threads_campaign';
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM admin_requests WHERE user_id=? AND type=? AND status='pending'");
    $stmtCheck->execute([$user['id'], $checkType]);
    
    if ((int)$stmtCheck->fetchColumn() > 0) {
        $flash = 'Kamu masih memiliki klaim pending untuk langkah ini.';
        $flashType = 'error';
    } elseif ($step === 'step2' && $user_referral_count < 10) {
        $flash = 'Kamu belum memenuhi syarat 10 referral untuk mengajukan Langkah 2.';
        $flashType = 'error';
    } else {
        $uploadDir = dirname(__DIR__) . '/uploads/threads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if ($step === 'step1') {
            // STEP 1: Single screenshot
            if (empty($_FILES['proof']['tmp_name'])) {
                $flash = 'Silakan pilih file bukti screenshot postingan kamu!';
                $flashType = 'error';
            } else {
                $file = $_FILES['proof'];
                $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $flash = 'Format file tidak didukung! Harus JPG, PNG, atau WEBP.';
                    $flashType = 'error';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $flash = 'Ukuran file terlalu besar! Maksimal 5MB.';
                    $flashType = 'error';
                } else {
                    $filename = 'threads_step1_' . $user['id'] . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        $proof_path = 'uploads/threads/' . $filename;
                        
                        $payload = json_encode([
                            'proof_images' => [$proof_path],
                            'proof_image'  => $proof_path,
                            'reward_amount'=> $reward_amount,
                            'submitted_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $pdo->prepare("INSERT INTO admin_requests (user_id, type, status, payload, created_at, updated_at) VALUES (?, 'threads_campaign', 'pending', ?, NOW(), NOW())")
                            ->execute([$user['id'], $payload]);
                        $request_id = (int)$pdo->lastInsertId();
                        
                        // Telegram Notification
                        $tgMsg = "🧵 <b>KLAIM PROMOSI THREADS - LANGKAH 1</b>\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n"
                            . "👤 <b>User:</b> <code>{$user['username']}</code> (ID: {$user['id']})\n"
                            . "💵 <b>Reward Saldo Tarik:</b> <code>" . format_rp($reward_amount) . "</code>\n"
                            . "📅 <b>Tanggal:</b> " . date('d M Y H:i') . "\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n"
                            . "<i>Silakan verifikasi screenshot postingan Threads di atas.</i>";
                        
                        $kb = [
                            [
                                ['text' => '✅ Setujui Reward', 'callback_data' => 'req_approve_' . $request_id],
                                ['text' => '❌ Tolak Klaim', 'callback_data' => 'req_reject_' . $request_id]
                            ]
                        ];
                        
                        tg_store_msg_id($pdo, 'admin_requests', $request_id, send_telegram_photo($pdo, $uploadDir . $filename, $tgMsg, $kb, 'threads'));
                        
                        $_SESSION['flash_threads_msg'] = 'Bukti postingan Langkah 1 berhasil di-upload! Admin akan segera memverifikasi klaim kamu.';
                        $_SESSION['flash_threads_type'] = 'success';
                        redirect('/threads');
                    } else {
                        $flash = 'Gagal menyimpan file upload. Coba lagi.';
                        $flashType = 'error';
                    }
                }
            }
        } elseif ($step === 'step2') {
            // STEP 2: Up to 3 screenshots (minimal 5k views)
            $uploaded_files = [];
            $input_names = ['proof_1', 'proof_2', 'proof_3'];
            
            // Support both multiple array input or separate slots
            if (!empty($_FILES['proofs']['name'][0])) {
                $total_files = min(3, count($_FILES['proofs']['name']));
                for ($i = 0; $i < $total_files; $i++) {
                    if (!empty($_FILES['proofs']['tmp_name'][$i]) && is_uploaded_file($_FILES['proofs']['tmp_name'][$i])) {
                        $ext = strtolower(pathinfo((string)$_FILES['proofs']['name'][$i], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $_FILES['proofs']['size'][$i] <= 5 * 1024 * 1024) {
                            $filename = 'threads_step2_' . $user['id'] . '_' . ($i + 1) . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['proofs']['tmp_name'][$i], $uploadDir . $filename)) {
                                $uploaded_files[] = 'uploads/threads/' . $filename;
                            }
                        }
                    }
                }
            } else {
                foreach ($input_names as $idx => $in_name) {
                    if (!empty($_FILES[$in_name]['tmp_name']) && is_uploaded_file($_FILES[$in_name]['tmp_name'])) {
                        $ext = strtolower(pathinfo((string)$_FILES[$in_name]['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $_FILES[$in_name]['size'] <= 5 * 1024 * 1024) {
                            $filename = 'threads_step2_' . $user['id'] . '_' . ($idx + 1) . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES[$in_name]['tmp_name'], $uploadDir . $filename)) {
                                $uploaded_files[] = 'uploads/threads/' . $filename;
                            }
                        }
                    }
                }
            }
            
            if (empty($uploaded_files)) {
                $flash = 'Wajib upload minimal 1 screenshot (bisa hingga 3 screenshot) bukti postingan ramai minimal 5.000 views!';
                $flashType = 'error';
            } else {
                $payload = json_encode([
                    'proof_images'   => $uploaded_files,
                    'proof_image'    => $uploaded_files[0],
                    'reward_amount'  => $reward_amount2,
                    'views_rule'     => 'min_5k_views',
                    'referral_count' => $user_referral_count,
                    'submitted_at'   => date('Y-m-d H:i:s')
                ]);
                
                $pdo->prepare("INSERT INTO admin_requests (user_id, type, status, payload, created_at, updated_at) VALUES (?, 'threads_campaign_step2', 'pending', ?, NOW(), NOW())")
                    ->execute([$user['id'], $payload]);
                $request_id = (int)$pdo->lastInsertId();
                
                // Dispatch to Telegram Topic: 'threads'
                $fileCount = count($uploaded_files);
                $tgMsg = "👑 <b>PENGAJUAN PROMOTOR KHUSUS THREADS (LEVEL 2)</b>\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👤 <b>User:</b> <code>{$user['username']}</code> (ID: {$user['id']})\n"
                    . "🎯 <b>Target:</b> Promotor Khusus (Gaji Pasti & Chat WhatsApp Admin)\n"
                    . "👁️ <b>Syarat Rule:</b> Minimal 5.000 (5K) Views\n"
                    . "📸 <b>Jumlah Screenshot:</b> {$fileCount} Foto dilampirkan\n"
                    . "👥 <b>Total Referral:</b> <code>{$user_referral_count} orang</code> (Lolos Syarat)\n"
                    . "💵 <b>Reward Saldo:</b> <code>" . format_rp($reward_amount2) . "</code>\n"
                    . "📅 <b>Tanggal:</b> " . date('d M Y H:i') . "\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "<i>Periksa bukti screenshot statistik views di atas. Jika valid $\ge$ 5k views, setujui untuk meloloskan user menjadi Promotor Khusus.</i>";
                
                $kb = [
                    [
                        ['text' => '👑 Setujui Promotor Khusus', 'callback_data' => 'req_approve_' . $request_id],
                        ['text' => '❌ Tolak Pengajuan', 'callback_data' => 'req_reject_' . $request_id]
                    ]
                ];
                
                // Send primary photo
                tg_store_msg_id($pdo, 'admin_requests', $request_id, send_telegram_photo($pdo, dirname(__DIR__) . '/' . $uploaded_files[0], $tgMsg, $kb, 'threads'));
                
                // Send additional photos if user uploaded 2 or 3 screenshots
                for ($k = 1; $k < $fileCount; $k++) {
                    $extraCap = "📸 <b>Bukti Tambahan #" . ($k + 1) . " (Statistik 5K Views)</b>\n"
                        . "User: <code>{$user['username']}</code> | Pengajuan Level 2";
                    send_telegram_photo($pdo, dirname(__DIR__) . '/' . $uploaded_files[$k], $extraCap, [], 'threads');
                }
                
                $_SESSION['flash_threads_msg'] = "Bukti postingan Level 2 ({$fileCount} screenshot) berhasil dikirim! Tim Admin akan segera memverifikasi bukti 5.000 views kamu.";
                $_SESSION['flash_threads_type'] = 'success';
                redirect('/threads?step=2');
            }
        }
    }
}

$pageTitle = 'Event Cuan Threads';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════
   THREADS MARINE EXPEDITION — MISI VIRAL SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */

.threads-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 34px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base */
.th-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* Ambient Marine Floating Bubbles */
.th-bubble-dec {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(244, 63, 94, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  box-shadow: inset 1px 1px 2px #ffffff, 0 2px 6px rgba(8, 145, 178, 0.15);
  pointer-events: none;
  z-index: 0;
}
.th-bubble-dec--1 {
  width: 18px;
  height: 18px;
  top: 10px;
  right: 18px;
  animation: thBubbleFloat 5.2s infinite ease-in-out alternate;
}
.th-bubble-dec--2 {
  width: 26px;
  height: 26px;
  bottom: 12px;
  right: 14px;
  animation: thBubbleFloat 6.8s infinite ease-in-out alternate-reverse;
  opacity: 0.65;
}
@keyframes thBubbleFloat {
  0% { transform: translateY(0) scale(0.95); }
  50% { transform: translateY(-7px) scale(1.05); }
  100% { transform: translateY(2px) scale(0.98); }
}

/* ── 1. COMPACT TOP NAVIGATOR ── */
.th-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.th-back-btn {
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
.th-back-btn:active { transform: scale(0.92); }

.th-nav-title {
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

.th-bounty-badge {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 1.5px solid #ffffff;
  color: #78350f;
  font-weight: 900;
  font-size: 11px;
  padding: 6px 11px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 3px 10px rgba(245, 158, 11, 0.22), inset 1px 1px 2px #ffffff;
}

/* ── 2. HERO BROADCAST CARD ── */
.th-hero-card {
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.th-hero-avatar {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: linear-gradient(135deg, #0f172a 0%, #1e1b2e 100%);
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 22px;
  box-shadow: 0 4px 12px rgba(30, 27, 46, 0.15);
  flex-shrink: 0;
}
.th-hero-info {
  line-height: 1.25;
}
.th-hero-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 9px;
  font-weight: 900;
  color: var(--reef-coral-500);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 2px;
}
.th-hero-title {
  font-size: 13.5px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 2px;
}
.th-hero-desc {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.35;
}

/* ── 3. TRUST STRIP ── */
.th-trust-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
  background: rgba(255, 255, 255, 0.94);
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 8px 6px;
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
  text-align: center;
}
.th-trust-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  font-size: 9px;
  font-weight: 800;
  color: var(--reef-ink);
}
.th-trust-col i {
  font-size: 14px;
}

/* ── 4. TWO-STEP SEGMENTED TABS ── */
.th-tabs-deck {
  display: grid;
  grid-template-columns: 1fr 1fr;
  background: rgba(255, 255, 255, 0.94);
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 4px;
  gap: 4px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
}
.th-tab-item {
  border: none;
  background: transparent;
  padding: 8px 6px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-muted);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  transition: all 0.15s ease;
  font-family: inherit;
  user-select: none;
}
.th-tab-item.active {
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(244, 63, 94, 0.28);
}
.th-tab-pill {
  font-size: 9px;
  font-weight: 800;
  padding: 1.5px 6px;
  border-radius: 6px;
  background: #f1f5f9;
  color: var(--reef-ink);
}
.th-tab-item.active .th-tab-pill {
  background: rgba(255, 255, 255, 0.3);
  color: #ffffff;
}

/* ── 5. CARDS CONTENT ── */
.th-content-box {
  padding: 14px 14px 16px;
}

/* Reward Strip Card */
.th-reward-box {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 9px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
}
.th-reward-lbl {
  font-size: 10.5px;
  font-weight: 800;
  color: #065f46;
  display: flex;
  align-items: center;
  gap: 5px;
}
.th-reward-val {
  font-size: 16px;
  font-weight: 900;
  color: #059669;
}

/* Referral Copy Strip */
.th-reff-bar {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
}
.th-reff-meta {
  line-height: 1.2;
}
.th-reff-code {
  font-family: monospace;
  font-size: 12.5px;
  font-weight: 900;
  color: #ea580c;
}
.btn-copy-reff {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 1px solid #ffffff;
  border-radius: 9px;
  padding: 4px 10px;
  font-size: 10px;
  font-weight: 900;
  color: #78350f;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 4px rgba(245, 158, 11, 0.2);
  transition: transform 0.1s ease;
}
.btn-copy-reff:active { transform: scale(0.95); }

/* Instructions Box */
.th-inst-deck {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 10px 12px;
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.45;
  margin-bottom: 12px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
  white-space: pre-line;
}

/* Dropzone Upload */
.th-dropzone {
  background: #ffffff;
  border: 2px dashed #fda4af;
  border-radius: 14px;
  padding: 12px 10px;
  text-align: center;
  position: relative;
  cursor: pointer;
  transition: all 0.15s ease;
  min-height: 80px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.th-dropzone:hover {
  background: #fff1f2;
  border-color: var(--reef-coral-500);
}
.th-dropzone input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}
.th-dropzone-icon {
  font-size: 24px;
  color: var(--reef-coral-500);
}
.th-dropzone-text {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-ink);
  line-height: 1.3;
}

/* 3-Slot Grid for Step 2 */
.th-slot-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-bottom: 12px;
}
.th-slot-box {
  background: #ffffff;
  border: 1.5px dashed #fed7aa;
  border-radius: 12px;
  padding: 10px 4px;
  text-align: center;
  position: relative;
  cursor: pointer;
  overflow: hidden;
  height: 85px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.th-slot-box input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
  z-index: 2;
}
.th-slot-icon {
  font-size: 18px;
  color: var(--reef-muted);
  margin-bottom: 2px;
}
.th-slot-lbl {
  font-size: 8.5px;
  font-weight: 800;
  color: var(--reef-ink);
  white-space: nowrap;
}
.th-slot-tag {
  font-size: 7.5px;
  font-weight: 900;
  padding: 1px 4px;
  border-radius: 4px;
  margin-top: 2px;
}
.th-slot-tag--req { background: #fee2e2; color: #dc2626; }
.th-slot-tag--opt { background: #f1f5f9; color: #64748b; }
.th-slot-preview {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: none;
  z-index: 1;
}

/* Submit Button 3D Tactile */
.btn-th-submit {
  width: 100%;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 50%, #e11d48 100%);
  border: 2px solid #ffffff;
  border-radius: 16px;
  padding: 12px 14px;
  color: #ffffff;
  font-size: 13px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 5px 14px rgba(244, 63, 94, 0.35), 0 3px 0 #be123c, inset 1px 1px 2px rgba(255, 255, 255, 0.6);
  transition: all 0.15s ease;
  font-family: inherit;
  user-select: none;
}
.btn-th-submit:hover { filter: brightness(1.03); }
.btn-th-submit:active {
  transform: translateY(3px);
  box-shadow: 0 2px 6px rgba(244, 63, 94, 0.3), 0 0 0 #be123c;
}
.btn-th-submit:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

/* Status Cards */
.th-status-card {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 14px 12px;
  text-align: center;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
}
.th-status-ico {
  font-size: 32px;
  margin-bottom: 4px;
}
.th-status-h {
  font-size: 14px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 2px;
}
.th-status-p {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.4;
}

/* VIP Promotor Header Card */
.th-vip-badge-card {
  background: linear-gradient(135deg, #1e1b2e 0%, #2d284d 100%);
  border: 2px solid #ffffff;
  border-radius: 16px;
  padding: 12px 14px;
  color: #ffffff;
  box-shadow: 0 6px 18px rgba(30, 27, 46, 0.15);
  margin-bottom: 10px;
}
.th-vip-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  color: #78350f;
  font-size: 9px;
  font-weight: 900;
  padding: 2px 8px;
  border-radius: 6px;
  text-transform: uppercase;
}
.th-benefits-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  margin-top: 8px;
}
.th-benefit-item {
  font-size: 10px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 5px;
  color: #e2e8f0;
}

/* Referral Progress Bar */
.th-ref-monitor {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
  margin-bottom: 10px;
}
.th-ref-bar {
  height: 8px;
  background: #e2e8f0;
  border-radius: 10px;
  overflow: hidden;
  margin: 6px 0;
}
.th-ref-fill {
  height: 100%;
  background: linear-gradient(90deg, #10b981, #059669);
  border-radius: 10px;
  transition: width 0.3s ease;
}

.btn-vip-wa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
  border: 2px solid #ffffff;
  border-radius: 14px;
  padding: 11px 14px;
  color: #ffffff;
  font-size: 12.5px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
  margin-top: 10px;
}
.btn-vip-wa:active { transform: scale(0.96); }

.th-gallery-preview {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-top: 8px;
}
.th-gallery-thumb {
  width: 100%;
  height: 60px;
  border-radius: 8px;
  border: 1.5px solid #cbd5e1;
  object-fit: cover;
}
</style>

<div class="threads-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="th-top-nav">
    <a href="/home" class="th-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="th-nav-title">
      <i class="ph-bold ph-threads-logo" style="color:var(--reef-coral-500);"></i>
      <span>Ekspedisi Threads</span>
    </div>
    <div class="th-bounty-badge">
      <i class="ph-bold ph-sparkle"></i>
      <span>+Rp 75.000 Total</span>
    </div>
  </div>

  <!-- ── 2. HERO BROADCAST CARD ── -->
  <div class="th-clay th-hero-card">
    <div class="th-bubble-dec th-bubble-dec--1"></div>
    <div class="th-bubble-dec th-bubble-dec--2"></div>

    <div class="th-hero-avatar">
      <i class="ph-bold ph-threads-logo"></i>
    </div>
    <div class="th-hero-info">
      <div class="th-hero-tag">
        <i class="ph-fill ph-broadcast"></i> Misi Viral Samudra
      </div>
      <div class="th-hero-title">Sebar Cerita Cuan, Panen Saldo & Gaji Pasti! 🧵💎</div>
      <div class="th-hero-desc">Bagikan pengalaman seru MancingCuan di Threads, ambil reward hingga Rp 75.000 & Chat Langsung WhatsApp Admin.</div>
    </div>
  </div>

  <!-- ── 3. TRUST STRIP ── -->
  <div class="th-trust-strip">
    <div class="th-trust-col">
      <i class="ph-fill ph-wallet" style="color:#10b981;"></i>
      <span>Saldo Tarik</span>
    </div>
    <div class="th-trust-col">
      <i class="ph-fill ph-briefcase" style="color:#f59e0b;"></i>
      <span>Gaji Pasti</span>
    </div>
    <div class="th-trust-col">
      <i class="ph-fill ph-whatsapp-logo" style="color:#22c55e;"></i>
      <span>Chat Admin WA</span>
    </div>
    <div class="th-trust-col">
      <i class="ph-fill ph-crown" style="color:#8b5cf6;"></i>
      <span>Status VIP</span>
    </div>
  </div>

  <?php if ($flash): ?>
    <div style="padding:9px 12px;border-radius:14px;font-size:11.5px;font-weight:800;border:1.5px solid #ffffff;<?= $flashType==='error'?'background:#ffe4e6;color:#9f1239;':'background:#d1fae5;color:#065f46;' ?>">
      <i class="ph-bold <?= $flashType==='error'?'ph-warning-circle':'ph-check-circle' ?>"></i>
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <!-- ── 4. TWO-STEP SEGMENTED TABS ── -->
  <div class="th-tabs-deck">
    <button type="button" class="th-tab-item active" id="btn-tab-step1" onclick="switchThTab('step1')">
      <span>Langkah 1</span>
      <span class="th-tab-pill">Reward Rp 25.000</span>
    </button>
    <button type="button" class="th-tab-item" id="btn-tab-step2" onclick="switchThTab('step2')">
      <span>Langkah 2</span>
      <span class="th-tab-pill">👑 VIP · 5K Views</span>
    </button>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 1: LANGKAH 1 (PROMO THREADS - RP 25.000)
       ══════════════════════════════════════════════ -->
  <div id="tab-pane-step1" class="th-tab-pane">
    <div class="th-clay th-content-box">
      
      <div class="th-reward-box">
        <div class="th-reward-lbl">
          <i class="ph-fill ph-coins" style="font-size:16px;"></i>
          <span>REWARD LANGKAH 1</span>
        </div>
        <div class="th-reward-val"><?= format_rp($reward_amount) ?></div>
      </div>

      <!-- Quick Copy Reff Link -->
      <div class="th-reff-bar">
        <div class="th-reff-meta">
          <div style="font-size:9px;font-weight:800;color:var(--reef-muted);">KODE REFERRAL KAMU:</div>
          <div class="th-reff-code"><?= htmlspecialchars($user['referral_code']) ?></div>
        </div>
        <button type="button" class="btn-copy-reff" onclick="copyReffText('<?= htmlspecialchars($user['referral_code']) ?>')">
          <i class="ph-bold ph-copy"></i>
          <span>Salin Kode</span>
        </button>
      </div>

      <?php if ($pendingRequest): ?>
        <?php $pData = json_decode($pendingRequest['payload'] ?? '{}', true) ?: []; ?>
        <div class="th-status-card" style="border-color:#fef08a;background:#fffbeb;">
          <div class="th-status-ico">⏳</div>
          <div class="th-status-h" style="color:#b45309;">Klaim Langkah 1 Sedang Diverifikasi</div>
          <div class="th-status-p">
            Screenshot postingan Threads kamu sedang diperiksa tim admin kasir. Reward <strong><?= format_rp($reward_amount) ?></strong> akan masuk otomatis ke Saldo Tarikmu begitu disetujui.
          </div>
          <?php if (!empty($pData['proof_image'])): ?>
            <div style="margin-top:8px;">
              <img src="/<?= htmlspecialchars($pData['proof_image']) ?>" alt="Bukti" style="max-height:130px;border-radius:10px;border:1.5px solid #fed7aa;object-fit:contain;">
            </div>
          <?php endif; ?>
        </div>
      <?php elseif ($approvedRequest): ?>
        <div class="th-status-card" style="border-color:#a7f3d0;background:#f0fdf4;">
          <div class="th-status-ico">🎉</div>
          <div class="th-status-h" style="color:#047857;">Klaim Langkah 1 Disetujui!</div>
          <div class="th-status-p">
            Reward <strong><?= format_rp($reward_amount) ?></strong> telah masuk ke Saldo Tarikmu. Ayo lanjutkan ke <strong>Langkah 2</strong> untuk meraih Rp 50.000, Gaji Pasti &amp; Chat WhatsApp Admin!
          </div>
          <button type="button" onclick="switchThTab('step2')" class="btn-th-submit" style="margin-top:10px;background:linear-gradient(135deg,#10b981,#059669);border-color:#ffffff;box-shadow:0 4px 12px rgba(16,185,129,0.3);">
            <span>Lanjut ke Langkah 2 (VIP Promotor) &rarr;</span>
          </button>
        </div>
      <?php else: ?>
        <?php if (!empty($rejectedRequest)): ?>
          <div style="padding:9px 12px;border-radius:12px;background:#fff1f2;border:1.5px solid #fecdd3;color:#9f1239;font-size:11px;font-weight:800;margin-bottom:10px;">
            <i class="ph-bold ph-warning-circle"></i> Klaim Sebelumnya Ditolak:
            <div style="font-weight:700;margin-top:2px;"><?= htmlspecialchars($rejectedRequest['admin_note'] ?: 'Bukti postingan belum memenuhi syarat.') ?></div>
          </div>
        <?php endif; ?>

        <div class="th-inst-deck">
          <?= htmlspecialchars($instructions) ?>
        </div>

        <form method="POST" enctype="multipart/form-data" id="form-step1" onsubmit="return handleFormSubmit('btn-sub-step1')">
          <?= csrf_field() ?>
          <input type="hidden" name="step" value="step1">

          <div style="margin-bottom:10px;">
            <label style="font-size:11px;font-weight:800;color:var(--reef-ink);margin-bottom:4px;display:block;">
              📸 Upload Screenshot Postingan Threads (Terlihat Username):
            </label>
            <div class="th-dropzone" id="zone-step1">
              <input type="file" name="proof" id="file-step1" accept="image/*" required onchange="previewSingleFile(this, 'zone-step1', 'icon-step1', 'lbl-step1')">
              <i class="ph-bold ph-image th-dropzone-icon" id="icon-step1"></i>
              <div class="th-dropzone-text" id="lbl-step1">
                Pilih atau seret screenshot postingan kamu<br><small style="color:var(--reef-muted);">Format: JPG, PNG, WEBP (Maks: 5MB)</small>
              </div>
            </div>
          </div>

          <button type="submit" id="btn-sub-step1" class="btn-th-submit">
            <i class="ph-bold ph-paper-plane-tilt"></i>
            <span>Kirim Bukti Langkah 1</span>
          </button>
        </form>
      <?php endif; ?>

    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 2: LANGKAH 2 (PROMOTOR KHUSUS & 5K VIEWS)
       ══════════════════════════════════════════════ -->
  <div id="tab-pane-step2" class="th-tab-pane" style="display:none;">
    <div class="th-clay th-content-box">

      <!-- VIP PROMOTOR BENEFIT CARD -->
      <div class="th-vip-badge-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <div class="th-vip-tag">
            <i class="ph-fill ph-crown"></i> PROMOTOR KHUSUS
          </div>
          <span style="font-size:9.5px;font-weight:900;color:#fde68a;">LEVEL 2 VIP</span>
        </div>
        <div style="font-size:12px;font-weight:900;margin-bottom:6px;line-height:1.3;">
          Dapatkan Rp 50.000, Gaji Pasti & Chat Langsung WhatsApp Admin!
        </div>
        <div class="th-benefits-grid">
          <div class="th-benefit-item"><i class="ph-fill ph-money" style="color:#34d399;"></i> Rp 50.000 Saldo Tarik</div>
          <div class="th-benefit-item"><i class="ph-fill ph-briefcase" style="color:#fbbf24;"></i> Gaji Pasti Promotor</div>
          <div class="th-benefit-item"><i class="ph-fill ph-whatsapp-logo" style="color:#22c55e;"></i> Chat WhatsApp Admin</div>
          <div class="th-benefit-item"><i class="ph-fill ph-lightning" style="color:#f472b6;"></i> Prioritas WD Kilat</div>
        </div>
      </div>

      <!-- REFERRAL PROGRESS MONITOR -->
      <div class="th-ref-monitor">
        <div style="display:flex;justify-content:space-between;font-size:10.5px;font-weight:800;color:var(--reef-ink);">
          <span>Syarat 1: Minimal 10 Referral</span>
          <strong style="color:#059669;"><?= $user_referral_count ?> / 10 Orang</strong>
        </div>
        <div class="th-ref-bar">
          <?php $refPct = min(100, (int)round(($user_referral_count / 10) * 100)); ?>
          <div class="th-ref-fill" style="width: <?= $refPct ?>%;"></div>
        </div>
        <div style="font-size:9.5px;font-weight:800;display:flex;justify-content:space-between;color:<?= $user_referral_count >= 10 ? '#059669' : '#d97706' ?>;">
          <span><?= $user_referral_count >= 10 ? '✓ Syarat Referral Terpenuhi' : 'Kurang ' . (10 - $user_referral_count) . ' referral lagi' ?></span>
          <span><?= $refPct ?>%</span>
        </div>
      </div>

      <?php if ($user_referral_count < 10): ?>
        <!-- LOCKED STATUS FOR STEP 2 -->
        <div class="th-status-card" style="border-color:#fecdd3;background:#fff5f5;">
          <div class="th-status-ico">🔒</div>
          <div class="th-status-h" style="color:#9f1239;">Langkah 2 Masih Terkunci</div>
          <div class="th-status-p" style="color:#9f1239;">
            Kamu butuh mengundang <strong><?= 10 - $user_referral_count ?> referral lagi</strong> agar dapat mengajukan Langkah 2, meraih Gaji Pasti, dan menjadi Promotor Khusus.
          </div>
          <a href="/referral" class="btn-th-submit" style="margin-top:10px;background:linear-gradient(135deg,#10b981,#059669);text-decoration:none;">
            <i class="ph-bold ph-share-network"></i>
            <span>Bagikan Link Referral Sekarang</span>
          </a>
        </div>

      <?php elseif ($approvedRequest2): ?>
        <!-- APPROVED VIP PROMOTOR STATUS -->
        <div class="th-vip-badge-card" style="text-align:center;">
          <div style="font-size:32px;margin-bottom:4px;">👑</div>
          <div style="font-size:14px;font-weight:900;color:#fde68a;margin-bottom:4px;">
            SELAMAT! KAMU PROMOTOR KHUSUS RESMI
          </div>
          <div style="font-size:11px;color:#e2e8f0;font-weight:700;line-height:1.4;margin-bottom:8px;">
            Klaim Langkah 2 kamu telah disetujui admin. Reward <strong><?= format_rp($reward_amount2) ?></strong> telah ditambahkan ke Saldo Tarikmu. Kamu resmi menjadi Promotor Khusus dan berhak atas <strong>Gaji Pasti</strong> &amp; <strong>Chat Langsung WhatsApp Admin</strong>!
          </div>
          <a href="<?= htmlspecialchars($admin_contact) ?>" target="_blank" class="btn-vip-wa">
            <i class="ph-bold ph-whatsapp-logo" style="font-size:18px;"></i>
            <span>Chat WhatsApp Admin (Klaim Gaji &amp; Bonus)</span>
          </a>
        </div>

      <?php elseif ($pendingRequest2): ?>
        <!-- PENDING STATUS STEP 2 -->
        <?php $pData2 = json_decode($pendingRequest2['payload'] ?? '{}', true) ?: []; ?>
        <div class="th-status-card" style="border-color:#fef08a;background:#fffbeb;">
          <div class="th-status-ico">⏳</div>
          <div class="th-status-h" style="color:#b45309;">Pengajuan Promotor Level 2 Sedang Diverifikasi</div>
          <div class="th-status-p">
            Tim admin sedang memeriksa bukti statistik tayangan (views) minimal 5.000 views kamu. Setelah diverifikasi, status <strong>Promotor Khusus</strong>, hak <strong>Gaji Pasti</strong>, dan reward <strong><?= format_rp($reward_amount2) ?></strong> akan aktif!
          </div>

          <?php 
          $proofImages = $pData2['proof_images'] ?? (!empty($pData2['proof_image']) ? [$pData2['proof_image']] : []);
          if (!empty($proofImages)): ?>
            <div style="margin-top:10px;font-size:10.5px;font-weight:800;color:var(--reef-ink);">
              Bukti Screenshot yang Dikirim (<?= count($proofImages) ?> Foto):
            </div>
            <div class="th-gallery-preview">
              <?php foreach ($proofImages as $img): ?>
                <a href="/<?= htmlspecialchars($img) ?>" target="_blank">
                  <img src="/<?= htmlspecialchars($img) ?>" class="th-gallery-thumb" alt="Bukti SS">
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <!-- REJECTED NOTICE IF ANY -->
        <?php if (!empty($rejectedRequest2)): ?>
          <div style="padding:9px 12px;border-radius:12px;background:#fff1f2;border:1.5px solid #fecdd3;color:#9f1239;font-size:11px;font-weight:800;margin-bottom:10px;">
            <i class="ph-bold ph-warning-circle"></i> Pengajuan Langkah 2 Ditolak:
            <div style="font-weight:700;margin-top:2px;"><?= htmlspecialchars($rejectedRequest2['admin_note'] ?: 'Bukti postingan belum mencapai minimal 5.000 views atau kriteria belum lengkap.') ?></div>
          </div>
        <?php endif; ?>

        <div class="th-inst-deck">
          <?= htmlspecialchars($instructions2) ?>
        </div>

        <!-- UPLOAD FORM FOR LEVEL 2 (UP TO 3 SCREENSHOTS) -->
        <form method="POST" enctype="multipart/form-data" id="form-step2" onsubmit="return handleFormSubmit('btn-sub-step2')">
          <?= csrf_field() ?>
          <input type="hidden" name="step" value="step2">

          <div style="margin-bottom:10px;">
            <div style="font-size:11px;font-weight:800;color:var(--reef-ink);margin-bottom:3px;display:flex;align-items:center;justify-content:space-between;">
              <span>📸 Bukti Screenshot Ramai (Min 5K Views):</span>
              <span style="font-size:9px;color:var(--reef-coral-500);font-weight:800;">Bisa s/d 3 Foto</span>
            </div>

            <!-- 3 INTERACTIVE UPLOAD SLOTS -->
            <div class="th-slot-grid">
              <!-- Slot 1 (Wajib) -->
              <div class="th-slot-box" id="slot-box-1">
                <input type="file" name="proof_1" id="file-slot-1" accept="image/*" required onchange="previewSlot(this, 1)">
                <img id="preview-slot-1" class="th-slot-preview" alt="Preview 1">
                <i class="ph-bold ph-chart-line-up th-slot-icon" id="icon-slot-1"></i>
                <div class="th-slot-lbl" id="lbl-slot-1">Bukti Views</div>
                <span class="th-slot-tag th-slot-tag--req" id="tag-slot-1">Wajib (5K+)</span>
              </div>

              <!-- Slot 2 (Opsional) -->
              <div class="th-slot-box" id="slot-box-2">
                <input type="file" name="proof_2" id="file-slot-2" accept="image/*" onchange="previewSlot(this, 2)">
                <img id="preview-slot-2" class="th-slot-preview" alt="Preview 2">
                <i class="ph-bold ph-plus-circle th-slot-icon" id="icon-slot-2"></i>
                <div class="th-slot-lbl" id="lbl-slot-2">Postingan</div>
                <span class="th-slot-tag th-slot-tag--opt" id="tag-slot-2">Opsional</span>
              </div>

              <!-- Slot 3 (Opsional) -->
              <div class="th-slot-box" id="slot-box-3">
                <input type="file" name="proof_3" id="file-slot-3" accept="image/*" onchange="previewSlot(this, 3)">
                <img id="preview-slot-3" class="th-slot-preview" alt="Preview 3">
                <i class="ph-bold ph-plus-circle th-slot-icon" id="icon-slot-3"></i>
                <div class="th-slot-lbl" id="lbl-slot-3">Interaksi</div>
                <span class="th-slot-tag th-slot-tag--opt" id="tag-slot-3">Opsional</span>
              </div>
            </div>
          </div>

          <button type="submit" id="btn-sub-step2" class="btn-th-submit" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);border-color:#ffffff;box-shadow:0 5px 14px rgba(139,92,246,0.35),0 3px 0 #4f46e5;">
            <i class="ph-bold ph-crown"></i>
            <span>Kirim Bukti Level 2 &amp; Raih Gaji Pasti</span>
          </button>
        </form>

      <?php endif; ?>

    </div>
  </div>

</div>

<script>
function switchThTab(step) {
  const btn1 = document.getElementById('btn-tab-step1');
  const btn2 = document.getElementById('btn-tab-step2');
  const pane1 = document.getElementById('tab-pane-step1');
  const pane2 = document.getElementById('tab-pane-step2');

  if (step === 'step1') {
    btn1.classList.add('active');
    btn2.classList.remove('active');
    pane1.style.display = 'block';
    pane2.style.display = 'none';
  } else {
    btn2.classList.add('active');
    btn1.classList.remove('active');
    pane2.style.display = 'block';
    pane1.style.display = 'none';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('step') === '2') {
    switchThTab('step2');
  }
});

function copyReffText(code) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code).then(() => {
      if (typeof nToast !== 'undefined') {
        nToast('Kode referral ' + code + ' disalin!', 'success');
      } else {
        alert('Kode referral berhasil disalin: ' + code);
      }
    }).catch(() => {
      alert('Kode: ' + code);
    });
  } else {
    alert('Kode: ' + code);
  }
}

function previewSingleFile(input, zoneId, iconId, lblId) {
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const lbl = document.getElementById(lblId);
    const icon = document.getElementById(iconId);
    const zone = document.getElementById(zoneId);
    
    lbl.innerHTML = `✓ Dipilih: <strong>${file.name}</strong> (${(file.size/1024/1024).toFixed(2)} MB)`;
    lbl.style.color = '#059669';
    icon.style.color = '#10b981';
    zone.style.borderColor = '#10b981';
    zone.style.background = '#f0fdf4';
  }
}

function previewSlot(input, slotNum) {
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const imgEl = document.getElementById('preview-slot-' + slotNum);
    const iconEl = document.getElementById('icon-slot-' + slotNum);
    const lblEl = document.getElementById('lbl-slot-' + slotNum);
    const tagEl = document.getElementById('tag-slot-' + slotNum);
    const boxEl = document.getElementById('slot-box-' + slotNum);

    const reader = new FileReader();
    reader.onload = function(e) {
      imgEl.src = e.target.result;
      imgEl.style.display = 'block';
      iconEl.style.display = 'none';
      lblEl.style.display = 'none';
      tagEl.style.position = 'absolute';
      tagEl.style.bottom = '4px';
      tagEl.style.zIndex = '3';
      tagEl.innerHTML = '✓ Terpilih';
      tagEl.className = 'th-slot-tag th-slot-tag--req';
      boxEl.style.borderColor = '#10b981';
    };
    reader.readAsDataURL(file);
  }
}

function handleFormSubmit(btnId) {
  const btn = document.getElementById(btnId);
  if (btn) {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.style.cursor = 'not-allowed';
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengirim Bukti...';
  }
  return true;
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
