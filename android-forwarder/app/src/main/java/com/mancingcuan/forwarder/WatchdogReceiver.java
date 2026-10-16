package com.mancingcuan.forwarder;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Alarm penjaga (tetap terpicu saat Doze / layar mati): menghidupkan lagi layanan
 * bila dimatikan sistem, kirim antrian, dan heartbeat.
 */
public class WatchdogReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        Util.startService(context);
        if (NotifListener.isEnabled(context) && !NotifListener.connected) NotifListener.rebind(context);
        Forwarder.heartbeat(context, false);
        Util.scheduleWatchdog(context, 15 * 60_000L);
    }
}
