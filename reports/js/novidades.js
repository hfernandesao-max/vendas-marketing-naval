// Resumo das actualizações do portal: só para o super administrador, na primeira entrada depois de cada actualização
// (servidor: nucleo/api/v1/novidades.php). Fechada a janela, as actualizações ficam marcadas como vistas.
import { apiJson } from './api.js';
import { botao, janela } from './componentes/janelas.js';
import { t } from './i18n/i18n.js';
import { SESSAO } from './perfil.js';
import { postJson } from './servidor.js';
import { el } from './util.js';

/** Texto do registo de alterações com **negrito** e `código`, montado sem HTML vindo do servidor. */
function textoFormatado(pai, txt) {
  for (const parte of String(txt).split(/(\*\*[^*]+\*\*|`[^`]+`)/)) {
    if (!parte) continue;
    if (parte.startsWith('**') && parte.endsWith('**')) pai.append(el('strong', null, parte.slice(2, -2)));
    else if (parte.startsWith('`') && parte.endsWith('`')) pai.append(el('code', null, parte.slice(1, -1)));
    else pai.append(document.createTextNode(parte));
  }
  return pai;
}

const dataHora = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(s || ''));
  return m ? `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}` : '';
};

/** Mostra a janela se houver actualizações por ver (nada para os outros perfis). */
export async function mostrarNovidades() {
  if (!SESSAO || SESSAO.perfil !== 'SADM') return;
  const d = await apiJson('novidades');
  const lista = d && Array.isArray(d.actualizacoes) ? d.actualizacoes : [];
  if (!lista.length) return;
  const ids = lista.map((a) => a.id);
  const total = lista.reduce((n, a) => n + a.seccoes.length, 0);
  const dlg = janela(
    t('Actualizações do portal'),
    t('Resumo do que mudou no portal desde a sua última entrada ({n} secções).', { n: total }),
    (b, ft, fechar) => {
      b.classList.add('nov');
      lista.forEach((a, i) => {
        const cab = el('p', 'nov-cab');
        cab.append(
          el('strong', null, a.versao ? t('Versão {v}', { v: a.versao }) : t('Actualização')),
          document.createTextNode(' · ' + t('instalada em {d}', { d: dataHora(a.detectada_em) })),
        );
        b.append(cab);
        // agrupa por dia; o dia mais recente da actualização mais recente vem aberto
        const dias = [];
        for (const s of a.seccoes) {
          let g = dias.find((x) => x.data === s.data);
          if (!g) dias.push((g = { data: s.data, seccoes: [] }));
          g.seccoes.push(s);
        }
        dias.forEach((g, j) => {
          const det = el('details', 'nov-dia');
          if (i === 0 && j === 0) det.open = true;
          det.append(el('summary', null, g.data + ' · ' + g.seccoes.map((s) => s.titulo).join(', ')));
          for (const s of g.seccoes) {
            det.append(el('h4', null, s.titulo));
            const ul = el('ul');
            for (const item of s.itens) ul.append(textoFormatado(el('li'), item));
            det.append(ul);
          }
          b.append(det);
        });
      });
      ft.append(botao(t('Fechar'), '', fechar));
    },
  );
  dlg.classList.add('dlg-largo');
  // fechada de qualquer forma (botão ou Esc): não volta a aparecer
  dlg.addEventListener('close', () => postJson('novidades', { vistas: ids }), { once: true });
}
