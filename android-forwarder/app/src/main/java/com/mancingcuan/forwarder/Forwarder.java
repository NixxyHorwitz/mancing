package com.mancingcuan.forwarder;

import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.os.BatteryManager;
import android.os.Build;
import android.os.PowerManager;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Inti pengiriman: antrian → server, plus heartbeat. Semua jalan di satu thread latar. */
public final class Forwarder {
    private static final ExecutorService EXEC = Executors.newSingleThreadExecutor();
    private static final Pattern AMOUNT = Pattern.compile("(?:Rp\\.?|IDR)\\s*([0-9][0-9.,]*)", Pattern.CASE_INSENSITIVE);
    private static final long MAX_AGE_MS = 24L * 60 * 60 * 1000;

    public static volatile String lastStatus = "";

    private Forwarder() {}

    /** Ambil nominal dari teks notifikasi; 0 bila tidak ada. */
    public static long parseAmount(String text) {
        if (text == null) return 0;
        Matcher m = AMOUNT.matcher(text);
        if (!m.find()) return 0;
        String n = m.group(1).replaceAll("[.,]+$", "");
        n = n.replaceAll("[.,]\\d{2}$", "");
        n = n.replace(".", "").replace(",", "");
        try { return Long.parseLong(n); } catch (NumberFormatException e) { return 0; }
    }

    public static void enqueueAndFlush(Context ctx, JSONObject item) {
        Context c = ctx.getApplicationContext();
        Prefs.enqueue(c, item);
        flush(c);
    }

    /** Kirim semua item di antrian. Item yang gagal karena jaringan/server tetap disimpan untuk dicoba lagi. */
    public static void flush(Context ctx) {
        final Context c = ctx.getApplicationContext();
        EXEC.execute(() -> {
            PowerManager.WakeLock wl = wakeLock(c, "flush");
            try {
                JSONArray q = Prefs.queue(c);
                if (q.length() == 0) return;
                JSONArray keep = new JSONArray();
                String token = Prefs.token(c);
                String url = Prefs.baseUrl(c) + "/qris_callback.php";
                boolean stop = false;
                for (int i = 0; i < q.length(); i++) {
                    JSONObject it = q.optJSONObject(i);
                    if (it == null) continue;
                    if (stop) { keep.put(it); continue; }
                    if (System.currentTimeMillis() - it.optLong("queued_at") > MAX_AGE_MS) {
                        Prefs.log(c, "drop", "Dibuang (lebih dari 24 jam): Rp" + it.optLong("amount"));
                        continue;
                    }
                    JSONObject body = new JSONObject(it.toString());
                    body.put("token", token);
                    body.put("device", device());
                    Result r = post(url, token, body);
                    String amt = "Rp" + String.format("%,d", it.optLong("amount")).replace(',', '.');
                    if (r.code == 200) {
                        String st = r.json != null ? r.json.optString("status", "ok") : "ok";
                        String extra = "";
                        if (r.json != null && r.json.has("deposit_id")) extra = " → deposit #" + r.json.optInt("deposit_id");
                        Prefs.log(c, st.startsWith("matched") ? "ok" : "info", amt + " · " + label(st) + extra);
                        Prefs.putLong(c, "last_sent", System.currentTimeMillis());
                        lastStatus = st;
                    } else if (r.code == 401) {
                        Prefs.log(c, "err", "Token ditolak server (401). Cek token di aplikasi.");
                        keep.put(it);
                        stop = true;
                    } else if (r.code >= 400 && r.code < 500) {
                        Prefs.log(c, "err", amt + " · ditolak server (" + r.code + "), dibuang");
                    } else {
                        Prefs.log(c, "warn", amt + " · gagal kirim (" + (r.code > 0 ? "HTTP " + r.code : r.error) + "), dicoba lagi");
                        it.put("tries", it.optInt("tries") + 1);
                        keep.put(it);
                        stop = true; // jaringan bermasalah: sisanya dicoba nanti
                    }
                }
                // Gabungkan dengan item baru yang masuk selama proses kirim
                JSONArray now = Prefs.queue(c);
                for (int i = q.length(); i < now.length(); i++) keep.put(now.optJSONObject(i));
                Prefs.saveQueue(c, keep);
            } catch (Exception e) {
                Prefs.log(c, "err", "Error kirim: " + e.getMessage());
            } finally {
                if (wl.isHeld()) wl.release();
            }
        });
    }

