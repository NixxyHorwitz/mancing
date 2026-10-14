<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$ref_bonus     = (float) setting($pdo, 'referral_bonus', '1000');
$ref_pct       = (float) setting($pdo, 'referral_commission_percent', '5');
$ref_hold_days = max(0, (int) setting($pdo, 'referral_hold_days', '3'));

$flash     = $_SESSION['ref_flash'] ?? '';
$flashType = $_SESSION['ref_flash_type'] ?? '';
unset($_SESSION['ref_flash'], $_SESSION['ref_flash_type']);

// Handle Claim POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_commissions') {
    if (!csrf_verify()) {
        $_SESSION['ref_flash'] = 'Sesi keamanan tidak valid. Silakan muat ulang halaman.';
        $_SESSION['ref_flash_type'] = 'error';
    } else {
        $claimRes = claim_user_referral_commissions($pdo, (int)$user['id']);
        $_SESSION['ref_flash'] = $claimRes['message'];
        $_SESSION['ref_flash_type'] = $claimRes['success'] ? 'success' : 'error';
    }
    header('Location: ' . base_url('user/referral.php'));
    exit;
}

// Refresh user balance in case it changed
$uStmt = $pdo->prepare("SELECT balance_wd, total_earned FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$freshUser = $uStmt->fetch();
if ($freshUser) {
    $user['balance_wd'] = $freshUser['balance_wd'];
    $user['total_earned'] = $freshUser['total_earned'];
}

// Referral stats
$s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE TRIM(UPPER(referred_by)) = TRIM(UPPER(?))");
$s->execute([$user['referral_code']]);
$ref_count = (int)$s->fetchColumn();

// Commission Breakdown Summary
$commSummary   = get_user_referral_commission_summary($pdo, (int)$user['id']);
$ref_locked    = $commSummary['locked_amount'];
$ref_claimable = $commSummary['claimable_amount'];
$ref_claimed   = $commSummary['claimed_amount'];
$ref_earned    = $commSummary['total_earned'];
$earliest_unlock = $commSummary['earliest_unlock'];

// Referral history (recent commissions with status & unlock date)
$hist = $pdo->prepare(
  "SELECT rc.id, rc.amount, rc.status, rc.unlock_at, rc.claimed_at, rc.created_at, u.username
   FROM referral_commissions rc
   JOIN users u ON u.id = rc.from_user_id
   WHERE rc.user_id = ?
   ORDER BY rc.created_at DESC LIMIT 30"
);
$hist->execute([$user['id']]);
$history = $hist->fetchAll();

// Referred users list
$refs = $pdo->prepare(
  "SELECT u.id, u.username, u.created_at, 
          COALESCE(m.name, 'Free') as membership_name,
          COALESCE((SELECT SUM(amount) FROM deposits WHERE user_id = u.id AND status = 'confirmed'), 0) as total_deposit,
          COALESCE((SELECT SUM(amount) FROM referral_commissions WHERE user_id = ? AND from_user_id = u.id), 0) as commission_earned
   FROM users u
   LEFT JOIN memberships m ON m.id = u.membership_id
   WHERE TRIM(UPPER(u.referred_by)) = TRIM(UPPER(?))
   ORDER BY u.created_at DESC"
);
$refs->execute([$user['id'], $user['referral_code']]);
$referreds = $refs->fetchAll();

$ref_url = base_url('register/' . $user['referral_code']);
$share_text = 'Gabung dan hasilkan cuan bersama! Daftar menggunakan tautan referralku: ' . $ref_url;

$pageTitle  = 'Misi Referral';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   REFERRAL PAGE — COMPACT AMBER HONEY THEME
   ══════════════════════════════════════════════ */
:root {
  --honey-50:  #fffbeb;
  --honey-100: #fef3c7;
  --honey-200: #fde68a;
  --honey-300: #fcd34d;
  --honey-400: #fbbf24;
  --honey-500: #f59e0b;
  --honey-600: #d97706;
  --honey-700: #b45309;
  --honey-800: #92400e;
  --honey-900: #78350f;
  --honey-ink: #451a03;
}

body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  color: var(--honey-ink);
  font-family: 'Nunito', sans-serif;
}

.ref-page-wrap {
  max-width: 480px;
  margin: 0 auto;
  padding: 10px 12px 100px;
}

