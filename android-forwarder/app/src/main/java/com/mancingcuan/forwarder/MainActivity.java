package com.mancingcuan.forwarder;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;

/** Layar pengaturan & pemantauan Cuan Forwarder (UI dibuat langsung di kode, tema gelap). */
public class MainActivity extends Activity {
    private static final int BG = Color.parseColor("#0a0f1c"), CARD = Color.parseColor("#111827"), CARD2 = Color.parseColor("#172033"),
            LINE = Color.parseColor("#1f2a3d"), TX = Color.parseColor("#e5edf7"), DIM = Color.parseColor("#8b9ab3"),
            CY = Color.parseColor("#22d3ee"), GR = Color.parseColor("#22c55e"), RD = Color.parseColor("#ef4444"), AM = Color.parseColor("#f59e0b");

    private final Handler handler = new Handler(Looper.getMainLooper());
    private LinearLayout root;
    private TextView statusTv, appsTv, logTv, recentTv;
    private Button btnNotif, btnBattery;
    private EditText urlEt, tokenEt;
    private final SimpleDateFormat hm = new SimpleDateFormat("HH:mm:ss", Locale.getDefault());

    private final Runnable refresher = new Runnable() {
        @Override public void run() { refresh(); handler.postDelayed(this, 2000); }
    };

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        ScrollView sv = new ScrollView(this);
        sv.setBackgroundColor(BG);
        sv.setFillViewport(true);
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        int p = dp(14);
        root.setPadding(p, dp(18), p, dp(30));
        sv.addView(root);
        setContentView(sv);
        if (Build.VERSION.SDK_INT >= 35) {
            sv.setOnApplyWindowInsetsListener((v, insets) -> {
                v.setPadding(0, insets.getSystemWindowInsetTop(), 0, insets.getSystemWindowInsetBottom());
                return insets;
            });
        }

        // Judul
        TextView h = text("⚡ Cuan Forwarder", 21, TX, true);
        root.addView(h);
        root.addView(text("Meneruskan notifikasi pembayaran ke server deposit", 12.5f, DIM, false));
        space(10);

        // Status
        LinearLayout st = card("Status");
        statusTv = text("", 13.5f, TX, false);
        statusTv.setLineSpacing(0, 1.25f);
        st.addView(statusTv);

