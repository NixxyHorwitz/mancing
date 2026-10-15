<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';
$error_fields = [];

// Client IP resolver (Handles Cloudflare & Reverse Proxy)
$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '127.0.0.1';
if (str_contains($client_ip, ',')) {
    $client_ip = trim(explode(',', $client_ip)[0]);
}

// Rate limiting by session
$ip_key = 'reg_' . md5($client_ip);
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

// ── Captcha "Laut Bersih": pilih semua ikan / semua sampah dari 6 kotak gambar ──
// Jawaban disimpan di session (tidak pernah dikirim ke browser), gambar diacak ulang tiap permintaan.
const REG_CAP_FISH = ['biasa', 'bagus', 'langka', 'super'];
const REG_CAP_JUNK = ['junk-bag', 'junk-boot', 'junk-bottle', 'junk-can', 'junk-cup', 'junk-trashbag'];
const REG_CAP_TTL  = 900;

function generate_captcha_challenge(): array {
    $mode    = random_int(0, 1) ? 'fish' : 'junk';
    $targets = random_int(2, 3);
    $fish = REG_CAP_FISH; shuffle($fish);
    $junk = REG_CAP_JUNK; shuffle($junk);
    $tiles = $mode === 'fish'
        ? array_merge(array_slice($fish, 0, $targets), array_slice($junk, 0, 6 - $targets))
        : array_merge(array_slice($junk, 0, $targets), array_slice($fish, 0, 6 - $targets));
    shuffle($tiles);

    $ans = [];
    foreach ($tiles as $i => $t) {
        if (($mode === 'junk') === str_starts_with($t, 'junk-')) $ans[] = $i;
    }
    $_SESSION['reg_cap'] = ['tiles' => $tiles, 'ans' => $ans, 'ts' => time(), 'v' => bin2hex(random_bytes(4))];

    return [
        'prompt' => $mode === 'fish' ? 'Tangkap semua ikan' : 'Bersihkan semua sampah',
        'icon'   => $mode === 'fish' ? 'ph-fish-simple' : 'ph-trash',
        'v'      => $_SESSION['reg_cap']['v'],
    ];
}

/** Gambar satu kotak captcha: rotasi, skala, posisi & gelembung acak agar tidak bisa dicocokkan per-byte */
function render_captcha_tile(int $i): void {
    $cap = $_SESSION['reg_cap'] ?? null;
    $name = $cap['tiles'][$i] ?? null;
    header('Cache-Control: no-store, max-age=0');
    $src = $name ? @imagecreatefrompng(dirname(__DIR__) . '/assets/img/fish/' . $name . '.png') : false;
    if (!$src) { http_response_code(404); exit; }

    $S = 96;
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $bgs = [[103, 232, 249], [94, 234, 212], [125, 211, 252], [196, 181, 253], [253, 164, 175], [253, 186, 116]];
    [$r, $g, $b] = $bgs[random_int(0, count($bgs) - 1)];
    imagefill($im, 0, 0, imagecolorallocate($im, $r + random_int(-6, 0), $g + random_int(-6, 0), $b + random_int(-6, 0)));
    for ($k = random_int(3, 6); $k > 0; $k--) {
        $d = random_int(4, 14);
        imagefilledellipse($im, random_int(0, $S), random_int(0, $S), $d, $d, imagecolorallocatealpha($im, 255, 255, 255, random_int(40, 90)));
    }
    if (random_int(0, 1)) imageflip($src, IMG_FLIP_HORIZONTAL);
    $rot = imagerotate($src, random_int(-28, 28), imagecolorallocatealpha($src, 0, 0, 0, 127));
    imagesavealpha($rot, true);
    $sz = random_int(62, 78);
    $rw = imagesx($rot); $rh = imagesy($rot);
    $dw = (int)round($sz * $rw / 128); $dh = (int)round($sz * $rh / 128);
    imagecopyresampled($im, $rot, (int)(($S - $dw) / 2) + random_int(-6, 6), (int)(($S - $dh) / 2) + random_int(-6, 6), 0, 0, $dw, $dh, $rw, $rh);
    header('Content-Type: image/png');
    imagepng($im, null, 6);
    exit;
}

// Gambar kotak captcha
if (isset($_GET['cap_tile'])) {
    render_captcha_tile(max(0, min(5, (int)$_GET['cap_tile'])));
}

