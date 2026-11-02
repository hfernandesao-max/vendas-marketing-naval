// Desenho da página actual e encaminhamento pelo endereço (#página/relatório?filtros).
import { FITS, REG, definirFits, definirReg } from './componentes/blocos.js';
import { NOME_CONSOLIDADO, exportarExcel, recolher } from './excel.js';
import { exportarDados } from './dados-gestao.js';
import { FV, desenharFiltros, filtroAberto, filtroAtivo, filtrosURL, lerFiltrosURL, prepararDados } from './filtros.js';
import { mostrarActualizacao } from './actualizacao.js';
import { artigosProntos, fetchVendas, garantirArtigos, mostrarFontes } from './fontes.js';
import { dl, ds, setFmt } from './formatos.js';
import { IDIOMAS, LANG, mudarIdioma, t, tv } from './i18n/i18n.js';
import { REL_ATUAL, definirRelPedido, nav, organizarRelatorios } from './navegacao.js';
import { PAGES } from './paginas/catalogo.js';
import { R } from './paginas/registo.js';
import { carregarPagina, carregarPaginas, paginaPronta } from './paginas/modulos.js';
import { mostrarIndicador } from './smartphones.js';
import { SESSAO, definirAtual, paginaAtual, paginasVisiveis, podeExportar, podeVer } from './perfil.js';
import { diasSap, guardar, presets, setPreset, st } from './periodo.js';
import { $, el, toast } from './util.js';

function textos() {
  document.documentElement.lang = IDIOMAS[LANG];
  $('#brand-sub').textContent = t('Portal RTM');
  $('#voltar-t').textContent = t('Página principal');
  $('#menu-t').textContent = t('Menu');
  $('#side').setAttribute('aria-label', t('Menu'));
  $('#filtros2').setAttribute('aria-label', t('Filtros'));
  $('#lang').setAttribute('aria-label', t('Idioma'));
  // Tema: Sistema / Claro / Escuro (o estado vem de tema-arranque.js)
  const TEMAS = { sistema: 'Tema do sistema', claro: 'Tema claro', escuro: 'Tema escuro' };
  const temaAct = window.vmTema ? window.vmTema.escolha() : 'sistema';
  $('#tema').setAttribute('aria-label', t('Tema'));
  document.querySelectorAll('#tema button').forEach((b) => {
    const on = b.dataset.t === temaAct;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.setAttribute('aria-label', t(TEMAS[b.dataset.t]));
    b.title = t(TEMAS[b.dataset.t]);
  });
  $('#l-de').textContent = t('De');
  $('#l-ate').textContent = t('Até');
  $('#xl-page span').textContent = t('Excel desta página');
  $('#xl-page').setAttribute('aria-label', t('Excel desta página'));
  $('#xl-page').title = t('Excel desta página');
  $('#xl-all span').textContent = t('Relatório consolidado');
  $('#xl-all').title = t('Relatório de gestão do período num só Excel: indicadores e relatórios de todas as páginas, com os dados de base do período');
  $('#xl-dados span').textContent = t('Exportar dados');
  $('#xl-dados').setAttribute('aria-label', t('Exportar dados'));
  $('#xl-dados').title = t('Todos os dados de gestão do negócio num só Excel, desde o arranque do Pinguim BO: tabelas de dados e dicionário de dados');
  document.querySelectorAll('#tema button').forEach((b) =>
    b.addEventListener('click', () => {
      if (window.vmTema) window.vmTema.definir(b.dataset.t);
      draw();
    }),
  );
  document.querySelectorAll('#lang button').forEach((b) => b.classList.toggle('on', b.dataset.l === LANG));
  if (diasSap.length) $('#foot').textContent = t('Vendas Pinguim BO até {a}', { a: dl(diasSap[diasSap.length - 1]) });
}
// Páginas que não dependem do período (sem a barra De / Até)
const SEM_PERIODO = [
  'plano-cargas',
  'conciliacao-carrinha',
  'equipamentos',
  'equipamentos-documentos',
  'actualizacoes',
  'alertas',
  'previsao',
  'assistente',
  'painel-sala',
  'pedidos-clientes',
  'clientes-qr',
];
// Páginas que desenham as linhas de artigos (as outras abrem só com os documentos)
const COM_ARTIGOS = new Set(['vendas-artigo', 'sap-exportar']);
const precisaArtigos = (id) => COM_ARTIGOS.has(id) || (filtroAtivo(id, 'artigo') && !!FV.artigo);
/**
 * O que falta para desenhar a página: o seu módulo (carregado a pedido) e, se precisa delas, as linhas de artigos.
 * Devolve null se já está tudo, ou a promessa do que falta.
 */
