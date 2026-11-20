package ao.co.naval.sala

import android.content.Context
import android.content.RestrictionsManager
import java.util.Locale

/**
 * Configuração gerida pelo ManageEngine MDM (res/xml/app_restrictions.xml). Sem SSID da sala nem pacote do Winrest,
 * a automação não arranca.
 *
 * modo «app»: a app abre o Winrest em primeiro plano (rápido, local).
 * modo «kiosk»: além disso, o portal pede ao MDM o quiosque de uma só app no Winrest durante uns minutos (as outras
 * apps deixam de poder vir para a frente), e retira-o sozinho; precisa de «dispositivo_mdm" (ID do equipamento no MDM).
 */
data class Config(
    val ssidSala: String,
    val bssids: Set<String>,
    val regras: Regras,
    val winrestPacote: String,
    val modo: String,
    val dispositivoMdm: String,
    val portalUrl: String,
    val portalChave: String,
    val activo: Boolean,
) {
    val pronta get() = activo && ssidSala.isNotBlank() && winrestPacote.isNotBlank()

    /** Esta rede Wi-Fi é a da sala? (SSID igual e, se houver lista, um dos pontos de acesso da sala) */
    fun eDaSala(ssid: String?, bssid: String?): Boolean =
        ssid?.trim('"') == ssidSala && (bssids.isEmpty() || bssid?.lowercase(Locale.ROOT) in bssids)

    companion object {
        fun ler(ctx: Context): Config {
            val b = (ctx.getSystemService(Context.RESTRICTIONS_SERVICE) as RestrictionsManager).applicationRestrictions
            return Config(
                ssidSala = b.getString("ssid_sala").orEmpty().trim(),
                bssids = b.getString("bssids_sala").orEmpty().split(',', ';', ' ').map { it.trim().lowercase(Locale.ROOT) }.filter { it.isNotEmpty() }.toSet(),
                regras = Regras(
                    rssiEntrada = b.getInt("rssi_entrada", -67),
                    leiturasEntrada = b.getInt("leituras_entrada", 2).coerceIn(1, 10),
                    janelaEntradaMs = 90_000,
                    saidaMs = b.getInt("saida_minutos", 5).coerceIn(2, 120) * 60_000L,
                    esperaMs = b.getInt("espera_minutos", 30).coerceIn(5, 480) * 60_000L,
                ),
                winrestPacote = b.getString("winrest_pacote").orEmpty().trim(),
                modo = if (b.getString("modo").orEmpty().trim() == "kiosk") "kiosk" else "app",
                dispositivoMdm = b.getString("dispositivo_mdm").orEmpty().trim(),
                portalUrl = b.getString("portal_url").orEmpty().trim().trimEnd('/'),
                portalChave = b.getString("portal_chave").orEmpty().trim(),
                activo = b.getBoolean("activo", true),
            )
        }
    }
}
