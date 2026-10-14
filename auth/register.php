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

// Compact SVG Captcha Generator
function generate_captcha_challenge(): array {
    $n1 = rand(10, 35);
    $n2 = rand(2, 12);
    $op = (rand(0, 1) === 1) ? '+' : '-';
    if ($op === '-' && $n1 < $n2) {
        [$n1, $n2] = [$n2, $n1];
    }
    $ans = ($op === '+') ? ($n1 + $n2) : ($n1 - $n2);
    
    $payload = [
        'ans' => $ans,
        'ts'  => time(),
        'n'   => bin2hex(random_bytes(6))
    ];
    $token = base64_encode(json_encode($payload));
    $secret = 'MancingCuan_SecCap_' . date('Ymd');
    $sig = hash_hmac('sha256', $token, $secret);
    
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 38" width="120" height="38" style="display:block;border-radius:10px;background:#fffbeb;border:2px solid #78350f;">'
         . '<defs>'
         . '<pattern id="hdots" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">'
         . '<circle cx="4" cy="4" r="1.2" fill="#fde68a" opacity="0.6"/>'
         . '</pattern>'
         . '</defs>'
         . '<rect width="100%" height="100%" fill="url(#hdots)"/>'
         . '<path d="M 6 19 Q 32 4, 60 19 T 114 17" fill="none" stroke="#fcd34d" stroke-width="2" opacity="0.6"/>'
         . '<path d="M 8 27 Q 38 35, 68 25 T 112 21" fill="none" stroke="#fbbf24" stroke-width="1.5" opacity="0.4"/>'
         . '<text x="50%" y="62%" dominant-baseline="middle" text-anchor="middle" font-family="\'Nunito\', sans-serif" font-weight="900" font-size="16" fill="#78350f" letter-spacing="1">'
         . "{$n1} {$op} {$n2} = ?"
         . '</text>'
         . '</svg>';
         
    return [
        'svg'   => $svg,
        'token' => $token,
        'sig'   => $sig,
        'ans'   => $ans
    ];
}

