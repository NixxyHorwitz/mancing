<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Dashboard: if staff doesn't have permission, redirect to their first accessible page
if (!staff_can('dashboard')) {
    redirect(staff_home_url());
}

try {
    $totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalVideos   = (int)$pdo->query("SELECT COUNT(*) FROM videos WHERE is_active=1")->fetchColumn();
    $watchesToday  = (int)$pdo->query("SELECT COUNT(*) FROM watch_history WHERE DATE(watched_at)=CURDATE()")->fetchColumn();
    $pendingWd     = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $pendingDep    = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
    $pendingUpg    = (int)$pdo->query("SELECT COUNT(*) FROM upgrade_orders WHERE status='pending'")->fetchColumn();
    $totalBalance  = (float)$pdo->query("SELECT COALESCE(SUM(balance_wd),0) FROM users")->fetchColumn();
    $totalEarned   = (float)$pdo->query("SELECT COALESCE(SUM(total_earned),0) FROM users")->fetchColumn();
    $totalRevenue  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='confirmed'")->fetchColumn();

    // Chart: watches last 7 days
    $chartData = $pdo->query(
        "SELECT DATE(watched_at) as d, COUNT(*) as cnt FROM watch_history
         WHERE watched_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(watched_at) ORDER BY d"
    )->fetchAll();

    // Chart: revenue last 7 days
    $revChartData = $pdo->query(
        "SELECT DATE(COALESCE(confirmed_at, created_at)) as d, SUM(amount) as total FROM deposits
         WHERE status='confirmed' AND COALESCE(confirmed_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(COALESCE(confirmed_at, created_at)) ORDER BY d"
    )->fetchAll();

    // Recent users
    $recentUsers = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 6")->fetchAll();

    // Top videos
    $topVideos = $pdo->query("SELECT title, total_watches, reward_amount FROM videos ORDER BY total_watches DESC LIMIT 5")->fetchAll();

    // Top users by earnings
    $topEarners = $pdo->query("SELECT id, username, total_earned FROM users ORDER BY total_earned DESC LIMIT 5")->fetchAll();
} catch(\Throwable $e) {
    $totalUsers=$totalVideos=$watchesToday=$pendingWd=$pendingDep=$pendingUpg=0;
    $totalBalance=$totalEarned=$totalRevenue=0.0;
    $chartData=$revChartData=$recentUsers=$topVideos=$topEarners=[];
}

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/partials/header.php';
?>

<!-- Stats grid -->
<div class="row g-3 mb-4">
  <?php
  $stats = [
    ['val'=>$totalUsers,   'lbl'=>'Total Pengguna',    'color'=>'#4E9BFF', 'bg'=>'rgba(78,155,255,.12)', 'icon'=>'👥'],
    ['val'=>$totalVideos,  'lbl'=>'Video Aktif',       'color'=>'#f59e0b', 'bg'=>'rgba(245,158,11,.15)', 'icon'=>'🎬'],
    ['val'=>$watchesToday, 'lbl'=>'Tonton Hari Ini',   'color'=>'#38bdf8', 'bg'=>'rgba(56,189,248,.15)', 'icon'=>'▶️'],
    ['val'=>($pendingWd+$pendingDep+$pendingUpg), 'lbl'=>'Pending Proses', 'color'=>'#ef4444','bg'=>'rgba(239,68,68,.12)','icon'=>'⏳'],
  ];
  foreach ($stats as $s): ?>
  <div class="col-6 col-md-3">
    <div class="c-stat">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__icon" style="background:<?= $s['bg'] ?>;font-size:18px"><?= $s['icon'] ?></div>
      </div>
      <div class="c-stat__val" style="color:<?= $s['color'] ?>"><?= number_format((int)$s['val']) ?></div>
      <div class="c-stat__lbl"><?= $s['lbl'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Balance stats -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">💰 Keuangan &amp; Saldo</span></div>
      <div class="c-card-body d-flex flex-wrap gap-4">
        <div>
          <div style="font-size:12px;color:#888;margin-bottom:2px">Total Revenue (Deposit Sukses)</div>
          <div style="font-size:22px;font-weight:800;color:var(--brand)"><?= format_rp($totalRevenue) ?></div>
        </div>
        <div>
          <div style="font-size:12px;color:#888;margin-bottom:2px">Total Saldo User (WD)</div>
          <div style="font-size:22px;font-weight:800;color:#4CAF82"><?= format_rp($totalBalance) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">⚡ Aksi Cepat</span></div>
      <div class="c-card-body d-flex flex-wrap gap-2">
        <a href="/console/withdrawals.php" class="btn btn-sm" style="background:rgba(255,107,53,.15);color:#FF6B35;border:none">
          Withdraw Pending <?php if($pendingWd>0): ?><span class="badge bg-danger ms-1"><?= $pendingWd ?></span><?php endif; ?>
        </a>
        <a href="/console/deposits.php" class="btn btn-sm" style="background:rgba(78,155,255,.15);color:#4E9BFF;border:none">
          Deposit Pending <?php if($pendingDep>0): ?><span class="badge bg-primary ms-1"><?= $pendingDep ?></span><?php endif; ?>
        </a>
        <a href="/console/upgrades.php" class="btn btn-sm" style="background:rgba(156,111,255,.15);color:#9C6FFF;border:none">
          Upgrade Pending <?php if($pendingUpg>0): ?><span class="badge bg-secondary ms-1"><?= $pendingUpg ?></span><?php endif; ?>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📈 Tonton 7 Hari Terakhir</span></div>
      <div class="c-card-body"><canvas id="watchChart" height="180"></canvas></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">💵 Revenue 7 Hari Terakhir</span></div>
      <div class="c-card-body"><canvas id="revChart" height="180"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="c-card h-100">
      <div class="c-card-header"><span class="c-card-title">🏆 Top Video</span></div>
      <div class="c-card-body" style="padding:0">
        <?php foreach ($topVideos as $i => $v): ?>
        <div style="padding:10px 16px;border-bottom:1px solid #1a1d27;display:flex;align-items:center;gap:10px">
          <span style="font-size:16px"><?= ['🥇','🥈','🥉','4️⃣','5️⃣'][$i] ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($v['title']) ?></div>
            <div style="font-size:11px;color:#666"><?= number_format((int)$v['total_watches']) ?>× · <?= format_rp((float)$v['reward_amount']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topVideos)): ?><div style="padding:20px;text-align:center;color:#555;font-size:13px">Belum ada video</div><?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Top Users -->
  <div class="col-md-4">
    <div class="c-card h-100">
      <div class="c-card-header"><span class="c-card-title">👑 Top User (Earned)</span></div>
      <div class="c-card-body" style="padding:0">
        <?php foreach ($topEarners as $i => $u): ?>
        <div style="padding:10px 16px;border-bottom:1px solid #1a1d27;display:flex;align-items:center;gap:10px">
          <span style="font-size:16px"><?= ['🥇','🥈','🥉','4️⃣','5️⃣'][$i] ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($u['username']) ?></div>
            <div style="font-size:11px;color:#4CAF82;font-weight:700"><?= format_rp((float)$u['total_earned']) ?></div>
          </div>
          <a href="/console/user_detail.php?id=<?= $u['id'] ?>" style="font-size:11px;color:#888;text-decoration:none">Detail</a>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topEarners)): ?><div style="padding:20px;text-align:center;color:#555;font-size:13px">Belum ada data</div><?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Recent users -->
  <div class="col-md-4">
    <div class="c-card h-100">
      <div class="c-card-header">
        <span class="c-card-title">👥 Pengguna Terbaru</span>
        <a href="/console/users.php" style="font-size:12px;color:var(--brand);text-decoration:none">Lihat semua →</a>
      </div>
      <div style="overflow-x:auto">
        <table class="c-table no-datatable mb-0">
          <thead><tr><th>Username</th><th>Email</th><th>Saldo Penarikan</th><th>Terdaftar</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <tr>
              <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
              <td style="color:#888"><?= htmlspecialchars($u['email']) ?></td>
              <td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$u['balance_wd']) ?></td>
              <td style="color:#666;font-size:12px"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
              <td><span class="badge <?= $u['is_active']?'b-success':'b-danger' ?>" style="border-radius:6px;font-size:11px;padding:3px 8px"><?= $u['is_active']?'Aktif':'Nonaktif' ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const raw = <?= json_encode($chartData) ?>;
