<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('settings');
csrf_enforce();

// Read flash from session (set after PRG redirect)
$flash     = $_SESSION['settings_flash']      ?? '';
$flashType = $_SESSION['settings_flash_type'] ?? '';
unset($_SESSION['settings_flash'], $_SESSION['settings_flash_type']);
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $flash     = '';
    $flashType = '';

    if ($action === 'save_general') {
        $keys = ['site_name','site_tagline','free_watch_limit','referral_bonus',
                 'referral_commission_percent','referral_hold_days','checkin_reward_min','checkin_reward_max','min_deposit',
                 'depo_unique_code_min','depo_unique_code_max',
                 'target_deposit_daily','target_member_daily'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) setting_set($pdo, $k, clean_input($_POST[$k]));
        }
        // Toggle checkbox
        setting_set($pdo, 'depo_unique_code_enabled', isset($_POST['depo_unique_code_enabled']) ? '1' : '0');
        setting_set($pdo, 'investment_enabled', isset($_POST['investment_enabled']) ? '1' : '0');

        $flash = 'Pengaturan umum berhasil disimpan!';
    }

    if ($action === 'save_bank') {
        foreach (['bank_name','bank_account','bank_holder'] as $k) {
            if (isset($_POST[$k])) setting_set($pdo, $k, clean_input($_POST[$k]));
        }
        // QRIS raw
        if (isset($_POST['qris_raw'])) setting_set($pdo, 'qris_raw', trim($_POST['qris_raw']));
        $flash = 'Info rekening & QRIS berhasil disimpan!';
    }

    if ($action === 'save_maintenance') {
        setting_set($pdo, 'maintenance_mode', isset($_POST['maintenance_mode']) && $_POST['maintenance_mode'] === '1' ? '1' : '0');
        setting_set($pdo, 'maintenance_message', clean_input($_POST['maintenance_message'] ?? 'Sistem sedang dalam perbaikan.'));
        $flash = 'Pengaturan maintenance disimpan!';
    }

    if ($action === 'save_threads_campaign') {
        setting_set($pdo, 'threads_campaign_enabled', isset($_POST['threads_campaign_enabled']) ? '1' : '0');
        setting_set($pdo, 'threads_campaign_reward', clean_input($_POST['threads_campaign_reward'] ?? '25000'));
        setting_set($pdo, 'threads_campaign_instructions', trim($_POST['threads_campaign_instructions'] ?? ''));
        setting_set($pdo, 'threads_campaign_reward_step2', clean_input($_POST['threads_campaign_reward_step2'] ?? '50000'));
        setting_set($pdo, 'threads_campaign_instructions_step2', trim($_POST['threads_campaign_instructions_step2'] ?? ''));
        setting_set($pdo, 'threads_admin_contact', trim($_POST['threads_admin_contact'] ?? ''));
        $flash = 'Pengaturan Kampanye Threads (Langkah 1 & 2) berhasil disimpan!';
    }



    if ($action === 'save_telegram') {
        setting_set($pdo, 'tg_bot_token', clean_input($_POST['tg_bot_token'] ?? ''));
        setting_set($pdo, 'tg_chat_id',   clean_input($_POST['tg_chat_id'] ?? ''));
        $flash = 'Pengaturan Telegram Bot disimpan!';
    }

    if ($action === 'sync_tg_webhook') {
        $token = setting($pdo, 'tg_bot_token', '');
        if (!$token) {
            $flash = 'Isi Token Bot terlebih dahulu!'; $flashType = 'error';
        } else {
            $webhook_url = base_url('webhook.php');
            $url = "https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhook_url);
            $response = @file_get_contents($url);
            if ($response) {
                $res = json_decode($response, true);
                if (isset($res['ok']) && $res['ok']) {
                    $flash = 'Webhook berhasil di-sync ke: ' . $webhook_url;
                } else {
                    $flash = 'Gagal sync webhook: ' . ($res['description'] ?? 'Unknown error'); $flashType = 'error';
                }
            } else {
                $flash = 'Gagal memanggil API Telegram.'; $flashType = 'error';
            }
        }
    }

    if ($action === 'auto_create_topics') {
        $token = setting($pdo, 'tg_bot_token', '');
        $chat_id = setting($pdo, 'tg_chat_id', '');
        if (!$token || !$chat_id) {
            $flash = 'Isi Token Bot dan Chat ID Admin terlebih dahulu!'; $flashType = 'error';
        } else {
            $topic_key = $_POST['topic_key'] ?? '';
            $topics = [
                'log' => '📝 Log Aktivitas',
                'wd' => '💸 Withdraw',
                'depo' => '💰 Deposit',
                'user_baru' => '🆕 User Baru',
                'permintaan' => '💬 Permintaan',
                'misi' => '🎯 Klaim Misi',
                'abuse' => '🚨 Deteksi Abuse',
                'threads' => '🧵 Promosi Threads'
            ];
            if ($topic_key && isset($topics[$topic_key])) {
                $topics = [$topic_key => $topics[$topic_key]];
            }
            $success_count = 0;
            $errors = [];
            foreach ($topics as $key => $name) {
                $url = "https://api.telegram.org/bot{$token}/createForumTopic";
                $options = [
                    'http' => [
                        'header'  => "Content-type: application/json\r\n",
                        'method'  => 'POST',
                        'content' => json_encode(['chat_id' => $chat_id, 'name' => $name]),
                        'ignore_errors' => true
                    ]
                ];
                $res = @file_get_contents($url, false, stream_context_create($options));
                $res = json_decode($res ?: '{}', true);
                if (isset($res['ok']) && $res['ok']) {
                    $thread_id = $res['result']['message_thread_id'];
                    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                        ->execute(["tg_topic_{$key}", (string)$thread_id, (string)$thread_id]);
                    $success_count++;
                } else {
                    $errors[] = "{$name}: " . ($res['description'] ?? 'Unknown error');
                }
            }
            if ($success_count > 0) {
                $flash = "Berhasil membuat {$success_count} topic otomatis! " . implode(', ', $errors);
            } else {
                $flash = "Gagal membuat topic: " . implode(', ', $errors); $flashType = 'error';
            }
        }
    }

    if ($action === 'change_password') {
        $admin = $_SESSION['admin'];
        $cur   = $pdo->prepare("SELECT password_hash FROM admins WHERE id=?"); $cur->execute([$admin['id']]); $cur = $cur->fetchColumn();
        $old   = $_POST['old_password'] ?? '';
        $new   = $_POST['new_password'] ?? '';
        if (!password_verify($old, $cur)) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $admin['id']]);
            $flash = 'Password admin berhasil diubah.';
        }
    }
    if ($action === 'save_rtp') {

    }
}

