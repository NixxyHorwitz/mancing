<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('deposits');
csrf_enforce();
require_once dirname(__DIR__) . '/lib/forwarder.php';

$flash = $flashType = '';

// Handle Clear/Truncate Logs Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'regen_forwarder_token') {
        forwarder_token($pdo, true);
        $flash = 'Token forwarder baru dibuat. Masukkan token baru ke aplikasi di HP.';
    }
    if ($action === 'clear_qris_logs') {
        try {
            $pdo->query("TRUNCATE TABLE payment_gateway_logs");
            $flash = "Seluruh log payment gateway berhasil dibersihkan!";
        } catch (\Throwable $e) {
            $flash = "Gagal membersihkan log: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Fetch logs along with associated deposit and user username
$logs = $pdo->query("
    SELECT pgl.*, d.amount as depo_amount, d.user_id, u.username 
    FROM payment_gateway_logs pgl 
    LEFT JOIN deposits d ON d.id = pgl.deposit_id 
    LEFT JOIN users u ON u.id = d.user_id 
    ORDER BY pgl.created_at DESC
    LIMIT 500
")->fetchAll();

// Status aplikasi forwarder di HP
$fwd_token = forwarder_token($pdo);
$fwd_base  = preg_replace('#^http:#', 'https:', rtrim(base_url(''), '/'));
$hb = $pdo->query("SELECT *, UNIX_TIMESTAMP(created_at) ts FROM forwarder_heartbeats ORDER BY id DESC LIMIT 1")->fetch() ?: null;
$hb_data = $hb ? (json_decode((string)$hb['payload_text'], true) ?: []) : [];
$hb_age = $hb ? time() - (int)$hb['ts'] : null;
$hb_online = $hb && $hb_age <= ((int)$hb['interval_minutes'] * 2 + 1) * 60;
$fwd_today = $pdo->query("SELECT SUM(status IN ('matched','matched_expired')) ok, SUM(status = 'unmatched') um, COUNT(*) n FROM payment_gateway_logs WHERE created_at >= CURDATE()")->fetch();

$pageTitle  = 'Log Payment QRIS';
$activePage = 'qris_logs';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">🤖 Log Payment Gateway QRIS</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Audit pencocokan otomatis transaksi QRIS dengan deposit pending user</div>
  </div>
  <?php if (!empty($logs)): ?>
  <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus seluruh log payment gateway?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_qris_logs">
    <button type="submit" class="btn btn-sm btn-danger">🗑️ Bersihkan Semua Log</button>
  </form>
  <?php endif; ?>
</div>

<div class="c-card mb-3">
  <div class="c-card-header d-flex justify-content-between align-items-center"><span class="c-card-title">📱 Aplikasi Cuan Forwarder (HP)</span>
    <span class="badge" style="background:<?= $hb_online ? '#16a34a' : '#b91c1c' ?>;padding:5px 9px;border-radius:7px"><?= $hb ? ($hb_online ? '● ONLINE' : '● OFFLINE') : 'Belum terhubung' ?></span></div>
  <div class="c-card-body p-3" style="font-size:13px">
    <div class="row g-3">
      <div class="col-md-6">
        <div style="color:#888;font-size:11px;font-weight:700;text-transform:uppercase">Alamat server (isi di aplikasi)</div>
        <div class="d-flex gap-2 align-items-center mb-2"><code style="font-size:13px" id="fwd-url"><?= htmlspecialchars($fwd_base) ?></code>
          <button type="button" class="btn btn-sm btn-outline-info py-0" onclick="navigator.clipboard.writeText(document.getElementById('fwd-url').textContent)">Salin</button></div>
        <div style="color:#888;font-size:11px;font-weight:700;text-transform:uppercase">Token rahasia</div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <code id="fwd-tok" data-tok="<?= htmlspecialchars($fwd_token) ?>" style="font-size:13px"><?= htmlspecialchars(substr($fwd_token, 0, 8)) ?>••••••••</code>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="const e=document.getElementById('fwd-tok');e.textContent=e.dataset.tok">Tampilkan</button>
          <button type="button" class="btn btn-sm btn-outline-info py-0" onclick="navigator.clipboard.writeText(document.getElementById('fwd-tok').dataset.tok)">Salin</button>
          <form method="POST" class="d-inline" onsubmit="return confirm('Buat token baru? Aplikasi di HP harus diisi token baru.')"><?= csrf_field() ?><input type="hidden" name="action" value="regen_forwarder_token"><button class="btn btn-sm btn-outline-danger py-0">Ganti token</button></form>
        </div>
        <div style="color:#666;font-size:11px;margin-top:6px">Callback: <code>/qris_callback.php</code> · Heartbeat: <code>/api/heartbeat.php</code> · header <code>X-Forwarder-Token</code></div>
      </div>
      <div class="col-md-6">
        <div class="row g-2">
          <div class="col-6"><div style="color:#888;font-size:11px">Heartbeat terakhir</div><b><?= $hb ? ($hb_age < 60 ? $hb_age . ' detik lalu' : floor($hb_age / 60) . ' menit lalu') : '-' ?></b></div>
          <div class="col-6"><div style="color:#888;font-size:11px">Perangkat</div><b><?= htmlspecialchars($hb['device_info'] ?? '-') ?></b></div>
          <div class="col-6"><div style="color:#888;font-size:11px">Baterai</div><b><?= isset($hb_data['battery']) ? (int)$hb_data['battery'] . '%' . (!empty($hb_data['charging']) ? ' ⚡' : '') : '-' ?></b></div>
          <div class="col-6"><div style="color:#888;font-size:11px">Izin baca notifikasi</div><b><?= isset($hb_data['listener']) ? ($hb_data['listener'] ? '✅ Aktif' : '❌ Mati') : '-' ?></b></div>
          <div class="col-6"><div style="color:#888;font-size:11px">Antrian belum terkirim</div><b><?= (int)($hb_data['queue'] ?? 0) ?></b></div>
          <div class="col-6"><div style="color:#888;font-size:11px">Hari ini</div><b><?= (int)$fwd_today['ok'] ?> cocok · <?= (int)$fwd_today['um'] ?> tidak cocok</b></div>
          <div class="col-12"><div style="color:#888;font-size:11px">Aplikasi dipantau</div><b><?= htmlspecialchars(implode(', ', (array)($hb_data['apps'] ?? [])) ?: '-') ?></b></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Riwayat Log Callback Gateway</span></div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>ID Log</th>
            <th>Tanggal Masuk</th>
            <th>Nominal Terdeteksi</th>
            <th>Status Pencocokan</th>
            <th>Deposit Terhubung</th>
            <th class="text-end">Payload</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr style="vertical-align: middle;">
              <td><code>#<?= $log['id'] ?></code></td>
              <td style="color:#aaa;"><?= htmlspecialchars($log['created_at']) ?></td>
              <td style="font-weight: 700; color: #fff;"><?= format_rp((float)$log['extracted_amount']) ?></td>
              <td>
                <?php if ($log['status'] === 'matched'): ?>
                  <span class="badge b-success" style="padding: 4px 8px; border-radius: 6px;">Matched ✅</span>
                <?php elseif ($log['status'] === 'matched_expired'): ?>
                  <span class="badge b-success" style="padding: 4px 8px; border-radius: 6px;">Matched (telat) ✅</span>
                <?php elseif ($log['status'] === 'unmatched'): ?>
                  <span class="badge b-warn" style="padding: 4px 8px; border-radius: 6px; background:#FF6B35; color:#fff">Unmatched ⏳</span>
                <?php elseif ($log['status'] === 'disabled'): ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#444; color:#aaa">Disabled ❌</span>
                <?php else: ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#ef4444; color:#fff">Failed ⚠️</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($log['deposit_id'] && $log['user_id']): ?>
                  <a href="/console/user_txns?uid=<?= $log['user_id'] ?>" class="text-info" style="text-decoration:none;font-weight:700">
                    👤 @<?= htmlspecialchars($log['username']) ?> (Depo #<?= $log['deposit_id'] ?>)
                  </a>
                <?php else: ?>
                  <span style="color:#555">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-info text-white" style="border:none;border-radius:6px;font-size:11px"
                        onclick="showPayloadModal(<?= htmlspecialchars(json_encode($log['payload'])) ?>)">
                  🔍 Lihat Payload
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payload Raw View Modal -->
<div class="modal fade" id="payloadModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <div class="modal-header border-0">
        <h6 class="modal-title fw-bold">📋 Raw Payload Gateway</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="raw-payload-content" style="background:#0f1117;border:1px solid #1f2235;color:#9cdcfe;font-family:monospace;font-size:12px;padding:12px;border-radius:8px;max-height:350px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
function showPayloadModal(rawPayload) {
  try {
    const parsed = JSON.parse(rawPayload);
    document.getElementById('raw-payload-content').textContent = JSON.stringify(parsed, null, 2);
  } catch (e) {
    document.getElementById('raw-payload-content').textContent = rawPayload;
  }
  new bootstrap.Modal(document.getElementById('payloadModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
