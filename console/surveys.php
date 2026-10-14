<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!staff_can('surveys') && !staff_can('analytics') && !staff_can('users')) {
    staff_require('surveys');
}

$flash = '';
$flashType = '';

// Handle Takeback Response (Batalkan Survei & Tarik Hadiah Saldo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['takeback_id'])) {
    $tbId = (int)$_POST['takeback_id'];
    $tbReason = trim((string)($_POST['takeback_reason'] ?? ''));
    if ($tbReason === '') {
        $tbReason = 'Jawaban survei tidak valid atau diisi asal-asalan.';
    }

    if ($tbId > 0) {
        $pdo->beginTransaction();
        try {
            $sStmt = $pdo->prepare("SELECT * FROM user_surveys WHERE id = ? FOR UPDATE");
            $sStmt->execute([$tbId]);
            $surveyItem = $sStmt->fetch(PDO::FETCH_ASSOC);

            if (!$surveyItem) {
                $pdo->rollBack();
                $flash = "Data survei #{$tbId} tidak ditemukan.";
                $flashType = "danger";
            } elseif (($surveyItem['status'] ?? 'completed') === 'revoked') {
                $pdo->rollBack();
                $flash = "Survei #{$tbId} sudah pernah di-takeback sebelumnya.";
                $flashType = "warning";
            } else {
                $userId = (int)$surveyItem['user_id'];
                $rewardAmount = (float)$surveyItem['reward_amount'];

                // 1. Potong Saldo Tarik (balance_wd) & total_earned pengguna
                $updUser = $pdo->prepare("
                    UPDATE users 
                    SET balance_wd = GREATEST(0, balance_wd - ?),
                        total_earned = GREATEST(0, total_earned - ?)
                    WHERE id = ?
                ");
                $updUser->execute([$rewardAmount, $rewardAmount, $userId]);

                // 2. Tandai status survei sebagai 'revoked'
                $updSurvey = $pdo->prepare("
                    UPDATE user_surveys 
                    SET status = 'revoked',
                        revoked_at = NOW(),
                        revoke_reason = ?
                    WHERE id = ?
                ");
                $updSurvey->execute([$tbReason, $tbId]);

                // 3. Kirim notifikasi sistem ke user
                $notifTitle = "Saldo Survei Ditarik (Takeback) ⚠️";
                $notifMsg = "Hadiah survei sebesar Rp " . number_format($rewardAmount, 0, ',', '.') . " telah ditarik kembali oleh Admin. Alasan: " . $tbReason;
                $insNotif = $pdo->prepare("
                    INSERT INTO notifications 
                    (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at)
                    VALUES (?, ?, 'alert', '⚠️', 'single', ?, '/survey', 'Lihat Status', NOW())
                ");
                $insNotif->execute([$notifTitle, $notifMsg, (string)$userId]);

                $pdo->commit();
                $flash = "Berhasil! Survei #{$tbId} dibatalkan dan saldo reward Rp " . number_format($rewardAmount, 0, ',', '.') . " berhasil ditarik (Takeback) dari akun pengguna.";
                $flashType = "success";
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = "Gagal memproses Takeback: " . $e->getMessage();
            $flashType = "danger";
        }
    }
}

// Handle Delete Response (Opsional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    if ($delId > 0) {
        $delStmt = $pdo->prepare("DELETE FROM user_surveys WHERE id = ?");
        $delStmt->execute([$delId]);
        $flash = "Data respon survei #{$delId} berhasil dihapus.";
        $flashType = "success";
    }
}

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_survei_mancingcuan_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Waktu Submit (WIB)', 'User ID', 'Username', 'Email', 'WhatsApp', 'Sumber Info', 'Rating Kepuasan (1-5)', 'Pengalaman Pengguna', 'Pesan / Keluhan', 'Hadiah Saldo (Rp)', 'Status Survei', 'Waktu Takeback', 'Alasan Takeback', 'IP Address']);

    $qExp = $pdo->query("
        SELECT s.*, u.username, u.email, u.whatsapp 
        FROM user_surveys s 
        LEFT JOIN users u ON u.id = s.user_id 
        ORDER BY s.id DESC
    ");
    while ($r = $qExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $r['id'],
            $r['created_at'],
            $r['user_id'],
            $r['username'] ?? 'User Dihapus',
            $r['email'] ?? '-',
            $r['whatsapp'] ?? '-',
            $r['source_info'],
            $r['satisfaction_rating'],
            $r['experience'],
            $r['feedback_message'],
            $r['reward_amount'],
            $r['status'] ?? 'completed',
            $r['revoked_at'] ?? '-',
            $r['revoke_reason'] ?? '-',
            $r['ip_address'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

// ── Ringkasan Statistik Global ──
$totalRespondents       = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys")->fetchColumn();
$totalActiveRespondents = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys WHERE status = 'completed'")->fetchColumn();
$totalRevoked           = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys WHERE status = 'revoked'")->fetchColumn();
$totalRewards           = (float)$pdo->query("SELECT COALESCE(SUM(reward_amount), 0) FROM user_surveys WHERE status = 'completed'")->fetchColumn();
$avgRating              = (float)$pdo->query("SELECT COALESCE(AVG(satisfaction_rating), 0) FROM user_surveys")->fetchColumn();
$positiveCount          = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys WHERE satisfaction_rating >= 4")->fetchColumn();
$csatPct                = $totalRespondents > 0 ? round(($positiveCount / $totalRespondents) * 100, 1) : 0;

// Distribusi Rating (1 - 5)
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$qRating = $pdo->query("SELECT satisfaction_rating, COUNT(*) as cnt FROM user_surveys GROUP BY satisfaction_rating")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($ratingCounts as $star => $v) {
    if (isset($qRating[$star])) {
        $ratingCounts[$star] = (int)$qRating[$star];
    }
}

// Distribusi Sumber Info
$sourceBreakdown = [
    'Threads'   => 0,
    'Facebook'  => 0,
    'Instagram' => 0,
    'Google'    => 0,
    'Teman'     => 0,
    'Lainnya'   => 0,
];
$allSurveysRaw = $pdo->query("SELECT source_info FROM user_surveys")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allSurveysRaw as $src) {
    $srcLower = strtolower(trim((string)$src));
    if (str_contains($srcLower, 'threads')) {
        $sourceBreakdown['Threads']++;
    } elseif (str_contains($srcLower, 'facebook') || str_contains($srcLower, 'fb')) {
        $sourceBreakdown['Facebook']++;
    } elseif (str_contains($srcLower, 'instagram') || str_contains($srcLower, 'ig')) {
        $sourceBreakdown['Instagram']++;
    } elseif (str_contains($srcLower, 'google')) {
        $sourceBreakdown['Google']++;
    } elseif (str_contains($srcLower, 'teman') || str_contains($srcLower, 'kawan') || str_contains($srcLower, 'rekan')) {
        $sourceBreakdown['Teman']++;
    } else {
        $sourceBreakdown['Lainnya']++;
    }
}

// ── FILTER, SEARCH, & PAGINASI ──
$qSearch = trim((string)($_GET['q'] ?? ''));
$fRating = (int)($_GET['rating'] ?? 0);
$fStatus = trim((string)($_GET['status'] ?? ''));
$fSource = trim((string)($_GET['source'] ?? ''));
$fSort   = trim((string)($_GET['sort'] ?? 'newest'));
$limit   = max(12, min(200, (int)($_GET['limit'] ?? 24)));
if (isset($_GET['limit']) && $_GET['limit'] === 'all') {
    $limit = 1000;
}
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];

if ($qSearch !== '') {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR s.experience LIKE ? OR s.feedback_message LIKE ? OR s.ip_address LIKE ?)";
    $like = "%{$qSearch}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

if ($fRating >= 1 && $fRating <= 5) {
    $where[] = "s.satisfaction_rating = ?";
    $params[] = $fRating;
}

if ($fStatus === 'completed' || $fStatus === 'revoked') {
    $where[] = "s.status = ?";
    $params[] = $fStatus;
}

if ($fSource !== '') {
    $srcL = strtolower($fSource);
    if ($srcL === 'threads') {
        $where[] = "LOWER(s.source_info) LIKE '%threads%'";
    } elseif ($srcL === 'facebook') {
        $where[] = "(LOWER(s.source_info) LIKE '%facebook%' OR LOWER(s.source_info) LIKE '%fb%')";
    } elseif ($srcL === 'instagram') {
        $where[] = "(LOWER(s.source_info) LIKE '%instagram%' OR LOWER(s.source_info) LIKE '%ig%')";
    } elseif ($srcL === 'google') {
        $where[] = "LOWER(s.source_info) LIKE '%google%'";
    } elseif ($srcL === 'teman') {
        $where[] = "(LOWER(s.source_info) LIKE '%teman%' OR LOWER(s.source_info) LIKE '%kawan%' OR LOWER(s.source_info) LIKE '%rekan%')";
    } elseif ($srcL === 'lainnya') {
        $where[] = "(LOWER(s.source_info) NOT LIKE '%threads%' AND LOWER(s.source_info) NOT LIKE '%facebook%' AND LOWER(s.source_info) NOT LIKE '%fb%' AND LOWER(s.source_info) NOT LIKE '%instagram%' AND LOWER(s.source_info) NOT LIKE '%ig%' AND LOWER(s.source_info) NOT LIKE '%google%' AND LOWER(s.source_info) NOT LIKE '%teman%')";
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total filtered
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM user_surveys s LEFT JOIN users u ON u.id = s.user_id {$whereSql}");
$cntStmt->execute($params);
$totalFiltered = (int)$cntStmt->fetchColumn();

// Order SQL
$orderSql = 'ORDER BY s.id DESC';
if ($fSort === 'oldest') {
    $orderSql = 'ORDER BY s.id ASC';
} elseif ($fSort === 'rating_high') {
    $orderSql = 'ORDER BY s.satisfaction_rating DESC, s.id DESC';
} elseif ($fSort === 'rating_low') {
    $orderSql = 'ORDER BY s.satisfaction_rating ASC, s.id DESC';
}

$totalPages = max(1, (int)ceil($totalFiltered / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;

// Fetch Paginated Surveys
$fetchSql = "
    SELECT s.*, u.username, u.email, u.whatsapp 
    FROM user_surveys s 
    LEFT JOIN users u ON u.id = s.user_id 
    {$whereSql}
    {$orderSql}
    LIMIT {$limit} OFFSET {$offset}
";
$fetchStmt = $pdo->prepare($fetchSql);
$fetchStmt->execute($params);
$surveysList = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to build pagination URLs
function survey_page_url(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

$ratingLabels = [
    5 => ['emoji' => '🤩', 'label' => 'Sangat Puas', 'color' => '#10b981', 'badge' => 'success'],
    4 => ['emoji' => '🙂', 'label' => 'Puas', 'color' => '#34d399', 'badge' => 'info'],
    3 => ['emoji' => '😐', 'label' => 'Biasa', 'color' => '#f59e0b', 'badge' => 'warning'],
    2 => ['emoji' => '🙁', 'label' => 'Kurang', 'color' => '#f97316', 'badge' => 'danger'],
    1 => ['emoji' => '😡', 'label' => 'Buruk', 'color' => '#ef4444', 'badge' => 'danger'],
];

$sourceIcons = [
    'Threads'   => ['icon' => 'ph-at', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)'],
    'Facebook'  => ['icon' => 'ph-facebook-logo', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.15)'],
    'Instagram' => ['icon' => 'ph-instagram-logo', 'color' => '#ec4899', 'bg' => 'rgba(236,72,153,0.15)'],
    'Google'    => ['icon' => 'ph-google-logo', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)'],
    'Teman'     => ['icon' => 'ph-users-three', 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.15)'],
    'Lainnya'   => ['icon' => 'ph-pencil-simple-line', 'color' => '#64748b', 'bg' => 'rgba(100,116,139,0.15)']
];

$activePage = 'surveys';
$pageTitle  = 'Hasil Survei Pengguna';
require_once __DIR__ . '/partials/header.php';
?>

<style>
/* ═════════════════════════════════════════════════════════════════
   DENSE COMPACT GRID DESIGN FOR USER SURVEY REVIEWS
   ═════════════════════════════════════════════════════════════════ */
.survey-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
}
@media (max-width: 576px) {
  .survey-cards-grid {
    grid-template-columns: 1fr;
    gap: 10px;
  }
}

.survey-card {
  background: #0d101e;
  border: 1px solid #1a2035;
  border-radius: 12px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
  position: relative;
  overflow: hidden;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}
.survey-card:hover {
  transform: translateY(-2px);
  border-color: rgba(245, 158, 11, 0.45);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.45);
}
.survey-card.status-revoked {
  border-left: 3.5px solid #ef4444 !important;
  background: linear-gradient(180deg, rgba(239, 68, 68, 0.04) 0%, #0d101e 100%);
}
.survey-card.rating-5 { border-left: 3.5px solid #10b981; }
.survey-card.rating-4 { border-left: 3.5px solid #34d399; }
.survey-card.rating-3 { border-left: 3.5px solid #f59e0b; }
.survey-card.rating-2 { border-left: 3.5px solid #f97316; }
.survey-card.rating-1 { border-left: 3.5px solid #ef4444; }

.survey-user-avatar {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: #0b0d17;
  font-weight: 800;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  text-transform: uppercase;
}

.survey-text-box {
  background: #080a13;
  border: 1px solid #151928;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
  line-height: 1.45;
  color: #cbd5e1;
  word-break: break-word;
}

.survey-feedback-box {
  background: rgba(245, 158, 11, 0.04);
  border: 1px dashed rgba(245, 158, 11, 0.25);
  border-radius: 8px;
  padding: 7px 10px;
  font-size: 11.5px;
  line-height: 1.4;
  color: #fde68a;
  word-break: break-word;
}

.survey-pill {
  font-size: 10.5px;
  font-weight: 700;
  border-radius: 6px;
  padding: 2.5px 7px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.survey-search-ctrl {
  background: #0d101e !important;
  border: 1px solid #1a2035 !important;
  color: #f8fafc !important;
  font-size: 12.5px !important;
  border-radius: 8px !important;
}
.survey-search-ctrl:focus {
  border-color: #f59e0b !important;
  box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2) !important;
}

.c-stat-compact {
  background: #0d101e;
  border: 1px solid #1a2035;
  border-radius: 12px;
  padding: 12px 14px;
  height: 100%;
}
</style>

<div class="c-content">

  <!-- Top Title & Action Bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h4 fw-bold text-white mb-1 d-flex align-items-center gap-2">
        <i class="ph-bold ph-chats-circle text-warning"></i> Ulasan &amp; Survei Pengguna
        <span class="badge bg-warning text-dark fs-6 ms-1 fw-bold"><?= number_format($totalRespondents) ?> Respon</span>
      </h1>
      <div class="text-secondary small">
        Data survei kepuasan, feedback pengalaman, dan sumber traffic pengguna dengan reward Rp 15.000 saldo tarik.
      </div>
    </div>
    
    <div class="d-flex align-items-center gap-2">
      <a href="/console/surveys.php?export=csv" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-2 px-3 py-1.5 fw-bold" style="border-radius:8px;">
        <i class="ph-bold ph-download-simple"></i> Export CSV
      </a>
      <a href="/survey" target="_blank" class="btn btn-secondary btn-sm d-flex align-items-center gap-2 px-3 py-1.5 fw-bold" style="border-radius:8px;">
        <i class="ph-bold ph-arrow-square-out"></i> Halaman Survei
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> d-flex align-items-center gap-2 mb-3 py-2 px-3" style="border-radius:10px;font-size:13px;">
      <i class="ph-bold <?= $flashType==='success'?'ph-check-circle':'ph-warning-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- ── 4 COMPACT STAT CARDS ── -->
  <div class="row g-2.5 mb-3">
    
    <!-- 1. Total Responden -->
    <div class="col-6 col-lg-3">
      <div class="c-stat-compact">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <span class="text-secondary small fw-bold">Total Responden</span>
          <div class="rounded-2 p-1.5 d-flex align-items-center justify-content-center" style="background:rgba(59,130,246,0.15);color:#3b82f6;">
            <i class="ph-bold ph-users fs-5"></i>
          </div>
        </div>
        <div class="h4 fw-bold text-info mb-0"><?= number_format($totalRespondents) ?></div>
        <div class="text-secondary small mt-1" style="font-size:11px;">
          <?= number_format($totalActiveRespondents) ?> aktif<?php if ($totalRevoked > 0): ?> &bull; <span class="text-danger fw-bold"><?= $totalRevoked ?> takeback</span><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 2. Total Reward Saldo -->
    <div class="col-6 col-lg-3">
      <div class="c-stat-compact">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <span class="text-secondary small fw-bold">Reward Diberikan</span>
          <div class="rounded-2 p-1.5 d-flex align-items-center justify-content-center" style="background:rgba(245,158,11,0.15);color:#f59e0b;">
            <i class="ph-bold ph-coins fs-5"></i>
          </div>
        </div>
        <div class="h4 fw-bold text-warning mb-0">Rp <?= number_format($totalRewards, 0, ',', '.') ?></div>
        <div class="text-secondary small mt-1" style="font-size:11px;">
          @Rp 15.000 saldo tarik<?php if ($totalRevoked > 0): ?> (<?= $totalRevoked ?> dibatalkan)<?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 3. Rata-Rata Rating -->
    <div class="col-6 col-lg-3">
      <div class="c-stat-compact">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <span class="text-secondary small fw-bold">Rata-Rata Rating</span>
          <div class="rounded-2 p-1.5 d-flex align-items-center justify-content-center" style="background:rgba(234,179,8,0.15);color:#eab308;">
            <i class="ph-bold ph-star fs-5"></i>
          </div>
        </div>
        <div class="h4 fw-bold text-white mb-0 d-flex align-items-baseline gap-1.5">
          <span><?= number_format($avgRating, 1) ?></span>
          <span style="font-size:12px;color:#94a3b8;font-weight:normal;">/ 5.0</span>
        </div>
        <div class="mt-1 d-flex align-items-center gap-0.5">
          <?php for($i=1; $i<=5; $i++): ?>
            <i class="ph-fill ph-star" style="color: <?= $i <= round($avgRating) ? '#f59e0b' : '#334155' ?>; font-size:11px;"></i>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <!-- 4. Kepuasan Pelanggan (CSAT) -->
    <div class="col-6 col-lg-3">
      <div class="c-stat-compact">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <span class="text-secondary small fw-bold">Kepuasan (CSAT)</span>
          <div class="rounded-2 p-1.5 d-flex align-items-center justify-content-center" style="background:rgba(16,185,129,0.15);color:#10b981;">
            <i class="ph-bold ph-smiley fs-5"></i>
          </div>
        </div>
        <div class="h4 fw-bold text-success mb-0"><?= $csatPct ?>%</div>
        <div class="text-secondary small mt-1" style="font-size:11px;">Rating 4 atau 5 (Puas)</div>
      </div>
    </div>

  </div>

  <!-- ── COLLAPSIBLE ANALYTICS BREAKDOWN ── -->
  <div class="c-card mb-3 p-2.5">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 cursor-pointer" onclick="$('#analyticsPanel').collapse('toggle')">
      <div class="d-flex align-items-center gap-2">
        <i class="ph-bold ph-chart-bar text-warning"></i>
        <span class="fw-bold text-white small">Ringkasan Sumber Traffic &amp; Distribusi Rating</span>
      </div>
      <button class="btn btn-sm btn-outline-secondary py-0.5 px-2 text-secondary" style="font-size:11px;border-radius:6px;">
        <i class="ph-bold ph-caret-down"></i> Buka / Tutup
      </button>
    </div>

    <div class="collapse show mt-2.5 pt-2 border-top border-secondary" id="analyticsPanel">
      <div class="row g-3">
        <!-- Kolom Kiri: Sumber Informasi -->
        <div class="col-12 col-md-6">
          <div class="text-secondary small fw-bold mb-2 d-flex align-items-center justify-content-between">
            <span><i class="ph-bold ph-share-network text-warning me-1"></i> Dari Mana Tahu MancingCuan?</span>
            <span class="badge bg-dark border border-secondary text-secondary" style="font-size:10px;">Traffic Sources</span>
          </div>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($sourceBreakdown as $name => $count): 
              $pct = $totalRespondents > 0 ? round(($count / $totalRespondents) * 100, 1) : 0;
              $meta = $sourceIcons[$name] ?? ['icon' => 'ph-dot', 'color' => '#64748b', 'bg' => 'rgba(255,255,255,0.1)'];
            ?>
              <div>
                <div class="d-flex align-items-center justify-content-between" style="font-size:11.5px;margin-bottom:2px;">
                  <span class="text-white d-flex align-items-center gap-1.5 fw-bold">
                    <i class="ph-bold <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;"></i>
                    <?= htmlspecialchars($name) ?>
                  </span>
                  <span class="text-secondary" style="font-size:11px;">
                    <strong class="text-white"><?= $count ?></strong> (<?= $pct ?>%)
                  </span>
                </div>
                <div class="progress" style="height: 5px; background: #151928; border-radius: 3px;">
                  <div class="progress-bar" style="width: <?= $pct ?>%; background: <?= $meta['color'] ?>;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Kolom Kanan: Distribusi Rating Kepuasan -->
        <div class="col-12 col-md-6">
          <div class="text-secondary small fw-bold mb-2 d-flex align-items-center justify-content-between">
            <span><i class="ph-bold ph-star text-warning me-1"></i> Distribusi Bintang Kepuasan</span>
            <span class="badge bg-dark border border-secondary text-secondary" style="font-size:10px;">Skala 1 - 5</span>
          </div>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($ratingLabels as $star => $info): 
              $cnt = $ratingCounts[$star] ?? 0;
              $pct = $totalRespondents > 0 ? round(($cnt / $totalRespondents) * 100, 1) : 0;
            ?>
              <div>
                <div class="d-flex align-items-center justify-content-between" style="font-size:11.5px;margin-bottom:2px;">
                  <span class="text-white d-flex align-items-center gap-1.5 fw-bold">
                    <span><?= $info['emoji'] ?></span>
                    <span>Bintang <?= $star ?> (<?= $info['label'] ?>)</span>
                  </span>
                  <span class="text-secondary" style="font-size:11px;">
                    <strong class="text-white"><?= $cnt ?></strong> (<?= $pct ?>%)
                  </span>
                </div>
                <div class="progress" style="height: 5px; background: #151928; border-radius: 3px;">
                  <div class="progress-bar" style="width: <?= $pct ?>%; background: <?= $info['color'] ?>;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── FILTER & SEARCH TOOLBAR (COMPACT & DENSE) ── -->
  <div class="c-card mb-3 p-2.5">
    <form method="GET" action="/console/surveys.php" class="row g-2 align-items-center">
      
      <!-- Search Input -->
      <div class="col-12 col-md-4 col-xl-3">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-dark border-secondary text-secondary" style="border-radius:8px 0 0 8px;font-size:12px;">
            <i class="ph-bold ph-magnifying-glass"></i>
          </span>
          <input type="text" name="q" class="form-control survey-search-ctrl" placeholder="Cari user, pengalaman, masukan..." value="<?= htmlspecialchars($qSearch) ?>" style="border-radius:0 8px 8px 0;">
        </div>
      </div>

      <!-- Rating Filter -->
      <div class="col-6 col-md-2 col-xl-2">
        <select name="rating" class="form-select form-select-sm survey-search-ctrl" onchange="this.form.submit()">
          <option value="0">Semua Rating</option>
          <option value="5" <?= $fRating === 5 ? 'selected' : '' ?>>⭐ 5 (Sangat Puas)</option>
          <option value="4" <?= $fRating === 4 ? 'selected' : '' ?>>⭐ 4 (Puas)</option>
          <option value="3" <?= $fRating === 3 ? 'selected' : '' ?>>⭐ 3 (Biasa)</option>
          <option value="2" <?= $fRating === 2 ? 'selected' : '' ?>>⭐ 2 (Kurang)</option>
          <option value="1" <?= $fRating === 1 ? 'selected' : '' ?>>⭐ 1 (Buruk)</option>
        </select>
      </div>

      <!-- Status Filter -->
      <div class="col-6 col-md-2 col-xl-2">
        <select name="status" class="form-select form-select-sm survey-search-ctrl" onchange="this.form.submit()">
          <option value="">Semua Status</option>
          <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>Aktif (+Rp 15.000)</option>
          <option value="revoked" <?= $fStatus === 'revoked' ? 'selected' : '' ?>>Ditarik (Takeback)</option>
        </select>
      </div>

      <!-- Sumber Filter -->
      <div class="col-6 col-md-2 col-xl-2">
        <select name="source" class="form-select form-select-sm survey-search-ctrl" onchange="this.form.submit()">
          <option value="">Semua Sumber</option>
          <option value="Threads" <?= strtolower($fSource) === 'threads' ? 'selected' : '' ?>>Threads</option>
          <option value="Facebook" <?= strtolower($fSource) === 'facebook' ? 'selected' : '' ?>>Facebook</option>
          <option value="Instagram" <?= strtolower($fSource) === 'instagram' ? 'selected' : '' ?>>Instagram</option>
          <option value="Google" <?= strtolower($fSource) === 'google' ? 'selected' : '' ?>>Google</option>
          <option value="Teman" <?= strtolower($fSource) === 'teman' ? 'selected' : '' ?>>Teman</option>
          <option value="Lainnya" <?= strtolower($fSource) === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
        </select>
      </div>

      <!-- Sort & Per-Page Actions -->
      <div class="col-6 col-md-2 col-xl-2">
        <select name="sort" class="form-select form-select-sm survey-search-ctrl" onchange="this.form.submit()">
          <option value="newest" <?= $fSort === 'newest' ? 'selected' : '' ?>>Terbaru</option>
          <option value="oldest" <?= $fSort === 'oldest' ? 'selected' : '' ?>>Terlama</option>
          <option value="rating_high" <?= $fSort === 'rating_high' ? 'selected' : '' ?>>Rating Tertinggi</option>
          <option value="rating_low" <?= $fSort === 'rating_low' ? 'selected' : '' ?>>Rating Terendah</option>
        </select>
      </div>

      <!-- Submit & Reset Buttons -->
      <div class="col-12 col-xl-1 d-flex gap-1 justify-content-end">
        <button type="submit" class="btn btn-warning btn-sm fw-bold px-2 py-1 w-100" style="border-radius:8px;font-size:12px;" title="Terapkan Filter">
          <i class="ph-bold ph-funnel"></i>
        </button>
        <?php if ($qSearch !== '' || $fRating > 0 || $fStatus !== '' || $fSource !== '' || $fSort !== 'newest'): ?>
          <a href="/console/surveys.php" class="btn btn-outline-danger btn-sm px-2 py-1" style="border-radius:8px;font-size:12px;" title="Reset Filter">
            <i class="ph-bold ph-arrow-counter-clockwise"></i>
          </a>
        <?php endif; ?>
      </div>

    </form>

    <!-- Filter Status Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 pt-2 border-top border-secondary small text-secondary" style="font-size:11.5px;">
      <div>
        Menampilkan <strong><?= count($surveysList) ?></strong> dari <strong><?= number_format($totalFiltered) ?></strong> respon
        <?php if ($totalFiltered !== $totalRespondents): ?>
          (difilter dari total <?= number_format($totalRespondents) ?> respon)
        <?php endif; ?>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span>Tampilkan per halaman:</span>
        <div class="btn-group btn-group-sm" role="group">
          <?php foreach ([24, 48, 96] as $limOpt): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['limit' => $limOpt, 'page' => 1])) ?>" class="btn btn-<?= $limit === $limOpt ? 'warning text-dark fw-bold' : 'outline-secondary text-secondary' ?> py-0 px-2" style="font-size:11px;">
              <?= $limOpt ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── GRID DAFTAR ULASAN SURVEI ── -->
  <?php if (empty($surveysList)): ?>
    <div class="c-card p-5 text-center text-secondary">
      <i class="ph-bold ph-tray fs-1 d-block mb-2 text-muted"></i>
      <h6 class="text-white fw-bold">Tidak ada respon survei ditemukan</h6>
      <p class="small text-secondary mb-3">Coba ubah kata kunci pencarian atau sesuaikan pilihan filter di atas.</p>
      <a href="/console/surveys.php" class="btn btn-outline-warning btn-sm px-3">
        <i class="ph-bold ph-arrow-counter-clockwise"></i> Reset Semua Filter
      </a>
    </div>
  <?php else: ?>
    
    <div class="survey-cards-grid mb-4">
      <?php foreach ($surveysList as $idx => $s): 
        $r = (int)$s['satisfaction_rating'];
        $rInfo = $ratingLabels[$r] ?? ['emoji' => '⭐', 'label' => "Rating {$r}", 'color' => '#94a3b8', 'badge' => 'secondary'];
        $isRevoked = (($s['status'] ?? 'completed') === 'revoked');
        $uName = !empty($s['username']) ? (string)$s['username'] : ('User #' . $s['user_id']);
        $initial = strtoupper(substr($uName, 0, 1));
        
        // Match source badge
        $srcText = (string)$s['source_info'];
        $srcLower = strtolower($srcText);
        $srcMeta = ['icon' => 'ph-globe', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)'];
        if (str_contains($srcLower, 'threads'))   $srcMeta = $sourceIcons['Threads'];
        elseif (str_contains($srcLower, 'facebook') || str_contains($srcLower, 'fb')) $srcMeta = $sourceIcons['Facebook'];
        elseif (str_contains($srcLower, 'instagram') || str_contains($srcLower, 'ig')) $srcMeta = $sourceIcons['Instagram'];
        elseif (str_contains($srcLower, 'google'))   $srcMeta = $sourceIcons['Google'];
        elseif (str_contains($srcLower, 'teman'))    $srcMeta = $sourceIcons['Teman'];
      ?>
        <div class="survey-card status-<?= $isRevoked ? 'revoked' : 'completed' ?> rating-<?= $r ?>">
          
          <div>
            <!-- Card Top Bar: User info, Date, Status Badge -->
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
              <div class="d-flex align-items-center gap-2 overflow-hidden">
                <div class="survey-user-avatar">
                  <?= htmlspecialchars($initial) ?>
                </div>
                <div class="overflow-hidden">
                  <div class="d-flex align-items-center gap-1 text-truncate">
                    <?php if (!empty($s['username'])): ?>
                      <a href="/console/users.php?search=<?= urlencode((string)$s['username']) ?>" class="fw-bold text-warning text-decoration-none text-truncate small" title="Buka Profil @<?= htmlspecialchars($uName) ?>">
                        <?= htmlspecialchars($uName) ?>
                      </a>
                    <?php else: ?>
                      <span class="fw-bold text-muted small text-truncate">User #<?= (int)$s['user_id'] ?></span>
                    <?php endif; ?>
                    <span class="text-secondary" style="font-size:10px;">#<?= (int)$s['id'] ?></span>
                  </div>
                  <div class="text-secondary" style="font-size:10.5px;">
                    <?= date('d M Y, H:i', strtotime($s['created_at'])) ?> WIB
                  </div>
                </div>
              </div>

              <!-- Status Badge -->
              <div class="flex-shrink-0">
                <?php if ($isRevoked): ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold survey-pill" title="Reward ditarik oleh Admin">
                    <i class="ph-bold ph-arrow-u-up-left"></i> Takeback
                  </span>
                <?php else: ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold survey-pill" title="Reward Rp 15.000 sudah dikreditkan">
                    <i class="ph-bold ph-check"></i> +Rp 15k
                  </span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Card Chips: Rating Stars, Source Tag, WhatsApp -->
            <div class="d-flex flex-wrap align-items-center gap-1.5 mb-2.5">
              <!-- Rating Pill -->
              <div class="survey-pill" style="background:rgba(255,255,255,0.05);border:1px solid #1e2438;color:<?= $rInfo['color'] ?>;">
                <span style="font-size:12px;"><?= $rInfo['emoji'] ?></span>
                <span class="fw-bold"><?= $r ?>.0</span>
                <div class="d-inline-flex gap-0.5 ms-0.5">
                  <?php for($i=1; $i<=5; $i++): ?>
                    <i class="ph-fill ph-star" style="font-size:9.5px;color:<?= $i <= $r ? $rInfo['color'] : '#334155' ?>;"></i>
                  <?php endfor; ?>
                </div>
              </div>

              <!-- Source Pill -->
              <div class="survey-pill" style="background:<?= $srcMeta['bg'] ?>;color:<?= $srcMeta['color'] ?>;" title="Sumber Info: <?= htmlspecialchars($srcText) ?>">
                <i class="ph-bold <?= $srcMeta['icon'] ?>"></i>
                <span><?= htmlspecialchars(mb_substr($srcText, 0, 16)) . (mb_strlen($srcText) > 16 ? '...' : '') ?></span>
              </div>

              <!-- WA Link if available -->
              <?php if (!empty($s['whatsapp'])): ?>
                <a href="https://wa.me/<?= preg_replace('/\D/', '', $s['whatsapp']) ?>" target="_blank" class="survey-pill bg-success-subtle text-success text-decoration-none" title="Chat WhatsApp: <?= htmlspecialchars((string)$s['whatsapp']) ?>">
                  <i class="ph-bold ph-whatsapp-logo"></i> WA
                </a>
              <?php endif; ?>
            </div>

            <!-- Card Review Body: Pengalaman Pengguna -->
            <div class="survey-text-box mb-2">
              <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:0.5px;text-transform:uppercase;">
                <span><i class="ph-bold ph-chats text-info me-1"></i> Pengalaman:</span>
              </div>
              <div style="font-size:12px;color:#e2e8f0;line-height:1.45;">
                &ldquo;<?= htmlspecialchars((string)$s['experience']) ?>&rdquo;
              </div>
            </div>

            <!-- Card Review Body: Pesan / Masukan (Jika ada dan bukan '-') -->
            <?php 
              $feedbackMsg = trim((string)($s['feedback_message'] ?? ''));
              if ($feedbackMsg !== '' && $feedbackMsg !== '-' && strtolower($feedbackMsg) !== 'tidak ada'): 
            ?>
              <div class="survey-feedback-box mb-2">
                <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:9.5px;font-weight:700;color:#f59e0b;letter-spacing:0.5px;text-transform:uppercase;">
                  <span><i class="ph-bold ph-chat-teardrop-dots me-1"></i> Pesan / Saran:</span>
                </div>
                <div style="font-size:11.5px;color:#fde68a;line-height:1.4;">
                  <?= htmlspecialchars($feedbackMsg) ?>
                </div>
              </div>
            <?php endif; ?>

            <!-- Revoke Notice Banner (jika status revoked) -->
            <?php if ($isRevoked): ?>
              <div class="p-1.5 px-2 rounded mb-2 bg-danger-subtle border border-danger-subtle text-danger" style="font-size:10.5px;">
                <i class="ph-bold ph-warning-octagon me-1"></i>
                <strong>Dibatalkan:</strong> <?= htmlspecialchars((string)($s['revoke_reason'] ?: 'Jawaban tidak valid')) ?>
              </div>
            <?php endif; ?>

          </div>

          <!-- Card Bottom Bar: IP & Actions -->
          <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary mt-1">
            <div class="text-secondary d-flex align-items-center gap-1" style="font-size:10px;" title="IP Pendaftar: <?= htmlspecialchars((string)($s['ip_address'] ?? '-')) ?>">
              <i class="ph-bold ph-globe"></i>
              <span><?= htmlspecialchars((string)($s['ip_address'] ?: '-')) ?></span>
            </div>

            <div class="d-flex align-items-center gap-1">
              <button type="button" class="btn btn-sm btn-outline-warning py-0.5 px-2" style="border-radius:6px;font-size:11px;" onclick='showSurveyDetail(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Lihat Detail Respon">
                <i class="ph-bold ph-eye"></i> Detail
              </button>
              <?php if (!$isRevoked): ?>
                <button type="button" class="btn btn-sm btn-outline-danger py-0.5 px-2" style="border-radius:6px;font-size:11px;" onclick='openTakebackModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Tarik Saldo & Batalkan Survei">
                  <i class="ph-bold ph-arrow-u-up-left"></i> Takeback
                </button>
              <?php endif; ?>
            </div>
          </div>

        </div>
      <?php endforeach; ?>
    </div>

    <!-- ── PAGINATION CONTROLS ── -->
    <?php if ($totalPages > 1): ?>
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 c-card p-3">
        <div class="text-secondary small">
          Halaman <strong><?= $page ?></strong> dari <strong><?= $totalPages ?></strong> (Total <?= number_format($totalFiltered) ?> respon)
        </div>

        <nav aria-label="Page navigation">
          <ul class="pagination pagination-sm mb-0">
            <!-- First & Prev -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link bg-dark border-secondary text-secondary" href="<?= survey_page_url(1) ?>" title="Halaman Pertama">
                <i class="ph-bold ph-caret-double-left"></i>
              </a>
            </li>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link bg-dark border-secondary text-secondary" href="<?= survey_page_url(max(1, $page - 1)) ?>">
                Sebelumnya
              </a>
            </li>

            <!-- Page Number Windows -->
            <?php
              $startPage = max(1, $page - 2);
              $endPage   = min($totalPages, $page + 2);
              if ($startPage > 1) {
                  echo '<li class="page-item disabled"><span class="page-link bg-dark border-secondary text-muted">...</span></li>';
              }
              for ($p = $startPage; $p <= $endPage; $p++):
            ?>
              <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link <?= $p === $page ? 'bg-warning text-dark border-warning fw-bold' : 'bg-dark border-secondary text-white' ?>" href="<?= survey_page_url($p) ?>">
                  <?= $p ?>
                </a>
              </li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
              <li class="page-item disabled"><span class="page-link bg-dark border-secondary text-muted">...</span></li>
            <?php endif; ?>

            <!-- Next & Last -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link bg-dark border-secondary text-secondary" href="<?= survey_page_url(min($totalPages, $page + 1)) ?>">
                Berikutnya
              </a>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link bg-dark border-secondary text-secondary" href="<?= survey_page_url($totalPages) ?>" title="Halaman Terakhir">
                <i class="ph-bold ph-caret-double-right"></i>
              </a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<!-- ── MODAL DETAIL JAWABAN SURVEI ── -->
<div class="modal fade" id="surveyDetailModal" tabindex="-1" aria-labelledby="surveyDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#111422;border:1px solid #1d2238;border-radius:16px;color:#f8fafc;">
      <div class="modal-header border-secondary py-2.5">
        <h5 class="modal-title fw-bold text-warning d-flex align-items-center gap-2" id="surveyDetailModalLabel" style="font-size:14.5px;">
          <i class="ph-bold ph-clipboard-text"></i> Rincian Lengkap Jawaban Survei
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3.5">
        
        <div class="p-2.5 rounded mb-2.5" style="background:#090b14;border:1px solid #1d2238;font-size:12px;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary fw-bold">Pengguna:</span>
            <span class="text-white fw-bold" id="m-user">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary">Kontak WhatsApp:</span>
            <span class="text-success fw-bold" id="m-wa">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary">Waktu Submit:</span>
            <span class="text-secondary" id="m-time">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-secondary">Status &amp; Hadiah:</span>
            <span id="m-reward">-</span>
          </div>
        </div>

        <div id="m-revoked-box" class="p-2 rounded mb-2.5 bg-danger-subtle border border-danger-subtle text-danger small fw-bold d-none" style="font-size:11.5px;">
          <i class="ph-bold ph-warning-octagon"></i> <span id="m-revoked-text">-</span>
        </div>

        <div class="mb-2">
          <label class="text-secondary fw-bold text-uppercase" style="font-size:10px;letter-spacing:0.5px;">1. Sumber Info:</label>
          <div class="p-2 rounded bg-black border border-secondary text-white fw-bold" style="font-size:12px;" id="m-source">
            -
          </div>
        </div>

        <div class="mb-2">
          <label class="text-secondary fw-bold text-uppercase" style="font-size:10px;letter-spacing:0.5px;">2. Rating Kepuasan:</label>
          <div class="p-2 rounded bg-black border border-secondary text-warning fw-bold d-flex align-items-center gap-2" style="font-size:12px;" id="m-rating">
            -
          </div>
        </div>

        <div class="mb-2">
          <label class="text-secondary fw-bold text-uppercase" style="font-size:10px;letter-spacing:0.5px;">3. Pengalaman Menggunakan Web:</label>
          <div class="p-2.5 rounded bg-black border border-secondary text-light" style="font-size:12px;line-height:1.5;white-space:pre-wrap;" id="m-experience">
            -
          </div>
        </div>

        <div class="mb-2">
          <label class="text-secondary fw-bold text-uppercase" style="font-size:10px;letter-spacing:0.5px;">4. Pesan / Keluhan untuk Web:</label>
          <div class="p-2.5 rounded bg-black border border-secondary text-warning" style="font-size:12px;line-height:1.5;white-space:pre-wrap;" id="m-feedback">
            -
          </div>
        </div>

        <div class="text-secondary mt-2 pt-2 border-top border-secondary" style="font-size:10.5px;" id="m-meta">
          IP: -
        </div>

      </div>
      <div class="modal-footer border-secondary py-2 justify-content-between">
        <div class="d-flex gap-2">
          <form method="POST" id="formDeleteSurvey" onsubmit="return confirm('Yakin ingin menghapus catatan survei ini? (Data saldo user tidak akan terhapus)');">
            <input type="hidden" name="delete_id" id="m-delete-id" value="">
            <button type="submit" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 py-1 px-2.5" style="font-size:12px;" title="Hapus catatan survei">
              <i class="ph-bold ph-trash"></i> Hapus
            </button>
          </form>
          <div id="m-takeback-btn-container"></div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm py-1 px-3" style="font-size:12px;" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- ── MODAL TAKEBACK REWARD SURVEI ── -->
<div class="modal fade" id="takebackModal" tabindex="-1" aria-labelledby="takebackModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#111422;border:1.5px solid #ef4444;border-radius:16px;color:#f8fafc;">
      <form method="POST" id="formTakebackSurvey">
        <input type="hidden" name="takeback_id" id="tb-id" value="">
        <div class="modal-header border-secondary py-2.5" style="background:rgba(239, 68, 68, 0.1);">
          <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2" id="takebackModalLabel" style="font-size:14.5px;">
            <i class="ph-bold ph-warning-octagon"></i> Batalkan Survei &amp; Tarik Saldo (Takeback)
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-3.5">
          <div class="alert alert-danger d-flex align-items-start gap-2 mb-3 py-2 px-3" style="font-size:12px;border-radius:10px;background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.4);color:#fca5a5;">
            <i class="ph-bold ph-warning fs-5 flex-shrink-0 mt-0.5"></i>
            <div>
              <strong>Tindakan Takeback:</strong>
              Saldo Tarik pengguna sebesar <strong>Rp 15.000</strong> akan otomatis ditarik kembali/dikurangkan dari akun pengguna, dan status survei ini dibatalkan.
            </div>
          </div>

          <div class="p-2.5 rounded mb-3" style="background:#090b14;border:1px solid #1d2238;font-size:12px;">
            <div class="d-flex justify-content-between mb-1">
              <span class="text-secondary">Pengguna:</span>
              <span class="text-white fw-bold" id="tb-username">-</span>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span class="text-secondary">Jawaban Pengalaman:</span>
              <span class="text-light text-truncate" style="max-width:200px;" id="tb-exp">-</span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-secondary">Reward yang Ditarik:</span>
              <span class="badge bg-danger">Rp 15.000 (Saldo Tarik)</span>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label text-secondary small fw-bold">Alasan Pembatalan / Takeback:</label>
            <textarea name="takeback_reason" id="tb-reason" class="form-control bg-black border-secondary text-white" rows="3" style="font-size:12.5px;" placeholder="Contoh: Jawaban survei asal-asalan, karakter acak, atau spam..." required>Jawaban survei tidak valid atau diisi asal-asalan.</textarea>
            <div class="text-secondary" style="font-size:10.5px;margin-top:3px;">
              Alasan ini akan otomatis dikirimkan ke notifikasi pengguna.
            </div>
          </div>
        </div>
        <div class="modal-footer border-secondary py-2 justify-content-between">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-danger btn-sm fw-bold d-flex align-items-center gap-1">
            <i class="ph-bold ph-arrow-u-up-left"></i> Konfirmasi Tarik Saldo
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let currentDetailData = null;

function showSurveyDetail(data) {
  currentDetailData = data;
  const modalEl = document.getElementById('surveyDetailModal');
  const modal   = new bootstrap.Modal(modalEl);

  document.getElementById('m-user').textContent       = (data.username || 'User ID #' + data.user_id) + (data.email ? ' (' + data.email + ')' : '');
  document.getElementById('m-wa').textContent         = data.whatsapp || '-';
  document.getElementById('m-time').textContent       = data.created_at + ' WIB';
  document.getElementById('m-source').textContent     = data.source_info || '-';
  
  const ratingLabels = {
    1: '😡 Sangat Buruk (1/5)',
    2: '🙁 Kurang Puas (2/5)',
    3: '😐 Biasa Saja (3/5)',
    4: '🙂 Puas (4/5)',
    5: '🤩 Sangat Puas (5/5)'
  };
  document.getElementById('m-rating').textContent     = ratingLabels[data.satisfaction_rating] || (data.satisfaction_rating + ' / 5');
  document.getElementById('m-experience').textContent = data.experience || '-';
  document.getElementById('m-feedback').textContent   = data.feedback_message || '-';
  document.getElementById('m-meta').textContent       = 'IP Address: ' + (data.ip_address || '-') + ' | User Agent: ' + (data.user_agent || '-');
  document.getElementById('m-delete-id').value        = data.id;

  const rewardStatusEl = document.getElementById('m-reward');
  const revokedBox     = document.getElementById('m-revoked-box');
  const tbBtnContainer = document.getElementById('m-takeback-btn-container');

  if (data.status === 'revoked') {
    rewardStatusEl.innerHTML = '<span class="badge bg-danger"><i class="ph-bold ph-arrow-u-up-left"></i> Dibatalkan (Takeback)</span>';
    revokedBox.classList.remove('d-none');
    document.getElementById('m-revoked-text').textContent = 'Dibatalkan pada ' + (data.revoked_at || '-') + ' WIB. Alasan: ' + (data.revoke_reason || '-');
    tbBtnContainer.innerHTML = '';
  } else {
    rewardStatusEl.innerHTML = '<span class="badge bg-success"><i class="ph-bold ph-check"></i> Rp 15.000 (Terkredit)</span>';
    revokedBox.classList.add('d-none');
    tbBtnContainer.innerHTML = `<button type="button" class="btn btn-danger btn-sm d-flex align-items-center gap-1" onclick="bootstrap.Modal.getInstance(document.getElementById('surveyDetailModal')).hide(); openTakebackModal(currentDetailData);"><i class="ph-bold ph-arrow-u-up-left"></i> Takeback Saldo</button>`;
  }

  modal.show();
}

function openTakebackModal(data) {
  document.getElementById('tb-id').value             = data.id;
  document.getElementById('tb-username').textContent = data.username || 'User ID #' + data.user_id;
  document.getElementById('tb-exp').textContent      = data.experience || '-';
  
  const modalEl = document.getElementById('takebackModal');
  const modal   = new bootstrap.Modal(modalEl);
  modal.show();
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
