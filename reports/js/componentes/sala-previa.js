// Painel da sala comercial ao vivo dentro do portal (Apresentação e página do painel): o ecrã da TV (../sala/) numa
// moldura 16:9, com os dados lidos pela sessão do portal (v1/sala/previa, sem código de ligação) e entregues à moldura
// por postMessage (mesma origem). Actualiza de 2 em 2 minutos, como a TV. Escolha do local: sala interna ou open space
// (o servidor só envia o que esse local pode mostrar).
import { apiJson } from '../api.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

const ACTUALIZAR_MS = 2 * 60 * 1000;

/** Moldura do painel ao vivo. o.local: 'interna' | 'aberta' (por omissão interna); o.escolha: mostra os botões do local. */
export function previaSala(o = {}) {
  let local = o.local === 'aberta' ? 'aberta' : 'interna';
  const w = el('div', 'sala-previa');
  const f = el('iframe');
  f.title = t('Painel da sala comercial ao vivo');
  f.setAttribute('loading', 'lazy');
  f.src = '../sala/#previa=' + local;
  let relogio = 0;
  const enviar = () => {
    clearTimeout(relogio);
    if (!w.isConnected && relogio) return;
    apiJson('sala/previa?local=' + local).then((d) => {
      try {
        if (f.contentWindow)
          f.contentWindow.postMessage({ tipo: 'sala-dados', dados: d || { ok: false, erro: t('Não foi possível ler os dados.') } }, location.origin);
      } catch (e) {
        /* moldura ainda a carregar */
      }
      relogio = setTimeout(() => w.isConnected && enviar(), ACTUALIZAR_MS);
    });
  };
  const ouvir = (ev) => {
    if (ev.origin !== location.origin || ev.source !== f.contentWindow || !ev.data || ev.data.tipo !== 'sala-pronta') return;
    enviar();
  };
  window.addEventListener('message', ouvir);
  // deixa de ouvir quando a moldura sai da página
  const obs = new MutationObserver(() => {
    if (!w.isConnected) {
      window.removeEventListener('message', ouvir);
      clearTimeout(relogio);
      obs.disconnect();
    }
  });
  if (o.escolha) {
    const seg = el('div', 'seg sala-previa-local');
    seg.setAttribute('role', 'group');
    seg.setAttribute('aria-label', t('Onde fica o ecrã'));
    [
      ['interna', t('Sala interna')],
      ['aberta', t('Open space')],
    ].forEach(([k, n]) => {
      const b = el('button', k === local ? 'on' : '', n);
      b.type = 'button';
      b.setAttribute('aria-pressed', k === local ? 'true' : 'false');
      b.addEventListener('click', () => {
        if (k === local) return;
        local = k;
        [...seg.children].forEach((x) => {
          const on = x === b;
          x.classList.toggle('on', on);
          x.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        f.src = '../sala/#previa=' + local;
        f.contentWindow && f.contentWindow.location.reload();
      });
      seg.append(b);
    });
    w.append(seg);
  }
  const moldura = el('div', 'sala-previa-ecra');
  moldura.append(f);
  w.append(moldura);
  requestAnimationFrame(() => obs.observe(document.body, { childList: true, subtree: true }));
  return w;
}