$s = fn($k, $d='') => setting($pdo, $k, $d);
$maintenance_on = $s('maintenance_mode','0') === '1';

$pageTitle  = 'Pengaturan';
$activePage = 'settings';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4"><h5 class="mb-0 fw-bold">⚙️ Pengaturan Sistem</h5></div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php


$active_tab = $_GET['tab'] ?? 'general';
$tabs = [
    'general' => ['icon' => '🌐', 'label' => 'Umum'],
    'campaign' => ['icon' => '📢', 'label' => 'Kampanye Threads'],
    'bank'    => ['icon' => '🏦', 'label' => 'Rekening'],
    'system'  => ['icon' => '🔧', 'label' => 'Sistem & TG'],
];
?>

<style>
.stab-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;padding:6px;background:rgba(255,255,255,.04);border-radius:12px;border:1px solid rgba(255,255,255,.07)}
.stab-link{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;color:var(--text2,#aaa);text-decoration:none;transition:all .15s;border:1px solid transparent;white-space:nowrap}
.stab-link:hover{background:rgba(255,255,255,.07);color:#fff}
.stab-link.active{background:var(--brand,#6366f1);color:#fff;border-color:rgba(255,255,255,.2);box-shadow:0 2px 8px rgba(99,102,241,.35)}
.stab-pane{display:none}
.stab-pane.active{display:block}
</style>

<nav class="stab-nav">
<?php foreach($tabs as $key => $t): ?>
  <a href="?tab=<?= $key ?>" class="stab-link <?= $active_tab===$key?'active':'' ?>">
    <?= $t['icon'] ?> <?= $t['label'] ?>
  </a>
<?php endforeach; ?>
</nav>

<div class="tab-content">
  <!-- TAB GENERAL -->
  <div class="stab-pane <?= $active_tab==='general'?'active':'' ?>" id="tab-general">
    <div class="row g-3"><div class="col-md-8">
      <div class="c-card mb-3">
        <div class="c-card-header"><span class="c-card-title">🌐 Pengaturan Umum</span></div>
        <div class="c-card-body">
          <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Nama Website</label>
                <input type="text" name="site_name" class="c-form-control" value="<?= htmlspecialchars($s('site_name','MancingCuan')) ?>"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Tagline</label>
                <input type="text" name="site_tagline" class="c-form-control" value="<?= htmlspecialchars($s('site_tagline')) ?>"></div></div>
            </div>
            
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Limit Tonton <?= htmlspecialchars(get_free_tier_name($pdo)) ?> (video/hari)</label>
                <input type="number" name="free_watch_limit" class="c-form-control" value="<?= $s('free_watch_limit','7') ?>" min="1"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Reward Check-in Min (Rp)</label>
                <input type="number" name="checkin_reward_min" class="c-form-control" value="<?= $s('checkin_reward_min','500') ?>" min="0"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Reward Check-in Max (Rp)</label>
                <input type="number" name="checkin_reward_max" class="c-form-control" value="<?= $s('checkin_reward_max','2000') ?>" min="0"></div></div>
            </div>

            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Minimum Deposit (Rp)</label>
                <input type="number" name="min_deposit" class="c-form-control" value="<?= $s('min_deposit','10000') ?>" min="0"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">% Komisi Referral</label>
                <input type="number" name="referral_commission_percent" class="c-form-control" value="<?= $s('referral_commission_percent','5') ?>" min="0" max="100" step="0.1"></div></div>
            </div>
            
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Target Deposit Harian (Rp)</label>
                <input type="number" name="target_deposit_daily" class="c-form-control" value="<?= $s('target_deposit_daily','10000000') ?>" min="0"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Target Member Harian</label>
                <input type="number" name="target_member_daily" class="c-form-control" value="<?= $s('target_member_daily','100') ?>" min="0"></div></div>
            </div>
            

            
            <div class="c-form-group">
              <label class="c-label">Fitur Investasi Ponzi</label>
              <div class="form-check ms-1">
                <input class="form-check-input" type="checkbox" name="investment_enabled" id="investment_enabled_chk" value="1" <?= $s('investment_enabled','1')==='1'?'checked':'' ?>>
                <label class="form-check-label text-secondary" for="investment_enabled_chk" style="font-size:13px;font-weight:700">
                  Aktifkan Fitur Investasi Ponzi untuk Pengguna
                </label>
              </div>
              <small style="color:#888;font-size:11px">Jika dimatikan, seluruh menu dan halaman investasi tidak akan dapat diakses oleh user.</small>
            </div>

            <div class="row g-2">
              <div class="col-md-6">
                <div class="c-form-group">
                  <label class="c-label">Bonus Referral Registrasi (Rp) <small style="color:#888">(opsional)</small></label>
                  <input type="number" name="referral_bonus" class="c-form-control" value="<?= $s('referral_bonus','1000') ?>" min="0">
                </div>
              </div>
              <div class="col-md-6">
                <div class="c-form-group">
                  <label class="c-label">Masa Tahan Komisi (Hari) <small style="color:#888">(Holding Period)</small></label>
                  <input type="number" name="referral_hold_days" class="c-form-control" value="<?= $s('referral_hold_days','3') ?>" min="0">
                </div>
              </div>
            </div>
            <small style="color:#888;font-size:11px;display:block;margin-top:-6px;margin-bottom:12px">Komisi referral baru akan berstatus beku dan baru bisa dicairkan oleh user setelah jumlah hari ini tercapai (Isi 0 jika ingin langsung cair otomatis tanpa beku).</small>
            
            <div style="border-top:1px solid #2d3149;margin:20px 0 16px;"></div>
            
            <h6 style="font-weight:800;font-size:14px;margin-bottom:12px;color:var(--brand)">🔢 Kode Unik Deposit</h6>
            <div class="c-form-group mb-2">
              <div class="form-check ms-1">
                <input class="form-check-input" type="checkbox" name="depo_unique_code_enabled" id="depo_unique_code_enabled" value="1" <?= $s('depo_unique_code_enabled','0')==='1'?'checked':'' ?>>
                <label class="form-check-label text-secondary" for="depo_unique_code_enabled" style="font-size:13px;font-weight:700">
                  Aktifkan Kode Unik (Otomatis ditambahkan ke nominal transfer)
                </label>
              </div>
            </div>
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Range Kode Unik (Min)</label>
                <input type="number" name="depo_unique_code_min" class="c-form-control" value="<?= $s('depo_unique_code_min','1') ?>" min="1"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Range Kode Unik (Max)</label>
                <input type="number" name="depo_unique_code_max" class="c-form-control" value="<?= $s('depo_unique_code_max','999') ?>" min="1"></div></div>
            </div>
            
            <button type="submit" class="btn btn-sm text-white mt-2" style="background:var(--brand)">Simpan Pengaturan</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>

  <!-- TAB CAMPAIGN -->
  <div class="stab-pane <?= $active_tab==='campaign'?'active':'' ?>" id="tab-campaign">
    <div class="row g-3">
      <div class="col-md-8">
        <div class="c-card mb-3">
          <div class="c-card-header"><span class="c-card-title">📢 Kampanye Promosi Threads</span></div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_threads_campaign">
              
              <div class="c-form-group mb-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="threads_campaign_enabled" id="threads_campaign_enabled" value="1" <?= $s('threads_campaign_enabled','1')==='1'?'checked':'' ?>>
                  <label class="form-check-label text-secondary fw-bold" for="threads_campaign_enabled" style="font-size:13px">Aktifkan Kampanye Threads</label>
                </div>
                <small class="text-muted" style="font-size:11px">Jika dinonaktifkan, banner kampanye di halaman Beranda user tidak akan muncul.</small>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-md-6">
                  <div class="c-form-group">
                    <label class="c-label">Reward Langkah 1 (Rp)</label>
                    <input type="number" name="threads_campaign_reward" class="c-form-control" value="<?= htmlspecialchars($s('threads_campaign_reward','25000')) ?>" min="0" required>
                    <small class="text-muted" style="font-size:11px">Saldo setelah klaim Langkah 1 disetujui.</small>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="c-form-group">
                    <label class="c-label">Reward Langkah 2 (Rp)</label>
                    <input type="number" name="threads_campaign_reward_step2" class="c-form-control" value="<?= htmlspecialchars($s('threads_campaign_reward_step2','50000')) ?>" min="0" required>
                    <small class="text-muted" style="font-size:11px">Saldo setelah klaim Langkah 2 disetujui.</small>
                  </div>
                </div>
              </div>

              <div class="c-form-group mb-3">
                <label class="c-label">Petunjuk & Syarat Langkah 1</label>
                <textarea name="threads_campaign_instructions" class="c-form-control" rows="6" required><?= htmlspecialchars($s('threads_campaign_instructions', "Promosikan MancingCuan di Threads dan dapatkan cuan tambahan Rp 25.000! \n\nKriteria Postingan:\n1. Postingan harus menyertakan gambar (screenshot/bukti bayar/foto aplikasi).\n2. Teks postingan berupa kalimat ajakan atau cerita pengalaman positif kamu mendapatkan cuan di MancingCuan.\n3. Berikan komentar atau caption positif tentang MancingCuan.\n\nCara Klaim:\n1. Buat postingan sesuai kriteria di atas pada akun Threads kamu.\n2. Ambil screenshot (bukti SS) postingan tersebut.\n3. Upload screenshot di form bawah ini untuk diverifikasi oleh admin.")) ?></textarea>
                <small class="text-muted" style="font-size:11px">Ditampilkan kepada pengguna di halaman klaim Langkah 1.</small>
              </div>

              <div class="c-form-group mb-3">
                <label class="c-label">Petunjuk & Syarat Langkah 2 (Promotor Khusus & Min 5K Views)</label>
                <textarea name="threads_campaign_instructions_step2" class="c-form-control" rows="6" required><?= htmlspecialchars($s('threads_campaign_instructions_step2', "Promosikan MancingCuan di Threads - Langkah 2 (Dapatkan Rp 50.000, Gaji Pasti & Chat WhatsApp Admin!)\n\nKriteria Postingan Langkah 2:\n1. Kamu telah mengundang minimal 10 referral bergabung di MancingCuan.\n2. Postingan Threads kamu viral / ramai dengan minimal 5.000 (5K) views / tayangan.\n3. Berikan screenshot (bukti SS) bahwa postingan Threads kamu tembus minimal 5.000 views (bisa kirim hingga 3 screenshot bukti statistik & interaksi).")) ?></textarea>
                <small class="text-muted" style="font-size:11px">Ditampilkan kepada pengguna di halaman klaim Langkah 2.</small>
              </div>

              <div class="c-form-group mb-3">
                <label class="c-label">Kontak WhatsApp Admin VIP (Untuk Promotor Level 2 - Gaji Pasti &amp; Bonus)</label>
                <input type="text" name="threads_admin_contact" class="c-form-control" value="<?= htmlspecialchars($s('threads_admin_contact', 'https://wa.me/6281234567890')) ?>" placeholder="https://wa.me/628xxx atau @username_telegram">
                <small class="text-muted" style="font-size:11px">Link WhatsApp/Telegram admin yang langsung terbuka untuk user yang telah lolos verifikasi Level 2.</small>
              </div>

              <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Pengaturan Kampanye</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB BANK -->
  <div class="stab-pane <?= $active_tab==='bank'?'active':'' ?>" id="tab-bank">
    <div class="row g-3"><div class="col-md-6">
      <div class="c-card mb-3">
        <div class="c-card-header"><span class="c-card-title">🏦 Info Rekening & QRIS</span></div>
        <div class="c-card-body">
          <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_bank">
            <div class="c-form-group"><label class="c-label">Nama Bank</label>
              <input type="text" name="bank_name" class="c-form-control" value="<?= htmlspecialchars($s('bank_name','BCA')) ?>"></div>
            <div class="c-form-group"><label class="c-label">Nomor Rekening</label>
              <input type="text" name="bank_account" class="c-form-control" value="<?= htmlspecialchars($s('bank_account')) ?>"></div>
            <div class="c-form-group"><label class="c-label">Nama Pemilik</label>
              <input type="text" name="bank_holder" class="c-form-control" value="<?= htmlspecialchars($s('bank_holder')) ?>"></div>
            <div class="c-form-group"><label class="c-label">QRIS Raw String <small style="color:#888">(paste string QRIS statis tanpa CRC)</small></label>
              <textarea name="qris_raw" class="c-form-control" rows="4" placeholder="00020101021226..."><?= htmlspecialchars($s('qris_raw')) ?></textarea>
              <small style="color:#888">Kosongkan jika tidak menggunakan QRIS</small></div>
            <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Rekening & QRIS</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>



  <!-- TAB SYSTEM & TELEGRAM -->
  <div class="stab-pane <?= $active_tab==='system'?'active':'' ?>" id="tab-system">
    <div class="row g-3">
      <div class="col-md-6">
        <!-- Maintenance mode -->
        <div class="c-card mb-3">
          <div class="c-card-header"><span class="c-card-title">🔧 Mode Maintenance</span>
            <span class="badge <?= $maintenance_on ? 'b-danger' : 'b-success' ?>" style="float:right;border-radius:6px"><?= $maintenance_on ? '🔴 Aktif' : '🟢 Normal' ?></span>
          </div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_maintenance">
              <div class="c-form-group">
                <label class="c-label">Status Maintenance</label>
                <select name="maintenance_mode" class="c-form-control">
                  <option value="0" <?= !$maintenance_on?'selected':'' ?>>🟢 Normal (User bisa akses)</option>
                  <option value="1" <?= $maintenance_on?'selected':'' ?>>🔴 Maintenance (User diblokir)</option>
                </select>
              </div>
              <div class="c-form-group"><label class="c-label">Pesan Maintenance</label>
                <textarea name="maintenance_message" class="c-form-control" rows="2"><?= htmlspecialchars($s('maintenance_message','Sistem sedang dalam perbaikan.')) ?></textarea></div>
              <button type="submit" class="btn btn-sm <?= $maintenance_on ? 'btn-success' : 'btn-warning' ?>" style="color:#000">
                <?= $maintenance_on ? '✅ Matikan Maintenance' : '🔧 Simpan Maintenance' ?>
              </button>
            </form>
          </div>
        </div>

        <!-- Telegram Bot Settings -->
        <div class="c-card mb-3">
          <div class="c-card-header"><span class="c-card-title">🤖 Telegram Bot Notifikasi</span></div>
          <div class="c-card-body">
            <form method="POST" class="mb-3">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_telegram">
              <div class="c-form-group"><label class="c-label">Bot Token <small style="color:#888">(dari @BotFather)</small></label>
                <input type="text" name="tg_bot_token" class="c-form-control" value="<?= htmlspecialchars($s('tg_bot_token')) ?>" placeholder="123456789:ABCdefGHI..."></div>
              <div class="c-form-group"><label class="c-label">Chat ID Admin <small style="color:#888">(ID grup atau ID admin)</small></label>
                <input type="text" name="tg_chat_id" class="c-form-control" value="<?= htmlspecialchars($s('tg_chat_id')) ?>" placeholder="-100123456789"></div>
              <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Telegram</button>
            </form>
            <div class="d-flex gap-2 flex-wrap mb-2">
              <form method="POST">
                <?= csrf_field() ?><input type="hidden" name="action" value="sync_tg_webhook">
                <button type="submit" class="btn btn-sm btn-info text-white">🔄 Sync Webhook</button>
              </form>
              <form method="POST">
                <?= csrf_field() ?><input type="hidden" name="action" value="auto_create_topics">
                <button type="submit" class="btn btn-sm btn-success text-white" onclick="return confirm('Peringatan: Grup tujuan harus berupa Supergroup yang fitur Forum-nya AKTIF. Apakah Anda yakin ingin membuat SEMUA topic notifikasi secara otomatis di grup tersebut?')">⚙️ Auto-Create Semua Topics</button>
              </form>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <?php
              $tg_topics = [
                  'log' => '📝 Log Aktivitas',
                  'wd' => '💸 Withdraw',
                  'depo' => '💰 Deposit',
                  'user_baru' => '🆕 User Baru',
                  'permintaan' => '💬 Permintaan',
                  'misi' => '🎯 Klaim Misi',
                  'abuse' => '🚨 Deteksi Abuse',
                  'threads' => '🧵 Promosi Threads'
              ];
              foreach ($tg_topics as $tk => $tn): ?>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="auto_create_topics">
                <input type="hidden" name="topic_key" value="<?= $tk ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Buat topic <?= $tn ?> di grup?')">+ <?= $tn ?></button>
              </form>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      
      <div class="col-md-6">
        <!-- Change password -->
        <div class="c-card">
          <div class="c-card-header"><span class="c-card-title">🔐 Ganti Password Admin</span></div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
              <div class="c-form-group"><label class="c-label">Password Lama</label>
                <input type="password" name="old_password" class="c-form-control" required></div>
              <div class="c-form-group"><label class="c-label">Password Baru</label>
                <input type="password" name="new_password" class="c-form-control" required></div>
              <button type="submit" class="btn btn-sm btn-secondary">Ganti Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div><!-- end tab-system -->

</div>

<!-- System info -->
<div class="c-card mt-3">
  <div class="c-card-header"><span class="c-card-title">ℹ️ Info Sistem</span></div>
  <div class="c-card-body">
    <div class="row g-2" style="font-size:13px">
      <div class="col-6 col-md-3"><span style="color:#666">PHP Version</span><div style="font-weight:700"><?= PHP_VERSION ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Database</span><div style="font-weight:700"><?= $_ENV['DB_DATABASE'] ?? 'tonton' ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Server Time</span><div style="font-weight:700"><?= date('d M Y H:i') ?> <small style="color:#888;font-weight:400"><?= date_default_timezone_get() ?></small></div></div>
      <?php try {
        $sz = (int)$pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Watch History</span><div style='font-weight:700'>".number_format($sz)." baris</div></div>";
      } catch(\Throwable) {} ?>
      <?php try {
        $ref_cnt = (int)$pdo->query("SELECT COUNT(*) FROM referral_commissions")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Komisi Referral</span><div style='font-weight:700'>".number_format($ref_cnt)." transaksi</div></div>";
      } catch(\Throwable) {} ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