/* ── HERO BANNER ── */
.ref-hero-card {
  position: relative;
  background: linear-gradient(135deg, #78350f 0%, #92400e 45%, #b45309 100%);
  border: 2px solid #5a2608;
  border-radius: 18px;
  padding: 16px 14px;
  box-shadow: 0 4px 0 #5a2608, 0 10px 20px -6px rgba(120, 53, 15, 0.35);
  overflow: hidden;
  margin-bottom: 12px;
  color: #fff;
}
.ref-hero-card::after {
  content: '';
  position: absolute;
  top: -24px;
  right: -24px;
  width: 120px;
  height: 120px;
  background: radial-gradient(circle, rgba(253, 230, 138, 0.18) 0%, transparent 70%);
  pointer-events: none;
}
.ref-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.ref-hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(254, 243, 199, 0.16);
  border: 1px solid rgba(254, 243, 199, 0.3);
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 800;
  color: var(--honey-200);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.ref-hero-title {
  font-size: 18px;
  font-weight: 900;
  line-height: 1.2;
  color: #ffffff;
  text-shadow: 0 1.5px 2px rgba(69, 26, 3, 0.4);
}
.ref-hero-sub {
  font-size: 11px;
  font-weight: 700;
  color: var(--honey-100);
  margin-top: 2px;
  opacity: 0.95;
}

/* ── PROMOTOR LINK ── */
.ref-promo-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, #065f46, #047857);
  border: 2px solid #064e3b;
  border-radius: 12px;
  padding: 8px 12px;
  margin-bottom: 12px;
  box-shadow: 0 3px 0 #064e3b;
  color: #ffffff;
}
.ref-promo-strip-left {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  font-weight: 800;
}
.ref-promo-btn {
  background: var(--honey-300);
  border: 1.5px solid var(--honey-700);
  border-radius: 8px;
  padding: 4px 10px;
  font-size: 10px;
  font-weight: 900;
  color: var(--honey-900);
  text-decoration: none;
  box-shadow: 0 2px 0 var(--honey-800);
  transition: transform 0.1s;
}
.ref-promo-btn:active {
  transform: translateY(2px);
  box-shadow: none;
}

/* ── FLASH ALERT ── */
.ref-flash {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 800;
  margin-bottom: 12px;
  border: 2px solid;
}
.ref-flash.success {
  background: #ecfdf5;
  border-color: #059669;
  color: #065f46;
  box-shadow: 0 3px 0 #059669;
}
.ref-flash.error {
  background: #fef2f2;
  border-color: #dc2626;
  color: #991b1b;
  box-shadow: 0 3px 0 #dc2626;
}

