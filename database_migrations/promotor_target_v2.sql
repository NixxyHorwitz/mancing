-- Gaji promotor skema target + jaminan (2026-10-16). Aman dijalankan ulang.
-- Kode juga menambah kolom ini otomatis saat halaman promotor dibuka, file ini untuk dijalankan manual di phpMyAdmin.

ALTER TABLE promotor_daily_targets
    ADD COLUMN IF NOT EXISTS valid_regs INT NULL DEFAULT NULL AFTER actual_regs,
    ADD COLUMN IF NOT EXISTS deposit_count INT NOT NULL DEFAULT 0 AFTER actual_deposits,
    ADD COLUMN IF NOT EXISTS guarantee_amount DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER salary_rate,
    ADD COLUMN IF NOT EXISTS earned_amount DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER guarantee_amount;

-- Baris lama (valid_regs NULL) memakai nominal hitungan lama, supaya gaji pending tidak berkurang
UPDATE promotor_daily_targets
   SET earned_amount = ROUND(salary_rate * LEAST(100, percentage) / 100)
 WHERE valid_regs IS NULL AND earned_amount = 0;

-- WAJIB: atur target realistis tiap promotor SEBELUM kode baru aktif.
-- Contoh: 150 member valid, Rp 500.000 deposit, gaji penuh Rp 50.000 per hari.
-- UPDATE users SET promotor_target_regs = 150, promotor_target_deposits = 500000, promotor_salary_rate = 50000 WHERE is_promotor = 1;

SELECT user_id, date, valid_regs, actual_regs, actual_deposits, percentage, salary_rate, guarantee_amount, earned_amount, is_paid
  FROM promotor_daily_targets ORDER BY date DESC LIMIT 20;
