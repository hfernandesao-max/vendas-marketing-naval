package ao.co.naval.sala

import android.content.Context
import android.util.Log

/**
 * Liga a máquina de estados ao Android: guarda o estado (SharedPreferences, sobrevive à morte do processo), recebe
 * as leituras do scan e os tiques do serviço, e executa as acções nos eventos. Chamadas sempre na thread principal.
 */
object Motor {
    private const val TAG = "NavalSala"
    private const val PREF = "presenca"

    private fun carregar(ctx: Context, cfg: Config): Presenca {
        val p = ctx.getSharedPreferences(PREF, Context.MODE_PRIVATE)
        return Presenca(
            cfg.regras,
            EstadoPresenca(
                dentro = p.getBoolean("dentro", false),
                leiturasFortes = p.getInt("fortes", 0),
                primeiraForteMs = p.getLong("primeira", 0),
                ultimaVistaMs = p.getLong("vista", 0),
                ultimoDisparoMs = p.getLong("disparo", 0),
            ),
        )
    }

    private fun gravar(ctx: Context, e: EstadoPresenca) {
        ctx.getSharedPreferences(PREF, Context.MODE_PRIVATE).edit()
            .putBoolean("dentro", e.dentro).putInt("fortes", e.leiturasFortes).putLong("primeira", e.primeiraForteMs)
            .putLong("vista", e.ultimaVistaMs).putLong("disparo", e.ultimoDisparoMs).apply()
    }

    fun estado(ctx: Context): EstadoPresenca = carregar(ctx, Config.ler(ctx)).estado

    fun leitura(ctx: Context, rssi: Int) = passo(ctx) { it.leitura(rssi, System.currentTimeMillis()) }

    fun tique(ctx: Context) = passo(ctx) { it.tique(System.currentTimeMillis()) }

    private fun passo(ctx: Context, f: (Presenca) -> Evento?) {
        val cfg = Config.ler(ctx)
        if (!cfg.pronta) return
        val p = carregar(ctx, cfg)
        val ev = f(p)
        gravar(ctx, p.estado)
        when (ev) {
            Evento.ENTRADA -> {
                Log.i(TAG, "entrada na sala: abrir o Winrest")
                Accoes.entrar(ctx, cfg)
            }
            Evento.ENTRADA_SEM_DISPARO -> {
                Log.i(TAG, "entrada na sala dentro do tempo de espera: não reabre o Winrest")
                Accoes.avisarPortal(ctx, cfg, "entrada_sem_disparo")
            }
            Evento.SAIDA -> {
                Log.i(TAG, "saída da sala")
                Accoes.sair(ctx, cfg)
            }
            null -> Unit
        }
    }
}