/* ── 4-PILLAR KPI STATS GRID ── */
.ref-stats-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}
.ref-stat-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 14px;
  padding: 9px 10px;
  box-shadow: 0 3.5px 0 var(--honey-900);
  display: flex;
  align-items: center;
  gap: 8px;
  position: relative;
  overflow: hidden;
}
.ref-stat-icon-wrap {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
}
.ref-stat-card.blue .ref-stat-icon-wrap { background: #e0f2fe; color: #0284c7; }
.ref-stat-card.amber .ref-stat-icon-wrap { background: var(--honey-100); color: var(--honey-700); }
.ref-stat-card.ice .ref-stat-icon-wrap { background: #e0f7fa; color: #00838f; }
.ref-stat-card.green .ref-stat-icon-wrap { background: #dcfce7; color: #16a34a; }

.ref-stat-content {
  flex: 1;
  min-width: 0;
}
.ref-stat-val {
  font-size: 13px;
  font-weight: 900;
  line-height: 1.15;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ref-stat-card.blue .ref-stat-val { color: #0369a1; }
.ref-stat-card.amber .ref-stat-val { color: var(--honey-800); }
.ref-stat-card.ice .ref-stat-val { color: #006064; }
.ref-stat-card.green .ref-stat-val { color: #15803d; }

.ref-stat-lbl {
  font-size: 8.5px;
  font-weight: 800;
  color: #78716c;
  margin-top: 2px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

/* ── CLAIM ACTION BOX ── */
.ref-claim-box {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 3.5px 0 var(--honey-900);
  margin-bottom: 12px;
  position: relative;
  overflow: hidden;
}
.ref-claim-box.active-claim {
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  border-color: #059669;
  box-shadow: 0 3.5px 0 #064e3b, 0 8px 16px -4px rgba(16, 185, 129, 0.25);
}
.ref-claim-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.ref-claim-title-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 900;
  color: var(--honey-900);
}
.ref-claim-box.active-claim .ref-claim-title-wrap {
  color: #065f46;
}
.ref-claim-tag {
  font-size: 9px;
  font-weight: 900;
  text-transform: uppercase;
  padding: 2px 7px;
  border-radius: 999px;
  letter-spacing: 0.3px;
}
.ref-claim-tag.ready {
  background: #16a34a;
  color: #ffffff;
}
.ref-claim-tag.locked {
  background: #e2e8f0;
  color: #475569;
}
.ref-claim-body {
  margin-bottom: 10px;
}
.ref-claim-amt {
  font-size: 20px;
  font-weight: 900;
  line-height: 1.2;
  color: var(--honey-900);
}
.ref-claim-box.active-claim .ref-claim-amt {
  color: #047857;
}
.ref-claim-desc {
  font-size: 10px;
  font-weight: 700;
  color: #78716c;
  margin-top: 2px;
  line-height: 1.35;
}
.ref-claim-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 2px solid #064e3b;
  color: #ffffff;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 12.5px;
  font-weight: 900;
  box-shadow: 0 3px 0 #064e3b;
  cursor: pointer;
  transition: transform 0.1s, box-shadow 0.1s;
}
.ref-claim-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #064e3b;
}
.ref-claim-btn.disabled {
  background: #f1f5f9;
  border-color: #cbd5e1;
  color: #94a3b8;
  box-shadow: 0 2.5px 0 #cbd5e1;
  cursor: not-allowed;
  pointer-events: none;
}

/* ── REFERRAL CODE & LINK CARD ── */
.ref-box-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px;
  box-shadow: 0 3.5px 0 var(--honey-900);
  margin-bottom: 12px;
}
.ref-box-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.ref-box-label {
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-800);
  display: flex;
  align-items: center;
  gap: 5px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.ref-code-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--honey-50);
  border: 1.5px dashed var(--honey-600);
  border-radius: 12px;
  padding: 8px 10px;
  margin-bottom: 10px;
}
.ref-code-text {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 15px;
  font-weight: 900;
  color: var(--honey-900);
  letter-spacing: 1.5px;
}
.ref-copy-btn {
  background: linear-gradient(180deg, var(--honey-400), var(--honey-500));
  border: 1.5px solid var(--honey-800);
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-900);
  cursor: pointer;
  box-shadow: 0 2.5px 0 var(--honey-800);
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: transform 0.1s;
}
.ref-copy-btn:active {
  transform: translateY(2px);
  box-shadow: none;
}

/* ── QUICK SHARE ROW ── */
.ref-share-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 6px;
}
.ref-share-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 6px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 800;
  color: #ffffff;
  text-decoration: none;
  border: 1.5px solid rgba(0, 0, 0, 0.2);
  box-shadow: 0 2.5px 0 rgba(0, 0, 0, 0.25);
  transition: transform 0.1s;
  cursor: pointer;
}
.ref-share-btn:active {
  transform: translateY(2px);
  box-shadow: none;
}
.ref-share-btn.wa {
  background: linear-gradient(135deg, #22c55e, #16a34a);
  border-color: #15803d;
  box-shadow: 0 2.5px 0 #166534;
}
.ref-share-btn.tg {
  background: linear-gradient(135deg, #38bdf8, #0284c7);
  border-color: #0369a1;
  box-shadow: 0 2.5px 0 #075985;
}
.ref-share-btn.link {
  background: linear-gradient(135deg, var(--honey-500), var(--honey-600));
  border-color: var(--honey-800);
  box-shadow: 0 2.5px 0 var(--honey-900);
}

/* ── BENEFITS STRIP ── */
.ref-benefits-strip {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}
.ref-benefit-card {
  background: #ffffff;
  border: 1.5px solid var(--honey-200);
  border-radius: 12px;
  padding: 9px 10px;
  box-shadow: 0 2px 6px rgba(120, 53, 15, 0.05);
  display: flex;
  align-items: center;
  gap: 8px;
}
.ref-benefit-icon {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  border: 1.5px solid;
}
.ref-benefit-icon.gift {
  background: #fef3c7;
  color: #b45309;
  border-color: #fde68a;
}
.ref-benefit-icon.percent {
  background: #dcfce7;
  color: #15803d;
  border-color: #bbf7d0;
}
.ref-benefit-title {
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-900);
  line-height: 1.2;
}
.ref-benefit-desc {
  font-size: 9.5px;
  font-weight: 700;
  color: #78716c;
  margin-top: 1px;
}

/* ── TAB NAVIGATION ── */
.ref-tabs-wrap {
  display: flex;
  background: var(--honey-100);
  border: 1.5px solid var(--honey-900);
  border-radius: 12px;
  padding: 3px;
  margin-bottom: 10px;
  gap: 4px;
}
.ref-tab-btn {
  flex: 1;
  background: transparent;
  border: none;
  padding: 8px 6px;
  border-radius: 9px;
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-800);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  transition: all 0.15s ease;
}
.ref-tab-btn.active {
  background: #ffffff;
  color: var(--honey-900);
  font-weight: 900;
  border: 1px solid var(--honey-900);
  box-shadow: 0 2px 0 var(--honey-900);
}
.ref-tab-pill {
  font-size: 9px;
  font-weight: 900;
  padding: 1px 5px;
  border-radius: 999px;
  background: var(--honey-200);
  color: var(--honey-900);
}
.ref-tab-btn.active .ref-tab-pill {
  background: var(--honey-500);
  color: #ffffff;
}

