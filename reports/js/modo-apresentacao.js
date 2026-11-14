// Modo apresentação (recurso «modo-apresentacao»; servidor: nucleo/api/v1/modo-apresentacao.php).
// Para apresentar à administração com o computador ligado a um projector ou LCD:
//   - activa-se no menu da conta, por um período escolhido (30 min a 4 h), e acaba sozinho à hora marcada;
//   - durante esse período a sessão termina só após 60 minutos sem actividade e o portal não volta sozinho ao painel;
//   - no próprio portal escolhe-se o que se mostra: o portal, a app de gestão ou o portal e a app lado a lado. A app
//     corre dentro de uma moldura de telemóvel, ao vivo, com a mesma conta e os mesmos dados;
//   - não abrem janelas automáticas (resumo das actualizações) e uma marca discreta mostra que o modo está activo;
//   - a app de gestão mostrada no computador e a app no telemóvel da mesma conta mostram o mesmo ecrã ao mesmo tempo
//     (espelho.js), no idioma escolhido no portal;
//   - começo, fim e app mostrada ficam registados (ISO/IEC 27001 A.8.15).
import { postJson } from './servidor.js';
import { escutarJanela } from './inactividade.js';
import { LANG, t } from './i18n/i18n.js';
import { botao, janela } from './componentes/janelas.js';
import { SESSAO } from './perfil.js';
import { el, toast } from './util.js';

export const VISTAS = {
  portal: { nome: 'Portal' },
  gestao: { nome: 'App de gestão', url: '../gestao/' },
  lado: { nome: 'Portal e app lado a lado', url: '../gestao/' },
};
const DURACOES = [
  [30, '30 minutos'],
  [60, '1 hora'],
  [120, '2 horas'],
  [180, '3 horas'],
  [240, '4 horas'],
];
// ecrã de telemóvel de referência (pontos CSS de um telemóvel actual de 6,1")
const TEL_L = 390;
const TEL_A = 844;
const CHAVE = 'vm-apresentacao-vista';
let vista = 'portal';
let barra = null;
let palco = null;
let relogio = 0;

const embebido = () => window.self !== window.top;
export const activo = () => !!(SESSAO && SESSAO.apresentacao && SESSAO.apresentacao * 1000 > Date.now());
const hhmm = (s) => new Date(s * 1000).toTimeString().slice(0, 5);

function definirEstado(d) {
  if (!SESSAO || !d) return;
  SESSAO.apresentacao = d.activo ? d.ate : null;
  SESSAO.sessao = { ...(SESSAO.sessao || {}), inactiva_s: d.inactiva_s };
}

/** Janela para iniciar o modo apresentação (menu da conta). */
export function abrirModoApresentacao() {
  if (activo()) {
    mostrar(vista);
    return;
  }
  janela(
    t('Modo apresentação'),
    t(
      'Para apresentar com o computador ligado a um projector ou LCD. Durante o período escolhido, a sessão só termina após 60 minutos sem actividade e o portal não volta sozinho ao painel. Acaba sozinho à hora marcada e fica registado.',
    ),
    (b, ft, fechar) => {
      const dur = el('select');
      dur.setAttribute('aria-label', t('Duração da apresentação'));
      DURACOES.forEach(([m, n]) => {
        const o = el('option', null, t(n));
        o.value = String(m);
        if (m === 120) o.selected = true;
        dur.append(o);
      });
      const vis = el('select');
      vis.setAttribute('aria-label', t('O que mostrar'));
      Object.entries(VISTAS).forEach(([k, v]) => {
        const o = el('option', null, t(v.nome));
        o.value = k;
        vis.append(o);
      });
      const ec = el('input');
      ec.type = 'checkbox';
      ec.checked = true;
      const lEc = el('label', 'apres-ec');
      lEc.append(ec, el('span', null, t('Ecrã inteiro')));
      const lin = (rot, c) => {
        const l = el('label', 'campo');
        l.append(el('span', null, rot), c);
        return l;
      };
      const erro = el('p', 'note erro');
      erro.hidden = true;
      b.append(
        lin(t('Duração'), dur),
        lin(t('O que mostrar'), vis),
        lEc,
        el('p', 'note', t('Pode mudar o que mostra a qualquer momento, na marca do modo apresentação.')),
        erro,
      );
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Iniciar apresentação'), null, () => {
          // ecrã inteiro pedido já, no clique (os navegadores só o permitem num gesto da pessoa)
          if (ec.checked && document.documentElement.requestFullscreen && !document.fullscreenElement)
            document.documentElement.requestFullscreen().catch(() => {});
          postJson('modo-apresentacao', { accao: 'iniciar', minutos: +dur.value }).then((d) => {
            if (!d || !d.ok) {
              erro.hidden = false;
              erro.textContent = t((d && d.erro) || 'Não foi possível gravar.');
              return;
            }
            definirEstado(d);
            fechar();
            mostrar(vis.value);
            toast(t('Modo apresentação até às {h}.', { h: hhmm(d.ate) }), 4000);
          });
        }),
      );
    },
  );
}

/** Termina o modo apresentação (pela pessoa ou à hora marcada). */
export function terminarModoApresentacao(pelaHora) {
  const fim = () => {
    if (SESSAO) {
      SESSAO.apresentacao = null;
      SESSAO.sessao = { ...(SESSAO.sessao || {}), inactiva_s: (SESSAO.sessao && SESSAO.sessao.normal_s) || 900 };
    }
    limpar();
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    toast(t(pelaHora ? 'O modo apresentação chegou ao fim.' : 'Modo apresentação terminado.'), 4000);
  };
  if (pelaHora) fim();
  else postJson('modo-apresentacao', { accao: 'terminar' }).then(fim);
}