// AJAX Refresh Captcha Endpoint
if (isset($_GET['refresh_captcha'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + generate_captcha_challenge());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan pendaftaran gagal. Silakan tunggu {$wait} menit lagi.";
        goto end_reg;
    }

    // ── 1. HONEYPOT ANTI-BOT TRAP (DENGAN TOLERANSI BROWSER AUTOFILL) ──
    $hp_val     = trim((string)($_POST['website_hp_check'] ?? ''));
    $user_email = trim((string)($_POST['email'] ?? ''));
    $user_uname = trim((string)($_POST['username'] ?? ''));

    // Deteksi jika terisi karena false-positive autofill browser (email / username pengguna)
    $is_autofill_accidental = ($hp_val !== '' && (
        ($user_email !== '' && strcasecmp($hp_val, $user_email) === 0) ||
        ($user_uname !== '' && strcasecmp($hp_val, $user_uname) === 0)
    ));

    if (!empty($hp_val) && !$is_autofill_accidental) {
        $msg_bot = "🚨 <b>PERINGATAN ABUSE: BOT SPAMMER TERPERANGKAP HONEYPOT!</b>\n\n"
            . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
            . "👤 <b>Username:</b> <code>" . htmlspecialchars($_POST['username'] ?? '') . "</code>\n"
            . "📝 <b>Input Perangkap:</b> " . htmlspecialchars($hp_val) . "\n"
            . "🛑 <b>Status:</b> Pendaftaran otomatis digagalkan sistem.\n"
            . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
        send_telegram_notif($pdo, $msg_bot, [], 'abuse');
        
        $error = 'Permintaan tidak dapat diproses. Silakan refresh halaman dan coba lagi.';
        goto end_reg;
    }

    // ── 2. SUBMISSION SPEED CHECK ──
    $form_time = (int)($_POST['form_time_sig'] ?? 0);
    if ($form_time > 0 && (time() - $form_time) < 2) {
        $error = 'Pengisian formulir terlalu cepat (terindikasi bot otomatis).';
        goto end_reg;
    }

    // ── 3. CAPTCHA VALIDATION (sekali pakai) ──
    $cap = $_SESSION['reg_cap'] ?? null;
    unset($_SESSION['reg_cap']);
    $cap_pick = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)($_POST['captcha_answer'] ?? '')), 'is_numeric'))));
    sort($cap_pick);

    if (!$cap || !isset($cap['ans'], $cap['ts'])) {
        $error = 'Sesi verifikasi kedaluwarsa. Silakan ulangi verifikasi gambar.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ((time() - (int)$cap['ts']) > REG_CAP_TTL) {
        $error = 'Waktu verifikasi habis. Silakan ulangi verifikasi gambar.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if (!$cap_pick || $cap_pick !== $cap['ans']) {
        $error = 'Verifikasi gambar belum tepat. Coba lagi ya!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    // ── 4. FORM DATA SANITIZATION ──
    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));
    if (empty($ref_input)) {
        $ref_input = strtoupper(trim($_COOKIE['tonton_ref'] ?? $_COOKIE['ref_code'] ?? ''));
    }
    
    $bank_name           = trim($_POST['bank_name'] ?? '');
    $account_number      = trim($_POST['account_number'] ?? '');
    $account_name        = trim($_POST['account_name'] ?? '');
    $acc_num_input_type  = ($_POST['acc_num_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_name_input_type = ($_POST['acc_name_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_num_record      = trim($_POST['acc_num_record'] ?? '[]');
    $acc_name_record     = trim($_POST['acc_name_record'] ?? '[]');

    // ── 5. BASIC VALIDATIONS ──
    if (!$username || !$email || !$whatsapp || !$password || !$bank_name || !$account_number || !$account_name) {
        $error = 'Semua kolom wajib diisi lengkap.';
        if (!$username) $error_fields[] = 'f_username';
        if (!$email) $error_fields[] = 'f_email';
        if (!$whatsapp) $error_fields[] = 'f_wa';
        if (!$password) $error_fields[] = 'f_pwd';
        if (!$bank_name) $error_fields[] = 'f_bank_name';
        if (!$account_number) $error_fields[] = 'f_account_number';
        if (!$account_name) $error_fields[] = 'f_account_name';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username harus 3–30 karakter (huruf, angka, underscore).';
        $error_fields[] = 'f_username';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format alamat email tidak valid.';
        $error_fields[] = 'f_email';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 16) {
        $error = 'Nomor WhatsApp tidak valid (minimal 9 digit).';
        $error_fields[] = 'f_wa';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
        $error_fields[] = 'f_pwd';
    } else {
        // ── 6. ANTI-ABUSE: IP REGISTRATION RATE LIMIT (Max 3 accounts / 24h) ──
        if ($client_ip !== '127.0.0.1') {
            $ip_cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $ip_cnt->execute([$client_ip]);
            $reg_24h = (int)$ip_cnt->fetchColumn();
            if ($reg_24h >= 3) {
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: BATAS REGISTRASI IP TERLAMPAUI!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> Terdeteksi {$reg_24h} akun dibuat dari IP yang sama dalam 24 jam terakhir.\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
                    . "🔗 <b>Kode Referral:</b> " . ($ref_input ?: "Tanpa Referral") . "\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Batas pendaftaran harian untuk jaringan ini telah tercapai (maksimal 3 akun per 24 jam).';
                goto end_reg;
            }
        }

        // ── 7. DUPLICATE USERNAME OR EMAIL CHECK ──
        $chk_user = $pdo->prepare("SELECT id, username, email FROM users WHERE username=? OR email=?");
        $chk_user->execute([$username, $email]);
        $existing_user = $chk_user->fetch();
        if ($existing_user) {
            if ($existing_user['username'] === $username) {
                $error = 'Username sudah digunakan. Pilih username lain.';
                $error_fields[] = 'f_username';
            } else {
                $error = 'Alamat email sudah terdaftar. Silakan login.';
                $error_fields[] = 'f_email';
            }
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
            goto end_reg;
        }

        // ── 8. ANTI-ABUSE: DUPLICATE WHATSAPP CHECK ──
        $chk_wa = $pdo->prepare("SELECT id, username FROM users WHERE whatsapp = ? LIMIT 1");
        $chk_wa->execute([$whatsapp]);
        $existing_wa = $chk_wa->fetch();
        if ($existing_wa) {
            $error = 'Nomor WhatsApp sudah digunakan oleh akun lain.';
            $error_fields[] = 'f_wa';
            goto end_reg;
        }

        // ── 9. ANTI-ABUSE: DUPLICATE BANK / E-WALLET CHECK ──
        $chk_bank = $pdo->prepare("SELECT id, username, referral_code, registration_ip FROM users WHERE account_number = ? LIMIT 1");
        $chk_bank->execute([$account_number]);
        $existing_bank = $chk_bank->fetch();
        if ($existing_bank) {
            if ($ref_input && $existing_bank['referral_code'] === $ref_input) {
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: SELF-REFERRAL REKENING IDENTIK!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> User baru mendaftar menggunakan nomor rekening yang PERSIS SAMA dengan pemilik referralnya!\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🔗 <b>Kode Referral:</b> <code>{$ref_input}</code> (@{$existing_bank['username']})\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                    . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Nomor rekening/e-wallet ini sudah terdaftar sebagai pemilik kode referral tersebut.';
                $error_fields[] = 'f_account_number';
                goto end_reg;
            }

            $msg_abuse = "⚠️ <b>PERINGATAN ABUSE: DUPLIKASI REKENING BANK!</b>\n\n"
                . "⚠️ <b>Pelanggaran:</b> Mencoba mendaftar dengan nomor rekening yang sudah dimiliki user lain.\n"
                . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                . "👤 <b>Pemilik Asli:</b> @{$existing_bank['username']}\n"
                . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                . "🛑 <b>Status:</b> Pendaftaran ditolak.\n"
                . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
            send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
            
            $error = 'Nomor rekening/e-wallet ini sudah terdaftar di akun lain. Satu rekening untuk satu akun.';
            $error_fields[] = 'f_account_number';
            goto end_reg;
        }

        // ── 10. REFERRAL VERIFICATION & ANTI-ABUSE ENGINE ──
        $ref_by = null;
        $ref_username = null;
        $ref_abuse = false;
        $ref_abuse_note = '';

        if ($ref_input) {
            $rs = $pdo->prepare("SELECT id, username, referral_code, is_promotor, is_referral_active, registration_ip FROM users WHERE referral_code = ?");
            $rs->execute([$ref_input]);
            $referrer = $rs->fetch();
            
            if (!$referrer) {
                $error = 'Kode referral tidak valid atau tidak ditemukan.';
                $error_fields[] = 'f_referral';
                goto end_reg;
            }

            if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                $ref_by = null;
            } else {
                $ref_by = $ref_input;
                $ref_username = $referrer['username'];

                // Check A: Same IP as Referrer
                if ($client_ip !== '127.0.0.1' && !empty($referrer['registration_ip']) && $client_ip === $referrer['registration_ip']) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: IP pendaftar sama dengan IP pengundang (@{$referrer['username']}) [{$client_ip}]";
                }

                // Check B: Same device via cookie
                if (!empty($_COOKIE['self_ref_owner']) && $_COOKIE['self_ref_owner'] === $ref_input) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: Perangkat sama dengan pemilik referral (@{$referrer['username']})";
                }

                // Check C: Referral Velocity (Bypass jika pemilik referral adalah promotor resmi)
                if (empty($referrer['is_promotor'])) {
                    $vel_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                    $vel_check->execute([$ref_input]);
                    $vel_cnt = (int)$vel_check->fetchColumn();
                    if ($vel_cnt >= 5) {
                        $ref_abuse = true;
                        $ref_abuse_note = "Referral Burst: Kode @{$referrer['username']} menerima {$vel_cnt} pendaftaran dalam 15 menit";
                    }
                }
            }
        }

        // ── 11. INSERT NEW USER ──
        $code = generate_referral_code($pdo);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $is_flagged_abuse = $ref_abuse ? 1 : 0;
        $abuse_reason     = $ref_abuse ? $ref_abuse_note : null;

        $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw,registration_ip,is_flagged_abuse,abuse_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)")
            ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record, $client_ip, $is_flagged_abuse, $abuse_reason]);
        $new_id = (int)$pdo->lastInsertId();

        setcookie('self_ref_owner', $code, time() + (86400 * 90), '/', '', false, true);

        // ── 12. REFERRAL BONUS LOGIC WITH ABUSE PROTECTION ──
        if ($ref_by) {
            if ($ref_abuse) {
                $msg_abuse = "🚨 <b>DETEKSI ABUSE: BONUS REFERRAL DITAHAN!</b>\n\n"
                    . "⚠️ <b>Alasan:</b> {$ref_abuse_note}\n"
                    . "👤 <b>User Baru:</b> <code>{$username}</code> (ID: #{$new_id})\n"
                    . "🔗 <b>Kode Ref:</b> <code>{$ref_by}</code> (@{$ref_username})\n"
                    . "🌐 <b>IP Terdeteksi:</b> <code>{$client_ip}</code>\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - {$account_number} (a.n. {$account_name})\n\n"
                    . "🛡️ <b>Tindakan Sistem:</b>\n"
                    . "• Saldo Tarik (Bonus Ref) <b>TIDAK DIKREDITKAN</b> ke akun @{$ref_username}\n"
                    . "• Akun baru ditandai [ABUSE FLAG] di panel admin.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
                $kb_abuse = $site_url ? [[['text' => '🔍 Cek Akun Baru', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
                send_telegram_notif($pdo, $msg_abuse, $kb_abuse, 'abuse');
            } else {
                credit_registration_referral_bonus($pdo, $new_id, $ref_by);
            }
        }

        // ── 13. TELEGRAM NEW USER NOTIFICATION ──
        $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
             . "👤 Username: <b>{$username}</b>\n"
             . "📧 Email: {$email}\n"
             . "📱 WhatsApp: {$whatsapp}\n"
             . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
             . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b> (@{$ref_username})" . ($ref_abuse ? " [⚠️ ABUSE DETECTED - BONUS WITHHELD]" : "") : "Langsung (tanpa referral)") . "\n"
             . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
             . "🌐 IP: <code>{$client_ip}</code>\n"
             . "🕐 Waktu: " . date('d M Y H:i:s');
        $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
        send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');

        // Clear attempts & Log user in
        unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$new_id);
        redirect('/home');
    }
}
end_reg:

// Generate initial captcha challenge for page display
$captcha = generate_captcha_challenge();

$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? $_COOKIE['ref_code'] ?? ''));

$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

// Dynamic account labels & placeholders based on selected payment channel
$_post_bank = $_POST['bank_name'] ?? '';
$_selected_type = '';
if (!empty($_post_bank)) {
    foreach ($_pay_channels as $_pc) {
        if ($_pc['name'] === $_post_bank) {
            $_selected_type = $_pc['type'];
            break;
        }
    }
}

$_lbl_acc_num  = 'No. Rekening / HP';
$_ph_acc_num   = 'Nomor Rekening';
$_lbl_acc_name = 'Nama Pemilik Rekening';
$_ph_acc_name  = 'Sesuai Buku Tabungan / KTP';

if ($_selected_type === 'bank') {
    $_lbl_acc_num  = 'Nomor Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_ph_acc_num   = 'Nomor Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_lbl_acc_name = 'Nama Pemilik Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_ph_acc_name  = 'Sesuai Buku Tabungan / KTP';
} elseif ($_selected_type === 'ewallet') {
    $_lbl_acc_num  = 'Nomor HP ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'E-Wallet');
    $_ph_acc_num   = 'Contoh: 08xxxxxxxxxx';
    $_lbl_acc_name = 'Nama Pemilik Akun ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'E-Wallet');
    $_ph_acc_name  = 'Nama Terdaftar di ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Akun E-Wallet');
}

$_seo_title  = setting($pdo, 'seo_title', 'MancingCuan — Nonton Video & Minigame Penghasil Saldo Cuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Platform nonton video dan minigame penghasil saldo cuan resmi.');
$_favicon    = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_name  = setting($pdo, 'site_name', 'MancingCuan');
$_seo_kw     = setting($pdo, 'seo_keywords', 'mancingcuan, mancing cuan, nonton video dapat uang, aplikasi penghasil saldo dana, game cuan terpercaya, withdraw instan');
$_seo_author = setting($pdo, 'seo_author', 'MancingCuan Official');
$_seo_twcard = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_og_img = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');

// Ensure OG banner exists on disk, fallback to /assets/seobanner.jpg
$_og_disk = dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og_img), '/');
if (!$_seo_og_img || !file_exists($_og_disk)) {
    $_seo_og_img = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og_img) ? $_seo_og_img : base_url(ltrim($_seo_og_img, '/'));
$fav_url     = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : base_url('assets/favicon.png');
$og_mime     = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';

// Dynamic Referral Inviter Meta Logic
$ref_inviter_name = '';
if (!empty($ref_from_url)) {
    try {
        $uStmt = $pdo->prepare("SELECT username FROM users WHERE referral_code = ? LIMIT 1");
        $uStmt->execute([$ref_from_url]);
        $ref_inviter_name = (string)($uStmt->fetchColumn() ?: '');
    } catch (\Throwable) {}
}

if (!empty($ref_inviter_name)) {
    $_page_title     = "Undangan dari @{$ref_inviter_name} — Daftar Akun Baru {$_site_name}";
    $_final_og_title = "Undangan Khusus dari @{$ref_inviter_name} — Gabung {$_site_name} & Dapatkan Bonus!";
    $_final_og_desc  = "Kamu diundang oleh @{$ref_inviter_name} untuk bergabung di {$_site_name}! Nonton video harian dapat saldo tunai, main minigame, dan cairkan saldo instan ke DANA, OVO, GoPay, atau Bank!";
} else {
    $_page_title     = 'Daftar Akun Baru — ' . $_seo_title;
    $_final_og_title = setting($pdo, 'seo_og_title', 'MancingCuan — Nonton Video & Main Game Panen Saldo Nyata Tiap Hari!');
    $_final_og_desc  = setting($pdo, 'seo_og_description', $_seo_desc);
}

$current_url = base_url('register' . (!empty($ref_from_url) ? '/' . urlencode($ref_from_url) : ''));
?>
<!DOCTYPE html>
<html lang="id" prefix="og: https://ogp.me/ns#">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#67e8f9">
<title><?= htmlspecialchars($_page_title) ?></title>
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta name="description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<?php if ($_seo_kw): ?><meta name="keywords" content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author): ?><meta name="author" content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="index, follow">

<!-- Open Graph / Facebook / WhatsApp / Telegram Meta Tags -->
<meta property="og:locale" content="id_ID">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars($_site_name) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="<?= $og_mime ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<!-- Twitter Card Meta Tags -->
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:site" content="@MancingCuan">
<meta name="twitter:creator" content="@MancingCuan">
<meta name="twitter:url" content="<?= htmlspecialchars($current_url) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta name="twitter:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($fav_url) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="shortcut icon" href="/assets/favicon.ico">

<!-- Schema.org JSON-LD Structured Data for Google Rich Snippets -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": <?= json_encode($_final_og_title, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "description": <?= json_encode($_final_og_desc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "url": <?= json_encode($current_url, JSON_UNESCAPED_SLASHES) ?>,
  "image": <?= json_encode($absolute_og, JSON_UNESCAPED_SLASHES) ?>
}
</script>

<!-- VERSION_CHECK_PROMOTOR_BYPASS_20261002 -->
<!-- Google Fonts & Phosphor Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>

