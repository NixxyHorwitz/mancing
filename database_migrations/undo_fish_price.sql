-- Kembalikan harga ikan ke harga asli sebelum diturunkan (2026-10-15). Saat ini: 70% dari harga asli.
UPDATE fishing_species SET price_min = 4865.00, price_max = 8500.00 WHERE badge = 'bagus';
UPDATE fishing_species SET price_min = 1607.00, price_max = 3500.00 WHERE badge = 'biasa';
UPDATE fishing_species SET price_min = 24500.00, price_max = 26493.00 WHERE badge = 'jackpot';
UPDATE fishing_species SET price_min = 11375.00, price_max = 14000.00 WHERE badge = 'langka';
UPDATE fishing_species SET price_min = 16662.00, price_max = 22750.00 WHERE badge = 'super';
UPDATE fishing_species SET price_min = 0.00, price_max = 0.00 WHERE badge = 'zonk';
