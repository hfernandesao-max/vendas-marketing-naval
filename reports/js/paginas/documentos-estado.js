// Vendas › Documentos válidos e anulados: uma linha por artigo de cada documento do Pinguim BO, com a rota e o
// promotor, o estado (válido ou anulado), o motivo da anulação escolhido no telemóvel e a hora a que ficou registado
// (servidor: nucleo/api/v1/documentos-estado.php, só leitura). Totais por estado, a % de anulações por promotor e as
// anulações por motivo; exportação para Excel em cada quadro.
//   - filtros: tipo de documentos (vendas por omissão, cargas, todos) e estado; a rota vem da barra de filtros comum;
//   - quem trabalha por rota (SUP, PRO) só recebe as suas; o nome do cliente só para quem vê os clientes.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao } from '../componentes/janelas.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, mkz, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { st } from '../periodo.js';
import { el } from '../util.js';
import { R } from './registo.js';

const MAX = 366;
const GRUPOS = { vendas: 'Vendas (FR, FA e notas de crédito)', cargas: 'Cargas (saídas de armazém, guias, devoluções)', todos: 'Todos os documentos' };
const ESTADOS = { todos: 'Válidos e anulados', valido: 'Só válidos', anulado: 'Só anulados' };
const F = { grupo: 'vendas', estado: 'todos' };
const dias = (a, b) => Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 864e5) + 1;
let D = null;
let chave = '';
let aLer = false;

function ler(de, ate) {
  if (aLer) return;
  aLer = true;
  chave = [de, ate, F.grupo].join('|');
  const qs = new URLSearchParams({ de, ate, grupo: F.grupo });
  apiJson('documentos-estado?' + qs.toString()).then((d) => {
    aLer = false;
    D = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os documentos.') };
    if (atual === 'documentos-estado') draw();
  });
}

function filtros(de, ate) {
  const f = el('form', 'mov-filtros');
  const g = el('select');
  g.setAttribute('aria-label', t('Tipo de documentos'));
  Object.entries(GRUPOS).forEach(([k, v]) => g.append(new Option(t(v), k)));
  g.value = F.grupo;
  const e = el('select');
  e.setAttribute('aria-label', t('Estado'));
  Object.entries(ESTADOS).forEach(([k, v]) => e.append(new Option(t(v), k)));
  e.value = F.estado;
  g.addEventListener('change', () => {
    F.grupo = g.value;
    D = null;
    ler(de, ate);
    draw();
  });
  e.addEventListener('change', () => {
    F.estado = e.value;
    draw();
  });
  f.addEventListener('submit', (x) => x.preventDefault());
  const b = botao(t('Actualizar'), 'sm', () => {
    D = null;
    ler(de, ate);
    draw();
  });
  b.type = 'button';
  f.append(g, e, b);
  return f;
}

// totais calculados no portal sobre as linhas filtradas (rota da barra de filtros e estado): por documento
function resumir(linhas) {
  const docs = new Map();
  linhas.forEach((l) => {
    const d = docs.get(l.doc_id) || { rota: l.rota, promotor: l.promotor, estado: l.estado, motivo: l.motivo, valor: 0 };
    d.valor += l.valor;
    docs.set(l.doc_id, d);
  });
  const r = { documentos: docs.size, validos: 0, anulados: 0, valor_valido: 0, valor_anulado: 0 };
  const porRota = new Map();
  const porMotivo = new Map();
  docs.forEach((d) => {
    const a = d.estado === 'anulado';
    r[a ? 'anulados' : 'validos']++;
    r[a ? 'valor_anulado' : 'valor_valido'] += d.valor;
    const k = d.rota || '—';
    const p = porRota.get(k) || { rota: k, promotor: d.promotor, documentos: 0, anulados: 0, valor_anulado: 0 };
    p.documentos++;
    if (a) {
      p.anulados++;
      p.valor_anulado += d.valor;
      const m = d.motivo || t('Sem motivo registado');
      const x = porMotivo.get(m) || { motivo: m, documentos: 0, valor: 0 };
      x.documentos++;
      x.valor += d.valor;
      porMotivo.set(m, x);
    }
    porRota.set(k, p);
  });
  const rotas = [...porRota.values()].map((p) => ({ ...p, pct_anulados: p.documentos ? p.anulados / p.documentos : 0 }));
  return { r, rotas, motivos: [...porMotivo.values()] };
}