// AJAX Refresh Captcha Endpoint
if (isset($_GET['refresh_captcha'])) {
    header('Content-Type: application/json');
    $c = generate_captcha_challenge();
    echo json_encode(['ok' => true, 'svg' => $c['svg'], 'token' => $c['token'], 'sig' => $c['sig']]);
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

    // ── 3. CAPTCHA VALIDATION ──
    $cap_answer = trim($_POST['captcha_answer'] ?? '');
    $cap_token  = $_POST['captcha_token'] ?? '';
    $cap_sig    = $_POST['captcha_sig'] ?? '';
    
    $secret = 'MancingCuan_SecCap_' . date('Ymd');
    $calc_sig = hash_hmac('sha256', $cap_token, $secret);
    if (!hash_equals($calc_sig, $cap_sig)) {
        $secret_prev = 'MancingCuan_SecCap_' . date('Ymd', strtotime('-1 day'));
        $calc_sig = hash_hmac('sha256', $cap_token, $secret_prev);
    }

    if (!hash_equals($calc_sig, $cap_sig)) {
        $error = 'Sesi keamanan kedaluwarsa. Silakan refresh soal hitungan!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    $cap_data = json_decode(base64_decode($cap_token), true);
    if (!$cap_data || !isset($cap_data['ans'], $cap_data['ts'])) {
        $error = 'Token keamanan tidak valid.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ((time() - $cap_data['ts']) > 900) {
        $error = 'Waktu hitungan keamanan telah habis. Silakan refresh soal!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ($cap_answer === '' || (int)$cap_answer !== (int)$cap_data['ans']) {
        $error = 'Jawaban hitungan keamanan salah. Coba lagi!';
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
<meta name="theme-color" content="#78350f">
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
   MANCINGCUAN — CARDLESS COMPACT REGISTER (LIKE LOGIN PAGE)
   Seamless Form • Themed Background • Zero Emojis
   ══════════════════════════════════════════════════════════ */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
}

body {
  font-family: 'Nunito', sans-serif;
  background-color: #fef8ee;
  background-image: 
    radial-gradient(circle at 15% 15%, rgba(251, 191, 36, 0.22) 0%, transparent 45%),
    radial-gradient(circle at 85% 85%, rgba(217, 119, 6, 0.16) 0%, transparent 50%),
    radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px);
  background-size: 100% 100%, 100% 100%, 18px 18px;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 30px 16px 20px;
  color: #1e293b;
  position: relative;
  overflow-x: hidden;
}

/* ── TOP HONEY DRIP DECORATION ── */
.honey-drip-top {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 60px;
  pointer-events: none;
  z-index: 1;
}
.honey-drip-svg {
  width: 100%;
  height: 100%;
  display: block;
}

/* ── FLOATING THEMED ASSETS ── */
.decor-float {
  position: fixed;
  pointer-events: none;
  z-index: 1;
  user-select: none;
}
.decor-float img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.decor-tl {
  top: 35px;
  left: max(10px, calc(50% - 290px));
  width: 65px;
  height: 65px;
  opacity: 0.85;
  filter: drop-shadow(0 8px 16px rgba(120, 53, 15, 0.15));
  animation: floatSlow 5s ease-in-out infinite;
}
.decor-br {
  bottom: 30px;
  right: max(10px, calc(50% - 290px));
  width: 72px;
  height: 72px;
  opacity: 0.85;
  filter: drop-shadow(0 8px 16px rgba(120, 53, 15, 0.15));
  animation: floatSlower 6s ease-in-out infinite;
}
.decor-tr {
  top: 75px;
  right: max(15px, calc(50% - 260px));
  width: 34px;
  height: 34px;
  opacity: 0.75;
  animation: floatSlower 4.5s ease-in-out infinite;
}
.decor-bl {
  bottom: 75px;
  left: max(15px, calc(50% - 260px));
  width: 38px;
  height: 38px;
  opacity: 0.75;
  animation: floatSlow 5.5s ease-in-out infinite;
}

@keyframes floatSlow {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-10px) rotate(4deg); }
}
@keyframes floatSlower {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(12px) rotate(-4deg); }
}

/* ── CARDLESS REGISTER WRAPPER ── */
.reg-wrapper {
  width: 100%;
  max-width: 440px;
  position: relative;
  z-index: 10;
  margin: 0 auto;
}

/* Hero Header */
.reg-hero {
  text-align: center;
  margin-bottom: 16px;
  position: relative;
}
.mascot-wrap {
  position: relative;
  display: inline-block;
  margin-bottom: 8px;
}
.mascot-badge {
  width: 62px;
  height: 62px;
  border-radius: 20px;
  background: #ffffff;
  border: 2.5px solid #78350f;
  box-shadow: 0 3.5px 0 #78350f, 0 8px 20px rgba(217, 119, 6, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: buzzyBob 3s ease-in-out infinite;
  margin: 0 auto;
}
.mascot-badge img {
  width: 42px;
  height: 42px;
  object-fit: contain;
}
@keyframes buzzyBob {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-5px) rotate(3deg); }
}

.reg-title {
  font-size: 21px;
  font-weight: 900;
  color: #78350f;
  letter-spacing: -0.4px;
  line-height: 1.15;
  margin-bottom: 3px;
  text-shadow: 0 1.5px 0 rgba(255, 255, 255, 0.8);
}
.reg-sub {
  font-size: 11px;
  font-weight: 800;
  color: #92400e;
  line-height: 1.3;
}

/* Back/Close button */
.reg-close-btn {
  position: absolute;
  top: 0;
  right: 0;
  width: 32px;
  height: 32px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #78350f;
  font-size: 16px;
  text-decoration: none;
  box-shadow: 0 2px 0 #78350f;
  transition: transform 0.1s;
}
.reg-close-btn:active {
  transform: translateY(1.5px);
  box-shadow: 0 0.5px 0 #78350f;
}