        // Langkah izin
        LinearLayout perm = card("Izin agar tetap jalan saat layar mati");
        btnNotif = button("1. Izinkan baca notifikasi", v -> Util.openNotificationAccess(this));
        perm.addView(btnNotif);
        if (Build.VERSION.SDK_INT >= 33) {
            perm.addView(button("2. Izinkan aplikasi menampilkan notifikasi", v -> requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 5)));
        }
        btnBattery = button("3. Matikan optimasi baterai", v -> Util.requestBatteryExempt(this));
        perm.addView(btnBattery);
        perm.addView(button("4. Xiaomi: aktifkan Autostart", v -> Util.openAutostart(this)));
        perm.addView(button("5. Xiaomi: Penghemat baterai → Tanpa batasan", v -> Util.openMiuiBattery(this)));
        TextView tip = text("Tips HyperOS: buka Recent apps, tahan kartu Cuan Forwarder lalu tekan ikon gembok agar tidak ditutup otomatis.", 12, DIM, false);
        tip.setPadding(0, dp(6), 0, 0);
        perm.addView(tip);

        // Server
        LinearLayout srv = card("Server");
        srv.addView(text("Alamat server", 12, DIM, false));
        urlEt = input(Prefs.baseUrl(this), InputType.TYPE_TEXT_VARIATION_URI);
        srv.addView(urlEt);
        srv.addView(text("Token (Console › Log Payment QRIS)", 12, DIM, false));
        tokenEt = input(Prefs.token(this), InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD);
        srv.addView(tokenEt);
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        Button save = button("Simpan", v -> {
            Prefs.saveServer(this, urlEt.getText().toString(), tokenEt.getText().toString());
            Toast.makeText(this, "Tersimpan", Toast.LENGTH_SHORT).show();
            Util.startService(this);
            Forwarder.heartbeat(this, true);
        });
        Button test = button("Tes koneksi", v -> {
            Prefs.saveServer(this, urlEt.getText().toString(), tokenEt.getText().toString());
            Forwarder.heartbeat(this, true);
            Toast.makeText(this, "Mengirim heartbeat…", Toast.LENGTH_SHORT).show();
        });
        row.addView(save, new LinearLayout.LayoutParams(0, -2, 1));
        View gap = new View(this);
        row.addView(gap, new LinearLayout.LayoutParams(dp(8), 1));
        row.addView(test, new LinearLayout.LayoutParams(0, -2, 1));
        srv.addView(row);

        // Aplikasi dipantau
        LinearLayout ap = card("Aplikasi pembayaran yang dipantau");
        appsTv = text("", 13.5f, TX, false);
        ap.addView(appsTv);
        ap.addView(button("Pilih aplikasi", v -> pickApps()));
        ap.addView(text("Notifikasi terbaru di HP (tekan untuk memantau aplikasinya):", 12, DIM, false));
        recentTv = text("", 12, DIM, false);
        recentTv.setOnClickListener(v -> pickFromRecent());
        ap.addView(recentTv);

        // Log
        LinearLayout lg = card("Aktivitas");
        logTv = text("", 12, TX, false);
        logTv.setTypeface(Typeface.MONOSPACE);
        lg.addView(logTv);
        LinearLayout row2 = new LinearLayout(this);
        Button resend = button("Kirim ulang antrian", v -> { Forwarder.flush(this); Toast.makeText(this, "Mengirim antrian…", Toast.LENGTH_SHORT).show(); });
        Button clear = button("Bersihkan log", v -> { Prefs.clearLogs(this); refresh(); });
        row2.addView(resend, new LinearLayout.LayoutParams(0, -2, 1));
        View gap2 = new View(this);
        row2.addView(gap2, new LinearLayout.LayoutParams(dp(8), 1));
        row2.addView(clear, new LinearLayout.LayoutParams(0, -2, 1));
        lg.addView(row2);

        Util.startService(this);
        Util.scheduleWatchdog(this, 15 * 60_000L);
    }

    @Override protected void onResume() { super.onResume(); handler.post(refresher); }
    @Override protected void onPause() { super.onPause(); handler.removeCallbacks(refresher); }

    private void refresh() {
        boolean notif = NotifListener.isEnabled(this);
        boolean bat = Util.batteryExempt(this);
        boolean postOk = Build.VERSION.SDK_INT < 33 || checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
        long lastSent = Prefs.getLong(this, "last_sent"), lastHb = Prefs.getLong(this, "last_hb");
        int q = Prefs.queue(this).length();
        String s = (notif ? "✅" : "❌") + " Baca notifikasi" + (notif && !NotifListener.connected ? " (menyambung…)" : "") + "\n"
                + (bat ? "✅" : "❌") + " Bebas optimasi baterai\n"
                + (postOk ? "✅" : "⚠️") + " Notifikasi layanan\n"
                + (Prefs.token(this).isEmpty() ? "❌ Token belum diisi\n" : "")
                + (Prefs.apps(this).isEmpty() ? "❌ Belum ada aplikasi dipantau\n" : "")
                + "📤 Terakhir kirim: " + (lastSent > 0 ? hm.format(new Date(lastSent)) : "-") + (q > 0 ? "   ·   antrian " + q : "") + "\n"
                + "💓 Heartbeat: " + (lastHb > 0 ? hm.format(new Date(lastHb)) + " · " + Prefs.getString(this, "last_hb_result") : "-");
        statusTv.setText(s);
        btnNotif.setText((notif ? "✅ " : "") + "1. Izinkan baca notifikasi");
        btnBattery.setText((bat ? "✅ " : "") + "3. Matikan optimasi baterai");

        Set<String> apps = Prefs.apps(this);
        appsTv.setText(apps.isEmpty() ? "Belum ada. Tekan \"Pilih aplikasi\" (mis. GoPay Merchant, DANA Bisnis, OrderKuota)." : String.join("\n", Util.appLabels(this, apps)));

        JSONArray rec = Prefs.recent(this);
        StringBuilder rb = new StringBuilder();
        for (int i = rec.length() - 1, n = 0; i >= 0 && n < 6; i--, n++) {
            JSONObject o = rec.optJSONObject(i);
            rb.append(apps.contains(o.optString("pkg")) ? "● " : "○ ").append(o.optString("app")).append(": ").append(o.optString("text")).append("\n");
        }
        recentTv.setText(rb.length() == 0 ? "Belum ada notifikasi tertangkap." : rb.toString().trim());

        JSONArray logs = Prefs.logs(this);
        StringBuilder lb = new StringBuilder();
        for (int i = logs.length() - 1, n = 0; i >= 0 && n < 40; i--, n++) {
            JSONObject o = logs.optJSONObject(i);
            String type = o.optString("type");
            String icon = type.equals("ok") ? "✔" : type.equals("err") ? "✖" : type.equals("warn") ? "!" : type.equals("in") ? "↓" : "·";
            lb.append(hm.format(new Date(o.optLong("t")))).append(' ').append(icon).append(' ').append(o.optString("msg")).append('\n');
        }
        logTv.setText(lb.length() == 0 ? "Belum ada aktivitas." : lb.toString().trim());
    }

    private void pickApps() {
        PackageManager pm = getPackageManager();
        Intent main = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER);
        List<ResolveInfo> infos = pm.queryIntentActivities(main, 0);
        Set<String> chosen = Prefs.apps(this);
        List<String[]> list = new ArrayList<>();
        Set<String> seen = new HashSet<>();
        for (ResolveInfo ri : infos) {
            String pkg = ri.activityInfo.packageName;
            if (pkg.equals(getPackageName()) || !seen.add(pkg)) continue;
            list.add(new String[]{ri.loadLabel(pm).toString(), pkg});
        }
        // Aplikasi yang sudah dipilih tapi tidak punya launcher tetap ditampilkan
        for (String pkg : chosen) if (seen.add(pkg)) list.add(new String[]{Util.appLabel(this, pkg), pkg});
        Collections.sort(list, (a, b2) -> {
            boolean ca = chosen.contains(a[1]), cb = chosen.contains(b2[1]);
            if (ca != cb) return ca ? -1 : 1;
            return a[0].compareToIgnoreCase(b2[0]);
        });
        String[] labels = new String[list.size()];
        boolean[] checked = new boolean[list.size()];
        for (int i = 0; i < list.size(); i++) {
            labels[i] = list.get(i)[0] + "  (" + list.get(i)[1] + ")";
            checked[i] = chosen.contains(list.get(i)[1]);
        }
        new AlertDialog.Builder(this, android.R.style.Theme_Material_Dialog_Alert)
                .setTitle("Pilih aplikasi pembayaran")
                .setMultiChoiceItems(labels, checked, (d, which, isChecked) -> checked[which] = isChecked)
                .setPositiveButton("Simpan", (d, w) -> {
                    Set<String> out = new HashSet<>();
                    for (int i = 0; i < checked.length; i++) if (checked[i]) out.add(list.get(i)[1]);
                    Prefs.setApps(this, out);
                    Prefs.log(this, "info", "Aplikasi dipantau: " + String.join(", ", Util.appLabels(this, out)));
                    refresh();
                })
                .setNegativeButton("Batal", null)
                .show();
    }

    private void pickFromRecent() {
        JSONArray rec = Prefs.recent(this);
        List<String> pkgs = new ArrayList<>();
        List<String> labels = new ArrayList<>();
        for (int i = rec.length() - 1; i >= 0; i--) {
            JSONObject o = rec.optJSONObject(i);
            String pkg = o.optString("pkg");
            if (pkgs.contains(pkg)) continue;
            pkgs.add(pkg);
            labels.add(o.optString("app") + " — " + o.optString("text"));
        }
        if (pkgs.isEmpty()) return;
        Set<String> chosen = Prefs.apps(this);
        boolean[] checked = new boolean[pkgs.size()];
        for (int i = 0; i < pkgs.size(); i++) checked[i] = chosen.contains(pkgs.get(i));
        new AlertDialog.Builder(this, android.R.style.Theme_Material_Dialog_Alert)
                .setTitle("Pantau aplikasi dari notifikasi terbaru")
                .setMultiChoiceItems(labels.toArray(new String[0]), checked, (d, which, isChecked) -> checked[which] = isChecked)
                .setPositiveButton("Simpan", (d, w) -> {
                    for (int i = 0; i < pkgs.size(); i++) { if (checked[i]) chosen.add(pkgs.get(i)); else chosen.remove(pkgs.get(i)); }
                    Prefs.setApps(this, chosen);
                    refresh();
                })
                .setNegativeButton("Batal", null)
                .show();
    }

    // ── Komponen UI ─────────────────────────────────────────────────────────
    private LinearLayout card(String title) {
        LinearLayout c = new LinearLayout(this);
        c.setOrientation(LinearLayout.VERTICAL);
        c.setPadding(dp(13), dp(11), dp(13), dp(12));
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(CARD);
        bg.setCornerRadius(dp(14));
        bg.setStroke(dp(1), LINE);
        c.setBackground(bg);
        TextView t = text(title.toUpperCase(Locale.ROOT), 11.5f, CY, true);
        t.setPadding(0, 0, 0, dp(6));
        c.addView(t);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.bottomMargin = dp(10);
        root.addView(c, lp);
        return c;
    }

    private TextView text(String s, float sp, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(sp);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.DEFAULT_BOLD);
        return t;
    }

    private Button button(String label, View.OnClickListener l) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextColor(TX);
        b.setTextSize(13.5f);
        b.setGravity(Gravity.CENTER_VERTICAL | Gravity.START);
        b.setPadding(dp(12), 0, dp(12), 0);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(CARD2);
        bg.setCornerRadius(dp(10));
        bg.setStroke(dp(1), LINE);
        b.setBackground(bg);
        b.setOnClickListener(l);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(44));
        lp.topMargin = dp(6);
        b.setLayoutParams(lp);
        return b;
    }

    private EditText input(String value, int variation) {
        EditText e = new EditText(this);
        e.setText(value);
        e.setSingleLine(true);
        e.setInputType(InputType.TYPE_CLASS_TEXT | variation);
        e.setTextColor(TX);
        e.setHintTextColor(DIM);
        e.setTextSize(14);
        e.setPadding(dp(10), dp(8), dp(10), dp(8));
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(BG);
        bg.setCornerRadius(dp(9));
        bg.setStroke(dp(1), LINE);
        e.setBackground(bg);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.topMargin = dp(4);
        lp.bottomMargin = dp(8);
        e.setLayoutParams(lp);
        return e;
    }

    private void space(int h) {
        root.addView(new View(this), new LinearLayout.LayoutParams(1, dp(h)));
    }

    private int dp(float v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }
}
