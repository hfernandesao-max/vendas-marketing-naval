// Inteligência de negócio › Valor do portal: quanto o portal rende à Naval, mês a mês, a partir dos dados reais
// (conciliações das carrinhas, ficheiros SAP, relatórios exportados, utilizadores e documentos anulados no Pinguim BO)
// e dos parâmetros definidos pela Direcção (custo da hora, minutos poupados, perda de referência, licença equivalente,
// reconstrução). O que falta definir não entra no total. Servidor: nucleo/api/v1/valor.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { kz, n0, n1 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let VP = null;
let aLer = false;
let meses = 12;
const mesTxt = (m) => m.slice(5, 7) + '-' + m.slice(0, 4);

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('valor?meses=' + meses).then((d) => {
    aLer = false;
    VP = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'valor-portal') draw();
  });
}

/** Kz e, com o câmbio definido, também euros. */
function dinheiro(v) {
  if (v == null) return t('por definir');
  const c = VP.parametros.cambio_eur_kz;
  return kz(v) + (c > 0 ? ' · ' + n0(v / c) + ' €' : '');
}

function editar() {
  janela(
    t('Parâmetros do cálculo'),
    t('Valores da Naval, definidos pela Direcção. A zero, a parcela fica por definir e não entra no total.'),
    (b, ft, fechar) => {
      const campos = {};
      Object.keys(VP.nomes).forEach((k) => {
        const e = entrada('number', String(VP.parametros[k]), { min: 0, step: 'any' });
        campos[k] = e;
        b.append(campo(t(VP.nomes[k]), e));
      });
      const erro = msgErro();
      b.append(erro);
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      ft.append(
        botao(t('Gravar'), '', () => {
          const parametros = {};
          Object.keys(campos).forEach((k) => (parametros[k] = campos[k].value === '' ? 0 : +campos[k].value));
          postJson('valor?meses=' + meses, { accao: 'parametros', parametros }).then((d) => {
            if (!d || !d.ok) {
              erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
              erro.hidden = false;
              return;
            }
            VP = d;
            fechar();
            toast(t('Parâmetros gravados.'), 4000);
            draw();
          });
        }),
      );
    },
  );
}