/* Error Flash */
.auth-err {
  background: #fee2e2;
  border: 2px solid #dc2626;
  border-radius: 12px;
  padding: 8px 12px;
  font-size: 11.5px;
  font-weight: 800;
  color: #991b1b;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 2.5px 0 #dc2626;
  line-height: 1.3;
}

/* ── 2-COLUMN COMPACT FORM GRID ── */
.reg-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.span-2 {
  grid-column: span 2;
}

.inp-group {
  display: flex;
  flex-direction: column;
}
.inp-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 3px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.inp-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 13px;
  padding: 0 10px;
  height: 40px;
  box-shadow: 0 2.5px 0 #78350f;
  transition: all 0.15s ease;
  position: relative;
}
.inp-wrap:focus-within {
  border-color: #d97706;
  box-shadow: 0 3px 0 #d97706, 0 0 10px rgba(245, 158, 11, 0.22);
  transform: translateY(-1px);
}
.inp-wrap--ref {
  background: #f0fdf4;
  border-color: #059669;
  box-shadow: 0 2.5px 0 #059669;
}
.inp-icon {
  font-size: 17px;
  color: #b45309;
  flex-shrink: 0;
}
.inp-field {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 800;
  color: #0f172a;
  width: 100%;
  min-width: 0;
}
.inp-field::placeholder {
  color: #94a3b8;
  font-weight: 700;
  font-size: 11.5px;
}
select.inp-field {
  cursor: pointer;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
}
.eye-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px;
  color: #94a3b8;
  font-size: 17px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.eye-btn:hover {
  color: #78350f;
}

.ref-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: #dcfce7;
  color: #166534;
  font-size: 9px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  border: 1px solid #86efac;
  white-space: nowrap;
}

/* ── COMPACT INLINE CAPTCHA BAR ── */
.captcha-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 1px;
}
.captcha-svg-box {
  flex-shrink: 0;
  border-radius: 10px;
  overflow: hidden;
  height: 40px;
}
.captcha-svg-box svg {
  display: block;
  height: 40px;
  width: auto;
}
.captcha-ans-box {
  flex: 1;
}
.captcha-ans-box input {
  width: 100%;
  height: 40px;
  border: 2px solid #78350f;
  border-radius: 12px;
  text-align: center;
  font-family: inherit;
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  background: #ffffff;
  outline: none;
  box-shadow: 0 2.5px 0 #78350f;
  padding: 0 6px;
}
.captcha-ans-box input:focus {
  border-color: #d97706;
  box-shadow: 0 3px 0 #d97706;
}
.btn-cap-refresh {
  width: 40px;
  height: 40px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #78350f;
  font-size: 18px;
  cursor: pointer;
  box-shadow: 0 2.5px 0 #78350f;
  transition: transform 0.1s;
  flex-shrink: 0;
}
.btn-cap-refresh:active {
  transform: translateY(2px);
  box-shadow: 0 0.5px 0 #78350f;
}

/* ── SUBMIT CTA BUTTON ── */
.btn-reg-submit {
  width: 100%;
  height: 48px;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  color: #ffffff;
  font-size: 14.5px;
  font-weight: 900;
  letter-spacing: 0.3px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  box-shadow: 0 4px 0 #78350f;
  text-shadow: 0 1px 2px #78350f;
  cursor: pointer;
  font-family: inherit;
  margin-top: 12px;
  transition: transform 0.1s, box-shadow 0.1s;
}
.btn-reg-submit:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #78350f;
}

/* Footer Login Link */
.reg-footer-link {
  text-align: center;
  margin-top: 12px;
  font-size: 11.5px;
  font-weight: 800;
  color: #78350f;
}
.reg-login-link {
  font-weight: 900;
  color: #b45309;
  text-decoration: underline;
  margin-left: 3px;
  transition: color 0.1s;
}
.reg-login-link:hover {
  color: #78350f;
}

