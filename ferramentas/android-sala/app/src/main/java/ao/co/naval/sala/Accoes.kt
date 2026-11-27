package ao.co.naval.sala

import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.wifi.WifiInfo
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.Log
import android.view.View
import android.view.WindowManager
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * A sequência ao entrar na sala, só com mecanismos oficiais do Android e do MDM (ver docs/SALA-SINCRONIZACAO.md):
 *  1. Dados móveis: nenhuma app pode desligá-los (nem o Device Owner tem essa API). O Android passa todo o tráfego
 *     para o Wi-Fi da sala logo que ele está validado: os dados móveis ficam parados enquanto se está na sala.
 *  2. Outras apps: modo «kiosk» — o portal pede ao MDM (Device Owner) o quiosque de uma só app no Winrest durante uns
 *     minutos; modo «app» — o Winrest vem para a frente e as outras ficam em segundo plano.
 *  3. Wi-Fi: o perfil Wi-Fi do MDM liga sozinho; a entrada na sala SÓ é detectada já ligado à rede e ao ponto de acesso
 *     da sala, por isso este passo está confirmado antes do 4.
 *  4. Winrest em primeiro plano: pela excepção oficial do Android para apps com «sobrepor a outras apps» (no Android
 *     15 com uma janela de sobreposição visível: um ponto de 1 px durante 3 s).
 */
object Accoes {
    private const val TAG = "NavalSala"
    private val principal = Handler(Looper.getMainLooper())

    fun entrar(ctx: Context, cfg: Config) {
        Notificacoes.estado(ctx, naSala = true)
        val wifi = noWifiDaSala(ctx, cfg)
        val aberto = abrirWinrest(ctx, cfg.winrestPacote)
        // o portal regista a presença (o LCD mostra quem sincronizou, confirmado pelo Pinguim BO) e a rede que as apps
        // estão a usar: o Winrest não sincroniza se o Android continuar nos dados móveis
        val (rede, dados) = redeEmUso(ctx)
        avisarPortal(ctx, cfg, "entrada", mapOf("wifi_sala" to wifi.toString(), "winrest_aberto" to aberto.toString(), "rede" to rede,
            "dados_moveis" to dados.toString()))
    }

    /**
     * A rede que as apps estão a usar agora (a rede predefinida do Android) e se os dados móveis estão ligados:
     *  - «wifi»: Wi-Fi com Internet confirmada pelo Android (tudo passa por aqui, os dados móveis ficam parados);
     *  - «wifi_sem_internet»: Wi-Fi sem Internet confirmada (o Android pode continuar a usar os dados móveis);
     *  - «dados_moveis»: as apps estão nos dados móveis; «sem_rede».
     */
    @Suppress("DEPRECATION")
    fun redeEmUso(ctx: Context): Pair<String, Boolean> {
        val cm = ctx.getSystemService(ConnectivityManager::class.java)
        val dados = cm.allNetworks.any { cm.getNetworkCapabilities(it)?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true }
        val caps = cm.getNetworkCapabilities(cm.activeNetwork) ?: return "sem_rede" to dados
        val rede = when {
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) -> "wifi"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi_sem_internet"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "dados_moveis"
            else -> "sem_rede"
        }
        return rede to dados
    }

    fun sair(ctx: Context, cfg: Config) {
        Notificacoes.estado(ctx, naSala = false)
        avisarPortal(ctx, cfg, "saida")
    }

    @Suppress("DEPRECATION")
    fun noWifiDaSala(ctx: Context, cfg: Config): Boolean {
        val cm = ctx.getSystemService(ConnectivityManager::class.java)
        val caps = cm.getNetworkCapabilities(cm.activeNetwork) ?: return false
        if (!caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) return false
        val info = (if (Build.VERSION.SDK_INT >= 29) caps.transportInfo as? WifiInfo else null)
            ?: ctx.applicationContext.getSystemService(WifiManager::class.java).connectionInfo ?: return false
        return cfg.eDaSala(info.ssid, info.bssid)
    }

    /** Põe o Winrest em primeiro plano. Devolve false se faltar a permissão de sobreposição (o MDM tem de a conceder). */
    fun abrirWinrest(ctx: Context, pacote: String): Boolean {
        val abrir = ctx.packageManager.getLaunchIntentForPackage(pacote)?.apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
        }
        if (abrir == null) {
            Log.e(TAG, "Winrest não instalado ou pacote errado: $pacote")
            return false
        }
        if (!Settings.canDrawOverlays(ctx)) {
            Log.e(TAG, "sem «sobrepor a outras apps»: o Android não deixa abrir o Winrest a partir do segundo plano")
            Notificacoes.pedirToque(ctx, abrir)   // recurso: um toque na notificação abre o Winrest
            return false
        }
        val wm = ctx.getSystemService(WindowManager::class.java)
        val ponto = View(ctx)
        val lp = WindowManager.LayoutParams(
            1, 1,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE,
            PixelFormat.TRANSLUCENT,
        )
        val comPonto = runCatching { wm.addView(ponto, lp) }.isSuccess
        try {
            ctx.startActivity(abrir)
            Log.i(TAG, "Winrest aberto em primeiro plano ($pacote)")
        } finally {
            if (comPonto) principal.postDelayed({ runCatching { wm.removeView(ponto) } }, 3_000)
        }
        return true
    }

    /** Diz ao portal o que aconteceu (POST v1/sala/presenca). Sem portal configurado, não faz nada. */
    fun avisarPortal(ctx: Context, cfg: Config, evento: String, extra: Map<String, String> = emptyMap()) {
        if (cfg.portalUrl.isBlank() || cfg.portalChave.isBlank()) return
        val campos = linkedMapOf(
            "evento" to evento,
            "modo" to cfg.modo,
            "dispositivo_mdm" to cfg.dispositivoMdm,
            "rota" to cfg.rota,
            "modelo" to Build.MODEL,
            "app" to (Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ANDROID_ID) ?: ""),
        ) + extra
        val corpo = campos.entries.joinToString(",", "{", "}") { (k, v) -> "\"$k\":\"" + v.replace("\\", "").replace("\"", "") + "\"" }
        thread(name = "naval-sala-portal") {
            runCatching {
                val c = URL(cfg.portalUrl + "/api/index.php/v1/sala/presenca").openConnection() as HttpURLConnection
                c.requestMethod = "POST"
                c.connectTimeout = 5_000
                c.readTimeout = 10_000
                c.doOutput = true
                c.setRequestProperty("Content-Type", "application/json")
                c.setRequestProperty("Authorization", "Bearer " + cfg.portalChave)
                c.outputStream.use { it.write(corpo.toByteArray()) }
                Log.i(TAG, "portal: $evento → ${c.responseCode}")
                c.disconnect()
            }.onFailure { Log.w(TAG, "portal sem resposta: ${it.message}") }
        }
    }
}