R['valor-portal'] = (pg) => {
  if (!VP) {
    pg.append(el('div', 'glass empty', t('A calcular…')));
    ler();
    return;
  }
  if (VP.falhou) {
    pg.append(el('div', 'glass empty', t(VP.falhou)));
    return;
  }
  const T = VP.totais;
  pg.append(
    tiles([
      {
        l: t('Valor para a Naval ({n} meses)', { n: meses }),
        v: dinheiro(T.total_kz),
        s: VP.por_definir.length ? t('{n} parcelas por definir', { n: VP.por_definir.length }) : t('todas as parcelas definidas'),
        tone: VP.por_definir.length ? 'warn' : null,
      },
      { l: t('Projecção anual'), v: dinheiro(T.anual_kz), s: t('média dos {n} meses com dados × 12', { n: VP.meses_com_dados }) },
      {
        l: t('Horas de trabalho libertadas'),
        v: n1(T.horas) + ' h',
        s: t('{a} fechos automáticos · {s} ficheiros SAP', { a: n0(T.automaticas), s: n0(T.ficheiros_sap) }),
      },
      { l: t('Faltas de stock aprovadas'), v: kz(T.falta_kz), s: t('{n} rotas conciliadas', { n: n0(T.conciliadas) }) },
      {
        l: t('Documentos anulados corrigidos'),
        v: VP.pinguim ? kz(T.anulados_kz) : '—',
        s: VP.pinguim ? t('{n} documentos fora das vendas e do SAP', { n: n0(T.anulados) }) : t('sem ligação ao Pinguim BO'),
      },
    ]),
  );
  const barra = el('section', 'glass sec valor-barra');
  const sel = el('select', 'inp');
  sel.setAttribute('aria-label', t('Período'));
  [6, 12, 24].forEach((n) => {
    const o = el('option', null, t('Últimos {n} meses', { n }));
    o.value = String(n);
    if (n === meses) o.selected = true;
    sel.append(o);
  });
  sel.addEventListener('change', () => {
    meses = +sel.value;
    VP = null;
    draw();
  });
  barra.append(
    sel,
    botao(t('Recalcular'), 'ghost', () => ((VP = null), draw())),
  );
  if (VP.gere) barra.append(botao(t('Parâmetros do cálculo'), '', editar));
  pg.append(barra);
  if (VP.por_definir.length) {
    const ul = el('ul');
    VP.por_definir.forEach((k) => ul.append(el('li', null, t(VP.nomes[k]))));
    pg.append(secBox(t('Por definir pela Direcção'), t('Sem estes valores, a parcela correspondente não entra no total.'), ul));
  }
  pg.append(
    tabela({
      id: 'valor-meses',
      titulo: t('Valor por mês'),
      nota: t('Contagens reais do portal e do Pinguim BO; valores em Kz.'),
      search: false,
      rows: VP.meses,
      sort: 'mes',
      dir: -1,
      cols: [
        { key: 'mes', label: t('Mês'), p: 0, render: (v) => document.createTextNode(mesTxt(v)) },
        { key: 'conciliadas', label: t('Rotas conciliadas'), type: 'n' },
        { key: 'automaticas', label: t('Fechos automáticos'), type: 'n' },
        { key: 'ficheiros_sap', label: t('Ficheiros SAP'), type: 'n' },
        { key: 'exportacoes', label: t('Relatórios'), type: 'n' },
        { key: 'utilizadores', label: t('Utilizadores'), type: 'n' },
        { key: 'horas', label: t('Horas'), type: 'n', render: (v) => document.createTextNode(n1(v)) },
        { key: 'tempo_kz', label: t('Tempo'), type: 'kz' },
        { key: 'stock_kz', label: t('Stock'), type: 'kz' },
        { key: 'licencas_kz', label: t('Licenças'), type: 'kz' },
        { key: 'construcao_kz', label: t('Construção'), type: 'kz' },
        { key: 'total_kz', label: t('Total'), type: 'kz', p: 0 },
        { key: 'anulados_kz', label: t('Anulados corrigidos'), type: 'kz' },
      ],
    }),
  );
  const f = el('ol', 'valor-formula');
  [
    'Tempo = (fechos automáticos × minutos + rotas conciliadas × minutos + ficheiros SAP × minutos + relatórios × minutos) ÷ 60 × custo da hora.',
    'Stock = perda de referência por rota × rotas activas − faltas aprovadas (nunca abaixo de zero).',
    'Licenças = utilizadores que entraram no mês × licença de uma solução comercial equivalente.',
    'Construção = custo de fazer o portal de novo ÷ (anos de amortização × 12).',
    'Os documentos anulados mostram o valor que o portal deixou fora das vendas e do SAP: é valor corrigido, não poupança, e não entra no total.',
  ].forEach((x) => f.append(el('li', null, t(x))));
  pg.append(
    secBox(
      t('Como se calcula'),
      VP.alterado_em
        ? t('Parâmetros alterados em {d} por {p}', {
            d: VP.alterado_em.slice(8, 10) + '-' + VP.alterado_em.slice(5, 7) + '-' + VP.alterado_em.slice(0, 4),
            p: VP.alterado_por,
          })
        : t('Parâmetros por omissão'),
      f,
    ),
  );
  pg.append(
    tabela({
      id: 'valor-parametros',
      titulo: t('Parâmetros em vigor'),
      search: false,
      rows: Object.keys(VP.nomes).map((k, i) => ({ k, ordem: i, nome: VP.nomes[k], valor: VP.parametros[k] })),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'nome', label: t('Parâmetro'), p: 0, wrap: true, get: (r) => t(r.nome) },
        { key: 'valor', label: t('Valor'), get: (r) => (r.valor > 0 ? n1(r.valor) : t('por definir')) },
      ],
    }),
  );
};
