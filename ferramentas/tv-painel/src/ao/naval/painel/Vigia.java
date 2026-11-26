package ao.naval.painel;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.app.usage.UsageEvents;
import android.app.usage.UsageStatsManager;
import android.content.Intent;
import android.content.pm.ResolveInfo;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.SystemClock;
import android.util.Log;

/**
 * Vigia do painel em primeiro plano (iniciada pela Arranque, com a app à frente, a única altura em que a TCL a deixa
 * arrancar): a cada 5 segundos vê que app está no ecrã (estatísticas de utilização) e, se a página inicial do Google TV
 * estiver à frente há 30 segundos, abre o painel. Substitui o serviço de acessibilidade, que a TCL recusa depois de cada
 * arranque. Precisa de GET_USAGE_STATS e SYSTEM_ALERT_WINDOW (dadas por adb com appops; ver construir.ps1).
 */
public class Vigia extends Service {
    private static final String TAG = "NavalPainel";
    private static final String FULLY = "de.ozerov.fully";
    private static final long ESPERA_MS = 30_000;
    private static final long VERIFICAR_MS = 5_000;

    private final Handler h = new Handler(Looper.getMainLooper());
    private String inicio = "";
    private String frente = "";
    private long desde = 0;

    private final Runnable verificar = new Runnable() {
        @Override
        public void run() {
            try {
                String p = appAFrente();
                if (p != null && !p.equals(frente)) {
                    frente = p;
                    desde = SystemClock.elapsedRealtime();
                    Registo.escrever(Vigia.this, "vigia: à frente " + p);
                }
                if (!inicio.isEmpty() && inicio.equals(frente) && SystemClock.elapsedRealtime() - desde >= ESPERA_MS) abrirPainel();
            } catch (Exception e) {
                Log.w(TAG, "vigia: " + e);
                Registo.escrever(Vigia.this, "vigia: " + e);
            }
            h.postDelayed(this, VERIFICAR_MS);
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        NotificationManager nm = getSystemService(NotificationManager.class);
        nm.createNotificationChannel(new NotificationChannel("vigia", "Painel da sala", NotificationManager.IMPORTANCE_MIN));
        Notification n = new Notification.Builder(this, "vigia").setContentTitle("Naval Painel").setContentText("A manter o painel no ecrã")
            .setSmallIcon(android.R.drawable.ic_menu_view).build();
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        else startForeground(1, n);
        ResolveInfo r = getPackageManager().resolveActivity(new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME), 0);
        if (r != null && r.activityInfo != null) inicio = r.activityInfo.packageName;
        Log.i(TAG, "vigia ligada; página inicial = " + inicio);
        Registo.escrever(this, "vigia ligada; página inicial = " + inicio);
        h.postDelayed(verificar, VERIFICAR_MS);
    }

    @Override
    public int onStartCommand(Intent i, int f, int id) {
        return START_STICKY;
    }

    /** App que passou para a frente mais recentemente (últimos 10 minutos). */
    private String appAFrente() {
        UsageStatsManager u = getSystemService(UsageStatsManager.class);
        long agora = System.currentTimeMillis();
        UsageEvents ev = u.queryEvents(agora - 600_000, agora);
        UsageEvents.Event e = new UsageEvents.Event();
        String ultimo = null;
        while (ev.hasNextEvent()) {
            ev.getNextEvent(e);
            if (e.getEventType() == UsageEvents.Event.ACTIVITY_RESUMED) ultimo = e.getPackageName();
        }
        return ultimo;
    }

    private void abrirPainel() {
        Intent i = getPackageManager().getLaunchIntentForPackage(FULLY);
        if (i == null) return;
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED);
        try {
            startActivity(i);
            Log.i(TAG, "vigia: painel aberto");
            Registo.escrever(this, "vigia: painel aberto");
            frente = FULLY;
            desde = SystemClock.elapsedRealtime();
        } catch (Exception ex) {
            Log.w(TAG, "vigia: não abriu o painel: " + ex);
            Registo.escrever(this, "vigia: não abriu o painel: " + ex);
        }
    }

    @Override
    public IBinder onBind(Intent i) {
        return null;
    }

    @Override
    public void onDestroy() {
        h.removeCallbacksAndMessages(null);
        super.onDestroy();
    }
}
