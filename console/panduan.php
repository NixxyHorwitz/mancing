<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('panduan');
csrf_enforce();

$flash = $flashType = '';
global $pdo;
$s = fn($k, $d='') => setting($pdo, $k, $d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save popup settings
    if ($action === 'save_popup') {
        $keys = ['popup_enabled','popup_title','popup_body','popup_cta_text',
                 'popup_cta_url','popup_delay','popup_reset_hours'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                $val = trim((string)$_POST[$k]);
                // Hilangkan karakter ?? dan emoji yang tidak didukung
                $val = preg_replace('/^\?+\s*/', '', $val);
                setting_set($pdo, $k, $val);
            }
        }
        $flash = 'Pengaturan popup berhasil disimpan!';
    }

    // Save panduan content
    if ($action === 'save_panduan') {
        $keys = ['panduan_intro','panduan_step1','panduan_step2','panduan_step3','panduan_step4',
                 'panduan_faq_custom','panduan_cta_text','panduan_cta_url'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) setting_set($pdo, $k, trim($_POST[$k]));
        }
        $flash = '✅ Konten halaman Panduan disimpan!';
    }
}

$pageTitle  = 'Panduan & Popup';
$activePage = 'panduan';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h5 class="mb-0 fw-bold">📖 Panduan & Popup</h5>
  <div style="font-size:12px;color:#666;margin-top:2px">Kelola konten halaman panduan dan popup ajakan di Beranda</div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">

  <!-- ── Popup Settings ── -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🔔 Popup Ajakan di Beranda</span>
        <span style="font-size:11px;color:#666">Muncul otomatis saat user pertama kali buka Beranda</span>
      </div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_popup">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="c-form-group">
                <label class="c-label">Status Popup</label>
                <select name="popup_enabled" class="c-form-control">
                  <option value="1" <?= $s('popup_enabled','1')==='1'?'selected':'' ?>>✅ Aktif</option>
                  <option value="0" <?= $s('popup_enabled','1')==='0'?'selected':'' ?>>❌ Nonaktif</option>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group">
                <label class="c-label">Delay Muncul (milidetik)</label>
                <input type="number" name="popup_delay" class="c-form-control"
                       value="<?= htmlspecialchars($s('popup_delay','1500')) ?>"
                       min="0" max="10000" step="100" placeholder="1500">
                <div style="font-size:11px;color:#555;margin-top:3px">1500 = 1.5 detik setelah halaman terbuka</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group">
                <label class="c-label">Reset Popup Setiap (jam)</label>
                <input type="number" name="popup_reset_hours" class="c-form-control"
                       value="<?= htmlspecialchars($s('popup_reset_hours','0')) ?>"
                       min="0" max="720" step="1" placeholder="0">
                <div style="font-size:11px;color:#555;margin-top:3px">0 = hanya muncul sekali (tidak reset). Contoh: 24 = setiap hari</div>
              </div>
            </div>
            <div class="col-12">
              <div class="c-form-group">
                <label class="c-label">Judul Popup</label>
                <input type="text" name="popup_title" class="c-form-control"
                       value="<?= htmlspecialchars(preg_replace('/^\?+\s*/', '', $s('popup_title','Hei, sudah baca panduan?'))) ?>"
                       placeholder="Hei, sudah baca panduan?">
              </div>
            </div>
            <div class="col-12">
              <div class="c-form-group">
                <label class="c-label">Isi / Body Popup</label>
                <textarea name="popup_body" class="c-form-control" rows="3"
                          placeholder="Teks yang muncul di dalam popup..."><?= htmlspecialchars(str_replace('??', '', $s('popup_body','Biar makin lancar dapat reward, yuk baca dulu cara kerja MancingCuan! Dari cara tonton, jenis saldo, sampai tips withdraw.'))) ?></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Teks Tombol CTA</label>
                <input type="text" name="popup_cta_text" class="c-form-control"
                       value="<?= htmlspecialchars(preg_replace('/^\?+\s*/', '', $s('popup_cta_text','Baca Panduan Sekarang'))) ?>"
                       placeholder="Baca Panduan Sekarang">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">URL Tombol CTA</label>
                <input type="text" name="popup_cta_url" class="c-form-control"
                       value="<?= htmlspecialchars($s('popup_cta_url','/panduan')) ?>"
                       placeholder="/panduan">
              </div>
            </div>
            <div class="col-12">
              <!-- Live Preview -->
              <div style="background:#1a1d2e;border:1px solid #2a2d40;border-radius:10px;padding:16px;margin-top:4px">
                <div style="font-size:11px;color:#666;margin-bottom:10px;font-weight:600">Preview Popup (tampilan di Beranda)</div>
                <div style="background:#fff;color:#111;border-radius:16px 16px 0 0;padding:18px;max-width:380px;border:2px solid #111;box-shadow:0 -4px 0 #111;">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <div style="font-weight:900;font-size:14px" id="prev_title"><?= htmlspecialchars(preg_replace('/^\?+\s*/', '', $s('popup_title','Hei, sudah baca panduan?'))) ?></div>
                    <span style="color:#999;font-size:18px">✕</span>
                  </div>
                  <div style="font-size:12px;color:#555;margin-bottom:12px;line-height:1.5" id="prev_body"><?= htmlspecialchars(str_replace('??', '', $s('popup_body','Biar makin lancar dapat reward, yuk baca dulu cara kerja MancingCuan!'))) ?></div>
                  <div style="display:flex;gap:8px">
                    <div style="background:#111;color:#fff;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:800;flex:1;text-align:center" id="prev_cta"><?= htmlspecialchars(preg_replace('/^\?+\s*/', '', $s('popup_cta_text','Baca Panduan Sekarang'))) ?></div>
                    <div style="border:2px solid #111;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:700">Nanti</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-sm text-white px-4" style="background:var(--brand)">💾 Simpan Popup</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Panduan Content ── -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">📄 Konten Halaman Panduan</span>
        <a href="/panduan" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:11px">👁 Lihat Halaman →</a>
      </div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_panduan">
          <div class="row g-3">
            <div class="col-12">
              <div class="c-form-group">
                <label class="c-label">Teks Intro / Subjudul</label>
                <input type="text" name="panduan_intro" class="c-form-control"
                       value="<?= htmlspecialchars($s('panduan_intro','Cara kerja platform reward video ini')) ?>"
                       placeholder="Cara kerja platform reward video ini">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Langkah 1 — Deskripsi</label>
                <textarea name="panduan_step1" class="c-form-control" rows="2"><?= htmlspecialchars($s('panduan_step1','Buat akun gratis, tidak perlu verifikasi ribet. Langsung bisa mulai tonton.')) ?></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Langkah 2 — Deskripsi</label>
                <textarea name="panduan_step2" class="c-form-control" rows="2"><?= htmlspecialchars($s('panduan_step2','Setiap video yang ditonton hingga selesai akan otomatis memberikan reward ke Saldo Penarikan kamu.')) ?></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Langkah 3 — Deskripsi</label>
                <textarea name="panduan_step3" class="c-form-control" rows="2"><?= htmlspecialchars($s('panduan_step3','Reward terkumpul di Saldo Penarikan. Cek progresmu di halaman Beranda kapan saja.')) ?></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Langkah 4 — Deskripsi</label>
                <textarea name="panduan_step4" class="c-form-control" rows="2"><?= htmlspecialchars($s('panduan_step4','Minimal withdraw sesuai pengaturan. Proses 1–3 hari kerja ke rekening/e-wallet pilihanmu.')) ?></textarea>
              </div>
            </div>
            <div class="col-12">
              <div class="c-form-group">
                <label class="c-label">FAQ Tambahan (opsional)</label>
                <textarea name="panduan_faq_custom" class="c-form-control" rows="4"
                          placeholder="Pertanyaan umum tambahan yang ingin ditampilkan di halaman panduan..."><?= htmlspecialchars($s('panduan_faq_custom','')) ?></textarea>
                <div style="font-size:11px;color:#555;margin-top:3px">Teks bebas yang akan tampil di bagian bawah FAQ</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Teks Tombol CTA (di bawah panduan)</label>
                <input type="text" name="panduan_cta_text" class="c-form-control"
                       value="<?= htmlspecialchars($s('panduan_cta_text','🎬 Mulai Tonton Sekarang →')) ?>"
                       placeholder="🎬 Mulai Tonton Sekarang →">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">URL Tombol CTA</label>
                <input type="text" name="panduan_cta_url" class="c-form-control"
                       value="<?= htmlspecialchars($s('panduan_cta_url','/videos')) ?>"
                       placeholder="/videos">
              </div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-sm text-white px-4" style="background:var(--brand)">💾 Simpan Konten Panduan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Live preview sync
['popup_title','popup_body','popup_cta_text'].forEach(function(name) {
  const el = document.querySelector('[name="' + name + '"]');
  const map = {popup_title:'prev_title', popup_body:'prev_body', popup_cta_text:'prev_cta'};
  if (!el || !map[name]) return;
  el.addEventListener('input', function() {
    document.getElementById(map[name]).textContent = this.value || '—';
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