const rawRev = <?= json_encode($revChartData) ?>;
const labels=[], data=[], revData=[];

for(let i=6; i>=0; i--){
  const d=new Date(); d.setDate(d.getDate()-i);
  const key=d.toISOString().slice(0,10);
  labels.push(d.toLocaleDateString('id-ID',{day:'numeric',month:'short'}));
  
  const f = raw.find(r=>r.d===key);
  data.push(f ? parseInt(f.cnt) : 0);

  const fr = rawRev.find(r=>r.d===key);
  revData.push(fr ? parseInt(fr.total) : 0);
}

new Chart(document.getElementById('watchChart'), {
  type: 'bar',
  data: { labels, datasets: [{label:'Tonton', data, backgroundColor:'rgba(255,107,53,.2)', borderColor:'#FF6B35', borderWidth:2, borderRadius:6}] },
  options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{color:'#666',stepSize:1},grid:{color:'#1f2235'}},x:{ticks:{color:'#666'},grid:{color:'#1f2235'}}} }
});

new Chart(document.getElementById('revChart'), {
  type: 'line',
  data: { labels, datasets: [{label:'Revenue (Rp)', data: revData, backgroundColor:'rgba(251,188,4,.15)', borderColor:'#FBBC04', borderWidth:3, fill:true, tension:0.4}] },
  options: { 
    responsive:true, 
    plugins:{legend:{display:false}}, 
    scales:{
      y:{beginAtZero:true,ticks:{color:'#666',callback:function(v){return 'Rp '+v.toLocaleString('id-ID');}},grid:{color:'#1f2235'}},
      x:{ticks:{color:'#666'},grid:{color:'#1f2235'}}
    } 
  }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
