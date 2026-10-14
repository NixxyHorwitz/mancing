<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('seo');
csrf_enforce();

$flash = $flashType = '';
global $pdo;
$s = fn($k,$d='') => setting($pdo,$k,$d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_seo';

    // ── Favicon Base64 Upload ─────────────────────────────────
    if ($action === 'upload_favicon_b64' && !empty($_POST['b64_data'])) {
        $b64 = $_POST['b64_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
            $data = substr($b64, strpos($b64, ',') + 1);
            $type = strtolower($type[1]);
            $data = base64_decode($data);
            if ($data === false || strlen($data) > 15 * 1024 * 1024) {
                $flash = '❌ File terlalu besar atau corrupt. Maksimal 15MB.'; $flashType = 'error';
            } else {
                $tmpFile = tempnam(sys_get_temp_dir(), 'fav');
                file_put_contents($tmpFile, $data);
                
                $src = null;
                $info = @getimagesize($tmpFile);
                if ($info) {
                    $src = match((int)$info[2]) {
                        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
                        IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
                        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                        IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
                        default        => null,
                    };
                }
                
                if (!$src) {
                    $flash = '❌ File gambar tidak valid.'; $flashType = 'error';
                } else {
                    $out = imagecreatetruecolor(64, 64);
                    imagealphablending($out, false);
                    imagesavealpha($out, true);
                    $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
                    imagefill($out, 0, 0, $transparent);
                    imagecopyresampled($out, $src, 0, 0, 0, 0, 64, 64, imagesx($src), imagesy($src));
                    
                    $favPath = dirname(__DIR__) . '/assets/favicon.png';
                    if (@imagepng($out, $favPath, 7)) {
                        setting_set($pdo, 'favicon_path', '/assets/favicon.png');
                        setting_set($pdo, 'site_logo', '/assets/favicon.png');
                        $flash = '✅ Favicon & Logo berhasil diupload dan dikompres ke 64×64px!';
                    } else {
                        $flash = '❌ Gagal menyimpan favicon ke /assets/. Cek permission.'; $flashType = 'error';
                    }
                }
                @unlink($tmpFile);
            }
        } else {
            $flash = '❌ Format base64 tidak dikenali.'; $flashType = 'error';
        }
    }
    
    // ── Favicon upload (legacy) ──────────────────────────────
    if ($action === 'upload_favicon' && !empty($_FILES['favicon']['tmp_name'])) {
        $maxBytes = 15 * 1024 * 1024; // 15MB
        $tmpFile  = $_FILES['favicon']['tmp_name'];
        $origName = $_FILES['favicon']['name'];
        $fileSize = $_FILES['favicon']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($fileSize > $maxBytes) {
            $flash = '❌ File terlalu besar. Maksimal 5MB.'; $flashType = 'error';
        } elseif (!in_array($ext, ['png','jpg','jpeg','webp','gif','ico'])) {
            $flash = '❌ Format tidak didukung. Gunakan PNG/JPG/WEBP.'; $flashType = 'error';
        } elseif ($ext === 'ico') {
            $favDir  = dirname(__DIR__) . '/assets/';
            $favPath = $favDir . 'favicon.ico';
            if (move_uploaded_file($tmpFile, $favPath)) {
                setting_set($pdo, 'favicon_path', '/assets/favicon.ico');
                $flash = '✅ Favicon (.ico) berhasil diupload!';
            } else {
                $flash = '❌ Gagal menyimpan favicon ke /assets/. Cek permission folder.';
                $flashType = 'error';
            }
        } elseif (!function_exists('imagecreatefrompng')) {
            $flash = '❌ GD extension tidak tersedia di server.'; $flashType = 'error';
        } else {
            // Try to load image — first by getimagesize(), fallback by extension
            $src = null;
            $info = @getimagesize($tmpFile);
            if ($info) {
                $src = match((int)$info[2]) {
                    IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
                    IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
                    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                    IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
                    default        => null,
                };
            }
            // Extension-based fallback (handles mis-detected types)
            if (!$src) {
                $src = match($ext) {
                    'jpg','jpeg' => @imagecreatefromjpeg($tmpFile),
                    'png'        => @imagecreatefrompng($tmpFile),
                    'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                    'gif'        => @imagecreatefromgif($tmpFile),
                    default      => null,
                };
            }

            if (!$src) {
                $flash = '❌ File gambar tidak dapat dibaca. Pastikan file valid dan tidak corrupt.';
                $flashType = 'error';
            } else {
                // Resize to 64×64 with transparency support
                $out = imagecreatetruecolor(64, 64);
                imagealphablending($out, false);
                imagesavealpha($out, true);
                $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
                imagefill($out, 0, 0, $transparent);
                imagecopyresampled($out, $src, 0, 0, 0, 0, 64, 64, imagesx($src), imagesy($src));

                $favDir  = dirname(__DIR__) . '/assets/';
                $favPath = $favDir . 'favicon.png';
                if (@imagepng($out, $favPath, 7)) {
                    setting_set($pdo, 'favicon_path', '/assets/favicon.png');
                    setting_set($pdo, 'site_logo', '/assets/favicon.png');
                    $flash = '✅ Favicon & Logo berhasil diupload dan dikompres ke 64×64px!';
                } else {
                    $flash = '❌ Gagal menyimpan favicon ke /assets/. Cek permission folder.';
                    $flashType = 'error';
                }
            }
        }
    }

    // ── Save SEO meta settings ─────────────────────────────────
    if ($action === 'save_seo') {
        $keys = ['seo_title','seo_description','seo_og_image','seo_robots',
                 'seo_keywords','seo_author','seo_twitter_card',
                 'seo_og_title','seo_og_description','seo_og_type'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) setting_set($pdo, $k, trim($_POST[$k]));
        }
        // Robots.txt write
        if (isset($_POST['robots_txt'])) {
            file_put_contents(dirname(__DIR__) . '/robots.txt', trim($_POST['robots_txt']));
        }
        if (!$flash) $flash = '✅ Pengaturan SEO disimpan!';
    }

    // ── OG Image Base64 Upload ────────────────────────────────
    if ($action === 'upload_og_image_b64' && !empty($_POST['b64_data'])) {
        $b64 = $_POST['b64_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
            $data = substr($b64, strpos($b64, ',') + 1);
            $type = strtolower($type[1]);
            $data = base64_decode($data);
            if ($data === false || strlen($data) > 15 * 1024 * 1024) {
                $flash = '❌ File terlalu besar atau corrupt. Maksimal 15MB.'; $flashType = 'error';
            } else {
                $tmpFile = tempnam(sys_get_temp_dir(), 'og_');
                file_put_contents($tmpFile, $data);
                
                $src = null;
                $info = @getimagesize($tmpFile);
                if ($info) {
                    $src = match((int)$info[2]) {
                        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
                        IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
                        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                        default        => null,
                    };
                }
                
                if (!$src) {
                    $flash = '❌ File gambar tidak valid.'; $flashType = 'error';
                } else {
                    // Kompresi dan crop ke 1200x630 (Standar OG Image)
                    $targetW = 1200;
                    $targetH = 630;
                    $origW = imagesx($src);
                    $origH = imagesy($src);
                    
                    $out = imagecreatetruecolor($targetW, $targetH);
                    // Background putih untuk gambar transparan
                    $white = imagecolorallocate($out, 255, 255, 255);
                    imagefill($out, 0, 0, $white);
                    
                    // Hitung rasio untuk crop center
                    $ratio = max($targetW / $origW, $targetH / $origH);
                    $newW = (int)($origW * $ratio);
                    $newH = (int)($origH * $ratio);
                    $offX = (int)(($targetW - $newW) / 2);
                    $offY = (int)(($targetH - $newH) / 2);
                    
                    imagecopyresampled($out, $src, $offX, $offY, 0, 0, $newW, $newH, $origW, $origH);
                    
                    $filename = 'og_image_' . time() . '.jpg';
                    $uploadDir = dirname(__DIR__) . '/uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $dest = $uploadDir . $filename;
                    
                    // Simpan sebagai JPG dengan kualitas 80% (optimal untuk SEO)
                    if (@imagejpeg($out, $dest, 80)) {
                        setting_set($pdo, 'seo_og_image', '/uploads/' . $filename);
                        $flash = '✅ OG Image berhasil diupload, dikompres, dan di-resize ke 1200×630px!';
                    } else {
                        $flash = '❌ Gagal menyimpan file. Cek permission /uploads/.'; $flashType = 'error';
                    }
                    imagedestroy($out);
                    imagedestroy($src);
                }
                @unlink($tmpFile);
            }
        } else {
            $flash = '❌ Format base64 tidak dikenali.'; $flashType = 'error';
        }
    }

    // ── OG Image Upload (legacy) ──────────────────────────────
    if ($action === 'upload_og_image' && !empty($_FILES['og_image']['tmp_name'])) {
        $maxBytes = 15 * 1024 * 1024;
        $tmpFile  = $_FILES['og_image']['tmp_name'];
        $origName = $_FILES['og_image']['name'];
        $fileSize = $_FILES['og_image']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($fileSize > $maxBytes) {
            $flash = '❌ File terlalu besar. Maksimal 5MB.'; $flashType = 'error';
        } elseif (!in_array($ext, ['png','jpg','jpeg','webp'])) {
            $flash = '❌ Format tidak didukung. Gunakan PNG/JPG/WEBP.'; $flashType = 'error';
        } else {
            $filename  = 'og_image_' . time() . '.' . $ext;
            $uploadDir = dirname(__DIR__) . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $dest = $uploadDir . $filename;
            if (move_uploaded_file($tmpFile, $dest)) {
                setting_set($pdo, 'seo_og_image', '/uploads/' . $filename);
                $flash = '✅ OG Image berhasil diupload!';
            } else {
                $flash = '❌ Gagal menyimpan file. Cek permission folder /uploads/.'; $flashType = 'error';
            }
        }
    }
}

