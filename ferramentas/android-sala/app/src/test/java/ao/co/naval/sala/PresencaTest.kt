package ao.co.naval.sala

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Máquina de presença: histerese na entrada, saída por ausência e espera entre disparos. */
class PresencaTest {
    private val min = 60_000L
    private val regras = Regras(rssiEntrada = -67, leiturasEntrada = 2, janelaEntradaMs = 90_000, saidaMs = 5 * min, esperaMs = 30 * min)

    @Test
    fun `sinal fraco no corredor nao entra`() {
        val p = Presenca(regras)
        assertNull(p.leitura(-80, 0))
        assertNull(p.leitura(-78, 10_000))
        assertTrue(!p.estado.dentro)
    }

    @Test
    fun `duas leituras fortes seguidas entram e disparam uma vez`() {
        val p = Presenca(regras)
        assertNull(p.leitura(-60, 0))
        assertEquals(Evento.ENTRADA, p.leitura(-58, 20_000))
        assertTrue(p.estado.dentro)
        // dentro da sala, mais leituras não voltam a disparar
        assertNull(p.leitura(-55, 40_000))
    }

    @Test
    fun `leituras fortes demasiado afastadas recomecam a contagem`() {
        val p = Presenca(regras)
        assertNull(p.leitura(-60, 0))
        assertNull(p.leitura(-60, 200_000))   // fora da janela de 90 s: conta como a primeira
        assertEquals(Evento.ENTRADA, p.leitura(-60, 230_000))
    }

    @Test
    fun `sai so depois de 5 minutos sem a rede da sala`() {
        val p = Presenca(regras)
        p.leitura(-60, 0)
        p.leitura(-60, 10_000)
        assertNull(p.tique(10_000 + 4 * min))
        assertEquals(Evento.SAIDA, p.tique(10_000 + 5 * min))
        assertTrue(!p.estado.dentro)
    }

    @Test
    fun `voltar dentro da espera entra sem reabrir o Winrest; depois da espera volta a disparar`() {
        val p = Presenca(regras)
        p.leitura(-60, 0)
        assertEquals(Evento.ENTRADA, p.leitura(-60, 10_000))
        p.tique(10_000 + 5 * min)                                 // saiu
        p.leitura(-60, 12 * min)
        assertEquals(Evento.ENTRADA_SEM_DISPARO, p.leitura(-60, 12 * min + 10_000))
        p.tique(12 * min + 10_000 + 5 * min)                      // saiu outra vez
        p.leitura(-60, 45 * min)
        assertEquals(Evento.ENTRADA, p.leitura(-60, 45 * min + 10_000))   // passaram 30 min do último disparo
    }
}