/* ── TAB PANELS & LIST ITEMS ── */
.ref-tab-panel {
  display: none;
}
.ref-tab-panel.active {
  display: block;
}

.ref-list-container {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ref-member-row {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 12px;
  padding: 9px 12px;
  box-shadow: 0 2.5px 0 var(--honey-900);
}
.ref-member-avatar {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--honey-100), var(--honey-200));
  border: 1.5px solid var(--honey-600);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: var(--honey-800);
  flex-shrink: 0;
}
.ref-member-info {
  flex: 1;
  min-width: 0;
}
.ref-member-name {
  font-size: 12px;
  font-weight: 900;
  color: var(--honey-900);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ref-member-meta {
  font-size: 9.5px;
  font-weight: 700;
  color: #78716c;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 1px;
}
.ref-member-right {
  text-align: right;
  flex-shrink: 0;
}
.ref-membership-tag {
  display: inline-block;
  font-size: 8.5px;
  font-weight: 800;
  padding: 1.5px 5px;
  border-radius: 5px;
  text-transform: uppercase;
  margin-bottom: 2px;
  border: 1px solid;
}
.ref-membership-tag.free {
  background: #f1f5f9;
  color: #475569;
  border-color: #cbd5e1;
}
.ref-membership-tag.vip {
  background: #fef3c7;
  color: #92400e;
  border-color: #f59e0b;
}
.ref-member-amt {
  font-size: 11.5px;
  font-weight: 900;
  color: #16a34a;
}

/* ── HISTORY ROW ── */
.ref-hist-row {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 12px;
  padding: 8px 12px;
  box-shadow: 0 2.5px 0 var(--honey-900);
}
.ref-hist-icon {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
}
.ref-hist-icon.claimed {
  background: #ecfdf5;
  border: 1.5px solid #059669;
  color: #059669;
}
.ref-hist-icon.ready {
  background: #fef3c7;
  border: 1.5px solid #f59e0b;
  color: #d97706;
}
.ref-hist-icon.locked {
  background: #e0f2fe;
  border: 1.5px solid #0284c7;
  color: #0284c7;
}
.ref-hist-body {
  flex: 1;
  min-width: 0;
}
.ref-hist-title {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--honey-900);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ref-hist-meta-row {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 2px;
  flex-wrap: wrap;
}
.ref-hist-date {
  font-size: 9.5px;
  font-weight: 700;
  color: #78716c;
}
.ref-status-badge {
  font-size: 8px;
  font-weight: 800;
  padding: 1.5px 5px;
  border-radius: 5px;
  display: inline-flex;
  align-items: center;
  gap: 2.5px;
  text-transform: uppercase;
}
.ref-status-badge.claimed {
  background: #ecfdf5;
  color: #065f46;
  border: 1px solid #a7f3d0;
}
.ref-status-badge.ready {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
}
.ref-status-badge.locked {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #bae6fd;
}
.ref-hist-amt {
  font-size: 12px;
  font-weight: 900;
  color: #16a34a;
  text-align: right;
  flex-shrink: 0;
}

/* ── EMPTY STATE ── */
.ref-empty-box {
  text-align: center;
  padding: 24px 16px;
  background: #ffffff;
  border: 1.5px dashed var(--honey-300);
  border-radius: 14px;
}
.ref-empty-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--honey-100);
  color: var(--honey-600);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  margin-bottom: 8px;
}
.ref-empty-title {
  font-size: 12px;
  font-weight: 800;
  color: var(--honey-900);
}
.ref-empty-desc {
  font-size: 10px;
  font-weight: 700;
  color: #78716c;
  margin-top: 2px;
}

