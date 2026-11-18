// Actualizações do portal (servidor: nucleo/api/v1/novidades.php), na primeira entrada depois de cada actualização:
// - todos: o aviso do que muda para a pessoa e de como proceder, só sobre as páginas a que tem acesso e na sua língua
//   (ISO/IEC 20000-1 8.5.3, comunicação das alterações). «Li e compreendi» fica registado; «Mais tarde» (ou Esc)
//   volta a mostrar na próxima entrada;
// - super administrador: também o resumo técnico completo; fechado, não volta a aparecer.
import { apiJson } from './api.js';
import { botao, janela } from './componentes/janelas.js';
import { LANG, t } from './i18n/i18n.js';
import { SESSAO } from './perfil.js';
import { postJson } from './servidor.js';
import { el } from './util.js';

/** Classificação de cada alteração (nucleo/servicos/versoes.php): nome e etiqueta (sem verde). */
const TIPOS_ALTERACAO = {
  correcao: ['Correcção', 'p-mut'],
  melhoria: ['Melhoria', 'p-ok'],
  funcionalidade: ['Nova funcionalidade', 'p-ok'],
  impacto: ['Alteração com impacto', 'p-warn'],
};

/** Classificação de um conjunto de secções: a mais exigente (ordem de TIPOS_ALTERACAO). */
export const tipoMaior = (seccoes) =>
  (seccoes || []).reduce((m, s) => {
    const k = Object.keys(TIPOS_ALTERACAO);
    return k.indexOf(s.tipo) > k.indexOf(m) ? s.tipo : m;
  }, '');

/** Título de uma secção do registo de alterações com a etiqueta da classificação, quando existe. */
export function tituloSeccao(tag, texto, tipo) {
  const h = el(tag, 'nov-tit', texto);
  const x = TIPOS_ALTERACAO[tipo];
  if (x) h.append(' ', el('span', 'pill ' + x[1], t(x[0])));
  return h;
}

/** Texto do registo de alterações com **negrito** e `código`, montado sem HTML vindo do servidor. */
export function textoFormatado(pai, txt) {
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

/** Texto do aviso na língua da pessoa (sem tradução, em português). */
const naLingua = (x) => (x && (x[LANG] || x.pt)) || '';

/**
 * Aviso aos utilizadores: o portal foi actualizado, o que muda para si e como proceder. Devolve a janela.
 * Só «Li e compreendi» regista a tomada de conhecimento.
 */
export function mostrarAvisos(avisos) {
  const ids = avisos.map((a) => a.id);
  const dlg = janela(t('O portal foi actualizado'), t('Leia o que muda para si e como proceder.'), (b, ft, fechar) => {
    b.classList.add('nov-aviso');
    const faixa = el('div', 'nov-faixa');
    faixa.setAttribute('aria-hidden', 'true');
    faixa.innerHTML =
      '<svg viewBox="0 0 48 48" width="44" height="44"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="3"/>' +
      '<path d="M24 12v14" stroke="currentColor" stroke-width="4" stroke-linecap="round"/><circle cx="24" cy="34" r="2.6" fill="currentColor"/></svg>';
    const ult = avisos[0];
    faixa.append(el('span', null, (ult.versao ? t('Versão {v}', { v: ult.versao }) + ' · ' : '') + t('instalada em {d}', { d: dataHora(ult.detectada_em) })));
    b.append(faixa);
    for (const a of avisos) {
      for (const s of a.seccoes) {
        const art = el('section', 'nov-cartao');
        art.append(tituloSeccao('h3', naLingua(s.aviso.titulo) || s.titulo, s.tipo));
        if (s.aviso.resumo.length) {
          art.append(el('h4', null, t('O que muda para si')));
          const ul = el('ul');
          for (const r of s.aviso.resumo) ul.append(textoFormatado(el('li'), naLingua(r)));
          art.append(ul);
        }
        if (s.aviso.passos.length) {
          art.append(el('h4', null, t('Como proceder')));
          const ol = el('ol', 'nov-passos');
          for (const r of s.aviso.passos) ol.append(textoFormatado(el('li'), naLingua(r)));
          art.append(ol);
        }
        b.append(art);
      }
    }
    b.append(el('p', 'note', t('Dúvidas ou algo que não funciona como descrito: abra um pedido em Suporte.')));
    ft.append(
      botao(t('Mais tarde'), 'ghost', fechar),
      botao(t('Li e compreendi'), '', () => {
        postJson('novidades', { confirmar: ids });
        fechar();
      }),
    );
  });
  dlg.classList.add('dlg-largo', 'dlg-aviso');
  return dlg;
}

/** Mostra o aviso aos utilizadores e, ao super administrador, também o resumo das actualizações por ver. */
export async function mostrarNovidades() {
  if (!SESSAO) return;
  const d = await apiJson('novidades');
  const avisos = d && Array.isArray(d.avisos) ? d.avisos : [];
  const lista = d && Array.isArray(d.actualizacoes) ? d.actualizacoes : [];
  if (!lista.length) {
    if (avisos.length) mostrarAvisos(avisos);
    return;
  }
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
            det.append(tituloSeccao('h4', s.titulo, s.tipo));
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
  // fechada de qualquer forma (botão ou Esc): não volta a aparecer; depois, o aviso que os utilizadores vão ver
  dlg.addEventListener(
    'close',
    () => {
      postJson('novidades', { vistas: ids });
      if (avisos.length) mostrarAvisos(avisos);
    },
    { once: true },
  );
}