/* ── TRUST & REGULATION STRIP (CARDLESS) ── */
.auth-trust-strip {
  margin-top: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: rgba(255, 255, 255, 0.95);
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 8px 14px;
  box-shadow: 0 2.5px 0 #78350f;
}
.trust-strip-lbl {
  font-size: 10px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 4px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.trust-strip-logos {
  display: flex;
  align-items: center;
  gap: 12px;
}
.trust-logo--ojk {
  height: 28px;
  max-width: 85px;
  object-fit: contain;
  display: block;
}
.trust-logo--bap {
  height: 22px;
  max-width: 95px;
  object-fit: contain;
  display: block;
}
.trust-strip-sep {
  width: 1.5px;
  height: 22px;
  background: #cbd5e1;
}
</style>
</head>
<body>

<!-- TOP HONEY DRIP DECORATION -->
<div class="honey-drip-top">
  <svg viewBox="0 0 1440 90" preserveAspectRatio="none" class="honey-drip-svg">
    <path d="M0,0 L1440,0 L1440,25 Q1380,75 1320,35 Q1260,-5 1200,45 Q1140,95 1080,40 Q1020,-15 960,30 Q900,75 840,35 Q780,-5 720,55 Q660,115 600,45 Q540,-25 480,40 Q420,105 360,35 Q300,-35 240,50 Q180,135 120,40 Q60,-50 0,30 Z" fill="#d97706" opacity="0.12"></path>
    <path d="M0,0 L1440,0 L1440,18 Q1380,60 1320,25 Q1260,-8 1200,35 Q1140,80 1080,30 Q1020,-18 960,20 Q900,60 840,25 Q780,-8 720,45 Q660,95 600,35 Q540,-25 480,30 Q420,90 360,25 Q300,-35 240,40 Q180,115 120,30 Q60,-55 0,22 Z" fill="#f59e0b" opacity="0.22"></path>
    <path d="M0,0 L1440,0 L1440,12 Q1350,45 1260,16 Q1170,-12 1080,28 Q990,70 900,20 Q810,-28 720,28 Q630,85 540,24 Q450,-38 360,20 Q270,78 180,20 Q90,-38 0,16 Z" fill="#fbbf24" opacity="0.28"></path>
  </svg>
</div>

<!-- FLOATING THEMED ASSETS -->
<div class="decor-float decor-tl">
  <img src="/assets/game/honey_jar.png" alt="">
</div>
<div class="decor-float decor-br">
  <img src="/assets/game/beehive_amber.png" alt="">
</div>
<div class="decor-float decor-tr">
  <img src="/assets/game/honey_drop.png" alt="">
</div>
<div class="decor-float decor-bl">
  <img src="/assets/game/honey_drop.png" alt="">
</div>

<!-- CARDLESS REGISTER WRAPPER -->
<div class="reg-wrapper">

  <!-- Hero Header -->
  <div class="reg-hero">
    <a href="/" class="reg-close-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-x"></i>
    </a>
    <div class="mascot-wrap">
      <div class="mascot-badge">
        <img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="MancingCuan">
      </div>
    </div>
    <h1 class="reg-title">Daftar Akun MancingCuan</h1>
    <p class="reg-sub">Tonton Video &amp; Panen Cuan Setiap Hari</p>
  </div>

  <?php if ($error): ?>
    <div class="auth-err">
      <i class="ph-bold ph-warning-circle" style="font-size:16px;flex-shrink:0;"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <!-- Cardless Form -->
  <form method="POST" id="reg-form" novalidate onsubmit="return validateReg(event)">
    <?= csrf_field() ?>
    
    <!-- Anti-Bot Hidden Fields -->
    <input type="hidden" name="form_time_sig" value="<?= time() ?>">
    <input type="hidden" name="captcha_token" id="captcha_token" value="<?= htmlspecialchars($captcha['token']) ?>">
    <input type="hidden" name="captcha_sig" id="captcha_sig" value="<?= htmlspecialchars($captcha['sig']) ?>">
    <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
    <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">
    <input type="hidden" name="acc_num_record" id="f_acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
    <input type="hidden" name="acc_name_record" id="f_acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">

    <!-- 2-COLUMN COMPACT GRID -->
    <div class="reg-grid">
      
      <!-- Col 1: Username -->
      <div class="inp-group">
        <label class="inp-label" for="f_username">Username</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-user inp-icon"></i>
          <input type="text" class="inp-field" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Min. 3 Huruf" autocomplete="username" required>
        </div>
      </div>

      <!-- Col 2: WhatsApp -->
      <div class="inp-group">
        <label class="inp-label" for="f_wa">WhatsApp</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-whatsapp-logo inp-icon"></i>
          <input type="tel" class="inp-field" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="08xxxxxxxx" autocomplete="tel" required>
        </div>
      </div>

      <!-- Col 3: Email -->
      <div class="inp-group">
        <label class="inp-label" for="f_email">Email</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-envelope-simple inp-icon"></i>
          <input type="email" class="inp-field" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="email@gmail.com" autocomplete="email" required>
        </div>
      </div>

      <!-- Col 4: Password -->
      <div class="inp-group">
        <label class="inp-label" for="f_pwd">Kata Sandi</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-lock-key inp-icon"></i>
          <input type="password" class="inp-field" id="f_pwd" name="password" placeholder="Min. 6 Karakter" autocomplete="new-password" required>
          <button type="button" class="eye-btn" onclick="togglePasswordVisibility()" title="Lihat">
            <i class="ph-bold ph-eye" id="eye-icon"></i>
          </button>
        </div>
      </div>

      <!-- Col 5: Bank / E-Wallet -->
      <div class="inp-group">
        <label class="inp-label" for="f_bank_name">Bank / E-Wallet</label>
        <div class="inp-wrap">
          <i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-wallet' : 'ph-bank' ?> inp-icon" id="ico_bank"></i>
          <select class="inp-field" id="f_bank_name" name="bank_name" required onchange="handleBankTypeChange()">
            <option value="">Pilih Bank / E-Wallet</option>
            <?php if (!empty($_banks)): ?>
            <optgroup label="Bank Nasional">
              <?php foreach ($_banks as $_ch): ?>
              <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="bank" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($_ewallets)): ?>
            <optgroup label="E-Wallet">
              <?php foreach ($_ewallets as $_ch): ?>
              <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="ewallet" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
          <i class="ph-bold ph-caret-down" style="color:#b45309;font-size:14px;flex-shrink:0;"></i>
        </div>
      </div>

      <!-- Col 6: Account Number -->
      <div class="inp-group">
        <label class="inp-label" for="f_account_number" id="lbl_account_number"><?= $_lbl_acc_num ?></label>
        <div class="inp-wrap">
          <i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-device-mobile' : 'ph-credit-card' ?> inp-icon" id="ico_account_number"></i>
          <input type="text" class="inp-field" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="<?= $_ph_acc_num ?>" required>
        </div>
      </div>

      <!-- Span 2: Account Name -->
      <div class="inp-group span-2">
        <label class="inp-label" for="f_account_name" id="lbl_account_name"><?= $_lbl_acc_name ?></label>
        <div class="inp-wrap">
          <i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-user-check' : 'ph-identification-card' ?> inp-icon" id="ico_account_name"></i>
          <input type="text" class="inp-field" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="<?= $_ph_acc_name ?>" required>
        </div>
      </div>

      <!-- Span 2: Referral Code -->
      <div class="inp-group span-2">
        <label class="inp-label" for="f_referral">Kode Referral <span style="font-size:9.5px;color:#92400e;font-weight:700;">(Opsional)</span></label>
        <div class="inp-wrap <?= $ref_from_url ? 'inp-wrap--ref' : '' ?>">
          <i class="ph-bold ph-gift inp-icon" style="<?= $ref_from_url ? 'color:#059669;' : '' ?>"></i>
          <input type="text" class="inp-field" id="f_referral" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="Masukkan Kode Referral" style="text-transform:uppercase;letter-spacing:1px;<?= $ref_from_url ? 'color:#166534;font-weight:900;' : '' ?>" <?= $ref_from_url ? 'readonly' : '' ?>>
          <?php if ($ref_from_url): ?>
            <span class="ref-badge"><i class="ph-fill ph-check-circle"></i> Terverifikasi</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Span 2: Compact Inline Captcha -->
      <div class="inp-group span-2">
        <label class="inp-label">Verifikasi Keamanan (Hitung Angka)</label>
        <div class="captcha-bar">
          <div class="captcha-svg-box" id="captcha-svg-wrap">
            <?= $captcha['svg'] ?>
          </div>
          <div class="captcha-ans-box">
            <input type="number" id="f_captcha" name="captcha_answer" placeholder="Hasil?" autocomplete="off" required>
          </div>
          <button type="button" class="btn-cap-refresh" onclick="refreshCaptcha()" title="Ganti Soal">
            <i class="ph-bold ph-arrows-clockwise" id="refresh-ico"></i>
          </button>
        </div>
      </div>

    </div>

    <!-- Submit CTA Button -->
    <button type="submit" id="btn-submit-reg" class="btn-reg-submit">
      <span>Daftar Akun Sekarang</span>
      <i class="ph-bold ph-arrow-right"></i>
    </button>

    <!-- Honeypot Anti-Bot Trap (hidden safely from browser autofill) -->
    <div style="display:none !important;" aria-hidden="true">
      <input type="text" name="website_hp_check" value="" tabindex="-1" autocomplete="new-password">
    </div>
  </form>

  <!-- Login Link -->
  <div class="reg-footer-link">
    <span>Sudah punya akun?</span>
    <a href="/login" class="reg-login-link">Masuk Sekarang</a>
  </div>

  <!-- ── COMPACT TRUST & REGULATION STRIP ── -->
  <div class="auth-trust-strip">
    <div class="trust-strip-lbl">
      <i class="ph-fill ph-shield-check" style="color:#059669;font-size:14px;"></i>
      <span>Diawasi &amp; Terdaftar</span>
    </div>
    <div class="trust-strip-logos">
      <img src="/assets/ojkkk.png?v=3" alt="OJK" class="trust-logo--ojk">
      <div class="trust-strip-sep"></div>
      <img src="/assets/bap.png?v=3" alt="Bappebti" class="trust-logo--bap">
    </div>
  </div>