/* ── PAGINATION ── */
.ref-pg-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 10px;
}
.ref-pg-btn {
  padding: 5px 12px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 8px;
  font-size: 10.5px;
  font-weight: 900;
  color: var(--honey-900);
  box-shadow: 0 2px 0 var(--honey-900);
  cursor: pointer;
  transition: transform 0.1s;
}
.ref-pg-btn:active {
  transform: translateY(2px);
  box-shadow: none;
}
.ref-pg-counter {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--honey-800);
}
</style>

<div class="ref-page-wrap">

  <!-- FLASH ALERT -->
  <?php if ($flash): ?>
    <div class="ref-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size: 16px;"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- HERO BANNER -->
  <div class="ref-hero-card">
    <div class="ref-hero-top">
      <div class="ref-hero-badge">
        <i class="ph-fill ph-trophy"></i>
        <span>Misi Kemitraan</span>
      </div>
      <div style="font-size: 11px; font-weight: 800; color: var(--honey-200);">
        Kode: <strong style="font-family: monospace; letter-spacing: 0.5px;"><?= htmlspecialchars($user['referral_code']) ?></strong>
      </div>
    </div>
    <div class="ref-hero-title">Panen Cuan Bersama Kawan</div>
    <div class="ref-hero-sub">Dapatkan bonus pendaftaran dan komisi deposit seumur hidup.</div>
  </div>

  <?php if ((int)($user['is_promotor'] ?? 0) === 1): ?>
  <!-- PROMOTOR STRIP -->
  <div class="ref-promo-strip">
    <div class="ref-promo-strip-left">
      <i class="ph-fill ph-shield-star" style="font-size: 18px; color: #fde68a;"></i>
      <span>Status Promotor Aktif</span>
    </div>
    <a href="/user/promotor.php" class="ref-promo-btn">
      <i class="ph-bold ph-chart-line-up"></i>
      <span>Dashboard</span>
    </a>
  </div>
  <?php endif; ?>

  <!-- 4-PILLAR KPI STATS -->
  <div class="ref-stats-grid">
    <div class="ref-stat-card blue">
      <div class="ref-stat-icon-wrap"><i class="ph-bold ph-users-three"></i></div>
      <div class="ref-stat-content">
        <div class="ref-stat-val"><?= number_format($ref_count, 0, ',', '.') ?></div>
        <div class="ref-stat-lbl">Kawan Terdaftar</div>
      </div>
    </div>
    <div class="ref-stat-card amber">
      <div class="ref-stat-icon-wrap"><i class="ph-bold ph-wallet"></i></div>
      <div class="ref-stat-content">
        <div class="ref-stat-val"><?= format_rp((float)$user['balance_wd']) ?></div>
        <div class="ref-stat-lbl">Saldo Siap WD</div>
      </div>
    </div>
    <div class="ref-stat-card ice">
      <div class="ref-stat-icon-wrap"><i class="ph-bold ph-lock-key"></i></div>
      <div class="ref-stat-content">
        <div class="ref-stat-val"><?= format_rp($ref_locked) ?></div>
        <div class="ref-stat-lbl">Komisi Beku</div>
      </div>
    </div>
    <div class="ref-stat-card green">
      <div class="ref-stat-icon-wrap"><i class="ph-bold ph-lightning"></i></div>
      <div class="ref-stat-content">
        <div class="ref-stat-val"><?= format_rp($ref_claimable) ?></div>
        <div class="ref-stat-lbl">Siap Dicairkan</div>
      </div>
    </div>
  </div>

  <!-- PENCAIRAN KOMISI ACTION CARD -->
  <div class="ref-claim-box <?= $ref_claimable > 0 ? 'active-claim' : '' ?>">
    <div class="ref-claim-header">
      <div class="ref-claim-title-wrap">
        <i class="ph-bold <?= $ref_claimable > 0 ? 'ph-lightning' : ($ref_locked > 0 ? 'ph-lock-key' : 'ph-coins') ?>"></i>
        <span>Pencairan Komisi Referral</span>
      </div>
      <?php if ($ref_claimable > 0): ?>
        <span class="ref-claim-tag ready">Siap Dicairkan</span>
      <?php elseif ($ref_locked > 0): ?>
        <span class="ref-claim-tag locked">Tertahan</span>
      <?php else: ?>
        <span class="ref-claim-tag locked">Info Sistem</span>
      <?php endif; ?>
    </div>

    <div class="ref-claim-body">
      <div class="ref-claim-amt">
        <?= format_rp($ref_claimable > 0 ? $ref_claimable : $ref_locked) ?>
      </div>
      <div class="ref-claim-desc">
        <?php if ($ref_claimable > 0): ?>
          Masa beku telah selesai! Klik tombol di bawah untuk mencairkan langsung ke Saldo Penarikan Anda.
        <?php elseif ($ref_locked > 0): ?>
          <?php if ($earliest_unlock): ?>
            Komisi terdekat dapat dicairkan pada: <strong><?= date('d M Y, H:i', strtotime($earliest_unlock)) ?> WIB</strong> (Masa tahan: <?= $ref_hold_days ?> hari).
          <?php else: ?>
            Menunggu masa tahan <?= $ref_hold_days ?> hari sebelum dapat dicairkan ke saldo penarikan.
          <?php endif; ?>
        <?php else: ?>
          Komisi baru dari pendaftaran (+<?= format_rp($ref_bonus) ?>) dan deposit (+<?= (float)$ref_pct ?>%) akan berstatus beku selama <?= $ref_hold_days ?> hari sebelum siap dicairkan.
        <?php endif; ?>
      </div>
    </div>

    <?php if ($ref_claimable > 0): ?>
      <form method="POST" action="" onsubmit="this.querySelector('button').disabled = true;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim_commissions">
        <button type="submit" class="ref-claim-btn" id="btn-claim-commissions">
          <i class="ph-bold ph-hand-coins" style="font-size: 16px;"></i>
          <span>Cairkan <?= format_rp($ref_claimable) ?> Sekarang</span>
        </button>
      </form>
    <?php else: ?>
      <button type="button" class="ref-claim-btn disabled" disabled>
        <i class="ph-bold <?= $ref_locked > 0 ? 'ph-lock' : 'ph-check-circle' ?>"></i>
        <span><?= $ref_locked > 0 ? 'Menunggu Waktu Buka' : 'Tidak Ada Komisi Tertahan' ?></span>
      </button>
    <?php endif; ?>
  </div>

  <!-- REFERRAL CODE & QUICK SHARE -->
  <div class="ref-box-card">
    <div class="ref-box-header">
      <span class="ref-box-label"><i class="ph-bold ph-link-simple"></i> Tautan Undangan Anda</span>
      <span style="font-size: 10px; font-weight: 800; color: #16a34a;">Siap Dibagikan</span>
    </div>

    <div class="ref-code-strip">
      <div>
        <div style="font-size: 8.5px; font-weight: 800; color: #78716c; text-transform: uppercase;">Kode Referral</div>
        <div class="ref-code-text" id="ref-code-val"><?= htmlspecialchars($user['referral_code']) ?></div>
      </div>
      <button onclick="copyRefLink()" class="ref-copy-btn" id="btn-copy-code">
        <i class="ph-bold ph-copy" id="copy-ico"></i>
        <span id="copy-txt">Salin Link</span>
      </button>
    </div>

    <!-- QUICK SHARE BUTTONS -->
    <div class="ref-share-row">
      <a href="https://wa.me/?text=<?= urlencode($share_text) ?>" target="_blank" rel="noopener noreferrer" class="ref-share-btn wa">
        <i class="ph-bold ph-whatsapp-logo"></i>
        <span>WhatsApp</span>
      </a>
      <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Yuk gabung dan dapatkan saldo gratis!') ?>" target="_blank" rel="noopener noreferrer" class="ref-share-btn tg">
        <i class="ph-bold ph-telegram-logo"></i>
        <span>Telegram</span>
      </a>
      <button type="button" onclick="shareNative()" class="ref-share-btn link">
        <i class="ph-bold ph-share-network"></i>
        <span>Bagikan</span>
      </button>
    </div>
  </div>

  <!-- BENEFITS / SKEMA KOMISI STRIP -->
  <div class="ref-benefits-strip">
    <div class="ref-benefit-card">
      <div class="ref-benefit-icon gift">
        <i class="ph-fill ph-gift"></i>
      </div>
      <div>
        <div class="ref-benefit-title">+<?= format_rp($ref_bonus) ?></div>
        <div class="ref-benefit-desc">Bonus tiap pendaftaran teman baru</div>
      </div>
    </div>
    <div class="ref-benefit-card">
      <div class="ref-benefit-icon percent">
        <i class="ph-fill ph-percent"></i>
      </div>
      <div>
        <div class="ref-benefit-title">+<?= (float)$ref_pct ?>% Komisi</div>
        <div class="ref-benefit-desc">Dari setiap isi saldo teman seumur hidup</div>
      </div>
    </div>
  </div>

  <!-- TAB SWITCHER -->
  <div class="ref-tabs-wrap">
    <button type="button" class="ref-tab-btn active" onclick="switchRefTab('members', this)">
      <i class="ph-bold ph-users-three"></i>
      <span>Kawan Terdaftar</span>
      <span class="ref-tab-pill"><?= count($referreds) ?></span>
    </button>
    <button type="button" class="ref-tab-btn" onclick="switchRefTab('history', this)">
      <i class="ph-bold ph-clock-counter-clockwise"></i>
      <span>Riwayat Komisi</span>
      <span class="ref-tab-pill"><?= count($history) ?></span>
    </button>
  </div>

  <!-- TAB PANEL 1: REFERRED MEMBERS -->
  <div id="panel-members" class="ref-tab-panel active">
    <?php if (empty($referreds)): ?>
      <div class="ref-empty-box">
        <div class="ref-empty-icon"><i class="ph-bold ph-user-plus"></i></div>
        <div class="ref-empty-title">Belum Ada Kawan Bergabung</div>
        <div class="ref-empty-desc">Bagikan link atau kode referral kamu untuk mulai mendapatkan komisi!</div>
      </div>
    <?php else: ?>
      <div class="ref-list-container">
        <?php foreach ($referreds as $idx => $r): 
          $isFree = (stripos((string)$r['membership_name'], 'Free') !== false || (string)$r['membership_name'] === '');
          $badgeCls = $isFree ? 'free' : 'vip';
        ?>
          <div class="ref-member-row ref-member-item" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
            <div class="ref-member-avatar">
              <i class="ph-fill ph-user"></i>
            </div>
            <div class="ref-member-info">
              <div class="ref-member-name"><?= htmlspecialchars($r['username']) ?></div>
              <div class="ref-member-meta">
                <i class="ph-bold ph-calendar-blank"></i>
                <span><?= date('d M Y', strtotime($r['created_at'])) ?></span>
              </div>
            </div>
            <div class="ref-member-right">
              <span class="ref-membership-tag <?= $badgeCls ?>"><?= htmlspecialchars($r['membership_name'] ?: 'Free') ?></span>
              <div class="ref-member-amt">+<?= format_rp((float)$r['commission_earned']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($referreds) > 5): ?>
        <div class="ref-pg-row">
          <button type="button" onclick="changeMemberPage(-1)" id="btn-mem-prev" class="ref-pg-btn" style="opacity: 0.5; pointer-events: none;">
            <i class="ph-bold ph-caret-left"></i> Prev
          </button>
          <span id="mem-page-info" class="ref-pg-counter">1 / <?= ceil(count($referreds) / 5) ?></span>
          <button type="button" onclick="changeMemberPage(1)" id="btn-mem-next" class="ref-pg-btn">
            Next <i class="ph-bold ph-caret-right"></i>
          </button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- TAB PANEL 2: RECENT COMMISSION FEED -->
  <div id="panel-history" class="ref-tab-panel">
    <?php if (empty($history)): ?>
      <div class="ref-empty-box">
        <div class="ref-empty-icon"><i class="ph-bold ph-receipt"></i></div>
        <div class="ref-empty-title">Belum Ada Riwayat Komisi</div>
        <div class="ref-empty-desc">Komisi pendaftaran atau deposit teman kamu akan tercatat secara otomatis di sini.</div>
      </div>
    <?php else: ?>
      <div class="ref-list-container">
        <?php foreach ($history as $h): 
          $isClaimed = ($h['status'] === 'claimed');
          $isReady   = ($h['status'] === 'locked' && ($h['unlock_at'] === null || strtotime($h['unlock_at']) <= time()));
          $isLocked  = ($h['status'] === 'locked' && !empty($h['unlock_at']) && strtotime($h['unlock_at']) > time());
        ?>
          <div class="ref-hist-row">
            <div class="ref-hist-icon <?= $isClaimed ? 'claimed' : ($isReady ? 'ready' : 'locked') ?>">
              <i class="ph-bold <?= $isClaimed ? 'ph-check' : ($isReady ? 'ph-lightning' : 'ph-lock') ?>"></i>
            </div>
            <div class="ref-hist-body">
              <div class="ref-hist-title">Komisi dari <?= htmlspecialchars($h['username']) ?></div>
              <div class="ref-hist-meta-row">
                <span class="ref-hist-date"><?= date('d M Y, H:i', strtotime($h['created_at'])) ?> WIB</span>
                <?php if ($isClaimed): ?>
                  <span class="ref-status-badge claimed"><i class="ph-bold ph-check-circle"></i> Dicairkan</span>
                <?php elseif ($isReady): ?>
                  <span class="ref-status-badge ready"><i class="ph-bold ph-lightning"></i> Siap Cair</span>
                <?php else: ?>
                  <span class="ref-status-badge locked" title="Bisa dicairkan pada <?= date('d M Y H:i', strtotime($h['unlock_at'])) ?> WIB">
                    <i class="ph-bold ph-lock-key"></i> Buka <?= date('d M H:i', strtotime($h['unlock_at'])) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <div class="ref-hist-amt">
              +<?= format_rp((float)$h['amount']) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
