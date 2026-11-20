package ao.co.naval.sala

/**
 * Máquina de estados da presença na sala (pura, sem Android: testada em PresencaTest).
 *
 *  FORA ──(N leituras seguidas com sinal ≥ limiar, numa janela de 30 s)──▶ DENTRO
 *  DENTRO ──(sem ver o emissor durante «saida» minutos)──▶ FORA
 *
 * Histerese: entra com sinal forte e várias leituras (não dispara ao passar no corredor); sai só depois de vários
 * minutos sem sinal (uma leitura perdida ou o telemóvel no bolso não contam como saída). Espera entre disparos:
 * mesmo que saia e volte, só volta a abrir o Winrest passados «espera» minutos desde o último disparo, para não o
 * reiniciar durante a reunião.
 */
enum class Evento { ENTRADA, ENTRADA_SEM_DISPARO, SAIDA }

data class Regras(
    val rssiEntrada: Int = -75,
    val leiturasEntrada: Int = 2,
    val janelaEntradaMs: Long = 30_000,
    val saidaMs: Long = 10 * 60_000,
    val esperaMs: Long = 30 * 60_000,
)

/** Estado gravável (o Android pode matar o processo entre duas leituras). */
data class EstadoPresenca(
    val dentro: Boolean = false,
    val leiturasFortes: Int = 0,
    val primeiraForteMs: Long = 0,
    val ultimaVistaMs: Long = 0,
    val ultimoDisparoMs: Long = 0,
)

class Presenca(private val regras: Regras, var estado: EstadoPresenca = EstadoPresenca()) {

    /** Uma leitura do emissor da sala. Devolve o evento, se houver. */
    fun leitura(rssi: Int, agoraMs: Long): Evento? {
        val e = estado
        if (e.dentro) {
            estado = e.copy(ultimaVistaMs = agoraMs)
            return null
        }
        if (rssi < regras.rssiEntrada) {
            // sinal fraco (corredor, porta): conta como visto, mas não aproxima a entrada
            estado = e.copy(leiturasFortes = 0, primeiraForteMs = 0, ultimaVistaMs = agoraMs)
            return null
        }
        val novaJanela = e.leiturasFortes == 0 || agoraMs - e.primeiraForteMs > regras.janelaEntradaMs
        val n = if (novaJanela) 1 else e.leiturasFortes + 1
        val primeira = if (novaJanela) agoraMs else e.primeiraForteMs
        if (n < regras.leiturasEntrada) {
            estado = e.copy(leiturasFortes = n, primeiraForteMs = primeira, ultimaVistaMs = agoraMs)
            return null
        }
        val podeDisparar = e.ultimoDisparoMs == 0L || agoraMs - e.ultimoDisparoMs >= regras.esperaMs
        estado = EstadoPresenca(
            dentro = true,
            ultimaVistaMs = agoraMs,
            ultimoDisparoMs = if (podeDisparar) agoraMs else e.ultimoDisparoMs,
        )
        return if (podeDisparar) Evento.ENTRADA else Evento.ENTRADA_SEM_DISPARO
    }

    /** Verificação periódica (sem leituras novas): a saída detecta-se pela ausência do emissor. */
    fun tique(agoraMs: Long): Evento? {
        val e = estado
        if (e.dentro && agoraMs - e.ultimaVistaMs >= regras.saidaMs) {
            estado = EstadoPresenca(ultimoDisparoMs = e.ultimoDisparoMs)
            return Evento.SAIDA
        }
        return null
    }
}