</div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('f_pwd');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;

  if (inp.type === 'password') {
    inp.type = 'text';
    ico.className = 'ph-bold ph-eye-slash';
  } else {
    inp.type = 'password';
    ico.className = 'ph-bold ph-eye';
  }
}

async function refreshCaptcha() {
  const ico = document.getElementById('refresh-ico');
  if (ico) ico.style.transform = 'rotate(180deg)';
  try {
    const res = await fetch('/register?refresh_captcha=1');
    const data = await res.json();
    if (data.ok) {
      document.getElementById('captcha-svg-wrap').innerHTML = data.svg;
      document.getElementById('captcha_token').value = data.token;
      document.getElementById('captcha_sig').value = data.sig;
      document.getElementById('f_captcha').value = '';
      document.getElementById('f_captcha').focus();
    }
  } catch (err) {
    console.error('Failed to refresh captcha', err);
  } finally {
    if (ico) setTimeout(() => ico.style.transform = 'none', 300);
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
    if (lblNum)  lblNum.textContent = val ? 'Nomor HP ' + val : 'Nomor HP E-Wallet';
    if (inpNum)  inpNum.placeholder = 'Contoh: 08xxxxxxxxxx';
    if (icoNum)  icoNum.className = 'ph-bold ph-device-mobile inp-icon';

    if (lblName) lblName.textContent = val ? 'Nama Pemilik Akun ' + val : 'Nama Pemilik Akun E-Wallet';
    if (inpName) inpName.placeholder = val ? 'Nama Terdaftar di ' + val : 'Nama Terdaftar di Akun E-Wallet';
    if (icoName) icoName.className = 'ph-bold ph-user-check inp-icon';
  } else if (type === 'bank') {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = val ? 'Nomor Rekening ' + val : 'Nomor Rekening Bank';
    if (inpNum)  inpNum.placeholder = val ? 'Nomor Rekening ' + val : 'Nomor Rekening Bank';
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';

    if (lblName) lblName.textContent = val ? 'Nama Pemilik Rekening ' + val : 'Nama Pemilik Rekening Bank';
    if (inpName) inpName.placeholder = 'Sesuai Buku Tabungan / KTP';
    if (icoName) icoName.className = 'ph-bold ph-identification-card inp-icon';
  } else {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = 'No. Rekening / HP';
    if (inpNum)  inpNum.placeholder = 'Nomor Rekening';
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';

    if (lblName) lblName.textContent = 'Nama Pemilik Rekening';
    if (inpName) inpName.placeholder = 'Sesuai Buku Tabungan / KTP';
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
  const cap = document.getElementById('f_captcha').value.trim();

  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) {
    alert('Username minimal 3 karakter (huruf, angka, underscore)!');
    document.getElementById('f_username').focus();
    return false;
  }
  if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
    alert('Format email tidak valid!');
    document.getElementById('f_email').focus();
    return false;
  }
  if (wa.length < 9) {
    alert('Nomor WhatsApp minimal 9 digit!');
    document.getElementById('f_wa').focus();
    return false;
  }
  if (pwd.length < 6) {
    alert('Password minimal 6 karakter!');
    document.getElementById('f_pwd').focus();
    return false;
  }
  if (!b) {
    alert('Pilih Bank atau E-Wallet!');
    document.getElementById('f_bank_name').focus();
    return false;
  }

  const selBank = document.getElementById('f_bank_name');
  const optBank = selBank ? selBank.options[selBank.selectedIndex] : null;
  const isEwallet = optBank && optBank.getAttribute('data-type') === 'ewallet';

  if (!acc) {
    alert(isEwallet ? ('Nomor HP akun ' + (b || 'e-wallet') + ' wajib diisi!') : ('Nomor rekening ' + (b || 'bank') + ' wajib diisi!'));
    document.getElementById('f_account_number').focus();
    return false;
  }
  if (!nam) {
    alert(isEwallet ? ('Nama pemilik akun ' + (b || 'e-wallet') + ' wajib diisi sesuai akun!') : 'Nama pemilik rekening bank wajib diisi sesuai buku tabungan/KTP!');
    document.getElementById('f_account_name').focus();
    return false;
  }
  if (cap === '') {
    alert('Ketik hasil hitungan keamanan captcha!');
    document.getElementById('f_captcha').focus();
    return false;
  }

  const btn = document.getElementById('btn-submit-reg');
  btn.style.opacity = '0.7';
  btn.style.pointerEvents = 'none';
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
  const errFields = <?= json_encode($error_fields) ?>;
  errFields.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      const inp = el.closest('.inp-wrap') || el.closest('.captcha-bar');
      if (inp) {
        inp.style.borderColor = '#dc2626';
        inp.style.boxShadow = '0 0 0 2.5px rgba(220, 38, 38, 0.2)';
        el.addEventListener('focus', () => {
          inp.style.borderColor = '';
          inp.style.boxShadow = '';
        }, { once: true });
      }
    }
  });
<?php endif; ?>
</script>
</body>
</html>