const refUrl = "<?= htmlspecialchars($ref_url, ENT_QUOTES) ?>";
const shareText = "<?= htmlspecialchars($share_text, ENT_QUOTES) ?>";

function copyRefLink() {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(refUrl).then(setCopySuccess).catch(() => fallbackCopy(refUrl));
  } else {
    fallbackCopy(refUrl);
  }
}

function fallbackCopy(text) {
  const el = document.createElement('textarea');
  el.value = text;
  el.style.position = 'fixed';
  el.style.opacity = '0';
  document.body.appendChild(el);
  el.focus();
  el.select();
  try {
    document.execCommand('copy');
    setCopySuccess();
  } catch (err) {}
  document.body.removeChild(el);
}

function setCopySuccess() {
  const btn = document.getElementById('btn-copy-code');
  const txt = document.getElementById('copy-txt');
  const ico = document.getElementById('copy-ico');
  if (!btn || !txt || !ico) return;

  txt.textContent = 'Tersalin!';
  ico.className = 'ph-bold ph-check';
  btn.style.background = '#22c55e';
  btn.style.color = '#ffffff';
  btn.style.borderColor = '#15803d';

  setTimeout(() => {
    txt.textContent = 'Salin Link';
    ico.className = 'ph-bold ph-copy';
    btn.style.background = '';
    btn.style.color = '';
    btn.style.borderColor = '';
  }, 2200);
}

