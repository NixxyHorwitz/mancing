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
/* ══════════════════════════════════════════════════════════
   REFERRAL — "KRU KAPAL CUAN" (Living Coral Reef claymorphism)
   Token warna & latar laut dari partials/header.php (--reef-*)
   ══════════════════════════════════════════════════════════ */
.kru-wrap { position: relative; z-index: 1; max-width: 480px; margin: 0 auto; padding: 10px 14px 100px; color: var(--reef-ink); }
.kru-clay { background: var(--reef-card); border: 2px solid #fff; border-radius: 24px; box-shadow: var(--reef-clay-shadow); }
.kru-sec-title { display: flex; align-items: center; justify-content: space-between; margin: 16px 4px 8px; font-size: 13px; font-weight: 900; }
.kru-sec-title span { display: inline-flex; align-items: center; gap: 6px; }
.kru-sec-title i { font-size: 17px; color: var(--reef-lagoon-600); }
.kru-sec-title small { font-size: 10px; font-weight: 800; color: var(--reef-muted); }

/* ── Flash ── */
.kru-flash { display: flex; align-items: center; gap: 9px; padding: 10px 12px; margin-bottom: 12px; border-radius: 18px; border: 2px solid #fff; font-size: 12px; font-weight: 800; box-shadow: var(--reef-clay-shadow); }
.kru-flash i { font-size: 20px; flex-shrink: 0; }
.kru-flash.success { background: linear-gradient(145deg, #ecfdf5, #d1fae5); color: #065f46; }
.kru-flash.error { background: linear-gradient(145deg, #fff1f2, #ffe4e6); color: #9f1239; }

/* ── HERO: jendela kapal (porthole) ── */
.kru-hero { position: relative; display: flex; align-items: center; gap: 14px; padding: 16px 14px 16px 16px; overflow: hidden;
  background: linear-gradient(150deg, #0e7490 0%, #0891b2 48%, #22d3ee 100%); color: #fff; border: 2px solid #fff; border-radius: 28px;
  box-shadow: 0 14px 30px rgba(8, 145, 178, 0.28), inset 3px 3px 6px rgba(255, 255, 255, 0.35), inset -4px -6px 12px rgba(8, 51, 68, 0.35); }
.kru-hero::before { content: ''; position: absolute; inset: auto -20px -34px -20px; height: 70px; background: radial-gradient(ellipse at 50% 0, rgba(255, 255, 255, 0.22), transparent 70%); }
.kru-hero-txt { position: relative; flex: 1; min-width: 0; }
.kru-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; background: rgba(255, 255, 255, 0.2); border: 1.5px solid rgba(255, 255, 255, 0.55); font-size: 9.5px; font-weight: 900; letter-spacing: 0.5px; text-transform: uppercase; }
.kru-hero-title { margin-top: 7px; font-size: 20px; font-weight: 900; line-height: 1.15; text-shadow: 0 2px 4px rgba(8, 51, 68, 0.35); }
.kru-hero-sub { margin-top: 4px; font-size: 11px; font-weight: 700; opacity: 0.92; line-height: 1.4; }
.kru-port { position: relative; flex: 0 0 auto; width: 112px; height: 112px; border-radius: 50%; padding: 7px;
  background: conic-gradient(from 210deg, #fde68a, #f59e0b, #b45309, #fde68a, #f59e0b, #fde68a);
  box-shadow: 0 8px 18px rgba(8, 51, 68, 0.4), inset 0 0 0 2px rgba(255, 255, 255, 0.6); }
.kru-port-in { position: relative; width: 100%; height: 100%; border-radius: 50%; overflow: hidden; border: 3px solid #fff;
  background: linear-gradient(180deg, #fde4cf 0%, #fbcfe8 30%, #67e8f9 52%, #0891b2 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; }
.kru-port-in svg { position: absolute; inset: 0; width: 100%; height: 100%; }
.kru-port-num { position: relative; font-size: 28px; font-weight: 900; line-height: 1; color: #fff; text-shadow: 0 2px 0 #0e7490, 0 3px 8px rgba(8, 51, 68, 0.5); margin-top: 14px; }
.kru-port-lbl { position: relative; font-size: 8.5px; font-weight: 900; letter-spacing: 0.6px; text-transform: uppercase; color: #ecfeff; text-shadow: 0 1px 2px rgba(8, 51, 68, 0.6); }
.kru-port .bolt { position: absolute; width: 8px; height: 8px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #fff7d6, #b45309); }

/* ── Promotor ── */
.kru-promo { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 9px 10px 9px 12px; border-radius: 18px; border: 2px solid #fff;
  background: linear-gradient(145deg, #c084fc, #7c3aed); color: #fff; box-shadow: 0 8px 18px rgba(124, 58, 237, 0.28), inset 2px 2px 4px rgba(255, 255, 255, 0.35); }
.kru-promo i.lead { font-size: 22px; color: #fde68a; }
.kru-promo b { flex: 1; font-size: 12px; font-weight: 900; }
.kru-promo a { display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; border-radius: 12px; background: #fff; color: #6d28d9; font-size: 11px; font-weight: 900; text-decoration: none; }

/* ── TIKET UNDANGAN (boarding pass) ── */
.kru-ticket { position: relative; display: flex; margin-top: 14px; border-radius: 22px; filter: drop-shadow(0 10px 16px rgba(244, 63, 94, 0.14)) drop-shadow(0 4px 8px rgba(8, 145, 178, 0.1)); }
.kru-ticket-main, .kru-ticket-stub { position: relative; background: linear-gradient(160deg, #ffffff 0%, #fff7ed 70%, #ffedd5 100%); }
.kru-ticket-main { flex: 1; min-width: 0; padding: 12px 14px; border-radius: 22px 0 0 22px;
  -webkit-mask: radial-gradient(circle 10px at 100% 0, transparent 98%, #000) top right / 100% 51% no-repeat, radial-gradient(circle 10px at 100% 100%, transparent 98%, #000) bottom right / 100% 51% no-repeat;
  mask: radial-gradient(circle 10px at 100% 0, transparent 98%, #000) top right / 100% 51% no-repeat, radial-gradient(circle 10px at 100% 100%, transparent 98%, #000) bottom right / 100% 51% no-repeat; }
.kru-ticket-stub { flex: 0 0 96px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px 8px; border-radius: 0 22px 22px 0;
  background: linear-gradient(160deg, #fecdd3 0%, #fb7185 100%);
  -webkit-mask: radial-gradient(circle 10px at 0 0, transparent 98%, #000) top left / 100% 51% no-repeat, radial-gradient(circle 10px at 0 100%, transparent 98%, #000) bottom left / 100% 51% no-repeat;
  mask: radial-gradient(circle 10px at 0 0, transparent 98%, #000) top left / 100% 51% no-repeat, radial-gradient(circle 10px at 0 100%, transparent 98%, #000) bottom left / 100% 51% no-repeat; }
.kru-ticket-stub::before { content: ''; position: absolute; left: -1px; top: 14px; bottom: 14px; border-left: 2.5px dashed rgba(255, 255, 255, 0.95); }
.kru-tk-row { display: flex; align-items: center; justify-content: space-between; font-size: 9px; font-weight: 900; letter-spacing: 0.6px; text-transform: uppercase; color: var(--reef-muted); }
.kru-tk-row i { color: var(--reef-coral-500); font-size: 13px; }
.kru-code { margin-top: 3px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 22px; font-weight: 900; letter-spacing: 2.5px; color: #be123c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-url { margin-top: 4px; padding: 5px 8px; border-radius: 10px; background: rgba(255, 255, 255, 0.85); font-size: 10px; font-weight: 700; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; box-shadow: inset 1px 1px 3px rgba(251, 146, 60, 0.18); }
.kru-copy { display: flex; flex-direction: column; align-items: center; gap: 3px; width: 66px; padding: 9px 4px 7px; border: 2px solid #fff; border-radius: 16px; cursor: pointer;
  background: linear-gradient(145deg, #fff1f2, #ffe4e6); color: #be123c; font-size: 10.5px; font-weight: 900; box-shadow: 0 5px 10px rgba(159, 18, 57, 0.25), inset 2px 2px 3px #fff; transition: transform 0.12s; }
.kru-copy i { font-size: 20px; }
.kru-copy:active { transform: scale(0.94); }
.kru-stub-note { font-size: 8.5px; font-weight: 900; color: #fff; text-align: center; text-transform: uppercase; letter-spacing: 0.4px; text-shadow: 0 1px 2px rgba(159, 18, 57, 0.5); }

/* Gelembung bagikan */
.kru-bubbles { display: flex; justify-content: center; gap: 16px; margin-top: 12px; }
.kru-bub { display: flex; flex-direction: column; align-items: center; gap: 5px; text-decoration: none; color: var(--reef-ink); font-size: 10px; font-weight: 900; background: none; border: none; cursor: pointer; padding: 0; }
.kru-bub span.b { width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 25px; color: #fff; border: 2.5px solid #fff;
  box-shadow: 0 7px 14px rgba(30, 27, 46, 0.18), inset 3px 3px 5px rgba(255, 255, 255, 0.5), inset -3px -4px 7px rgba(0, 0, 0, 0.16); transition: transform 0.15s; }
.kru-bub:active span.b { transform: scale(0.9); }
.kru-bub.wa span.b { background: linear-gradient(145deg, #4ade80, #16a34a); }
.kru-bub.tg span.b { background: linear-gradient(145deg, #7dd3fc, #0284c7); }
.kru-bub.sh span.b { background: linear-gradient(145deg, #fcd34d, #f59e0b); }

/* ── ALUR KOMISI (3 tahap) ── */
.kru-flow { padding: 14px 12px 12px; }
.kru-flow-steps { position: relative; display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.kru-flow-steps::before { content: ''; position: absolute; left: 16%; right: 16%; top: 21px; height: 4px; border-radius: 4px; background: repeating-linear-gradient(90deg, #a5f3fc 0 8px, transparent 8px 13px); }
.kru-step { position: relative; text-align: center; }
.kru-step .dot { width: 44px; height: 44px; margin: 0 auto 6px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; border: 2.5px solid #fff;
  box-shadow: 0 6px 12px rgba(30, 27, 46, 0.14), inset 2px 2px 4px rgba(255, 255, 255, 0.5), inset -2px -3px 6px rgba(0, 0, 0, 0.15); }
.kru-step.locked .dot { background: linear-gradient(145deg, #7dd3fc, #0284c7); }
.kru-step.ready .dot { background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.kru-step.done .dot { background: linear-gradient(145deg, #6ee7b7, #059669); }
.kru-step.ready.on .dot { animation: kruPulse 1.6s ease-in-out infinite; }
@keyframes kruPulse { 50% { box-shadow: 0 0 0 7px rgba(245, 158, 11, 0.22), inset 2px 2px 4px rgba(255, 255, 255, 0.5); } }
.kru-step b { display: block; font-size: 12.5px; font-weight: 900; white-space: nowrap; }
.kru-step.locked b { color: #0369a1; } .kru-step.ready b { color: #b45309; } .kru-step.done b { color: #047857; }
.kru-step small { display: block; margin-top: 1px; font-size: 9px; font-weight: 800; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.3px; }
.kru-flow-note { margin: 12px 0 10px; padding: 8px 10px; border-radius: 14px; background: rgba(255, 255, 255, 0.85); font-size: 10.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.45; display: flex; gap: 7px; box-shadow: inset 1px 1px 3px rgba(251, 146, 60, 0.15); }
.kru-flow-note i { font-size: 15px; color: var(--reef-lagoon-600); flex-shrink: 0; margin-top: 1px; }
.kru-flow-note strong { color: var(--reef-ink); }
.kru-claim { width: 100%; height: 52px; display: flex; align-items: center; justify-content: center; gap: 8px; border: 2.5px solid #fff; border-radius: 18px; cursor: pointer; font-size: 14px; font-weight: 900; color: #fff;
  background: linear-gradient(145deg, #6ee7b7 0%, #10b981 45%, #047857 100%); box-shadow: 0 10px 20px rgba(5, 150, 105, 0.32), inset 3px 3px 5px rgba(255, 255, 255, 0.45), inset -3px -4px 8px rgba(6, 78, 59, 0.35); transition: transform 0.12s; font-family: inherit; }
.kru-claim i { font-size: 20px; }
.kru-claim:active { transform: scale(0.98); }
.kru-claim.disabled { background: linear-gradient(145deg, #f1f5f9, #e2e8f0); color: #94a3b8; box-shadow: inset 2px 2px 4px #fff, inset -2px -3px 6px rgba(100, 116, 139, 0.15); cursor: not-allowed; pointer-events: none; }

/* Saldo mini */
.kru-wallet { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.kru-wallet > div { display: flex; align-items: center; gap: 9px; padding: 10px; border-radius: 18px; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); min-width: 0; }
.kru-wallet .ico { width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; box-shadow: inset 2px 2px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(0, 0, 0, 0.14); }
.kru-wallet .ico.gold { background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.kru-wallet .ico.violet { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.kru-wallet b { display: block; font-size: 13px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-wallet small { display: block; font-size: 9px; font-weight: 800; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.3px; }

/* ── CARA KERJA (anak tangga) ── */
.kru-how { display: flex; flex-direction: column; gap: 8px; }
.kru-how-row { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 20px; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.kru-how-row:nth-child(2) { margin-left: 14px; } .kru-how-row:nth-child(3) { margin-left: 28px; }
.kru-how-num { width: 38px; height: 38px; flex-shrink: 0; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; border: 2px solid #fff;
  box-shadow: 0 5px 10px rgba(30, 27, 46, 0.14), inset 2px 2px 3px rgba(255, 255, 255, 0.5); }
.kru-how-row:nth-child(1) .kru-how-num { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.kru-how-row:nth-child(2) .kru-how-num { background: linear-gradient(145deg, #fda4af, #e11d48); }
.kru-how-row:nth-child(3) .kru-how-num { background: linear-gradient(145deg, #6ee7b7, #059669); }
.kru-how-row b { display: block; font-size: 12px; font-weight: 900; }
.kru-how-row small { display: block; font-size: 10px; font-weight: 700; color: var(--reef-muted); margin-top: 1px; }
.kru-how-row .gain { margin-left: auto; flex-shrink: 0; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 900; color: #047857; background: #d1fae5; }

/* ── TAB pelampung ── */
.kru-tabs { display: flex; gap: 8px; margin: 18px 0 10px; }
.ref-tab-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 8px; border: 2px solid #fff; border-radius: 999px; cursor: pointer; font-family: inherit;
  background: rgba(255, 255, 255, 0.7); color: var(--reef-muted); font-size: 11.5px; font-weight: 900; box-shadow: 0 4px 10px rgba(8, 145, 178, 0.08), inset 2px 2px 3px #fff; transition: all 0.15s; }
.ref-tab-btn i { font-size: 15px; }
.ref-tab-btn.active { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 7px 14px rgba(225, 29, 72, 0.3), inset 2px 2px 4px rgba(255, 255, 255, 0.4); }
.ref-tab-pill { min-width: 20px; padding: 1px 6px; border-radius: 999px; font-size: 10px; background: rgba(8, 145, 178, 0.12); color: #0e7490; }
.ref-tab-btn.active .ref-tab-pill { background: rgba(255, 255, 255, 0.95); color: #e11d48; }
.ref-tab-panel { display: none; }
.ref-tab-panel.active { display: block; }

/* Kartu kru (daftar kawan) */
.kru-crew { display: flex; flex-direction: column; gap: 8px; }
.kru-mate { display: flex; align-items: center; gap: 11px; padding: 9px 12px 9px 9px; border-radius: 20px; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.kru-ava { position: relative; width: 44px; height: 44px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 900; color: #fff; border: 2.5px solid #fff;
  box-shadow: 0 4px 9px rgba(30, 27, 46, 0.16), inset 2px 2px 4px rgba(255, 255, 255, 0.45), inset -2px -3px 5px rgba(0, 0, 0, 0.14); }
.kru-ava.c0 { background: linear-gradient(145deg, #67e8f9, #0891b2); } .kru-ava.c1 { background: linear-gradient(145deg, #fda4af, #e11d48); }
.kru-ava.c2 { background: linear-gradient(145deg, #6ee7b7, #059669); } .kru-ava.c3 { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.kru-ava.c4 { background: linear-gradient(145deg, #fcd34d, #d97706); }
.kru-ava .vip { position: absolute; right: -4px; bottom: -3px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #78350f; background: linear-gradient(145deg, #fef08a, #f59e0b); border: 2px solid #fff; }
.kru-mate-info { flex: 1; min-width: 0; }
.kru-mate-name { font-size: 12.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-mate-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; flex-wrap: wrap; }
.kru-tag { padding: 1.5px 7px; border-radius: 999px; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.3px; }
.kru-tag.free { background: #f1f5f9; color: #64748b; }
.kru-tag.vip { background: linear-gradient(145deg, #fef3c7, #fde68a); color: #92400e; }
.kru-date { font-size: 9.5px; font-weight: 700; color: var(--reef-muted); display: inline-flex; align-items: center; gap: 3px; }
.kru-mate-amt { flex-shrink: 0; text-align: right; }
.kru-mate-amt b { display: block; font-size: 12.5px; font-weight: 900; color: #059669; }
.kru-mate-amt small { font-size: 8.5px; font-weight: 800; color: var(--reef-muted); text-transform: uppercase; }

.kru-pg { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 12px; }
.kru-pg-btn { width: 38px; height: 38px; border-radius: 50%; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); color: #0e7490; font-size: 16px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.kru-pg-info { min-width: 64px; text-align: center; padding: 6px 12px; border-radius: 999px; background: rgba(255, 255, 255, 0.85); font-size: 11px; font-weight: 900; color: #0e7490; }

/* Garis waktu riwayat */
.kru-timeline { position: relative; padding-left: 26px; }
.kru-timeline::before { content: ''; position: absolute; left: 10px; top: 8px; bottom: 8px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #67e8f9, #fda4af, #fcd34d); }
.kru-tl { position: relative; margin-bottom: 8px; padding: 9px 12px; border-radius: 18px; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); display: flex; align-items: center; gap: 10px; }
.kru-tl::before { content: ''; position: absolute; left: -22px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 5px rgba(30, 27, 46, 0.2); }
.kru-tl.claimed::before { background: #10b981; } .kru-tl.ready::before { background: #f59e0b; } .kru-tl.locked::before { background: #0ea5e9; }
.kru-tl-body { flex: 1; min-width: 0; }
.kru-tl-title { font-size: 12px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-tl-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; flex-wrap: wrap; }
.kru-st { display: inline-flex; align-items: center; gap: 3px; padding: 1.5px 7px; border-radius: 999px; font-size: 9px; font-weight: 900; text-transform: uppercase; }
.kru-st.claimed { background: #d1fae5; color: #047857; } .kru-st.ready { background: #fef3c7; color: #b45309; } .kru-st.locked { background: #e0f2fe; color: #0369a1; }
.kru-tl-amt { flex-shrink: 0; font-size: 13px; font-weight: 900; color: #059669; }

/* Kosong */
.kru-empty { text-align: center; padding: 22px 16px; border-radius: 22px; border: 2px dashed rgba(8, 145, 178, 0.35); background: rgba(255, 255, 255, 0.65); }
.kru-empty .ic { width: 56px; height: 56px; margin: 0 auto 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #fff; background: linear-gradient(145deg, #67e8f9, #0891b2); border: 3px solid #fff; box-shadow: 0 6px 14px rgba(8, 145, 178, 0.25); }
.kru-empty b { display: block; font-size: 13px; font-weight: 900; }
.kru-empty small { display: block; margin-top: 3px; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.4; }
@media (prefers-reduced-motion: reduce) { .kru-step.ready.on .dot { animation: none; } }

/* ── Mode padat (compact) ── */
.kru-wrap { padding: 8px 12px 96px; }
.kru-sec-title { margin: 12px 4px 6px; font-size: 12.5px; }
.kru-hero { padding: 11px 11px 11px 13px; gap: 10px; border-radius: 22px; }
.kru-hero-title { margin-top: 5px; font-size: 16.5px; }
.kru-hero-sub { margin-top: 3px; font-size: 10.5px; line-height: 1.35; }
.kru-chip { padding: 2px 8px; font-size: 9px; }
.kru-port { width: 90px; height: 90px; padding: 6px; }
.kru-port-num { margin-top: 10px; }
.kru-port-lbl { font-size: 8px; }
.kru-promo { margin-top: 8px; padding: 6px 7px 6px 10px; border-radius: 16px; }
.kru-promo i.lead { font-size: 18px; }
.kru-promo b { font-size: 11.5px; }
.kru-promo a { padding: 5px 10px; font-size: 10.5px; }
.kru-ticket { margin-top: 9px; }
.kru-ticket-main { padding: 8px 12px; }
.kru-code { margin-top: 1px; font-size: 18px; letter-spacing: 2px; }
.kru-url { margin-top: 3px; padding: 4px 7px; font-size: 9.5px; }
.kru-ticket-stub { flex-basis: 80px; padding: 7px 6px; gap: 3px; }
.kru-copy { width: 58px; padding: 6px 3px 5px; border-radius: 14px; font-size: 9.5px; }
.kru-copy i { font-size: 17px; }
.kru-stub-note { font-size: 7.5px; }
.kru-bubbles { margin-top: 8px; gap: 18px; }
.kru-bub { gap: 3px; font-size: 9.5px; }
.kru-bub span.b { width: 42px; height: 42px; font-size: 20px; }
.kru-flow { padding: 10px 10px 10px; border-radius: 20px; }
.kru-flow-steps::before { top: 17px; }
.kru-step .dot { width: 36px; height: 36px; margin-bottom: 4px; font-size: 16px; }
.kru-step b { font-size: 11.5px; }
.kru-step small { font-size: 8.5px; }
.kru-flow-note { margin: 8px 0; padding: 6px 9px; font-size: 10px; line-height: 1.4; border-radius: 12px; }
.kru-flow-note i { font-size: 13px; }
.kru-claim { height: 44px; font-size: 13px; border-radius: 15px; }
.kru-claim i { font-size: 18px; }
.kru-wallet { gap: 6px; margin-top: 8px; }
.kru-wallet > div { padding: 7px 8px; gap: 7px; border-radius: 15px; }
.kru-wallet .ico { width: 30px; height: 30px; font-size: 15px; border-radius: 10px; }
.kru-wallet b { font-size: 12px; }
.kru-wallet small { font-size: 8px; }
/* Cara kerja: 3 kolom berjajar */
.kru-how { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.kru-how-row { flex-direction: column; text-align: center; gap: 4px; padding: 8px 5px 7px; margin-left: 0 !important; border-radius: 16px; }
.kru-how-num { width: 32px; height: 32px; font-size: 15px; border-radius: 11px; }
.kru-how-row b { font-size: 10.5px; line-height: 1.15; }
.kru-how-row small { font-size: 8.5px; line-height: 1.25; }
.kru-how-row .gain { margin: 1px auto 0; padding: 2px 7px; font-size: 10px; }
.kru-tabs { margin: 12px 0 8px; gap: 6px; }
.ref-tab-btn { padding: 7px 8px; font-size: 11px; }
.kru-crew { gap: 6px; }
.kru-mate { gap: 9px; padding: 6px 10px 6px 6px; border-radius: 16px; }
.kru-ava { width: 36px; height: 36px; font-size: 15px; border-width: 2px; }
.kru-ava .vip { width: 15px; height: 15px; font-size: 8px; }
.kru-mate-name { font-size: 12px; }
.kru-mate-meta { margin-top: 2px; gap: 5px; }
.kru-mate-amt b { font-size: 12px; }
.kru-pg { margin-top: 8px; }
.kru-pg-btn { width: 34px; height: 34px; }
.kru-tl { padding: 7px 10px; margin-bottom: 6px; border-radius: 15px; }
.kru-tl-title { font-size: 11.5px; }
.kru-empty { padding: 16px 14px; }
</style>

<?php
$code_initial = static fn(string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
$code_color   = static fn(string $name): string => 'c' . (crc32($name) % 5);
?>
<div class="kru-wrap">

  <?php if ($flash): ?>
    <div class="kru-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-fill ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- HERO: jendela kapal berisi jumlah kru -->
  <section class="kru-hero">
    <div class="kru-hero-txt">
      <span class="kru-chip"><i class="ph-fill ph-anchor-simple"></i> Misi Kru Kapal</span>
      <div class="kru-hero-title">Ajak Kawan Melaut, Panen Cuan Bareng</div>
      <div class="kru-hero-sub">Bonus <b><?= format_rp($ref_bonus) ?></b> tiap kawan daftar &amp; komisi <b><?= (float)$ref_pct ?>%</b> dari setiap isi saldonya, seumur hidup.</div>
    </div>
    <div class="kru-port" aria-label="Jumlah kawan terdaftar">
      <span class="bolt" style="top:4px;left:50%;margin-left:-4px"></span><span class="bolt" style="bottom:4px;left:50%;margin-left:-4px"></span>
      <span class="bolt" style="left:4px;top:50%;margin-top:-4px"></span><span class="bolt" style="right:4px;top:50%;margin-top:-4px"></span>
      <div class="kru-port-in">
        <svg viewBox="0 0 100 100" aria-hidden="true">
          <circle cx="74" cy="20" r="8" fill="#fff7ed" opacity=".9"/>
          <path d="M0 40 C18 35 30 44 50 39 S82 34 100 40 V100 H0Z" fill="#0891b2" opacity=".5"/>
          <path d="M20 37 L36 37 L33 42 L23 42Z" fill="#fb7185"/><path d="M28 37 V26 L35 35Z" fill="#fff"/>
          <g transform="translate(18 74)"><path d="M0 0 C4 -5 12 -5 16 0 C12 5 4 5 0 0Z" fill="#fbbf24"/><path d="M-5 -4 L0 0 L-5 4Z" fill="#f59e0b"/><circle cx="11" cy="-1" r="1.3" fill="#1e1b2e"/></g>
          <g transform="translate(72 82) scale(-1 1)"><path d="M0 0 C4 -5 12 -5 16 0 C12 5 4 5 0 0Z" fill="#fda4af"/><path d="M-5 -4 L0 0 L-5 4Z" fill="#f43f5e"/><circle cx="11" cy="-1" r="1.3" fill="#1e1b2e"/></g>
          <circle cx="60" cy="66" r="2" fill="#fff" opacity=".8"/><circle cx="64" cy="60" r="1.3" fill="#fff" opacity=".7"/>
        </svg>
        <?php $refCountTxt = number_format($ref_count, 0, ',', '.'); ?>
        <div class="kru-port-num" style="font-size:<?= [1 => 26, 2 => 26, 3 => 22, 4 => 18, 5 => 15][strlen($refCountTxt)] ?? 13 ?>px"><?= $refCountTxt ?></div>
        <div class="kru-port-lbl">Kawan</div>
      </div>
    </div>
  </section>

  <?php if ((int)($user['is_promotor'] ?? 0) === 1): ?>
  <div class="kru-promo">
    <i class="ph-fill ph-shield-star lead"></i>
    <b>Status Promotor Aktif</b>
    <a href="/user/promotor.php"><i class="ph-bold ph-chart-line-up"></i> Dashboard</a>
  </div>
  <?php endif; ?>

  <!-- TIKET UNDANGAN -->
  <div class="kru-ticket">
    <div class="kru-ticket-main">
      <div class="kru-tk-row"><span>Tiket Undangan Kapal</span><i class="ph-fill ph-ticket"></i></div>
      <div class="kru-code" id="ref-code-val"><?= htmlspecialchars($user['referral_code']) ?></div>
      <div class="kru-url"><i class="ph-bold ph-link-simple"></i> <?= htmlspecialchars(preg_replace('~^https?://~', '', $ref_url)) ?></div>
    </div>
    <div class="kru-ticket-stub">
      <button type="button" onclick="copyRefLink()" class="kru-copy" id="btn-copy-code">
        <i class="ph-bold ph-copy" id="copy-ico"></i>
        <span id="copy-txt">Salin Link</span>
      </button>
      <div class="kru-stub-note">Tap salin</div>
    </div>
  </div>
  <div class="kru-bubbles">
    <a href="https://wa.me/?text=<?= urlencode($share_text) ?>" target="_blank" rel="noopener noreferrer" class="kru-bub wa"><span class="b"><i class="ph-fill ph-whatsapp-logo"></i></span>WhatsApp</a>
    <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Yuk gabung dan dapatkan saldo gratis!') ?>" target="_blank" rel="noopener noreferrer" class="kru-bub tg"><span class="b"><i class="ph-fill ph-telegram-logo"></i></span>Telegram</a>
    <button type="button" onclick="shareNative()" class="kru-bub sh"><span class="b"><i class="ph-bold ph-share-network"></i></span>Lainnya</button>
  </div>

  <!-- ALUR KOMISI -->
  <div class="kru-sec-title"><span><i class="ph-fill ph-hand-coins"></i> Jaring Komisi</span><small>Masa tahan <?= $ref_hold_days ?> hari</small></div>
  <section class="kru-clay kru-flow">
    <div class="kru-flow-steps">
      <div class="kru-step locked"><div class="dot"><i class="ph-fill ph-lock-key"></i></div><b><?= format_rp($ref_locked) ?></b><small>Masa Beku</small></div>
      <div class="kru-step ready <?= $ref_claimable > 0 ? 'on' : '' ?>"><div class="dot"><i class="ph-fill ph-lightning"></i></div><b><?= format_rp($ref_claimable) ?></b><small>Siap Cair</small></div>
      <div class="kru-step done"><div class="dot"><i class="ph-fill ph-check-circle"></i></div><b><?= format_rp($ref_claimed) ?></b><small>Sudah Cair</small></div>
    </div>

    <div class="kru-flow-note">
      <i class="ph-fill ph-info"></i>
      <span>
        <?php if ($ref_claimable > 0): ?>
          Masa beku selesai! Cairkan sekarang langsung ke <strong>Saldo Penarikan</strong> kamu.
        <?php elseif ($ref_locked > 0): ?>
          <?php if ($earliest_unlock): ?>
            Komisi terdekat bisa dicairkan pada <strong><?= date('d M Y, H:i', strtotime($earliest_unlock)) ?> WIB</strong> (masa tahan <?= $ref_hold_days ?> hari).
          <?php else: ?>
            Menunggu masa tahan <?= $ref_hold_days ?> hari sebelum bisa dicairkan ke saldo penarikan.
          <?php endif; ?>
        <?php else: ?>
          Komisi pendaftaran (+<?= format_rp($ref_bonus) ?>) dan deposit (+<?= (float)$ref_pct ?>%) dibekukan <?= $ref_hold_days ?> hari sebelum siap dicairkan.
        <?php endif; ?>
      </span>
    </div>

    <?php if ($ref_claimable > 0): ?>
      <form method="POST" action="" onsubmit="this.querySelector('button').disabled = true;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim_commissions">
        <button type="submit" class="kru-claim" id="btn-claim-commissions">
          <i class="ph-fill ph-hand-coins"></i>
          <span>Cairkan <?= format_rp($ref_claimable) ?> Sekarang</span>
        </button>
      </form>
    <?php else: ?>
      <button type="button" class="kru-claim disabled" disabled>
        <i class="ph-bold <?= $ref_locked > 0 ? 'ph-hourglass-medium' : 'ph-check-circle' ?>"></i>
        <span><?= $ref_locked > 0 ? 'Menunggu Waktu Buka' : 'Tidak Ada Komisi Tertahan' ?></span>
      </button>
    <?php endif; ?>
  </section>

  <div class="kru-wallet">
    <div><span class="ico gold"><i class="ph-fill ph-wallet"></i></span><span style="min-width:0"><b><?= format_rp((float)$user['balance_wd']) ?></b><small>Saldo Siap WD</small></span></div>
    <div><span class="ico violet"><i class="ph-fill ph-trophy"></i></span><span style="min-width:0"><b><?= format_rp($ref_earned) ?></b><small>Total Komisi</small></span></div>
  </div>

  <!-- CARA KERJA -->
  <div class="kru-sec-title"><span><i class="ph-fill ph-compass"></i> Cara Kerjanya</span></div>
  <div class="kru-how">
    <div class="kru-how-row"><span class="kru-how-num"><i class="ph-fill ph-paper-plane-tilt"></i></span><span><b>Bagikan tiketmu</b><small>Kirim link atau kode ke kawan</small></span></div>
    <div class="kru-how-row"><span class="kru-how-num"><i class="ph-fill ph-user-plus"></i></span><span><b>Kawan naik kapal</b><small>Daftar memakai kodemu</small></span><span class="gain">+<?= format_rp($ref_bonus) ?></span></div>
    <div class="kru-how-row"><span class="kru-how-num"><i class="ph-fill ph-coins"></i></span><span><b>Kawan isi saldo</b><small>Komisi tiap deposit, seumur hidup</small></span><span class="gain">+<?= (float)$ref_pct ?>%</span></div>
  </div>

  <!-- TAB -->
  <div class="kru-tabs">
    <button type="button" class="ref-tab-btn active" onclick="switchRefTab('members', this)">
      <i class="ph-fill ph-users-three"></i><span>Kru Kawan</span><span class="ref-tab-pill"><?= count($referreds) ?></span>
    </button>
    <button type="button" class="ref-tab-btn" onclick="switchRefTab('history', this)">
      <i class="ph-fill ph-clock-counter-clockwise"></i><span>Riwayat</span><span class="ref-tab-pill"><?= count($history) ?></span>
    </button>
  </div>

  <!-- PANEL: KAWAN -->
  <div id="panel-members" class="ref-tab-panel active">
    <?php if (empty($referreds)): ?>
      <div class="kru-empty">
        <div class="ic"><i class="ph-fill ph-user-plus"></i></div>
        <b>Kapalmu masih sepi</b>
        <small>Bagikan tiket undanganmu untuk mulai mengumpulkan kru dan komisi!</small>
      </div>
    <?php else: ?>
      <div class="kru-crew">
        <?php foreach ($referreds as $idx => $r):
          $isFree = (stripos((string)$r['membership_name'], 'Free') !== false || (string)$r['membership_name'] === '');
        ?>
          <div class="kru-mate ref-member-item" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
            <div class="kru-ava <?= $code_color((string)$r['username']) ?>">
              <?= htmlspecialchars($code_initial((string)$r['username'])) ?>
              <?php if (!$isFree): ?><span class="vip"><i class="ph-fill ph-crown"></i></span><?php endif; ?>
            </div>
            <div class="kru-mate-info">
              <div class="kru-mate-name"><?= htmlspecialchars($r['username']) ?></div>
              <div class="kru-mate-meta">
                <span class="kru-tag <?= $isFree ? 'free' : 'vip' ?>"><?= htmlspecialchars($r['membership_name'] ?: 'Free') ?></span>
                <span class="kru-date"><i class="ph-bold ph-calendar-blank"></i><?= date('d M Y', strtotime($r['created_at'])) ?></span>
              </div>
            </div>
            <div class="kru-mate-amt"><b>+<?= format_rp((float)$r['commission_earned']) ?></b><small>Komisi</small></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($referreds) > 5): ?>
        <div class="kru-pg">
          <button type="button" onclick="changeMemberPage(-1)" id="btn-mem-prev" class="kru-pg-btn" style="opacity: 0.5; pointer-events: none;" aria-label="Sebelumnya"><i class="ph-bold ph-caret-left"></i></button>
          <span id="mem-page-info" class="kru-pg-info">1 / <?= ceil(count($referreds) / 5) ?></span>
          <button type="button" onclick="changeMemberPage(1)" id="btn-mem-next" class="kru-pg-btn" aria-label="Berikutnya"><i class="ph-bold ph-caret-right"></i></button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- PANEL: RIWAYAT -->
  <div id="panel-history" class="ref-tab-panel">
    <?php if (empty($history)): ?>
      <div class="kru-empty">
        <div class="ic"><i class="ph-fill ph-receipt"></i></div>
        <b>Belum ada riwayat komisi</b>
        <small>Komisi pendaftaran atau deposit kawanmu akan tercatat otomatis di sini.</small>
      </div>
    <?php else: ?>
      <div class="kru-timeline">
        <?php foreach ($history as $h):
          $isClaimed = ($h['status'] === 'claimed');
          $isReady   = ($h['status'] === 'locked' && ($h['unlock_at'] === null || strtotime($h['unlock_at']) <= time()));
          $state     = $isClaimed ? 'claimed' : ($isReady ? 'ready' : 'locked');
        ?>
          <div class="kru-tl <?= $state ?>">
            <div class="kru-tl-body">
              <div class="kru-tl-title">Komisi dari <?= htmlspecialchars($h['username']) ?></div>
              <div class="kru-tl-meta">
                <?php if ($isClaimed): ?>
                  <span class="kru-st claimed"><i class="ph-bold ph-check-circle"></i> Dicairkan</span>
                <?php elseif ($isReady): ?>
                  <span class="kru-st ready"><i class="ph-bold ph-lightning"></i> Siap Cair</span>
                <?php else: ?>
                  <span class="kru-st locked" title="Bisa dicairkan pada <?= date('d M Y H:i', strtotime($h['unlock_at'])) ?> WIB"><i class="ph-bold ph-lock-key"></i> Buka <?= date('d M H:i', strtotime($h['unlock_at'])) ?></span>
                <?php endif; ?>
                <span class="kru-date"><?= date('d M Y, H:i', strtotime($h['created_at'])) ?></span>
              </div>
            </div>
            <div class="kru-tl-amt">+<?= format_rp((float)$h['amount']) ?></div>
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
