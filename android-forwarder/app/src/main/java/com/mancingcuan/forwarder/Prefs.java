package com.mancingcuan.forwarder;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.HashSet;
import java.util.Set;

/** Pengaturan, antrian kirim, dan log lokal (SharedPreferences). */
public final class Prefs {
    private static final String NAME = "fwd";
    public static final String DEFAULT_URL = "https://mancingcuans.online";
    private static final int MAX_LOG = 80;
    private static final int MAX_RECENT = 25;
    private static final int MAX_SEEN = 400;

    private Prefs() {}

    private static SharedPreferences sp(Context c) {
        return c.getApplicationContext().getSharedPreferences(NAME, Context.MODE_PRIVATE);
    }

    // ── Pengaturan ──────────────────────────────────────────────────────────
    public static String baseUrl(Context c) {
        String u = sp(c).getString("base_url", DEFAULT_URL).trim();
        while (u.endsWith("/")) u = u.substring(0, u.length() - 1);
        return u;
    }
    public static String token(Context c) { return sp(c).getString("token", "").trim(); }
    public static void saveServer(Context c, String url, String token) {
        sp(c).edit().putString("base_url", url.trim()).putString("token", token.trim()).apply();
    }
    public static Set<String> apps(Context c) {
        return new HashSet<>(sp(c).getStringSet("apps", new HashSet<>()));
    }
    public static void setApps(Context c, Set<String> apps) {
        sp(c).edit().putStringSet("apps", new HashSet<>(apps)).apply();
    }
    public static int heartbeatMinutes(Context c) { return sp(c).getInt("hb_min", 5); }

    public static void putLong(Context c, String k, long v) { sp(c).edit().putLong(k, v).apply(); }
    public static long getLong(Context c, String k) { return sp(c).getLong(k, 0); }
    public static void putString(Context c, String k, String v) { sp(c).edit().putString(k, v).apply(); }
    public static String getString(Context c, String k) { return sp(c).getString(k, ""); }

    // ── Antrian kirim ───────────────────────────────────────────────────────
    public static synchronized JSONArray queue(Context c) {
        try { return new JSONArray(sp(c).getString("queue", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
    public static synchronized void saveQueue(Context c, JSONArray q) {
        sp(c).edit().putString("queue", q.toString()).commit();
    }
    public static synchronized void enqueue(Context c, JSONObject item) {
        JSONArray q = queue(c);
        q.put(item);
        // Batasi 300 item agar tidak membengkak
        while (q.length() > 300) q.remove(0);
        saveQueue(c, q);
    }

    // ── Dedup notifikasi ────────────────────────────────────────────────────
    public static synchronized boolean markSeen(Context c, String uid) {
        try {
            JSONArray seen = new JSONArray(sp(c).getString("seen", "[]"));
            for (int i = 0; i < seen.length(); i++) if (uid.equals(seen.optString(i))) return false;
            seen.put(uid);
            while (seen.length() > MAX_SEEN) seen.remove(0);
            sp(c).edit().putString("seen", seen.toString()).apply();
            return true;
        } catch (JSONException e) {
            return true;
        }
    }

    // ── Log aktivitas ───────────────────────────────────────────────────────
    public static synchronized void log(Context c, String type, String msg) {
        try {
            JSONArray a = new JSONArray(sp(c).getString("log", "[]"));
            JSONObject o = new JSONObject();
            o.put("t", System.currentTimeMillis());
            o.put("type", type);
            o.put("msg", msg);
            a.put(o);
            while (a.length() > MAX_LOG) a.remove(0);
            sp(c).edit().putString("log", a.toString()).apply();
        } catch (JSONException ignored) {}
    }
    public static synchronized JSONArray logs(Context c) {
        try { return new JSONArray(sp(c).getString("log", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
    public static synchronized void clearLogs(Context c) { sp(c).edit().putString("log", "[]").apply(); }

    // ── Notifikasi terbaru semua aplikasi (untuk memilih aplikasi) ──────────
    public static synchronized void addRecent(Context c, String pkg, String app, String text) {
        try {
            JSONArray a = new JSONArray(sp(c).getString("recent", "[]"));
            JSONObject o = new JSONObject();
            o.put("t", System.currentTimeMillis());
            o.put("pkg", pkg);
            o.put("app", app);
            o.put("text", text.length() > 140 ? text.substring(0, 140) + "…" : text);
            a.put(o);
            while (a.length() > MAX_RECENT) a.remove(0);
            sp(c).edit().putString("recent", a.toString()).apply();
        } catch (JSONException ignored) {}
    }
    public static synchronized JSONArray recent(Context c) {
        try { return new JSONArray(sp(c).getString("recent", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
}
