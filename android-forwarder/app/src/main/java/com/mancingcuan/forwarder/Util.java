package com.mancingcuan.forwarder;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.os.SystemClock;
import android.provider.Settings;

import java.util.ArrayList;
import java.util.List;
import java.util.Set;

final class Util {
    private Util() {}

    static boolean batteryExempt(Context c) {
        PowerManager pm = (PowerManager) c.getSystemService(Context.POWER_SERVICE);
        return pm != null && pm.isIgnoringBatteryOptimizations(c.getPackageName());
    }

    static String appLabel(Context c, String pkg) {
        try {
            PackageManager pm = c.getPackageManager();
            ApplicationInfo ai = pm.getApplicationInfo(pkg, 0);
            return pm.getApplicationLabel(ai).toString();
        } catch (Exception e) {
            return pkg;
        }
    }

    static List<String> appLabels(Context c, Set<String> pkgs) {
        List<String> out = new ArrayList<>();
        for (String p : pkgs) out.add(appLabel(c, p));
        return out;
    }

    /** Jalankan layanan latar depan (aman dipanggil berulang). */
    static void startService(Context c) {
        try {
            Intent i = new Intent(c, ForwardService.class);
            if (Build.VERSION.SDK_INT >= 26) c.startForegroundService(i); else c.startService(i);
        } catch (Exception e) {
            Prefs.log(c, "warn", "Layanan belum bisa dijalankan: " + e.getClass().getSimpleName());
        }
    }

    /** Alarm penjaga tiap ±15 menit, tetap jalan saat Doze (layar mati). */
    static void scheduleWatchdog(Context c, long delayMs) {
        AlarmManager am = (AlarmManager) c.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        PendingIntent pi = PendingIntent.getBroadcast(c, 7, new Intent(c, WatchdogReceiver.class),
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + delayMs, pi);
    }

    // ── Pembuka halaman pengaturan ──────────────────────────────────────────
    static void openNotificationAccess(Context c) {
        tryStart(c, new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
    }

    static void requestBatteryExempt(Context c) {
        Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + c.getPackageName()));
        if (!tryStart(c, i)) tryStart(c, new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
    }

    /** Xiaomi/HyperOS: izin Autostart. */
    static void openAutostart(Context c) {
        Intent i = new Intent();
        i.setComponent(new ComponentName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"));
        if (!tryStart(c, i)) openAppDetails(c);
    }

    /** Xiaomi/HyperOS: Penghemat baterai aplikasi → "Tanpa batasan". */
    static void openMiuiBattery(Context c) {
        Intent i = new Intent();
        i.setComponent(new ComponentName("com.miui.powerkeeper", "com.miui.powerkeeper.ui.HiddenAppsConfigActivity"));
        i.putExtra("package_name", c.getPackageName());
        i.putExtra("package_label", "Cuan Forwarder");
        if (!tryStart(c, i)) openAppDetails(c);
    }

    static void openAppDetails(Context c) {
        tryStart(c, new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + c.getPackageName())));
    }

    static boolean tryStart(Context c, Intent i) {
        try {
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            c.startActivity(i);
            return true;
        } catch (Exception e) {
            return false;
        }
    }
}