function emFalta(id) {
  const falta = [];
  if (!paginaPronta(id)) falta.push(carregarPagina(id));
  if (precisaArtigos(id) && !artigosProntos()) falta.push(garantirArtigos());
  return falta.length ? Promise.all(falta) : null;
}
let aEsperar = null;
export function draw() {
  const p = paginaAtual();
  const falta = emFalta(p.id);
  if (falta) {
    // Enquanto chega o que falta, a página mostra que está a carregar; depois desenha-se (se ainda for a actual)
    if (aEsperar !== p.id) {
      aEsperar = p.id;
      document.body.classList.add('a-carregar');
      $('#page').replaceChildren(el('div', 'glass empty', t('A carregar…')));
      nav();
    }
    falta
      .catch((e) => {
        console.error(e);
        toast(t('Sem ligação ao servidor do portal.'), 6000);
      })
      .then(() => {
        if (aEsperar !== p.id) return;
        aEsperar = null;
        document.body.classList.remove('a-carregar');
        if (paginaAtual().id !== p.id) return;
        if (paginaPronta(p.id)) draw();
      });
    return;
  }
  aEsperar = null;
  // Página principal: o gráfico de artigos aparece quando as linhas chegam (pedidas em segundo plano)
  if (p.id === 'inicio' && podeVer('vendas-artigo') && !artigosProntos())
    garantirArtigos().then(() => {
      if (paginaAtual().id === 'inicio' && artigosProntos()) draw();
    });
  definirReg({ tabelas: [], kpis: [] });
  definirFits([]);
  textos();
  mostrarFontes();
  mostrarIndicador(); // cartão dos telemóveis no menu: sempre na língua escolhida
  document.querySelector('.filters').style.display = SEM_PERIODO.includes(p.id) ? 'none' : '';
  $('#xl-page').hidden = !(p.id === 'inicio' ? podeExportar('excel-completo') : podeExportar(p.id));
  $('#xl-all').hidden = !podeExportar('excel-completo');
  $('#xl-dados').hidden = !podeExportar('exportar-dados');
  if (SESSAO) {
    $('#quem').hidden = false;
    $('#quem').replaceChildren(
      el('b', null, tv(SESSAO.nome)), // nome genérico da conta traduz-se; nomes de pessoas não
      el('span', null, t(SESSAO.perfil_nome) + (SESSAO.rotas ? ' · ' + (SESSAO.rotas.length ? SESSAO.rotas.join(', ') : t('sem rotas atribuídas')) : '')),
    );
  }
  $('#de').value = st.de;
  $('#ate').value = st.ate;
  $('#de-v').textContent = dl(st.de);
  $('#ate-v').textContent = dl(st.ate);
  const ps = presets(),
    seg = $('#presets');
  seg.replaceChildren();
  Object.entries(ps).forEach(([k, v]) => {
    const b = el('button', st.preset === k ? 'on' : null, t(v[0]));
    b.type = 'button';
    b.addEventListener('click', () => setPreset(k));
    seg.append(b);
  });
  $('#eyebrow').textContent =
    t(p.grupo || 'Portal RTM') + (!st.de || SEM_PERIODO.includes(p.id) ? '' : ' · ' + (st.de === st.ate ? ds(st.de) : `${dl(st.de)} – ${dl(st.ate)}`));
  $('#titulo').textContent = p.id === 'inicio' ? t('Visão geral') : t(p.nome);
  // Na página principal em ecrã de computador o espaço vai para os gráficos (painel num só ecrã)
  $('#lead').textContent =
    p.id === 'inicio' && innerWidth < 1200
      ? t('Vendas, recebimentos, stock e carteira de clientes da equipa RTM, a partir do Pinguim BO e do plano de cargas.')
      : '';
  mostrarActualizacao();
  if (filtroAberto) filtroAberto(false);
  prepararDados(p.id);
  desenharFiltros(p.id);
  const pg = $('#page');
  pg.replaceChildren();
  try {
    R[p.id](pg);
  } catch (e) {
    console.error(e);
    pg.append(el('div', 'glass empty', t('Não foi possível desenhar esta página.')));
  }
  organizarRelatorios(pg, p.id);
  {
    // estado próprio da página no endereço (ex.: a ficha aberta, #equipamentos?id=NAV-SMT-0001)
    const q = [filtrosURL(p.id), R[p.id] && R[p.id].estadoURL ? R[p.id].estadoURL() : ''].filter(Boolean).join('&');
    const novo = '#' + p.id + (REL_ATUAL ? '/' + REL_ATUAL : '') + (q ? '?' + q : '');
    if (location.hash !== novo && location.hash.split('?')[0].split('/')[0] === '#' + p.id) history.replaceState(null, '', novo);
  }
  nav();
  // «Excel desta página»: inactivo (com a explicação) quando a página não tem dados para exportar
  if (p.id !== 'inicio') {
    const vazio = !REG.tabelas.some((x) => (x.rows || []).length) && !REG.kpis.length;
    $('#xl-page').disabled = vazio;
    $('#xl-page').title = vazio ? t('Esta página ainda não tem dados para exportar.') : t('Excel desta página');
  } else $('#xl-page').disabled = false;
  try {
    sessionStorage.setItem(ULTIMA, location.hash || '#' + p.id);
  } catch (e) {
    /* sem armazenamento */
  }
  requestAnimationFrame(() => FITS.forEach((f) => f()));
  document.title = (p.id === 'inicio' ? t('Portal RTM') : t(p.nome)) + ' · Grupo Naval';
}
// Ao actualizar (F5) o portal volta à página e ao relatório onde estava: o endereço (#página/relatório?filtros)
// é o estado; se o endereço chegar sem ele (portal dentro de outra página, ou entrada pelo login), usa-se o
// último endereço deste separador do navegador.
const ULTIMA = 'vm-ultima-pagina';
function restaurarPagina() {
  if (location.hash.length > 1) return;
  let nav = '';
  try {
    nav = (performance.getEntriesByType('navigation')[0] || {}).type || '';
  } catch (e) {
    /* navegador antigo */
  }
  const embebido = window.self !== window.top;
  let voltar = false;
  try {
    voltar = sessionStorage.getItem('vm-voltar') === '1';
    sessionStorage.removeItem('vm-voltar');
  } catch (e) {
    /* sem armazenamento */
  }
  if (nav !== 'reload' && !embebido && !voltar) return;
  try {
    const u = sessionStorage.getItem(ULTIMA);
    if (u && /^#[a-z0-9-]+/.test(u)) history.replaceState(null, '', u);
  } catch (e) {
    /* sem armazenamento */
  }
}
let primeiraVez = true;
export function route() {
  if (primeiraVez) {
    primeiraVez = false;
    restaurarPagina();
  }
  const [base, qs] = location.hash.slice(1).split('?');
  const [h, sub] = base.split('/');
  definirRelPedido(sub || '');
  if (qs) lerFiltrosURL(qs);
  // Atalho da página principal ("Exportações Excel"): abre o início e gera o Excel completo
  const primeira = (paginasVisiveis()[0] || PAGES[0]).id;
  if (h === 'exportar') {
    history.replaceState(null, '', '#' + primeira);
    definirAtual(primeira);
    draw();
    if (podeExportar('excel-completo')) setTimeout(() => $('#xl-all').click(), 300);
    else toast(t('O seu perfil não permite exportar o relatório completo.'), 6000);
    return;
  }
  definirAtual(PAGES.some((x) => x.id === h) && podeVer(h) ? h : primeira);
  draw();
  window.scrollTo(0, 0);
}

$('#xl-page').addEventListener('click', () => {
  const p = paginaAtual();
  if (p.id === 'inicio') {
    $('#xl-all').click();
    return;
  }
  exportarExcel([{ pagina: p.nome, grupo: p.grupo, kpis: REG.kpis, tabelas: REG.tabelas }], t(p.nome));
});
$('#xl-all').addEventListener('click', () => {
  // O relatório consolidado desenha todas as páginas do perfil: carrega antes as que faltam e as linhas de artigos
  const b = $('#xl-all');
  b.disabled = true;
  Promise.all([carregarPaginas(paginasVisiveis().map((p) => p.id)), garantirArtigos()])
    .then(consolidado, (e) => {
      console.error(e);
      toast(t('Não foi possível gerar o Excel.'), 4000);
    })
    .finally(() => {
      b.disabled = false;
    });
});
function consolidado() {
  const pags = paginasVisiveis()
    .filter((p) => p.id !== 'inicio' && p.id !== 'acessos')
    .map((p) => {
      const r = recolher(p.id);
      return { pagina: p.nome, grupo: p.grupo, kpis: r.kpis, tabelas: r.tabelas };
    });
  const ini = recolher('inicio');
  pags.unshift({ pagina: 'Início', grupo: null, kpis: ini.kpis, tabelas: [] });
  return exportarExcel(pags, NOME_CONSOLIDADO);
}
$('#xl-dados').addEventListener('click', () => exportarDados());
document.querySelectorAll('#lang button').forEach((b) =>
  b.addEventListener('click', () => {
    const l = b.dataset.l in IDIOMAS ? b.dataset.l : 'pt';
    // a escolha guarda-se logo; o dicionário do idioma descarrega-se na primeira vez que é escolhido
    try {
      localStorage.setItem('vm-lang', l);
    } catch (e) {}
    mudarIdioma(l).then(() => {
      setFmt();
      draw();
    });
  }),
);
const onDate = () => {
  const a = $('#de').value,
    b = $('#ate').value;
  if (a && b && a <= b) {
    st.de = a;
    st.ate = b;
    st.preset = null;
    guardar();
    fetchVendas(a, b).then(() => draw());
  }
};
$('#de').addEventListener('change', onDate);
$('#ate').addEventListener('change', onDate);
// Abre o calendário ao clicar e mantém a data visível em dia-mês-ano enquanto se escolhe
['de', 'ate'].forEach((id) => {
  const i = $('#' + id);
  i.addEventListener('click', () => {
    try {
      i.showPicker();
    } catch (e) {}
  });
  i.addEventListener('input', () => {
    $('#' + id + '-v').textContent = dl(i.value);
  });
});
$('#menu').addEventListener('click', (ev) => {
  ev.stopPropagation();
  $('#app').classList.toggle('open');
});
document.addEventListener('click', (ev) => {
  if ($('#app').classList.contains('open') && !ev.target.closest('#side')) $('#app').classList.remove('open');
});
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') $('#app').classList.remove('open');
});
addEventListener('hashchange', route);
let rz;
addEventListener('resize', () => {
  clearTimeout(rz);
  rz = setTimeout(() => FITS.forEach((f) => f()), 150);
});
// A letra Inter chega depois do primeiro desenho (display=swap) e muda as medidas: volta a ajustar os quadros
if (document.fonts) document.fonts.addEventListener('loadingdone', () => FITS.forEach((f) => f()));
