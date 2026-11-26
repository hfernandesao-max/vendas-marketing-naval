package ao.naval.painel;

import android.accessibilityservice.AccessibilityService;
import android.content.Intent;
import android.content.pm.ResolveInfo;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.util.Log;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;

/**
 * RTM Painel: mantém o painel da sala comercial (Fully Kiosk) no ecrã da TV.
 *
 * O Google TV volta à página inicial ao ligar, ao sair do modo de espera, depois de uma falha de luz ou quando alguém
 * carrega em Home; a TCL não deixa as apps arrancarem sozinhas (bloqueia o BOOT_COMPLETED). Um serviço de
 * acessibilidade é ligado pelo próprio sistema no arranque, sem depender desse aviso: quando a página inicial fica no
 * ecrã durante ESPERA_MS seguidos, abre o Fully. Essa espera deixa usar as definições da TV (o ícone das definições fica
 * na página inicial); fora da página inicial (definições, outras apps) nunca interfere.
 */
public class PainelServico extends AccessibilityService {
    private static final String TAG = "NavalPainel";
    private static final String FULLY = "de.ozerov.fully";
    private static final long ESPERA_MS = 30_000;      // tempo na página inicial antes de voltar ao painel
    private static final long ARRANQUE_MS = 20_000;    // depois de ligar a TV, abre o painel ao fim deste tempo
    private static final long VERIFICAR_MS = 5_000;

    /** Hora (elapsedRealtime) em que o serviço ficou ligado neste arranque; 0 se ainda não. A Arranque espera por ele. */
    static volatile long ligadoEm = 0;

    private final Handler h = new Handler(Looper.getMainLooper());
    private String inicio = "";          // pacote da página inicial do sistema (Google TV)
    private String frente = "";          // pacote da janela que está no ecrã
    private long desde = 0;              // desde quando está no ecrã

    private final Runnable verificar = new Runnable() {
        @Override
        public void run() {
            // a janela activa, perguntada ao sistema (os avisos de mudança de janela podem vir de camadas da TCL por cima)
            try {
                AccessibilityNodeInfo r = getRootInActiveWindow();
                if (r != null && r.getPackageName() != null) mudou(r.getPackageName().toString());
            } catch (Exception e) {
                Registo.escrever(PainelServico.this, "janela activa: " + e);
            }
            if (!inicio.isEmpty() && inicio.equals(frente) && SystemClock.elapsedRealtime() - desde >= ESPERA_MS) abrirPainel("página inicial");
            h.postDelayed(this, VERIFICAR_MS);
        }
    };

    private void mudou(String p) {
        if (p.equals("android") || p.equals("com.android.systemui") || p.startsWith("com.google.android.inputmethod")) return;
        if (!p.equals(frente)) {
            frente = p;
            desde = SystemClock.elapsedRealtime();
            Registo.escrever(this, "à frente: " + p);
        }
    }

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        ligadoEm = SystemClock.elapsedRealtime();
        Intent home = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME);
        ResolveInfo r = getPackageManager().resolveActivity(home, 0);
        if (r != null && r.activityInfo != null) inicio = r.activityInfo.packageName;
        Log.i(TAG, "ligado; página inicial = " + inicio);
        Registo.escrever(this, "serviço ligado; página inicial = " + inicio);
        frente = inicio;
        desde = SystemClock.elapsedRealtime();
        h.postDelayed(new Runnable() {
            @Override
            public void run() {
                if (!FULLY.equals(frente)) abrirPainel("arranque");
            }
        }, ARRANQUE_MS);
        h.postDelayed(verificar, VERIFICAR_MS);
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent e) {
        if (e == null || e.getEventType() != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED || e.getPackageName() == null) return;
        mudou(e.getPackageName().toString());
    }

    private void abrirPainel(String porque) {
        Intent i = getPackageManager().getLaunchIntentForPackage(FULLY);
        if (i == null) {
            Log.w(TAG, "Fully Kiosk não instalado");
            return;
        }
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED);
        try {
            startActivity(i);
            Log.i(TAG, "painel aberto (" + porque + ")");
            Registo.escrever(this, "painel aberto (" + porque + ")");
            frente = FULLY;
            desde = SystemClock.elapsedRealtime();
        } catch (Exception ex) {
            Log.w(TAG, "não foi possível abrir o painel: " + ex);
            Registo.escrever(this, "não abriu o painel: " + ex);
        }
    }

    @Override
    public void onInterrupt() {
    }

    @Override
    public void onDestroy() {
        ligadoEm = 0;
        h.removeCallbacksAndMessages(null);
        super.onDestroy();
    }
}
