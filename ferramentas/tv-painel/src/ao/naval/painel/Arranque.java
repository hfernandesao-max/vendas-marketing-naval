package ao.naval.painel;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.util.Log;

/**
 * Arranque da TV: é a app que o modo hotel da TCL abre ao ligar (definição tcl_app_hotel_mode_define_app), a única que
 * a TCL deixa passar no arranque. Com a app em primeiro plano, volta a pedir ao sistema que ligue o serviço
 * PainelServico (a TCL recusa-o em segundo plano depois de cada arranque) e abre o painel (Fully Kiosk).
 * Precisa de WRITE_SECURE_SETTINGS (dada uma vez por adb: pm grant ao.naval.painel android.permission.WRITE_SECURE_SETTINGS).
 */
public class Arranque extends Activity {
    private static final String TAG = "NavalPainel";
    private static final String SERVICO = "ao.naval.painel/ao.naval.painel.PainelServico";

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        religarServico();
        // vigia em primeiro plano: arranca agora, com a app à frente (a TCL não a deixa arrancar em segundo plano)
        try {
            startForegroundService(new Intent(this, Vigia.class));
        } catch (Exception e) {
            Log.w(TAG, "arranque: vigia não arrancou: " + e);
        }
        // fica à frente até o serviço confirmar que ligou (a TCL só o deixa ligar com a app à frente): repete o pedido a
        // cada 2 segundos, no máximo 20; depois abre o painel
        final Handler h = new Handler(Looper.getMainLooper());
        final long inicio = android.os.SystemClock.elapsedRealtime();
        h.postDelayed(new Runnable() {
            @Override
            public void run() {
                long passou = android.os.SystemClock.elapsedRealtime() - inicio;
                if (PainelServico.ligadoEm > 0 || passou >= 20_000) {
                    Registo.escrever(Arranque.this, PainelServico.ligadoEm > 0 ? "arranque: serviço confirmado" : "arranque: serviço não ligou em 20 s");
                    abrirPainel();
                    finish();
                    return;
                }
                religarServico();
                h.postDelayed(this, 2000);
            }
        }, 2000);
    }

    private void religarServico() {
        try {
            String actuais = Settings.Secure.getString(getContentResolver(), Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES);
            String sem = actuais == null ? "" : actuais.replace(SERVICO, "").replace("::", ":").replaceAll("^:|:$", "");
            Settings.Secure.putString(getContentResolver(), Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES, sem);
            Settings.Secure.putString(getContentResolver(), Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES, sem.isEmpty() ? SERVICO : sem + ":" + SERVICO);
            Settings.Secure.putInt(getContentResolver(), Settings.Secure.ACCESSIBILITY_ENABLED, 1);
            Log.i(TAG, "arranque: serviço pedido");
            Registo.escrever(this, "arranque: serviço pedido");
        } catch (SecurityException e) {
            Log.w(TAG, "arranque: sem WRITE_SECURE_SETTINGS (" + e.getMessage() + ")");
        }
    }

    private void abrirPainel() {
        Intent i = getPackageManager().getLaunchIntentForPackage("de.ozerov.fully");
        if (i == null) return;
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED);
        startActivity(i);
        Log.i(TAG, "arranque: painel aberto");
        Registo.escrever(this, "arranque: painel aberto");
    }
}
