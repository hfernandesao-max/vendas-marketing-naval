package ao.co.naval.sala

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.widget.TextView

/** Arranque do telefone, actualização da app ou nova configuração do MDM: o serviço volta a arrancar sozinho. */
class ArranqueRecetor : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) = SalaServico.arrancar(ctx)
}

/** Ecrã de diagnóstico para a Informática: configuração recebida do MDM, permissões e estado. */
class DiagnosticoActivity : Activity() {
    override fun onCreate(s: Bundle?) {
        super.onCreate(s)
        SalaServico.arrancar(this)
        val cfg = Config.ler(this)
        val e = Motor.estado(this)
        val pm = getSystemService(PowerManager::class.java)
        val ok = { b: Boolean -> if (b) "sim" else "NÃO" }
        setContentView(TextView(this).apply {
            setPadding(40, 60, 40, 40)
            textSize = 15f
            text = """
                Naval Sala — diagnóstico

                Configuração do MDM completa: ${ok(cfg.pronta)}
                Rede da sala: ${cfg.ssidSala.ifBlank { "—" }} · pontos de acesso: ${if (cfg.bssids.isEmpty()) "qualquer" else cfg.bssids.joinToString()}
                Ligado agora à rede da sala: ${ok(Accoes.noWifiDaSala(this, cfg))}
                Sinal mínimo: ${cfg.regras.rssiEntrada} dBm · ${cfg.regras.leiturasEntrada} leituras
                Saída: ${cfg.regras.saidaMs / 60000} min · espera entre sincronizações: ${cfg.regras.esperaMs / 60000} min
                Winrest: ${cfg.winrestPacote.ifBlank { "—" }} (instalado: ${ok(packageManager.getLaunchIntentForPackage(cfg.winrestPacote) != null)})
                Modo: ${cfg.modo}${if (cfg.modo == "kiosk") " · equipamento no MDM: ${cfg.dispositivoMdm.ifBlank { "FALTA" }}" else ""}
                Portal: ${cfg.portalUrl.ifBlank { "—" }}

                Sobrepor a outras apps: ${ok(Settings.canDrawOverlays(this))}
                Isento de optimização da bateria: ${ok(pm.isIgnoringBatteryOptimizations(packageName))}

                Na sala agora: ${ok(e.dentro)}
                Último sinal da sala: ${if (e.ultimaVistaMs > 0) java.util.Date(e.ultimaVistaMs) else "—"}
                Última sincronização disparada: ${if (e.ultimoDisparoMs > 0) java.util.Date(e.ultimoDisparoMs) else "—"}
            """.trimIndent()
        })
    }
}
