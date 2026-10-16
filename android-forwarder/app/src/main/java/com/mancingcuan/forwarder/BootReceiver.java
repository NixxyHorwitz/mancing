package com.mancingcuan.forwarder;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** HP dinyalakan ulang / aplikasi diperbarui → jalankan lagi layanan forwarder. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        Prefs.log(context, "info", "Aktif kembali setelah " + (Intent.ACTION_MY_PACKAGE_REPLACED.equals(intent.getAction()) ? "update aplikasi" : "HP menyala"));
        Util.startService(context);
        Util.scheduleWatchdog(context, 15 * 60_000L);
        NotifListener.rebind(context);
    }
}