    /** Heartbeat ke server (status HP, baterai, izin, antrian). */
    public static void heartbeat(Context ctx, boolean manual) {
        final Context c = ctx.getApplicationContext();
        EXEC.execute(() -> {
            PowerManager.WakeLock wl = wakeLock(c, "hb");
            try {
                JSONObject b = new JSONObject();
                b.put("token", Prefs.token(c));
                b.put("interval_minutes", Prefs.heartbeatMinutes(c));
                b.put("device_info", device());
                b.put("app_version", BuildConfig.VERSION_NAME);
                b.put("listener", NotifListener.isEnabled(c));
                b.put("listener_connected", NotifListener.connected);
                b.put("battery_exempt", Util.batteryExempt(c));
                b.put("queue", Prefs.queue(c).length());
                b.put("apps", new JSONArray(Util.appLabels(c, Prefs.apps(c))));
                b.put("last_sent", Prefs.getLong(c, "last_sent") / 1000);
                Intent bat = c.registerReceiver(null, new IntentFilter(Intent.ACTION_BATTERY_CHANGED));
                if (bat != null) {
                    int lvl = bat.getIntExtra(BatteryManager.EXTRA_LEVEL, -1), scale = bat.getIntExtra(BatteryManager.EXTRA_SCALE, 100);
                    int plug = bat.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0);
                    b.put("battery", lvl >= 0 ? Math.round(lvl * 100f / scale) : -1);
                    b.put("charging", plug != 0);
                }
                Result r = post(Prefs.baseUrl(c) + "/api/heartbeat.php", Prefs.token(c), b);
                String res = r.code == 200 ? "OK" : (r.code == 401 ? "Token salah" : (r.code > 0 ? "HTTP " + r.code : r.error));
                Prefs.putLong(c, "last_hb", System.currentTimeMillis());
                Prefs.putString(c, "last_hb_result", res);
                if (manual || r.code != 200) Prefs.log(c, r.code == 200 ? "ok" : "err", "Heartbeat: " + res);
            } catch (Exception e) {
                Prefs.putString(c, "last_hb_result", "Error " + e.getMessage());
            } finally {
                if (wl.isHeld()) wl.release();
            }
        });
        flush(c);
    }

    private static String label(String st) {
        switch (st) {
            case "matched": return "✅ cocok, deposit di-acc";
            case "matched_expired": return "✅ cocok (deposit expired di-acc)";
            case "unmatched": return "tidak ada deposit cocok";
            case "duplicate": return "sudah pernah dikirim";
            case "ignored": return "diabaikan server";
            default: return st;
        }
    }

    public static String device() {
        return Build.MANUFACTURER + " " + Build.MODEL + " · Android " + Build.VERSION.RELEASE;
    }

    private static PowerManager.WakeLock wakeLock(Context c, String tag) {
        PowerManager pm = (PowerManager) c.getSystemService(Context.POWER_SERVICE);
        PowerManager.WakeLock wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "CuanForwarder:" + tag);
        wl.acquire(60_000);
        return wl;
    }

    // ── HTTP ────────────────────────────────────────────────────────────────
    static final class Result {
        int code;
        String error = "";
        JSONObject json;
    }

    static Result post(String url, String token, JSONObject body) {
        Result r = new Result();
        HttpURLConnection con = null;
        try {
            con = (HttpURLConnection) new URL(url).openConnection();
            con.setRequestMethod("POST");
            con.setConnectTimeout(15_000);
            con.setReadTimeout(20_000);
            con.setDoOutput(true);
            con.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            con.setRequestProperty("X-Forwarder-Token", token);
            con.setRequestProperty("User-Agent", "CuanForwarder/" + BuildConfig.VERSION_NAME);
            byte[] data = body.toString().getBytes(StandardCharsets.UTF_8);
            try (OutputStream os = con.getOutputStream()) { os.write(data); }
            r.code = con.getResponseCode();
            InputStream in = r.code >= 400 ? con.getErrorStream() : con.getInputStream();
            if (in != null) {
                ByteArrayOutputStream bo = new ByteArrayOutputStream();
                byte[] buf = new byte[4096];
                int n;
                while ((n = in.read(buf)) > 0) bo.write(buf, 0, n);
                in.close();
                try { r.json = new JSONObject(bo.toString("UTF-8")); } catch (Exception ignored) {}
            }
        } catch (Exception e) {
            r.code = 0;
            r.error = e.getClass().getSimpleName();
        } finally {
            if (con != null) con.disconnect();
        }
        return r;
    }
}