function limpar() {
  clearInterval(relogio);
  relogio = 0;
  if (barra) barra.remove();
  if (palco) palco.remove();
  barra = palco = null;
  document.body.classList.remove('apres-activo', 'apres-lado', 'apres-palco');
  try {
    sessionStorage.removeItem(CHAVE);
  } catch (e) {
    /* sem armazenamento */
  }
}

/** Moldura de telemóvel com a app ao vivo (mesma origem e mesma sessão), ajustada à altura do ecrã. */
function telemovel(url) {
  const m = el('div', 'apres-tel');
  const f = el('iframe');
  f.src = url;
  f.title = t('App no ecrã do computador');
  f.width = String(TEL_L);
  f.height = String(TEL_A);
  // os gestos dentro da app contam como actividade da pessoa
  f.addEventListener('load', () => {
    try {
      escutarJanela(f.contentWindow);
    } catch (e) {
      /* outra origem: não acontece (a app é do próprio portal) */
    }
  });
  m.append(f);
  return m;
}

function escala() {
  if (!palco) return;
  const alt = innerHeight - 150; // espaço para a legenda e para a marca do modo apresentação
  const larg = document.body.classList.contains('apres-lado') ? 440 : innerWidth - 48;
  const k = Math.min(1, alt / (TEL_A + 28), larg / (TEL_L + 28));
  palco.style.setProperty('--apres-k', String(Math.round(k * 1000) / 1000));
}

/** Mostra a vista escolhida: portal, app de gestão ou portal e app lado a lado. */
export function mostrar(v) {
  if (!activo() || embebido()) return;
  vista = VISTAS[v] ? v : 'portal';
  try {
    sessionStorage.setItem(CHAVE, vista);
  } catch (e) {
    /* sem armazenamento */
  }
  document.body.classList.add('apres-activo');
  document.body.classList.toggle('apres-lado', vista === 'lado');
  document.body.classList.toggle('apres-palco', vista === 'gestao');
  if (palco) palco.remove();
  palco = null;
  if (VISTAS[vista].url) {
    palco = el('div', 'apres-cena' + (vista === 'lado' ? ' apres-cena-lado' : ''));
    palco.setAttribute('role', 'region');
    palco.setAttribute('aria-label', t(VISTAS[vista].nome));
    palco.append(telemovel(VISTAS[vista].url), el('p', 'apres-legenda', t(VISTAS[vista].nome)));
    document.body.append(palco);
    escala();
  }
  desenharBarra();
}

function desenharBarra() {
  if (barra) barra.remove();
  barra = el('div', 'apres-barra');
  barra.setAttribute('role', 'toolbar');
  barra.setAttribute('aria-label', t('Modo apresentação'));
  const quando = el('span', 'apres-ate', t('Apresentação até às {h}', { h: hhmm(SESSAO.apresentacao) }));
  barra.append(quando);
  Object.entries(VISTAS).forEach(([k, v]) => {
    const b = botao(t(v.nome), 'sm', () => mostrar(k));
    b.setAttribute('aria-pressed', String(k === vista));
    barra.append(b);
  });
  // idioma (também com a app à frente do portal): os mesmos botões PT · EN · TI do portal
  const idi = el('span', 'apres-idioma');
  idi.setAttribute('role', 'group');
  idi.setAttribute('aria-label', t('Idioma'));
  document.querySelectorAll('#lang button').forEach((o) => {
    const b = botao(o.dataset.l.toUpperCase(), 'sm', () => o.click());
    b.setAttribute('lang', o.dataset.l);
    b.setAttribute('aria-pressed', String(o.dataset.l === LANG));
    idi.append(b);
  });
  if (idi.childElementCount) barra.append(idi);
  if (document.documentElement.requestFullscreen)
    barra.append(
      botao(t('Ecrã inteiro'), 'sm', () =>
        document.fullscreenElement ? document.exitFullscreen().catch(() => {}) : document.documentElement.requestFullscreen().catch(() => {}),
      ),
    );
  barra.append(botao(t('Terminar'), 'sm', () => terminarModoApresentacao(false)));
  document.body.append(barra);
  clearInterval(relogio);
  relogio = setInterval(() => {
    if (!activo()) terminarModoApresentacao(true);
  }, 5000);
}

addEventListener('resize', escala);
// Mudar de idioma no portal durante a apresentação: a marca e a legenda mudam logo; a app de gestão muda sozinha
// (lê o idioma do portal) e o telemóvel que a segue também.
document.addEventListener('click', (e) => {
  if (!e.target.closest || !e.target.closest('#lang button') || !activo()) return;
  const antes = LANG;
  let n = 0;
  const ver = setInterval(() => {
    if (LANG !== antes || ++n > 40) {
      clearInterval(ver);
      if (LANG === antes) return;
      {
        desenharBarra();
        const l = palco && palco.querySelector('.apres-legenda');
        if (l) l.textContent = t(VISTAS[vista].nome);
        if (palco) palco.setAttribute('aria-label', t(VISTAS[vista].nome));
      }
    }
  }, 100);
});

let ligado = false;
/** Ao abrir o portal: retoma o modo apresentação activo nesta sessão (na vista em que estava). */
export function iniciarModoApresentacao() {
  if (ligado || embebido()) return;
  ligado = true;
  if (!activo()) return;
  let v = 'portal';
  try {
    v = sessionStorage.getItem(CHAVE) || 'portal';
  } catch (e) {
    /* sem armazenamento */
  }
  mostrar(v);
}
