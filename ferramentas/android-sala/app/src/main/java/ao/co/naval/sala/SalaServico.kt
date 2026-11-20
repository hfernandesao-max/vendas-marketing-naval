package ao.co.naval.sala

import android.annotation.SuppressLint
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.wifi.WifiInfo
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.util.Log

/**
 * Serviço em primeiro plano (tipo «connectedDevice»): acompanha a ligação Wi-Fi. O MDM mantém o Wi-Fi sempre ligado e
 * dá ao telemóvel o perfil da rede da sala (ligação automática), por isso o Android liga-se sozinho quando o comercial
 * entra. Cada mudança do sinal (RSSI) da rede da sala é uma leitura para a máquina de presença; a cada 30 s confirma-se
 * o sinal e detecta-se a saída (sem a rede da sala durante «saida» minutos).
 */
class SalaServico : Service() {
    private val principal = Handler(Looper.getMainLooper())
    private var cb: ConnectivityManager.NetworkCallback? = null
    private val tique = object : Runnable {
        override fun run() {
            lerWifiActual()
            Motor.tique(this@SalaServico)
            principal.postDelayed(this, 30_000)
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val naSala = Motor.estado(this).dentro
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(Notificacoes.ID_SERVICO, Notificacoes.servico(this, naSala), ServiceInfo.FOREGROUND_SERVICE_TYPE_CONNECTED_DEVICE)
        } else {
            startForeground(Notificacoes.ID_SERVICO, Notificacoes.servico(this, naSala))
        }
        acompanharWifi()
        principal.removeCallbacks(tique)
        principal.post(tique)
        return START_STICKY
    }

    override fun onDestroy() {
        principal.removeCallbacks(tique)
        cb?.let { runCatching { getSystemService(ConnectivityManager::class.java).unregisterNetworkCallback(it) } }
        super.onDestroy()
    }

    private fun acompanharWifi() {
        val cm = getSystemService(ConnectivityManager::class.java)
        cb?.let { runCatching { cm.unregisterNetworkCallback(it) } }
        // Android 12+: sem FLAG_INCLUDE_LOCATION_INFO o SSID e o BSSID vêm escondidos («<unknown ssid>»)
        val novo = if (Build.VERSION.SDK_INT >= 31) object : ConnectivityManager.NetworkCallback(FLAG_INCLUDE_LOCATION_INFO) {
            override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) = leitura(caps)
        } else object : ConnectivityManager.NetworkCallback() {
            override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) = leitura(caps)
        }
        cm.registerNetworkCallback(NetworkRequest.Builder().addTransportType(NetworkCapabilities.TRANSPORT_WIFI).build(), novo)
        cb = novo
    }

    /** Uma leitura do sinal da rede da sala (só conta se for a rede e o ponto de acesso da sala). */
    @Suppress("DEPRECATION")
    private fun leitura(caps: NetworkCapabilities) {
        val info = (if (Build.VERSION.SDK_INT >= 29) caps.transportInfo as? WifiInfo else null)
            ?: applicationContext.getSystemService(WifiManager::class.java).connectionInfo ?: return
        val cfg = Config.ler(this)
        if (!cfg.eDaSala(info.ssid, info.bssid)) return
        principal.post { Motor.leitura(this, info.rssi) }
    }

    @SuppressLint("MissingPermission")
    private fun lerWifiActual() {
        val cm = getSystemService(ConnectivityManager::class.java)
        cm.allNetworks.forEach { n ->
            val caps = cm.getNetworkCapabilities(n) ?: return@forEach
            if (caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) leitura(caps)
        }
    }

    companion object {
        private const val TAG = "NavalSala"

        fun arrancar(ctx: Context) {
            if (!Config.ler(ctx).pronta) {
                Log.w(TAG, "configuração do MDM incompleta (SSID da sala e pacote do Winrest): não arranca")
                return
            }
            ctx.startForegroundService(Intent(ctx, SalaServico::class.java))
        }
    }
}
