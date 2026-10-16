package com.mancingcuan.forwarder;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.ConnectivityManager;
import android.net.Network;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/**
 * Layanan latar depan: menjaga proses tetap hidup, mengirim ulang antrian,
 * heartbeat berkala, dan menyambung ulang listener notifikasi bila terputus.
 */
public class ForwardService extends Service {
    private static final String CHANNEL = "forwarder";
    private static final int NOTIF_ID = 41;
    private static final long TICK_MS = 60_000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private ConnectivityManager.NetworkCallback netCb;
    private long lastHb = 0;

    private final Runnable tick = new Runnable() {
        @Override
        public void run() {
            long hbEvery = Prefs.heartbeatMinutes(ForwardService.this) * 60_000L;
            if (System.currentTimeMillis() - lastHb >= hbEvery) {
                lastHb = System.currentTimeMillis();
                Forwarder.heartbeat(ForwardService.this, false);
            } else if (Prefs.queue(ForwardService.this).length() > 0) {
                Forwarder.flush(ForwardService.this);
            }
            if (NotifListener.isEnabled(ForwardService.this) && !NotifListener.connected) {
                NotifListener.rebind(ForwardService.this);
            }
            updateNotification();
            handler.postDelayed(this, TICK_MS);
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        NotificationManager nm = getSystemService(NotificationManager.class);
        NotificationChannel ch = new NotificationChannel(CHANNEL, "Forwarder aktif", NotificationManager.IMPORTANCE_LOW);
        ch.setShowBadge(false);
        nm.createNotificationChannel(ch);

        Notification n = build();
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        } else {
            startForeground(NOTIF_ID, n);
        }

        // Jaringan kembali → langsung kirim antrian
        ConnectivityManager cm = getSystemService(ConnectivityManager.class);
        netCb = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(Network network) {
                Forwarder.flush(ForwardService.this);
            }
        };
        try { cm.registerDefaultNetworkCallback(netCb); } catch (Exception ignored) {}

        handler.post(tick);
        Util.scheduleWatchdog(this, 15 * 60_000L);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        Forwarder.flush(this);
        updateNotification();
        return START_STICKY;
    }

    @Override
    public void onTaskRemoved(Intent rootIntent) {
        // Aplikasi di-swipe dari recent: pastikan hidup lagi sebentar lagi
        Util.scheduleWatchdog(this, 60_000L);
        super.onTaskRemoved(rootIntent);
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(tick);
        try { getSystemService(ConnectivityManager.class).unregisterNetworkCallback(netCb); } catch (Exception ignored) {}
        Util.scheduleWatchdog(this, 60_000L);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) { return null; }

    private Notification build() {
        PendingIntent open = PendingIntent.getActivity(this, 0, new Intent(this, MainActivity.class),
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        long last = Prefs.getLong(this, "last_sent");
        int q = Prefs.queue(this).length();
        String listener = NotifListener.isEnabled(this) ? "Membaca notifikasi" : "⚠ Izin baca notifikasi belum aktif";
        String sub = "Terakhir kirim " + (last > 0 ? new SimpleDateFormat("HH:mm", Locale.getDefault()).format(new Date(last)) : "-")
                + (q > 0 ? " · antrian " + q : "");
        return new Notification.Builder(this, CHANNEL)
                .setSmallIcon(R.drawable.ic_stat)
                .setContentTitle("Cuan Forwarder aktif")
                .setContentText(listener + " · " + sub)
                .setContentIntent(open)
                .setOngoing(true)
                .setShowWhen(false)
                .build();
    }

    private void updateNotification() {
        try { getSystemService(NotificationManager.class).notify(NOTIF_ID, build()); } catch (Exception ignored) {}
    }
}
