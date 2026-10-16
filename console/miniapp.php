<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';
staff_require('users'); // Membutuhkan login admin

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'search_users') {
            $q = trim($_POST['query'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['ok' => true, 'users' => []]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 20");
            $like = "%{$q}%";
            $stmt->execute([$like, $like]);
            echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()]);
            exit;
        }
        
        if ($action === 'get_user') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if (!$u) throw new Exception("User tidak ditemukan.");
            
            // Get pending deposits
            $dep = $pdo->prepare("SELECT id, amount, status, created_at FROM deposits WHERE user_id=? AND status='pending' ORDER BY id DESC");
            $dep->execute([$id]);
            $u['pending_deposits'] = $dep->fetchAll();
            
            // Get pending withdrawals
            $wd = $pdo->prepare("SELECT id, amount, status, created_at FROM withdrawals WHERE user_id=? AND status IN ('pending','hold') ORDER BY id DESC");
            $wd->execute([$id]);
            $u['pending_withdrawals'] = $wd->fetchAll();
            
            // Get memberships for dropdown
            $ms = $pdo->query("SELECT id, name FROM memberships ORDER BY id ASC")->fetchAll();
            $u['memberships'] = $ms;
            
            echo json_encode(['ok' => true, 'user' => $u]);
            exit;
        }
        
        if ($action === 'save_user') {
            $id = (int)($_POST['id'] ?? 0);
            $upd = [
                'username' => trim($_POST['username'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'whatsapp' => trim($_POST['whatsapp'] ?? ''),
                'balance_wd' => (float)($_POST['balance_wd'] ?? 0),
                'balance_dep' => (float)($_POST['balance_dep'] ?? 0),
                'total_earned' => (float)($_POST['total_earned'] ?? 0),

                'is_active' => (int)($_POST['is_active'] ?? 1),
                'can_withdraw' => (int)($_POST['can_withdraw'] ?? 1),
                'can_chat' => (int)($_POST['can_chat'] ?? 1),
                'bank_name' => trim($_POST['bank_name'] ?? ''),
                'account_number' => trim($_POST['account_number'] ?? ''),
                'account_name' => trim($_POST['account_name'] ?? ''),
                'refund_cut_percent' => (float)($_POST['refund_cut_percent'] ?? 20),
                'is_refund_enabled' => (int)($_POST['is_refund_enabled'] ?? 1),
                'is_promotor' => (int)($_POST['is_promotor'] ?? 0),
                'edit_bank_deposit_min' => (int)($_POST['edit_bank_deposit_min'] ?? 50000),

                'is_referral_active' => (int)($_POST['is_referral_active'] ?? 1),
                'promotor_target_deposits' => (int)($_POST['promotor_target_deposits'] ?? 0),
                'promotor_target_regs' => (int)($_POST['promotor_target_regs'] ?? 0),
                'promotor_salary_rate' => (float)($_POST['promotor_salary_rate'] ?? 0),
                'referral_code' => trim($_POST['referral_code'] ?? ''),
                'membership_expires_at' => trim($_POST['membership_expires_at'] ?? '') ?: null,
            ];
            
            if (!empty($_POST['new_password'])) {
                $upd['password_hash'] = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);
            }
            
            $mid = (int)($_POST['membership_id'] ?? 0);
            $memSql = "";
            if ($mid > 0) {
                $upd['membership_id'] = $mid;
                $memSql = ", membership_id=:membership_id";
            }
            
            $sql = "UPDATE users SET 
                username=:username, email=:email, whatsapp=:whatsapp,
                balance_wd=:balance_wd, balance_dep=:balance_dep, total_earned=:total_earned,
                is_active=:is_active, can_withdraw=:can_withdraw,
                can_chat=:can_chat, bank_name=:bank_name, account_number=:account_number,
                account_name=:account_name, refund_cut_percent=:refund_cut_percent,
                is_refund_enabled=:is_refund_enabled, is_promotor=:is_promotor,
                edit_bank_deposit_min=:edit_bank_deposit_min,
                is_referral_active=:is_referral_active, promotor_target_deposits=:promotor_target_deposits,
                promotor_target_regs=:promotor_target_regs, promotor_salary_rate=:promotor_salary_rate,
                referral_code=:referral_code, membership_expires_at=:membership_expires_at {$memSql}";
                
            if (isset($upd['password_hash'])) {
                $sql .= ", password_hash=:password_hash";
            }
            $sql .= " WHERE id=:id";
                
            $upd['id'] = $id;
            $pdo->prepare($sql)->execute($upd);
            
            echo json_encode(['ok' => true, 'msg' => 'Data user berhasil disimpan.']);
            exit;
        }
        
        if ($action === 'action_depo') {
            $id = (int)($_POST['id'] ?? 0);
            $type = $_POST['type'] ?? ''; // approve / reject
            
            $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND status='pending' FOR UPDATE");
            $pdo->beginTransaction();
            $stmt->execute([$id]);
            $d = $stmt->fetch();
            if (!$d) throw new Exception("Deposit tidak ditemukan atau bukan pending.");
            
            if ($type === 'approve') {
                $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$d['amount'], $d['user_id']]);
                $pdo->prepare("UPDATE deposits SET status='confirmed', admin_note=NULL, confirmed_at=NOW() WHERE id=?")->execute([$id]);
            } else {
                $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Ditolak oleh Admin' WHERE id=?")->execute([$id]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => "Deposit #{$id} berhasil di-{$type}."]);
            exit;
        }
        
        if ($action === 'action_wd') {
            $id = (int)($_POST['id'] ?? 0);
            $type = $_POST['type'] ?? ''; // approve / reject / hold
            
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
            $pdo->beginTransaction();
            $stmt->execute([$id]);
            $w = $stmt->fetch();
            if (!$w || in_array($w['status'], ['approved','rejected','refunded'])) throw new Exception("Withdraw tidak dapat diproses.");
            
            if ($type === 'approve') {
                $pdo->prepare("UPDATE withdrawals SET status='approved', admin_note='Disetujui oleh Tim Keuangan', processed_at=NOW() WHERE id=?")->execute([$id]);
            } elseif ($type === 'reject') {
                $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$w['amount'], $w['user_id']]);
                $pdo->prepare("UPDATE withdrawals SET status='rejected', admin_note='Dibatalkan oleh Admin (Data tidak valid)', processed_at=NOW() WHERE id=?")->execute([$id]);
            } elseif ($type === 'hold') {
                $pdo->prepare("UPDATE withdrawals SET status='hold', admin_note='Peninjauan antrean audit keuangan', processed_at=NOW() WHERE id=?")->execute([$id]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => "Withdraw #{$id} berhasil di-{$type}."]);
            exit;
        }
        
        throw new Exception("Unknown action: " . $action);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admin Mini App</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
  :root {
    --bg-color: var(--tg-theme-bg-color, #ffffff);
    --text-color: var(--tg-theme-text-color, #000000);
    --hint-color: var(--tg-theme-hint-color, #999999);
    --link-color: var(--tg-theme-link-color, #2481cc);
    --btn-color: var(--tg-theme-button-color, #2481cc);
    --btn-text: var(--tg-theme-button-text-color, #ffffff);
    --sec-bg: var(--tg-theme-secondary-bg-color, #f0f0f0);
  }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background-color: var(--bg-color);
    color: var(--text-color);
    margin: 0; padding: 16px;
  }
  * { box-sizing: border-box; }
  h3 { margin-top: 0; color: var(--text-color); font-size: 18px; font-weight: 800; }
  
  input, select, button {
    width: 100%;
    padding: 12px;
    margin-bottom: 12px;
    border: 1px solid var(--hint-color);
    border-radius: 8px;
    font-size: 14px;
    background: var(--bg-color);
    color: var(--text-color);
    outline: none;
  }
  input:focus, select:focus { border-color: var(--btn-color); }
  
  .btn {
    background: var(--btn-color);
    color: var(--btn-text);
    border: none;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
  }
  .btn:active { opacity: 0.8; }
  .btn-danger { background: #e74c3c; color: #fff; }
  .btn-warning { background: #f39c12; color: #fff; }
  .btn-success { background: #2ecc71; color: #fff; }
  
  .flex-row { display: flex; gap: 8px; }
  
  #search-results { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
  .user-card {
    padding: 12px; background: var(--sec-bg); border-radius: 8px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .user-card-info { flex: 1; }
  .user-card-name { font-weight: bold; font-size: 15px; }
  .user-card-email { font-size: 12px; color: var(--hint-color); }
  
  .section-card {
    background: var(--sec-bg);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
  }
  
  .label-group { font-size: 12px; font-weight: bold; color: var(--hint-color); margin-bottom: 4px; display: block; }
  
  .txn-item {
    background: var(--bg-color);
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 8px;
    font-size: 13px;
    border: 1px solid rgba(0,0,0,0.1);
  }
</style>
</head>
<body>

<div id="view-search">
  <h3>🔍 Cari User</h3>
  <div class="flex-row">
    <input type="text" id="search-q" placeholder="Username / Email..." onkeydown="if(event.key==='Enter') searchUsers()">
    <button class="btn" style="width:auto" onclick="searchUsers()">Cari</button>
  </div>
  <div id="search-results"></div>
</div>

<div id="view-edit" style="display:none;">
  <button class="btn" style="background:var(--sec-bg); color:var(--text-color); margin-bottom:16px;" onclick="backToSearch()">⬅️ Kembali</button>
  <h3 id="edit-title">Edit User</h3>
  
  <form id="edit-form" onsubmit="saveUser(event)">
    <input type="hidden" id="f_id">
    
    <div class="section-card">
      <label class="label-group">Data Dasar</label>
      <input type="text" id="f_username" placeholder="Username" required>
      <input type="email" id="f_email" placeholder="Email" required>
      <input type="text" id="f_whatsapp" placeholder="WhatsApp">
      <input type="text" id="f_new_password" placeholder="Password Baru (Kosongkan jika tidak diubah)">
      <div class="flex-row">
        <select id="f_membership"></select>
        <input type="datetime-local" id="f_membership_expires_at" placeholder="Expired">
      </div>
      <input type="text" id="f_referral_code" placeholder="Kode Referral">
    </div>
    
    <div class="section-card">
      <label class="label-group">Saldo & Koin</label>
      <div class="flex-row">
        <input type="number" id="f_balance_wd" step="0.01" placeholder="Saldo WD">
        <input type="number" id="f_balance_dep" step="0.01" placeholder="Saldo Depo">
      </div>
      <div class="flex-row">
        <input type="number" id="f_total_earned" step="0.01" placeholder="Total Earned">
      </div>
      <div class="flex-row">
        <input type="number" id="f_edit_bank_deposit_min" placeholder="Min. Depo (Rek)">
      </div>
    </div>
    
    <div class="section-card">
      <label class="label-group">Akses & Status</label>
      <div class="flex-row">
        <select id="f_is_active">
          <option value="1">Aktif</option><option value="0">Banned</option>
        </select>
        <select id="f_can_withdraw">
          <option value="1">Bisa WD</option><option value="0">Block WD</option>
        </select>
      </div>
      <div class="flex-row">
        <select id="f_can_chat">
          <option value="1">Bisa Chat</option><option value="0">Block Chat</option>
        </select>
        <select id="f_is_referral_active">
          <option value="1">Referral Aktif</option><option value="0">Referral Block</option>
        </select>
      </div>
    </div>
    
    <div class="section-card">
      <label class="label-group">Promotor</label>
      <select id="f_is_promotor">
        <option value="0">Member</option><option value="1">Promotor</option>
      </select>
      <div class="flex-row">
        <input type="number" id="f_promotor_target_deposits" placeholder="Target Depo">
        <input type="number" id="f_promotor_target_regs" placeholder="Target Reg">
      </div>
      <input type="number" id="f_promotor_salary_rate" step="0.01" placeholder="Salary Rate">
    </div>
    
    <div class="section-card">
      <label class="label-group">Rekening</label>
      <input type="text" id="f_bank_name" placeholder="Nama Bank/E-Wallet">
      <input type="text" id="f_account_number" placeholder="Nomor Rekening">
      <input type="text" id="f_account_name" placeholder="Nama Rekening">
    </div>
    
    <div class="section-card">
      <label class="label-group">Pengaturan Refund</label>
      <div class="flex-row">
        <input type="number" id="f_refund_cut" step="0.01" placeholder="Potongan Refund %">
        <select id="f_refund_enabled">
          <option value="1">Refund Aktif</option><option value="0">Refund Nonaktif</option>
        </select>
      </div>
    </div>
    
    <button type="submit" class="btn" id="btn-save">💾 Simpan Data User</button>
  </form>
  
  <!-- Pending Transactions -->
  <div class="section-card" id="sect-depo" style="margin-top:20px; display:none;">
    <h4 style="margin-top:0;margin-bottom:8px;">⬇️ Deposit Pending</h4>
    <div id="list-depo"></div>
  </div>
  
  <div class="section-card" id="sect-wd" style="margin-top:20px; display:none;">
    <h4 style="margin-top:0;margin-bottom:8px;">⬆️ Withdraw Pending/Hold</h4>
    <div id="list-wd"></div>
  </div>
  
</div>

<script>
let tg = window.Telegram.WebApp;
tg.expand(); // Expand app to maximum available height

function post(data) {
  return fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data)
  }).then(r => r.json());
}

function searchUsers() {
  const q = document.getElementById('search-q').value;
  if(q.length < 2) return tg.showAlert("Ketik min. 2 huruf");
  
  post({ action: 'search_users', query: q }).then(res => {
    if(!res.ok) return tg.showAlert(res.error);
    const box = document.getElementById('search-results');
    box.innerHTML = '';
    if(res.users.length === 0) {
      box.innerHTML = '<div style="text-align:center;color:var(--hint-color);font-size:13px;padding:10px;">User tidak ditemukan.</div>';
    } else {
      res.users.forEach(u => {
        box.innerHTML += `
          <div class="user-card" onclick="loadUser(${u.id})">
            <div class="user-card-info">
              <div class="user-card-name">${u.username}</div>
              <div class="user-card-email">${u.email}</div>
            </div>
            <div>▶️</div>
          </div>
        `;
      });
    }
  });
}

function loadUser(id) {
  post({ action: 'get_user', id: id }).then(res => {
    if(!res.ok) return tg.showAlert(res.error);
    const u = res.user;
    document.getElementById('view-search').style.display = 'none';
    document.getElementById('view-edit').style.display = 'block';
    
    document.getElementById('edit-title').innerText = 'Edit User: ' + u.username;
    
    document.getElementById('f_id').value = u.id;
    document.getElementById('f_username').value = u.username;
    document.getElementById('f_email').value = u.email;
    document.getElementById('f_whatsapp').value = u.whatsapp || '';
    document.getElementById('f_new_password').value = '';
    document.getElementById('f_referral_code').value = u.referral_code || '';
    
    // Format datetime-local
    if(u.membership_expires_at) {
      document.getElementById('f_membership_expires_at').value = u.membership_expires_at.slice(0, 16);
    } else {
      document.getElementById('f_membership_expires_at').value = '';
    }

    document.getElementById('f_balance_wd').value = u.balance_wd;
    document.getElementById('f_balance_dep').value = u.balance_dep;
    document.getElementById('f_total_earned').value = u.total_earned;

    document.getElementById('f_edit_bank_deposit_min').value = u.edit_bank_deposit_min;

    document.getElementById('f_is_active').value = u.is_active;
    document.getElementById('f_can_withdraw').value = u.can_withdraw;
    document.getElementById('f_can_chat').value = u.can_chat;
    document.getElementById('f_is_referral_active').value = u.is_referral_active;
    
    document.getElementById('f_is_promotor').value = u.is_promotor;
    document.getElementById('f_promotor_target_deposits').value = u.promotor_target_deposits;
    document.getElementById('f_promotor_target_regs').value = u.promotor_target_regs;
    document.getElementById('f_promotor_salary_rate').value = u.promotor_salary_rate;

    document.getElementById('f_bank_name').value = u.bank_name || '';
    document.getElementById('f_account_number').value = u.account_number || '';
    document.getElementById('f_account_name').value = u.account_name || '';
    document.getElementById('f_refund_cut').value = u.refund_cut_percent;
    document.getElementById('f_refund_enabled').value = u.is_refund_enabled;
    
    const selMem = document.getElementById('f_membership');
    selMem.innerHTML = '<option value="0">-- Pilih Level (Free) --</option>';
    u.memberships.forEach(m => {
      selMem.innerHTML += `<option value="${m.id}" ${u.membership_id==m.id?'selected':''}>${m.name}</option>`;
    });
    
    // Render Depo
    const lDepo = document.getElementById('list-depo');
    if(u.pending_deposits.length > 0) {
      document.getElementById('sect-depo').style.display = 'block';
      lDepo.innerHTML = '';
      u.pending_deposits.forEach(d => {
        lDepo.innerHTML += `
          <div class="txn-item">
            <strong>Depo #${d.id}</strong> - Rp ${parseFloat(d.amount).toLocaleString('id-ID')}<br>
            <div style="margin-top:8px;" class="flex-row">
              <button class="btn btn-success" style="padding:6px;font-size:12px;" onclick="processTxn('action_depo', ${d.id}, 'approve')">Approve</button>
              <button class="btn btn-danger" style="padding:6px;font-size:12px;" onclick="processTxn('action_depo', ${d.id}, 'reject')">Reject</button>
            </div>
          </div>
        `;
      });
    } else {
      document.getElementById('sect-depo').style.display = 'none';
    }
    
    // Render WD
    const lWd = document.getElementById('list-wd');
    if(u.pending_withdrawals.length > 0) {
      document.getElementById('sect-wd').style.display = 'block';
      lWd.innerHTML = '';
      u.pending_withdrawals.forEach(w => {
        lWd.innerHTML += `
          <div class="txn-item">
            <strong>WD #${w.id}</strong> - Rp ${parseFloat(w.amount).toLocaleString('id-ID')} (${w.status})<br>
            <div style="margin-top:8px;" class="flex-row">
              <button class="btn btn-success" style="padding:6px;font-size:12px;" onclick="processTxn('action_wd', ${w.id}, 'approve')">Approve</button>
              <button class="btn btn-danger" style="padding:6px;font-size:12px;" onclick="processTxn('action_wd', ${w.id}, 'reject')">Reject</button>
              ${w.status==='pending' ? `<button class="btn btn-warning" style="padding:6px;font-size:12px;" onclick="processTxn('action_wd', ${w.id}, 'hold')">Hold</button>` : ''}
            </div>
          </div>
        `;
      });
    } else {
      document.getElementById('sect-wd').style.display = 'none';
    }
  });
}

function saveUser(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-save');
  btn.innerText = 'Menyimpan...';
  
  const data = {
    action: 'save_user',
    id: document.getElementById('f_id').value,
    username: document.getElementById('f_username').value,
    email: document.getElementById('f_email').value,
    whatsapp: document.getElementById('f_whatsapp').value,
    new_password: document.getElementById('f_new_password').value,
    membership_id: document.getElementById('f_membership').value,
    membership_expires_at: document.getElementById('f_membership_expires_at').value,
    referral_code: document.getElementById('f_referral_code').value,
    balance_wd: document.getElementById('f_balance_wd').value,
    balance_dep: document.getElementById('f_balance_dep').value,
    total_earned: document.getElementById('f_total_earned').value,

    edit_bank_deposit_min: document.getElementById('f_edit_bank_deposit_min').value,
    is_active: document.getElementById('f_is_active').value,
    can_withdraw: document.getElementById('f_can_withdraw').value,
    can_chat: document.getElementById('f_can_chat').value,
    is_referral_active: document.getElementById('f_is_referral_active').value,
    is_promotor: document.getElementById('f_is_promotor').value,
    promotor_target_deposits: document.getElementById('f_promotor_target_deposits').value,
    promotor_target_regs: document.getElementById('f_promotor_target_regs').value,
    promotor_salary_rate: document.getElementById('f_promotor_salary_rate').value,
    bank_name: document.getElementById('f_bank_name').value,
    account_number: document.getElementById('f_account_number').value,
    account_name: document.getElementById('f_account_name').value,
    refund_cut_percent: document.getElementById('f_refund_cut').value,
    is_refund_enabled: document.getElementById('f_refund_enabled').value,
  };
  
  post(data).then(res => {
    btn.innerText = '💾 Simpan Data User';
    if(res.ok) {
      tg.showPopup({ title: "Berhasil", message: res.msg, buttons: [{type:"ok"}] });
    } else {
      tg.showAlert(res.error);
    }
  });
}

function processTxn(action, id, type) {
  tg.showConfirm(`Yakin ingin ${type} transaksi ini?`, function(btn) {
    if(btn) {
      post({ action: action, id: id, type: type }).then(res => {
        if(res.ok) {
          tg.showAlert(res.msg);
          loadUser(document.getElementById('f_id').value); // reload data
        } else {
          tg.showAlert(res.error);
        }
      });
    }
  });
}

function backToSearch() {
  document.getElementById('view-edit').style.display = 'none';
  document.getElementById('view-search').style.display = 'block';
}

tg.ready();
</script>
</body>
</html>