$robots_txt_content = '';
$robots_path = dirname(__DIR__) . '/robots.txt';
if (file_exists($robots_path)) {
    $robots_txt_content = file_get_contents($robots_path);
} else {
    $robots_txt_content = "User-agent: *\nAllow: /\nDisallow: /console/\nDisallow: /auth/\nDisallow: /uploads/\n\nSitemap: " . base_url('sitemap.xml');
}

$pageTitle  = 'SEO Management';
$activePage = 'seo';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h5 class="mb-0 fw-bold">🔍 SEO Management</h5>
  <div style="font-size:12px;color:#666;margin-top:2px">Kelola meta tag, Open Graph, dan robots.txt</div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<form action="/console/seo" method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_seo">
<div class="row g-3">

  <!-- Meta Tags -->
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📄 Meta Tags Global</span></div>
      <div class="c-card-body">
        <div class="c-form-group">
          <label class="c-label">Site Title (Title Tag)</label>
          <input type="text" name="seo_title" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_title','MancingCuan')) ?>"
                 placeholder="MancingCuan — Nonton Video & Minigame Penghasil Saldo Cuan">
          <div style="font-size:11px;color:#666;margin-top:3px">Rekomendasi: 50–60 karakter</div>
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Description</label>
          <textarea name="seo_description" class="c-form-control" rows="3"
            placeholder="Deskripsi singkat website..."><?= htmlspecialchars($s('seo_description')) ?></textarea>
          <div style="font-size:11px;color:#666;margin-top:3px">Rekomendasi: 120–158 karakter</div>
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Keywords (opsional)</label>
          <input type="text" name="seo_keywords" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_keywords')) ?>"
                 placeholder="nonton video reward, tonton dapat uang, mancing cuan, ...">
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Author</label>
           <input type="text" name="seo_author" class="c-form-control"
                  value="<?= htmlspecialchars($s('seo_author','MancingCuan Official')) ?>">
        </div>
        <div class="c-form-group">
          <label class="c-label">Robots</label>
          <select name="seo_robots" class="c-form-control">
            <?php foreach (['index,follow','noindex,nofollow','index,nofollow','noindex,follow'] as $r): ?>
            <option value="<?= $r ?>" <?= $s('seo_robots','index,follow')===$r?'selected':'' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- OG / Social -->
  <div class="col-md-6">
    <div class="c-card mb-3">
      <div class="c-card-header"><span class="c-card-title">📱 Open Graph & Social</span></div>
      <div class="c-card-body">
        <div class="c-form-group">
          <label class="c-label">OG Title (opsional)</label>
          <input type="text" name="seo_og_title" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_og_title')) ?>"
                 placeholder="Default: mengikuti Site Title">
        </div>
        <div class="c-form-group">
          <label class="c-label">OG Description (opsional)</label>
          <textarea name="seo_og_description" class="c-form-control" rows="2"
                    placeholder="Default: mengikuti Meta Description"><?= htmlspecialchars($s('seo_og_description')) ?></textarea>
        </div>
        <div class="c-form-group">
          <label class="c-label">OG Type</label>
          <select name="seo_og_type" class="c-form-control">
            <option value="website" <?= $s('seo_og_type','website')==='website'?'selected':'' ?>>website</option>
            <option value="article" <?= $s('seo_og_type')==='article'?'selected':'' ?>>article</option>
            <option value="profile" <?= $s('seo_og_type')==='profile'?'selected':'' ?>>profile</option>
          </select>
        </div>

        <div class="c-form-group">
          <label class="c-label">OG Image URL</label>
          <input type="text" name="seo_og_image" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_og_image')) ?>"
                 placeholder="<?= htmlspecialchars(base_url('og-image.jpg')) ?>">
          <div style="font-size:11px;color:#666;margin-top:3px">Ideal: 1200×630px</div>
        </div>
        <?php $og = $s('seo_og_image'); if ($og): ?>
        <img src="<?= htmlspecialchars($og) ?>" alt="OG Preview" style="width:100%;border-radius:8px;border:1px solid #1f2235;margin-top:8px">
        <?php endif; ?>
        <div class="c-form-group" style="margin-top:12px">
          <label class="c-label">Twitter Card Type</label>
          <select name="seo_twitter_card" class="c-form-control">
            <option value="summary_large_image" <?= $s('seo_twitter_card')==='summary_large_image'?'selected':'' ?>>summary_large_image</option>
            <option value="summary" <?= $s('seo_twitter_card','summary_large_image')==='summary'?'selected':'' ?>>summary</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <div class="c-card" style="border-color:#2563eb22">
      <div class="c-card-header"><span class="c-card-title">👁 SERP Preview</span></div>
      <div class="c-card-body">
        <div style="font-family:Arial,sans-serif;max-width:400px">
          <div style="font-size:12px;color:#666;margin-bottom:2px"><?= htmlspecialchars(rtrim(base_url(), '/')) ?></div>
          <div style="font-size:17px;color:#1a73e8;font-weight:500;margin-bottom:4px" id="preview-title"><?= htmlspecialchars($s('seo_title','MancingCuan')) ?></div>
          <div style="font-size:13px;color:#555;line-height:1.4" id="preview-desc"><?= htmlspecialchars($s('seo_description','')) ?></div>
        </div>
        <script>
        document.querySelector('[name="seo_title"]').addEventListener('input', function(){
          document.getElementById('preview-title').textContent = this.value || 'Title';
        });
        document.querySelector('[name="seo_description"]').addEventListener('input', function(){
          document.getElementById('preview-desc').textContent = this.value || 'Description';
        });
        </script>
      </div>
    </div>
  </div>

  <!-- Robots.txt -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🤖 robots.txt</span>
        <span style="font-size:11px;color:#666">Edit langsung akan disimpan ke file robots.txt</span>
      </div>
      <div class="c-card-body">
        <textarea name="robots_txt" class="c-form-control" rows="8"
          style="font-family:monospace;font-size:12px"><?= htmlspecialchars($robots_txt_content) ?></textarea>
    </div>
  </div>

  <div class="col-12">
    <button type="submit" class="btn btn-sm text-white px-4" style="background:var(--brand)">💾 Simpan Semua SEO Settings</button>
  </div>