function shareNative() {
  if (navigator.share) {
    navigator.share({
      title: 'MancingCuan',
      text: shareText,
      url: refUrl
    }).catch(() => {});
  } else {
    copyRefLink();
  }
}

function switchRefTab(tab, btn) {
  document.querySelectorAll('.ref-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.ref-tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('active');
}

// Client-side pagination for referred members
let memPage = 1;
const memPageSize = 5;
const memTotal = <?= count($referreds) ?>;
const memTotalPages = Math.max(1, Math.ceil(memTotal / memPageSize));

function changeMemberPage(delta) {
  const next = memPage + delta;
  if (next < 1 || next > memTotalPages) return;
  memPage = next;

  const rows = document.querySelectorAll('.ref-member-item');
  rows.forEach((row, idx) => {
    if (idx >= (memPage - 1) * memPageSize && idx < memPage * memPageSize) {
      row.style.display = 'flex';
    } else {
      row.style.display = 'none';
    }
  });

  const info = document.getElementById('mem-page-info');
  if (info) info.textContent = memPage + ' / ' + memTotalPages;

  const btnPrev = document.getElementById('btn-mem-prev');
  if (btnPrev) {
    btnPrev.style.opacity = memPage <= 1 ? '0.5' : '1';
    btnPrev.style.pointerEvents = memPage <= 1 ? 'none' : 'auto';
  }

  const btnNext = document.getElementById('btn-mem-next');
  if (btnNext) {
    btnNext.style.opacity = memPage >= memTotalPages ? '0.5' : '1';
    btnNext.style.pointerEvents = memPage >= memTotalPages ? 'none' : 'auto';
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
