// Espelho da apresentação (servidor: nucleo/api/v1/espelho.php). Com o modo apresentação activo, a app de gestão no
// ecrã do computador e a app no telemóvel da mesma conta mostram o mesmo ecrã ao mesmo tempo. Quem mexe conduz:
// cada lado envia o seu ecrã quando a pessoa lhe toca (ou quando muda o idioma ou a conversa do assistente) e aplica o
// do outro lado quando é mais recente. Só passa o estado do ecrã; os dados lê-os cada lado com a própria sessão.
import { apiFetch, apiJson } from './api.js';

const RAPIDO = 500; // ms entre leituras com a apresentação activa
const LENTO = 15000; // ms entre verificações sem apresentação
const GESTO = 2000; // ms em que um gesto da pessoa faz deste lado o condutor
// mudanças que se enviam mesmo sem gesto: a resposta do assistente (chega depois do gesto) e, do lado do portal, o
// idioma (escolhido nos botões do portal, fora da app)
const SEM_GESTO = ['conversa', 'pensar'];

const parte = (e, chaves) => JSON.stringify(chaves.map((k) => e[k]));

/**
 * Liga o espelho. obter() devolve o ecrã actual; aplicar(estado) mostra o ecrã do outro lado; activo(sim) avisa quando
 * a apresentação começa ou acaba; podeUsar() diz se a conta tem o modo apresentação; csrf() dá o código da sessão;
 * semGesto: chaves do ecrã que se enviam mesmo sem gesto da pessoa.
 */
export function ligarEspelho({ obter, aplicar, activo, podeUsar, csrf, semGesto = SEM_GESTO }) {
  const id = Array.from(crypto.getRandomValues(new Uint8Array(10)), (b) => (b % 36).toString(36)).join('');
  const st = { activo: false, seq: -1, ultimo: '', ultimoSem: '', gestoEm: 0, aplicadoEm: 0, aEnviar: false, aplicando: false, parado: false, temporizador: 0 };
  const gesto = () => (st.gestoEm = Date.now());
  ['pointerdown', 'touchstart', 'touchmove', 'wheel', 'keydown'].forEach((e) => addEventListener(e, gesto, { passive: true, capture: true }));

  const marcar = () => {
    const e = obter();
    st.ultimo = JSON.stringify(e);
    st.ultimoSem = parte(e, semGesto);
  };

  function enviar() {
    if (!st.activo || st.parado || st.aEnviar || st.aplicando || Date.now() - st.aplicadoEm < 400) return;
    const e = obter();
    const s = JSON.stringify(e);
    const sem = parte(e, semGesto);
    const conduz = Date.now() - st.gestoEm < GESTO;
    if (s === st.ultimo || (!conduz && sem === st.ultimoSem)) return;
    st.aEnviar = true;
    st.ultimo = s;
    st.ultimoSem = sem;
    apiFetch('espelho', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accao: 'estado', cliente: id, estado: e, csrf: csrf() }),
    })
      .then((r) => r.json())
      .then((d) => {
        if (d && d.ok && d.seq > st.seq) st.seq = d.seq;
      })
      .catch(() => {})
      .finally(() => (st.aEnviar = false));
  }

  function ler() {
    clearTimeout(st.temporizador);
    if (!podeUsar() || document.visibilityState === 'hidden') {
      st.temporizador = setTimeout(ler, LENTO);
      return;
    }
    apiJson('espelho?seq=' + st.seq)
      .then((d) => {
        if (!d || !d.ok) return;
        if (d.activo !== st.activo) {
          st.activo = d.activo;
          st.parado = false;
          activo(d.activo);
          if (d.activo) marcar();
        }
        // o ecrã do outro lado, mais recente e não enviado por este lado; não interrompe um gesto a meio
        if (d.activo && d.estado && d.origem !== id && d.seq > st.seq && !st.parado && Date.now() - st.gestoEm > 600) {
          st.seq = d.seq;
          st.aplicadoEm = Date.now();
          const em = st.aplicadoEm;
          // depois de aplicado (e de a página assentar), este ecrã passa a ser o «já enviado» — salvo se a pessoa
          // entretanto mexeu deste lado: aí o que fez segue para o outro lado
          // enquanto se aplica (o idioma pode ter de se descarregar) este lado não envia nada
          st.aplicando = true;
          Promise.resolve(aplicar(d.estado))
            .catch(() => {})
            .then(() => {
              st.aplicando = false;
              st.aplicadoEm = Date.now();
              setTimeout(() => {
                if (st.gestoEm < em) marcar();
              }, 300);
            });
        } else if (d.seq > st.seq && (!d.estado || d.origem === id)) st.seq = d.seq;
      })
      .catch(() => {})
      .finally(() => {
        st.temporizador = setTimeout(ler, st.activo ? RAPIDO : LENTO);
      });
  }
  setInterval(enviar, 250);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') ler();
  });
  ler();
  return {
    /** Deixar de seguir (só neste lado, até a apresentação acabar). */
    parar() {
      st.parado = true;
    },
    seguir() {
      st.parado = false;
      st.seq = -1;
      ler();
    },
    get parado() {
      return st.parado;
    },
    get activo() {
      return st.activo;
    },
  };
}