</div>
</form>

<div class="row g-3 mt-1">
  <!-- OG Image Upload -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🖼 Upload OG Image</span>
        <span style="font-size:11px;color:#666">Disimpan ke /uploads/ · Maks 15MB · Ideal 1200×630px</span>
      </div>
      <div class="c-card-body">
        <div class="row align-items-center g-3">
          <!-- Preview -->
          <div class="col-auto">
            <?php $og = $s('seo_og_image'); if ($og): ?>
            <div style="width:120px;height:63px;border:1.5px solid #1f2235;border-radius:8px;overflow:hidden;background:#111">
              <img src="<?= htmlspecialchars($og) ?>?v=<?= time() ?>" style="width:100%;height:100%;object-fit:cover" alt="OG Preview">
            </div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Current (1200×630)</div>
            <?php else: ?>
            <div style="width:120px;height:63px;border:1.5px dashed #444;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#555;font-size:22px">🖼</div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Belum ada</div>
            <?php endif; ?>
          </div>
          <div class="col">
            <form id="form-og" action="/console/seo" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload_og_image_b64">
              <input type="hidden" name="b64_data" id="b64-og-data">
              <input type="file" id="file-og" accept="image/png,image/jpeg,image/webp"
                     class="c-form-control" style="flex:1;min-width:180px" required>
              <button type="button" onclick="uploadBase64('file-og', 'b64-og-data', 'form-og', this)" class="btn btn-sm text-white" style="background:var(--brand);white-space:nowrap">⬆️ Upload OG Image</button>
            </form>
            <div style="font-size:11px;color:#666;margin-top:6px">Format: PNG, JPG, WEBP &mdash; Setelah upload, URL otomatis tersimpan ke pengaturan SEO</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- Favicon -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🖼️ Favicon Website</span>
        <span style="font-size:11px;color:#666">Akan dikompres otomatis ke 64×64px · Maks 15MB</span>
      </div>
      <div class="c-card-body">
        <div class="row align-items-center g-3">
          <!-- Preview -->
          <div class="col-auto">
            <?php $fav = $s('favicon_path'); if ($fav): ?>
            <div style="width:64px;height:64px;border:1.5px solid #1f2235;border-radius:8px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center">
              <img src="<?= htmlspecialchars($fav) ?>?v=<?= time() ?>" style="width:100%;height:100%;object-fit:contain" alt="Favicon">
            </div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Current</div>
            <?php else: ?>
            <div style="width:64px;height:64px;border:1.5px dashed #444;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#555;font-size:22px">
              🌐
            </div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Belum ada</div>
            <?php endif; ?>
          </div>
          <div class="col">
            <form id="form-fav" action="/console/seo" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload_favicon_b64">
              <input type="hidden" name="b64_data" id="b64-fav-data">
              <input type="file" id="file-fav" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon"
                     class="c-form-control" style="flex:1;min-width:180px" required>
              <button type="button" onclick="uploadBase64('file-fav', 'b64-fav-data', 'form-fav', this)" class="btn btn-sm text-white" style="background:var(--brand);white-space:nowrap">⬆️ Upload Favicon</button>
            </form>
            <div style="font-size:11px;color:#666;margin-top:6px">
              Format: PNG, JPG, WEBP, GIF · Sistem akan resize &amp; kompres otomatis ke 64×64px PNG
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function uploadBase64(fileId, dataId, formId, btn) {
    const fileInput = document.getElementById(fileId);
    if (!fileInput.files || !fileInput.files[0]) {
        alert("Silakan pilih file gambar terlebih dahulu!");
        return;
    }
    const file = fileInput.files[0];
    if (file.size > 15 * 1024 * 1024) {
        alert("Ukuran file maksimal 15MB!");
        return;
    }
    btn.disabled = true;
    btn.textContent = "⏳ Memproses...";
    
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById(dataId).value = e.target.result;
        document.getElementById(formId).submit();
    };
    reader.onerror = function() {
        alert("Gagal membaca file!");
        btn.disabled = false;
        btn.textContent = "⬆️ Upload";
    };
    reader.readAsDataURL(file);
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