<style>
/* ══════════════════════════════════════════════════════════
   REGISTER — Deep Lagoon · Living Coral Reef · 3D Clay
   Latar laut biru tegas (kontras), satu kartu clay putih,
   ringkas: placeholder sebagai label, referral bisa dibuka.
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #0f172a; --muted: #475569; --line: #e2e8f0;
  --chip-sh: inset 1.5px 1.5px 3px rgba(255, 255, 255, .6), inset -1.5px -1.5px 3px rgba(0, 0, 0, .18);
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', -apple-system, sans-serif; color: var(--ink); min-height: 100vh; min-height: 100dvh; -webkit-font-smoothing: antialiased;
  background: linear-gradient(180deg, #22d3ee 0%, #06b6d4 18%, #0891b2 46%, #0e7490 74%, #155e75 100%) fixed;
}
i[class^="ph-"] { display: inline-flex; align-items: center; justify-content: center; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* ══ Dunia bawah laut (latar, tidak bisa diklik) ══ */
.sea { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
.rays { position: absolute; top: -10%; left: 50%; width: 160%; height: 80%; transform: translateX(-50%);
  background: repeating-linear-gradient(100deg, transparent 0 46px, rgba(255, 255, 255, .16) 46px 74px, transparent 74px 130px);
  -webkit-mask-image: linear-gradient(180deg, #000, transparent); mask-image: linear-gradient(180deg, #000, transparent); animation: rays 10s ease-in-out infinite alternate; }
@keyframes rays { to { transform: translateX(-46%) skewX(-5deg); opacity: .6; } }
.caustic { position: absolute; inset: -60px; opacity: .22;
  background-image: radial-gradient(ellipse 40px 18px at 20px 20px, #fff 0, transparent 70%), radial-gradient(ellipse 26px 12px at 70px 60px, #fff 0, transparent 70%), radial-gradient(ellipse 30px 14px at 110px 24px, #a5f3fc 0, transparent 70%);
  background-size: 140px 90px; animation: caustic 16s linear infinite;
  -webkit-mask-image: linear-gradient(180deg, #000 0%, transparent 55%); mask-image: linear-gradient(180deg, #000 0%, transparent 55%); }
@keyframes caustic { to { transform: translate(140px, 90px); } }
.bub { position: absolute; bottom: -24px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, .85);
  background: radial-gradient(circle at 32% 30%, #fff 0 16%, rgba(255, 255, 255, .25) 42%, rgba(165, 243, 252, .12) 100%); animation: rise linear infinite; }
@keyframes rise { 0% { transform: translate(0, 0); opacity: 0; } 10% { opacity: .9; } 50% { transform: translate(10px, -50vh); } 100% { transform: translate(-8px, -108vh); opacity: 0; } }
.fish { position: absolute; width: 36px; height: 36px; animation: swim linear infinite; filter: drop-shadow(0 6px 6px rgba(8, 51, 68, .35)); }
.fish img { width: 100%; height: 100%; object-fit: contain; animation: wig 1.4s ease-in-out infinite; }
.fish.rev { animation-name: swimR; }
.fish.rev img { animation-name: wigR; }
@keyframes swim { from { transform: translateX(-60px); } to { transform: translateX(calc(100vw + 60px)); } }
@keyframes swimR { from { transform: translateX(calc(100vw + 60px)); } to { transform: translateX(-60px); } }
@keyframes wig { 50% { transform: translateY(-4px) rotate(-5deg); } }
@keyframes wigR { 0%, 100% { transform: scaleX(-1); } 50% { transform: scaleX(-1) translateY(-4px) rotate(-5deg); } }
.jelly { position: absolute; top: 9%; width: 44px; animation: jelly 7s ease-in-out infinite; opacity: .9; }
.jelly.j1 { right: max(8px, calc(50% - 300px)); }
.jelly.j2 { left: max(6px, calc(50% - 290px)); top: 46%; width: 30px; animation-duration: 9s; animation-delay: -3s; opacity: .75; }
.jelly .bell { transform-box: fill-box; transform-origin: 50% 100%; animation: pulse 1.8s ease-in-out infinite; }
@keyframes jelly { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-26px); } }
@keyframes pulse { 50% { transform: scale(1.08, .9); } }
.floor { position: absolute; left: 0; right: 0; bottom: 0; height: 120px; }
.floor svg { position: absolute; bottom: 0; }
.floor .sand { left: 0; width: 100%; height: 40px; }
.floor .reef-l { left: max(-8px, calc(50% - 320px)); width: 150px; height: 118px; }
.floor .reef-r { right: max(-8px, calc(50% - 320px)); width: 150px; height: 118px; }
.sway { transform-box: fill-box; transform-origin: 50% 100%; animation: sway 4.6s ease-in-out infinite alternate; }
.sway.d2 { animation-duration: 5.8s; animation-delay: -2s; }
@keyframes sway { from { transform: rotate(-6deg); } to { transform: rotate(6deg); } }

/* ══ Isi halaman ══ */
.wrap { position: relative; z-index: 1; width: 100%; max-width: 420px; margin: 0 auto; padding: 10px 12px 132px; }
.topbar { display: flex; justify-content: space-between; align-items: center; }
.glass { height: 34px; min-width: 34px; padding: 0 12px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 5px;
  background: rgba(255, 255, 255, .2); border: 1.5px solid rgba(255, 255, 255, .55); color: #fff; font-size: 11.5px; font-weight: 900; text-decoration: none;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, .45), 0 4px 10px rgba(8, 51, 68, .18); backdrop-filter: blur(6px); }
.glass.sq { padding: 0; font-size: 16px; }
.glass:active { transform: scale(.94); }

.head { display: flex; align-items: center; gap: 11px; margin: 12px 2px 12px; }
.logo { width: 52px; height: 52px; border-radius: 17px; background: #fff; border: 2.5px solid #fff; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 10px 20px rgba(8, 51, 68, .3), inset 2px 2px 4px #fff, inset -3px -3px 6px rgba(8, 145, 178, .2); animation: bob 3.4s ease-in-out infinite; }
.logo img { width: 38px; height: 38px; object-fit: contain; }
@keyframes bob { 50% { transform: translateY(-4px) rotate(-3deg); } }
.head h1 { font-size: 21px; font-weight: 900; color: #fff; letter-spacing: -.5px; line-height: 1.1; text-shadow: 0 2px 8px rgba(8, 51, 68, .35); }
.head p { margin-top: 3px; font-size: 11px; font-weight: 800; color: #cffafe; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.head p b { color: #fef08a; }

/* Kartu clay putih */
.card { position: relative; border-radius: 24px; border: 2px solid #fff; padding: 12px; background: linear-gradient(170deg, #ffffff 0%, #f8fdff 60%, #fff7ed 100%);
  box-shadow: 0 22px 40px rgba(8, 51, 68, .35), 0 6px 0 rgba(255, 255, 255, .35), inset 3px 3px 6px #fff, inset -4px -4px 10px rgba(8, 145, 178, .10); }
.card::before { content: ''; position: absolute; top: 6px; left: 18px; width: 38%; height: 8px; border-radius: 999px; background: linear-gradient(90deg, rgba(165, 243, 252, .6), transparent); }

.note { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 7px 9px; border-radius: 14px; background: #fff1f2; border: 1.5px solid #fecdd3;
  font-size: 11.5px; font-weight: 800; color: #9f1239; line-height: 1.35; animation: shake .35s; }
@keyframes shake { 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }

.sec + .sec { margin-top: 11px; padding-top: 11px; border-top: 2px dashed var(--line); }
.sec-h { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.sec-h h2 { flex: 1; font-size: 12.5px; font-weight: 900; letter-spacing: -.1px; color: var(--ink); }
.pearl { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; color: #fff;
  box-shadow: 0 3px 6px var(--csh), var(--chip-sh); }
.p-coral  { background: radial-gradient(circle at 32% 28%, #fecdd3, #f43f5e 70%); --csh: rgba(244, 63, 94, .4); }
.p-kelp   { background: radial-gradient(circle at 32% 28%, #a7f3d0, #059669 70%); --csh: rgba(5, 150, 105, .4); }
.p-violet { background: radial-gradient(circle at 32% 28%, #e9d5ff, #7c3aed 70%); --csh: rgba(124, 58, 237, .4); }

.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 7px; }
.fld { min-width: 0; }
.span-2 { grid-column: 1 / -1; }

.inp-wrap { display: flex; align-items: center; gap: 7px; height: 42px; padding: 0 9px 0 6px; border-radius: 14px; border: 1.5px solid #cbe9f2;
  background: #f0f9ff; box-shadow: inset 2px 2px 5px rgba(8, 145, 178, .12), inset -2px -2px 4px #fff; transition: border-color .15s, box-shadow .15s, background .15s; }
.inp-wrap:focus-within { border-color: #06b6d4; background: #fff; box-shadow: 0 0 0 3.5px rgba(6, 182, 212, .2); }
.inp-wrap.bad { border-color: #f43f5e; background: #fff1f2; box-shadow: 0 0 0 3.5px rgba(244, 63, 94, .16); }
.inp-icon { width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0; font-size: 14px; color: #fff; box-shadow: 0 3px 6px var(--csh), var(--chip-sh); }
.s-coral .inp-icon { background: linear-gradient(145deg, #fb7185, #e11d48); --csh: rgba(225, 29, 72, .32); }
.s-kelp .inp-icon  { background: linear-gradient(145deg, #34d399, #047857); --csh: rgba(4, 120, 87, .3); }
.inp-field { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: 800 13px/1 'Nunito', sans-serif; color: var(--ink); }
.inp-field::placeholder { color: #64748b; font-weight: 700; font-size: 12px; }
select.inp-field { appearance: none; -webkit-appearance: none; cursor: pointer; }
select.inp-field:invalid { color: #64748b; font-weight: 700; font-size: 12px; }
.caret { color: #047857; font-size: 13px; flex-shrink: 0; }
.inp-wrap--ref { background: #ecfdf5; border-color: #a7f3d0; }
.eye-btn { border: 0; background: none; color: #64748b; font-size: 17px; display: flex; padding: 2px; cursor: pointer; }
.eye-btn:hover { color: #e11d48; }

.ref-toggle { margin-top: 7px; border: 0; background: none; padding: 2px 4px; font: 900 11px/1 'Nunito', sans-serif; color: #047857; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
.ref-box[hidden] { display: none; }
.ref-box { margin-top: 7px; }

/* Captcha gambar */
.cap { padding: 8px; border-radius: 16px; background: linear-gradient(160deg, #ecfeff 0%, #e0f2fe 100%); border: 1.5px solid #bae6fd;
  box-shadow: inset 2px 2px 6px rgba(8, 145, 178, .12), inset -2px -2px 4px #fff; transition: border-color .15s, box-shadow .15s; }
.cap.bad { border-color: #f43f5e; box-shadow: 0 0 0 3.5px rgba(244, 63, 94, .16); }
.cap-top { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; }
.cap-q { flex: 1; display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 900; color: #0c4a6e; }
.cap-q i { width: 24px; height: 24px; border-radius: 8px; font-size: 13px; color: #fff; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 3px 6px rgba(14, 116, 144, .3), var(--chip-sh); }
.cap-re { width: 30px; height: 30px; border-radius: 10px; border: 1.5px solid #bae6fd; background: #fff; color: #0e7490; font-size: 15px; display: flex; align-items: center; justify-content: center; cursor: pointer;
  box-shadow: 0 2px 6px rgba(8, 145, 178, .15); transition: transform .3s; }
.cap-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; }
.cap-t { position: relative; aspect-ratio: 1; padding: 0; border-radius: 13px; border: 2.5px solid #fff; overflow: hidden; cursor: pointer; background: #fff;
  box-shadow: 0 4px 8px rgba(8, 51, 68, .16); transition: transform .15s cubic-bezier(.34, 1.56, .64, 1), border-color .12s, box-shadow .12s; }
.cap-t img { width: 100%; height: 100%; display: block; pointer-events: none; }
.cap-t::before { content: ''; position: absolute; inset: 0; z-index: 1; pointer-events: none; border-radius: 10px; box-shadow: inset 2px 2px 3px rgba(255, 255, 255, .7), inset -2px -3px 5px rgba(8, 51, 68, .12); }
.cap-t:active { transform: scale(.92); }
.cap-t .ok { position: absolute; top: 2px; right: 2px; z-index: 2; width: 17px; height: 17px; border-radius: 50%; font-size: 10px; color: #fff; border: 1.5px solid #fff;
  background: linear-gradient(145deg, #34d399, #047857); display: flex; align-items: center; justify-content: center; transform: scale(0); transition: transform .18s cubic-bezier(.3, 1.6, .6, 1); }
.cap-t.on { border-color: #10b981; transform: translateY(-3px); box-shadow: 0 8px 14px rgba(4, 120, 87, .3), 0 0 0 2px rgba(16, 185, 129, .35); }
.cap-t.on .ok { transform: scale(1); }
@media (max-width: 340px) { .cap-grid { grid-template-columns: repeat(3, 1fr); } }

.btn-reg-submit { position: relative; overflow: hidden; width: 100%; height: 50px; margin-top: 12px; border-radius: 17px; border: 2px solid #fff; cursor: pointer;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 50%, #e11d48 100%); color: #fff; font: 900 15px/1 'Nunito', sans-serif;
  display: flex; align-items: center; justify-content: center; gap: 8px; text-shadow: 0 1px 2px rgba(76, 5, 25, .35);
  box-shadow: 0 10px 20px rgba(225, 29, 72, .3), 0 5px 0 #be123c, inset 2px 2px 4px rgba(255, 255, 255, .5), inset -2px -3px 6px rgba(76, 5, 25, .22); transition: transform .1s, box-shadow .1s; }
.btn-reg-submit::before { content: ''; position: absolute; top: 5px; left: 16px; width: 42%; height: 8px; border-radius: 999px; background: linear-gradient(90deg, rgba(255, 255, 255, .45), transparent); }
.btn-reg-submit::after { content: ''; position: absolute; top: 0; left: -60%; width: 35%; height: 100%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .35), transparent); animation: shine 3.8s ease-in-out infinite; }
@keyframes shine { 0%, 60% { left: -60%; } 100% { left: 130%; } }
.btn-reg-submit .go { width: 26px; height: 26px; border-radius: 9px; background: rgba(255, 255, 255, .25); box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, .5); }
.btn-reg-submit:active { transform: translateY(4px); box-shadow: 0 4px 10px rgba(225, 29, 72, .3), 0 1px 0 #be123c; }
.btn-reg-submit[disabled] { opacity: .85; cursor: wait; }
@keyframes spin { to { transform: rotate(360deg); } }

.foot { margin-top: 14px; text-align: center; font-size: 12px; font-weight: 800; color: #e0f2fe; }
.foot a { color: #fef08a; font-weight: 900; text-decoration: none; margin-left: 3px; }
.trust { margin: 10px auto 0; width: max-content; display: flex; align-items: center; gap: 9px; padding: 5px 12px; border-radius: 999px; background: rgba(255, 255, 255, .92);
  box-shadow: 0 6px 14px rgba(8, 51, 68, .2); }
.trust small { font-size: 9.5px; font-weight: 900; color: var(--muted); display: inline-flex; align-items: center; gap: 3px; }
.trust small i { color: #059669; font-size: 12px; }
.trust img { height: 18px; max-width: 60px; object-fit: contain; }

.toast { position: fixed; left: 50%; bottom: 22px; z-index: 50; max-width: calc(100% - 32px); transform: translate(-50%, 24px); opacity: 0; pointer-events: none;
  display: flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 15px; border: 2px solid #fff; background: #fff; color: #9f1239;
  font-size: 12px; font-weight: 900; box-shadow: 0 14px 30px rgba(8, 51, 68, .35); transition: opacity .2s, transform .2s cubic-bezier(.34, 1.56, .64, 1); }
.toast i { color: #e11d48; font-size: 16px; }
.toast.show { opacity: 1; transform: translate(-50%, 0); }

@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; } }
</style>
</head>
<body>

<div class="sea" aria-hidden="true">
  <div class="rays"></div>
  <div class="caustic"></div>

  <span class="bub" style="left:7%;width:11px;height:11px;animation-duration:11s"></span>
  <span class="bub" style="left:19%;width:6px;height:6px;animation-duration:8s;animation-delay:-3s"></span>
  <span class="bub" style="left:52%;width:8px;height:8px;animation-duration:13s;animation-delay:-7s"></span>
  <span class="bub" style="left:74%;width:13px;height:13px;animation-duration:12s;animation-delay:-2s"></span>
  <span class="bub" style="left:93%;width:7px;height:7px;animation-duration:9s;animation-delay:-5s"></span>

  <div class="fish" style="top:16%;animation-duration:22s;animation-delay:-5s"><img src="/assets/img/fish/bagus.png" alt=""></div>
  <div class="fish rev" style="top:58%;width:28px;height:28px;animation-duration:28s;animation-delay:-12s"><img src="/assets/img/fish/super.png" alt=""></div>
  <div class="fish" style="top:80%;width:24px;height:24px;animation-duration:34s;animation-delay:-20s"><img src="/assets/img/fish/langka.png" alt=""></div>

  <svg class="jelly j1" viewBox="0 0 44 70">
    <defs><radialGradient id="jg" cx="45%" cy="30%" r="70%"><stop offset="0" stop-color="#fdf4ff"/><stop offset=".55" stop-color="#f0abfc" stop-opacity=".9"/><stop offset="1" stop-color="#c084fc" stop-opacity=".75"/></radialGradient></defs>
    <g class="bell"><path d="M4 26C4 12 12 3 22 3S40 12 40 26C34 24 30 28 22 28S10 24 4 26Z" fill="url(#jg)" stroke="#fff" stroke-width="1.2"/>
      <ellipse cx="16" cy="12" rx="6" ry="3" fill="#fff" opacity=".55"/></g>
    <g stroke="#f5d0fe" stroke-width="2" fill="none" stroke-linecap="round" opacity=".85">
      <path d="M12 28C10 38 15 44 12 54"/><path d="M20 29C22 40 17 48 21 62"/><path d="M28 29C26 40 31 46 28 56"/><path d="M34 27C36 36 32 40 34 48"/>
    </g>
  </svg>
  <svg class="jelly j2" viewBox="0 0 44 70">
    <g class="bell"><path d="M4 26C4 12 12 3 22 3S40 12 40 26C34 24 30 28 22 28S10 24 4 26Z" fill="#a5f3fc" fill-opacity=".75" stroke="#fff" stroke-width="1.2"/>
      <ellipse cx="16" cy="12" rx="6" ry="3" fill="#fff" opacity=".55"/></g>
    <g stroke="#cffafe" stroke-width="2" fill="none" stroke-linecap="round" opacity=".8"><path d="M12 28C10 38 15 44 12 54"/><path d="M22 29C24 40 19 48 23 60"/><path d="M32 28C30 38 35 44 32 52"/></g>
  </svg>

  <div class="floor">
    <svg class="sand" viewBox="0 0 600 40" preserveAspectRatio="none"><path d="M0 16Q75 2 150 12T300 10T450 8T600 12V40H0Z" fill="#fde68a"/><path d="M0 26Q100 16 200 24T400 22T600 22V40H0Z" fill="#fcd34d" opacity=".55"/></svg>
    <svg class="reef-l" viewBox="0 0 150 118">
      <path class="sway" d="M26 118C18 92 38 80 28 58S36 26 28 6" stroke="#10b981" stroke-width="8" fill="none" stroke-linecap="round"/>
      <path class="sway d2" d="M44 118C52 98 36 86 46 68S40 44 48 30" stroke="#34d399" stroke-width="6" fill="none" stroke-linecap="round"/>
      <g class="sway d2"><path d="M92 116C90 98 88 86 78 74C70 64 66 54 70 46C76 52 80 60 84 68C86 56 84 44 90 36C96 46 94 58 94 70C100 60 108 54 116 52C114 62 106 70 98 80C96 94 98 106 100 116Z" fill="#fb7185" stroke="#fff" stroke-width="1.5"/>
        <circle cx="70" cy="45" r="5" fill="#fecdd3"/><circle cx="90" cy="35" r="5" fill="#fecdd3"/><circle cx="116" cy="51" r="5" fill="#fecdd3"/></g>
      <ellipse cx="132" cy="110" rx="13" ry="7" fill="#94a3b8"/><ellipse cx="128" cy="107" rx="5" ry="2.2" fill="#fff" opacity=".5"/>
    </svg>
    <svg class="reef-r" viewBox="0 0 150 118">
      <g class="sway"><path d="M66 116C66 100 62 88 54 78C48 70 44 60 48 52C54 58 58 66 62 74C64 62 62 48 68 40C74 50 72 62 72 74C78 64 88 58 96 58C94 68 84 76 76 86C74 98 76 108 78 116Z" fill="#c084fc" stroke="#fff" stroke-width="1.5"/>
        <circle cx="48" cy="51" r="5" fill="#f3e8ff"/><circle cx="68" cy="39" r="5" fill="#f3e8ff"/><circle cx="96" cy="57" r="5" fill="#f3e8ff"/></g>
      <path class="sway d2" d="M128 118C120 94 138 80 128 58S136 28 128 8" stroke="#10b981" stroke-width="8" fill="none" stroke-linecap="round"/>
      <path d="M22 92l5 11l12 1l-9 8l3 12l-11 -6l-11 6l3 -12l-9 -8l12 -1z" fill="#fb923c" stroke="#fff" stroke-width="1.5" stroke-linejoin="round" transform="rotate(-14 22 104) translate(0 -8)"/>
      <path d="M100 114a10 10 0 0 1 20 0z" fill="#fecdd3" stroke="#fff" stroke-width="1.2"/><path d="M105 114l5 -8l5 8" stroke="#fb7185" stroke-width="1.4" fill="none"/>
    </svg>
  </div>
</div>

<div class="wrap">

  <nav class="topbar">
    <a href="/" class="glass sq" title="Kembali ke Beranda" aria-label="Kembali"><i class="ph-bold ph-caret-left"></i></a>
    <a href="/login" class="glass"><i class="ph-bold ph-sign-in"></i>Masuk</a>
  </nav>

  <header class="head">
    <div class="logo"><img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="<?= htmlspecialchars($_site_name) ?>"></div>
    <div>
      <h1>Gabung <?= htmlspecialchars($_site_name) ?></h1>
      <?php if (!empty($ref_inviter_name)): ?>
        <p><i class="ph-fill ph-gift"></i>Diundang <b>@<?= htmlspecialchars($ref_inviter_name) ?></b></p>
      <?php else: ?>
        <p>Gratis · 3 langkah · langsung panen cuan</p>
      <?php endif; ?>
    </div>
  </header>

  <main class="card">
    <?php if ($error): ?>
      <div class="note" role="alert"><i class="ph-fill ph-warning-circle" style="font-size:16px"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="reg-form" novalidate onsubmit="return validateReg(event)">
      <?= csrf_field() ?>

      <input type="hidden" name="form_time_sig" value="<?= time() ?>">
      <input type="hidden" name="captcha_answer" id="f_captcha_answer" value="">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">
      <input type="hidden" name="acc_num_record" id="f_acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
      <input type="hidden" name="acc_name_record" id="f_acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">

      <section class="sec s-coral">
        <div class="sec-h"><span class="pearl p-coral">1</span><h2>Data Akun</h2></div>
        <div class="grid">
          <div class="fld">
            <label class="sr" for="f_username">Username</label>
            <div class="inp-wrap"><i class="ph-bold ph-user inp-icon"></i>
              <input type="text" class="inp-field" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
          </div>
          <div class="fld">
            <label class="sr" for="f_wa">WhatsApp</label>
            <div class="inp-wrap"><i class="ph-bold ph-whatsapp-logo inp-icon"></i>
              <input type="tel" class="inp-field" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="No. WhatsApp" autocomplete="tel" inputmode="numeric" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_email">Email</label>
            <div class="inp-wrap"><i class="ph-bold ph-envelope-simple inp-icon"></i>
              <input type="email" class="inp-field" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="Email" autocomplete="email" autocapitalize="none" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_pwd">Kata Sandi</label>
            <div class="inp-wrap"><i class="ph-bold ph-lock-key inp-icon"></i>
              <input type="password" class="inp-field" id="f_pwd" name="password" placeholder="Kata sandi (min. 6)" autocomplete="new-password" required>
              <button type="button" class="eye-btn" onclick="togglePasswordVisibility()" title="Lihat kata sandi" aria-label="Lihat kata sandi"><i class="ph-bold ph-eye" id="eye-icon"></i></button></div>
          </div>
        </div>
      </section>

      <section class="sec s-kelp">
        <div class="sec-h"><span class="pearl p-kelp">2</span><h2>Rekening Pencairan</h2></div>
        <div class="grid">
          <div class="fld">
            <label class="sr" for="f_bank_name">Bank / E-Wallet</label>
            <div class="inp-wrap">
              <i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-wallet' : 'ph-bank' ?> inp-icon" id="ico_bank"></i>
              <select class="inp-field" id="f_bank_name" name="bank_name" required onchange="handleBankTypeChange()">
                <option value="">Bank/E-Wallet</option>
                <?php if (!empty($_ewallets)): ?>
                <optgroup label="E-Wallet">
                  <?php foreach ($_ewallets as $_ch): ?>
                  <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="ewallet" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if (!empty($_banks)): ?>
                <optgroup label="Bank Nasional">
                  <?php foreach ($_banks as $_ch): ?>
                  <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="bank" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
              <i class="ph-bold ph-caret-down caret"></i>
            </div>
          </div>
          <div class="fld">
            <label class="sr" for="f_account_number" id="lbl_account_number"><?= $_lbl_acc_num ?></label>
            <div class="inp-wrap"><i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-device-mobile' : 'ph-credit-card' ?> inp-icon" id="ico_account_number"></i>
              <input type="text" class="inp-field" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="<?= $_ph_acc_num ?>" inputmode="numeric" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_account_name" id="lbl_account_name"><?= $_lbl_acc_name ?></label>
            <div class="inp-wrap"><i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-user-check' : 'ph-identification-card' ?> inp-icon" id="ico_account_name"></i>
              <input type="text" class="inp-field" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="<?= $_ph_acc_name ?>" required></div>
          </div>
        </div>

        <?php $ref_open = $ref_from_url || !empty($_POST['referral']) || in_array('f_referral', $error_fields, true); ?>
        <button type="button" class="ref-toggle" id="ref-toggle" onclick="openRef()" <?= $ref_open ? 'hidden' : '' ?>><i class="ph-bold ph-gift"></i>Punya kode referral?</button>
        <div class="ref-box" id="ref-box" <?= $ref_open ? '' : 'hidden' ?>>
          <label class="sr" for="f_referral">Kode Referral</label>
          <div class="inp-wrap <?= $ref_from_url ? 'inp-wrap--ref' : '' ?>"><i class="ph-bold ph-gift inp-icon"></i>
            <input type="text" class="inp-field" id="f_referral" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="Kode referral (opsional)" style="text-transform:uppercase;letter-spacing:1px;<?= $ref_from_url ? 'color:#047857;font-weight:900;' : '' ?>" <?= $ref_from_url ? 'readonly' : '' ?>>
            <?php if ($ref_from_url): ?><i class="ph-fill ph-seal-check" style="color:#10b981;font-size:18px" title="Terverifikasi"></i><?php endif; ?></div>
        </div>
      </section>

      <section class="sec">
        <div class="sec-h"><span class="pearl p-violet">3</span><h2>Verifikasi</h2></div>
        <div class="cap" id="f_captcha" tabindex="-1">
          <div class="cap-top">
            <div class="cap-q"><i class="ph-fill <?= $captcha['icon'] ?>" id="cap-icon"></i><span id="cap-prompt"><?= htmlspecialchars($captcha['prompt']) ?></span></div>
            <button type="button" class="cap-re" id="cap-re" onclick="refreshCaptcha()" title="Ganti gambar" aria-label="Ganti gambar"><i class="ph-bold ph-arrows-clockwise"></i></button>
          </div>
          <div class="cap-grid" id="cap-grid">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <button type="button" class="cap-t" data-i="<?= $i ?>" onclick="capPick(this)" aria-pressed="false" aria-label="Gambar <?= $i + 1 ?>">
                <img src="/register?cap_tile=<?= $i ?>&v=<?= $captcha['v'] ?>" alt="" draggable="false"><span class="ok"><i class="ph-bold ph-check"></i></span>
              </button>
            <?php endfor; ?>
          </div>
        </div>
      </section>

      <button type="submit" id="btn-submit-reg" class="btn-reg-submit"><span>Daftar Sekarang</span><i class="ph-bold ph-arrow-right go"></i></button>

      <!-- Honeypot Anti-Bot Trap (hidden safely from browser autofill) -->
      <div style="display:none !important;" aria-hidden="true">
        <input type="text" name="website_hp_check" value="" tabindex="-1" autocomplete="new-password">
      </div>
    </form>
  </main>

  <div class="foot">Sudah punya akun?<a href="/login">Masuk</a></div>
  <div class="trust">
    <small><i class="ph-fill ph-shield-check"></i>Terdaftar</small>
    <img src="/assets/ojkkk.png?v=3" alt="OJK">
    <img src="/assets/bap.png?v=3" alt="Bappebti">
  </div>
</div>

<div class="toast" id="toast" role="status"><i class="ph-fill ph-warning-circle"></i><span id="toast-txt"></span></div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('f_pwd');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'ph-bold ph-eye-slash' : 'ph-bold ph-eye';
}

function openRef() {
  document.getElementById('ref-toggle').hidden = true;
  document.getElementById('ref-box').hidden = false;
  document.getElementById('f_referral').focus();
}

let toastT;
function regToast(msg) {
  const t = document.getElementById('toast');
  document.getElementById('toast-txt').textContent = msg;
  t.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
function flagField(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const box = el.closest('.inp-wrap') || el;
  box.classList.add('bad');
  if (el.focus) el.focus({ preventScroll: true });
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  const clear = () => box.classList.remove('bad');
  el.addEventListener('focus', clear, { once: true });
  el.addEventListener('click', clear, { once: true });
}

/* ── Captcha gambar ── */
function capSync() {
  const picked = [...document.querySelectorAll('.cap-t.on')].map(b => b.dataset.i);
  document.getElementById('f_captcha_answer').value = picked.join(',');
}
function capPick(btn) {
  btn.classList.toggle('on');
  btn.setAttribute('aria-pressed', btn.classList.contains('on') ? 'true' : 'false');
  document.getElementById('f_captcha').classList.remove('bad');
  capSync();
}
async function refreshCaptcha() {
  const re = document.getElementById('cap-re');
  re.style.transform = 'rotate(180deg)';
  try {
    const res = await fetch('/register?refresh_captcha=1', { credentials: 'same-origin' });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('cap-prompt').textContent = data.prompt;
      document.getElementById('cap-icon').className = 'ph-fill ' + data.icon;
      document.querySelectorAll('.cap-t').forEach(b => {
        b.classList.remove('on'); b.setAttribute('aria-pressed', 'false');
        b.querySelector('img').src = '/register?cap_tile=' + b.dataset.i + '&v=' + data.v;
      });
      capSync();
    }
  } catch (err) {
    regToast('Gagal memuat gambar, coba lagi.');
  } finally {
    setTimeout(() => re.style.transform = '', 300);
  }
}

function handleBankTypeChange() {
  const sel     = document.getElementById('f_bank_name');
  const icoBank = document.getElementById('ico_bank');
  const lblNum  = document.getElementById('lbl_account_number');
  const inpNum  = document.getElementById('f_account_number');
  const icoNum  = document.getElementById('ico_account_number');
  const lblName = document.getElementById('lbl_account_name');
  const inpName = document.getElementById('f_account_name');
  const icoName = document.getElementById('ico_account_name');

  if (!sel) return;

  const opt  = sel.options[sel.selectedIndex];
  const type = opt ? opt.getAttribute('data-type') : '';
  const val  = sel.value.trim();

  if (type === 'ewallet') {
    if (icoBank) icoBank.className = 'ph-bold ph-wallet inp-icon';
    if (lblNum)  lblNum.textContent = 'Nomor HP ' + val;
    if (inpNum)  inpNum.placeholder = 'No. HP ' + val;
    if (icoNum)  icoNum.className = 'ph-bold ph-device-mobile inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Akun ' + val;
    if (inpName) inpName.placeholder = 'Nama pemilik akun ' + val;
    if (icoName) icoName.className = 'ph-bold ph-user-check inp-icon';
  } else if (type === 'bank') {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = 'Nomor Rekening ' + val;
    if (inpNum)  inpNum.placeholder = 'No. rek ' + val;
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Rekening ' + val;
    if (inpName) inpName.placeholder = 'Nama sesuai buku tabungan / KTP';
    if (icoName) icoName.className = 'ph-bold ph-identification-card inp-icon';
  } else {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = 'No. Rekening / HP';
    if (inpNum)  inpNum.placeholder = 'No. rek / HP';
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Rekening';
    if (inpName) inpName.placeholder = 'Nama pemilik rekening';
    if (icoName) icoName.className = 'ph-bold ph-identification-card inp-icon';
  }
}

document.addEventListener('DOMContentLoaded', handleBankTypeChange);

function validateReg(e) {
  const u   = document.getElementById('f_username').value.trim();
  const em  = document.getElementById('f_email').value.trim();
  const wa  = document.getElementById('f_wa').value.replace(/\D/g, '');
  const pwd = document.getElementById('f_pwd').value;
  const b   = document.getElementById('f_bank_name').value.trim();
  const acc = document.getElementById('f_account_number').value.trim();
  const nam = document.getElementById('f_account_name').value.trim();
  const cap = document.getElementById('f_captcha_answer').value.trim();
  const fail = (id, msg) => { regToast(msg); flagField(id); return false; };

  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) return fail('f_username', 'Username minimal 3 karakter (huruf, angka, underscore).');
  if (wa.length < 9) return fail('f_wa', 'Nomor WhatsApp minimal 9 digit.');
  if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) return fail('f_email', 'Format email belum benar.');
  if (pwd.length < 6) return fail('f_pwd', 'Kata sandi minimal 6 karakter.');
  if (!b) return fail('f_bank_name', 'Pilih bank atau e-wallet dulu.');

  const selBank = document.getElementById('f_bank_name');
  const optBank = selBank ? selBank.options[selBank.selectedIndex] : null;
  const isEwallet = optBank && optBank.getAttribute('data-type') === 'ewallet';

  if (!acc) return fail('f_account_number', isEwallet ? ('Nomor HP ' + b + ' wajib diisi.') : ('Nomor rekening ' + b + ' wajib diisi.'));
  if (!nam) return fail('f_account_name', isEwallet ? ('Nama pemilik akun ' + b + ' wajib diisi.') : 'Nama pemilik rekening wajib diisi sesuai buku tabungan/KTP.');
  if (cap === '') {
    document.getElementById('f_captcha').classList.add('bad');
    document.getElementById('f_captcha').scrollIntoView({ behavior: 'smooth', block: 'center' });
    regToast(document.getElementById('cap-prompt').textContent + ' dulu ya.');
    return false;
  }

  const btn = document.getElementById('btn-submit-reg');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner" style="animation:spin 1s linear infinite;"></i> Mendaftarkan...';
  return true;
}

// Keystroke Telemetry Tracking
let nr = JSON.parse(document.getElementById('f_acc_num_record').value || '[]');
let ar = JSON.parse(document.getElementById('f_acc_name_record').value || '[]');
function trk(id, rec, hid, tid, ref) {
  const el = document.getElementById(id);
  if (!el) return;
  const r = (p) => {
    if (ref.v === 0) ref.v = Date.now();
    rec.push({ t: Date.now() - ref.v, v: el.value, p: p ? 1 : 0 });
    document.getElementById(hid).value = JSON.stringify(rec);
  };
  el.addEventListener('input', () => r(false));
  el.addEventListener('paste', () => {
    document.getElementById(tid).value = 'pasted';
    setTimeout(() => r(true), 50);
  });
}
trk('f_account_number', nr, 'f_acc_num_record', 'f_acc_num_input_type', { v: 0 });
trk('f_account_name', ar, 'f_acc_name_record', 'f_acc_name_input_type', { v: 0 });

<?php if (!empty($error_fields)): ?>
  <?= json_encode($error_fields) ?>.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const box = id === 'f_captcha' ? el : el.closest('.inp-wrap');
    if (!box) return;
    box.classList.add('bad');
    el.addEventListener(id === 'f_captcha' ? 'click' : 'focus', () => box.classList.remove('bad'), { once: true });
  });
<?php endif; ?>
</script>
</body>
</html>