R['documentos-estado'] = (pg) => {
  const de = st.de,
    ate = st.ate;
  if (!de || !ate) return;
  if (dias(de, ate) > MAX) {
    pg.append(el('p', 'aviso', t('Escolha um período até {n} dias.', { n: MAX })));
    return;
  }
  if (chave !== [de, ate, F.grupo].join('|')) {
    D = null;
    ler(de, ate);
  }
  pg.append(filtros(de, ate));
  if (!D) {
    pg.append(el('p', 'note', t('A ler os documentos do período no Pinguim BO…')));
    return;
  }
  if (D.falhou) {
    pg.append(el('p', 'erro', D.falhou));
    return;
  }
  let L = D.linhas || [];
  if (filtroAtivo('documentos-estado', 'rota')) L = L.filter((x) => FV.rota.includes(String(x.rota)));
  const todas = L;
  if (F.estado !== 'todos') L = L.filter((x) => x.estado === F.estado);
  const { r, rotas, motivos } = resumir(todas);
  pg.append(
    tiles([
      { l: t('Documentos'), v: n0(r.documentos), s: t('{v} válidos · {a} anulados', { v: n0(r.validos), a: n0(r.anulados) }) },
      { l: t('Anulações'), v: pct(r.documentos ? r.anulados / r.documentos : 0), s: t('dos documentos do período'), tone: r.anulados ? 'warn' : null },
      { l: t('Valor anulado'), v: mkz(r.valor_anulado), s: t('valor válido: {v}', { v: mkz(r.valor_valido) }) },
      { l: t('Motivo mais frequente'), v: motivos.length ? motivos.sort((a, b) => b.documentos - a.documentos)[0].motivo : '—', cls: 'nome' },
    ]),
  );
  if (!L.length) {
    pg.append(secBox(t('Documentos'), '', el('p', 'note', t('Sem documentos para os filtros escolhidos.'))));
    return;
  }
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'de-linhas',
      titulo: t('Documentos válidos e anulados'),
      nota: t(
        'Uma linha por artigo de cada documento, de {a} a {b}. O estado e o motivo da anulação vêm do Pinguim BO (o motivo escolhido no telemóvel ao anular).',
        {
          a: dl(de),
          b: dl(ate),
        },
      ),
      rows: L,
      sort: 'data',
      dir: 1,
      total: true,
      cols: [
        { key: 'data', label: t('Data'), type: 'date', p: 0 },
        { key: 'hora', label: t('Hora'), p: 4 },
        { key: 'rota', label: t('Rota · Promotor'), wrap: true, render: (v, x) => document.createTextNode(v + (x.promotor ? ' · ' + x.promotor : '')) },
        { key: 'documento', label: t('Documento') },
        { key: 'tipo', label: t('Tipo'), render: (v, x) => document.createTextNode(x.tipo_nome ? v + ' · ' + x.tipo_nome : v), wrap: true, p: 3 },
        { key: 'codigo', label: t('Código') },
        { key: 'artigo', label: t('Artigo'), wrap: true },
        { key: 'quantidade', label: t('Qtd'), type: 'n', sum: true },
        { key: 'valor', label: t('Valor c/ IVA'), type: 'kz', sum: true },
        {
          key: 'estado',
          label: t('Estado'),
          get: (x) => (x.estado === 'anulado' ? t('Anulado') : t('Válido')),
          // com «get», v já é o texto: o estado vem da linha
          render: (v, x) => pill(x.estado === 'anulado' ? t('Anulado') : t('Válido'), x.estado === 'anulado' ? 'p-bad' : 'p-ok'),
          xl: (x) => (x.estado === 'anulado' ? t('Anulado') : t('Válido')),
        },
        { key: 'motivo', label: t('Motivo da anulação'), wrap: true },
        { key: 'anulado_em', label: t('Anulado em'), p: 4 },
        { key: 'cliente', label: t('Cliente'), wrap: true, p: 4 },
      ],
    }),
  );
  secs.append(
    tabela({
      id: 'de-promotores',
      titulo: t('Anulações por promotor'),
      nota: t('Por documento (não por linha).'),
      rows: rotas,
      sort: 'pct_anulados',
      total: true,
      cols: [
        { key: 'rota', label: t('Rota'), p: 0 },
        { key: 'promotor', label: t('Promotor'), wrap: true },
        { key: 'documentos', label: t('Documentos'), type: 'n', sum: true },
        { key: 'anulados', label: t('Anulados'), type: 'n', sum: true },
        { key: 'pct_anulados', label: t('% de anulações'), type: 'pct' },
        { key: 'valor_anulado', label: t('Valor anulado'), type: 'kz', sum: true },
      ],
    }),
  );
  if (motivos.length)
    secs.append(
      tabela({
        id: 'de-motivos',
        titulo: t('Anulações por motivo'),
        rows: motivos,
        sort: 'documentos',
        total: true,
        cols: [
          { key: 'motivo', label: t('Motivo da anulação'), wrap: true, p: 0 },
          { key: 'documentos', label: t('Documentos'), type: 'n', sum: true },
          { key: 'valor', label: t('Valor'), type: 'kz', sum: true },
        ],
      }),
    );
  pg.append(secs);
};
