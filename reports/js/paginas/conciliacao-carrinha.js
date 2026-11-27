// Logística › Conciliação da carrinha: por rota e dia, o que foi carregado, vendido e devolvido (Pinguim BO), a contagem
// do técnico do armazém no regresso (cega: não vê o esperado antes de gravar) e a aprovação das diferenças, com motivo.
// Servidor: nucleo/api/v1/carrinha.php e nucleo/servicos/carrinha.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { quem as assinante } from '../componentes/assinantes.js';
import { foraDoPrazo, linhaHorario } from '../componentes/horario.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { lancamento, quantidadeAtipica } from '../componentes/lancamento.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';
import { folhaContagemCega, resultadoContagem } from '../componentes/contagem-pdf.js';
import { apagarRascunho, guardarRascunho, lerRascunho } from '../componentes/rascunho.js';
import { tArtigo } from '../i18n/artigos.js';

let CC = null;
let dia = null;
let aberta = null;
let aLer = false;
// Uma só linguagem de cor, a do passo do circuito: a etiqueta e o risco à esquerda do cartão têm sempre a mesma cor
// (cinzento por contar, âmbar por validar, laranja por aprovar, azul fechada). As diferenças vêem-se no texto, a vermelho;
// «fora do prazo» é a única etiqueta vermelha (atraso de quem tem de agir).
const ESTADOS = {
  por_contar: ['Por contar', 'p-mut'],
  por_validar: ['Por validar', 'p-warn'],
  por_aprovar: ['Por aprovar (coordenador)', 'p-serio'],
  por_fechar: ['Por aprovar (responsável)', 'p-serio'],
  aprovada: ['Fechada', 'p-ok'],
};
const ALERTAS = {
  negativo: 'Stock negativo na carrinha',
  sem_carga: 'Vendido mais do que estava na carrinha',
  nao_explicado: 'Movimento sem documento',
};

function ler(actualizar) {
  if (aLer) return;
  aLer = true;
  dia = dia || hoje();
  apiJson('conciliacao-carrinha?data=' + dia + (actualizar ? '&actualizar=1' : '')).then((d) => {
    aLer = false;
    CC = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'conciliacao-carrinha') draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  CC = null;
  aberta = null;
  draw();
}
const q = (v) => (v == null ? '—' : n0(v));
const difTxt = (v) => (v == null ? '—' : (v > 0 ? '+' : '') + n0(v));

function barra() {
  const b = el('section', 'glass sec pc-barra');
  const d = el('div', 'pc-dia');
  const ant = botao('‹', 'ghost sm', () => mudarDia(somaDias(dia, -1)));
  ant.setAttribute('aria-label', t('Dia anterior'));
  const seg = botao('›', 'ghost sm', () => mudarDia(somaDias(dia, 1)));
  seg.setAttribute('aria-label', t('Dia seguinte'));
  seg.disabled = dia >= hoje();
  const di = entrada('date', dia, { max: hoje() });
  di.setAttribute('aria-label', t('Dia'));
  di.addEventListener('change', () => mudarDia(di.value));
  const dc = el('span', 'pc-dcampo');
  dc.append(di, el('span', 'dv', dl(dia)));
  d.append(ant, el('b', 'pc-dia-t', ds(dia)), dc, seg);
  if (dia !== hoje()) d.append(botao(t('Hoje'), 'ghost sm', () => mudarDia(hoje())));
  d.append(botao(t('Actualizar'), 'ghost sm', () => ((CC = null), ler(true), draw())));
  // facturas fora do horário de vendas (indicador por promotor, 30 dias) e autorização prévia pelo coordenador
  d.append(botao(t('Facturas fora do horário'), 'ghost sm', () => verForaHorario()));
  if (CC && CC.pode && CC.pode.coordenar) d.append(botao(t('Autorizar venda fora do horário'), 'ghost sm', () => autorizarForaHorario()));
  b.append(d);
  return b;
}

/**
 * Venda por depósito (recibo «Cash sales receipt confirmation», facturada no SAP): o coordenador regista o documento,
 * a data, os artigos com a quantidade e o preço e a forma de pagamento. Abre já com as faltas da rota.
 */
function registarDeposito(r) {
  janela(
    t('Venda por depósito · rota {r}', { r: r.rota }),
    t(
      'Mercadoria vendida por depósito com recibo, facturada no SAP. Fica registada para seguir a factura SAP; não desconta a carrinha: a falta só fica regularizada com a saída de armazém (SA) lançada pela Logística no Pinguim BO.',
    ),
    (b, ft, fechar) => {
      const doc = entrada('text', '', { maxLength: 40, placeholder: t('Ex.: GRN 9916') });
      const dia = entrada('date', hoje(), { max: hoje() });
      const pag = el('select', 'inp');
      [
        ['transferencia', 'Transferência'],
        ['numerario', 'Numerário'],
        ['multicaixa', 'Multicaixa'],
        ['credito', 'A crédito (por receber)'],
      ].forEach(([k, v]) => pag.append(new Option(t(v), k)));
      const cli = entrada('text', '', { maxLength: 120, placeholder: t('Nome ou NIF do cliente') });
      const nota = entrada('text', '', { maxLength: 300, placeholder: t('Nota (opcional)') });
      const tab = el('table', 'cc-tab');
      const h = el('tr');
      [t('Artigo'), t('Quantidade'), t('Preço')].forEach((x, i) => h.append(el('th', i ? 'n' : null, x)));
      tab.append(h);
      const ls = r.linhas
        .filter((l) => (l.diferenca || 0) < 0)
        .map((l) => {
          const tr = el('tr');
          const q = entrada('number', String(Math.abs(l.diferenca)), { min: 0, step: 'any' });
          q.setAttribute('aria-label', t('Quantidade') + ' · ' + tArtigo(l.artigo));
          const pr = entrada('number', String(l.preco || 0), { min: 0, step: 'any' });
          pr.setAttribute('aria-label', t('Preço') + ' · ' + tArtigo(l.artigo));
          const tq = el('td', 'n');
          tq.append(q);
          const tp = el('td', 'n');
          tp.append(pr);
          tr.append(el('td', null, l.codigo + ' · ' + tArtigo(l.artigo)), tq, tp);
          tab.append(tr);
          return { l, q, pr };
        });
      const erro = msgErro();
      b.append(
        campo(t('Documento'), doc),
        campo(t('Data da venda'), dia),
        campo(t('Forma de pagamento'), pag),
        campo(t('Cliente'), cli),
        tab,
        campo(t('Nota'), nota),
        erro,
      );
      const ok = botao(t('Registar'), null, () => {
        const linhas = ls
          .filter((x) => +x.q.value > 0)
          .map((x) => ({ codigo: x.l.codigo, artigo: x.l.artigo, quantidade: +x.q.value, preco: +x.pr.value || 0 }));
        ok.disabled = true;
        postJson('conciliacao-carrinha', {
          accao: 'deposito',
          rota: r.rota,
          data: dia.value,
          documento: doc.value,
          linhas,
          pagamento: pag.value,
          cliente: cli.value,
          nota: nota.value,
        }).then((x) => {
          ok.disabled = false;
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.');
            return;
          }
          toast(t('Venda por depósito registada. A falta na carrinha regulariza-se com a saída de armazém (SA) no Pinguim BO.'), 6000);
          fechar();
          CC = null;
          ler(true);
          draw();
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
    },
  );
}

function facturaDeposito(x) {
  janela(t('Factura SAP · {doc}', { doc: x.documento }), t('O número da factura emitida no SAP para esta venda por depósito.'), (b, ft, fechar) => {
    const f = entrada('text', '', { maxLength: 40 });
    const erro = msgErro();
    b.append(campo(t('Factura SAP'), f), erro);
    const ok = botao(t('Gravar'), null, () =>
      postJson('conciliacao-carrinha', { accao: 'deposito_factura', id: x.id, factura: f.value }).then((y) => {
        if (!y.ok) {
          erro.hidden = false;
          erro.textContent = t(y.erro || 'Não foi possível gravar.');
          return;
        }
        fechar();
        CC = null;
        ler(true);
        draw();
      }),
    );
    ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
  });
}

const MOTIVO_FH = { depois: 'depois do fim', antes: 'antes do início', descanso: 'dia de descanso', turno_aberto: 'com a data de outro dia' };

/** Facturas fora do horário dos últimos 30 dias: por promotor (pior primeiro) e a lista, com as autorizadas à parte. */
function verForaHorario() {
  janela(
    t('Facturas fora do horário · últimos 30 dias'),
    t('Facturas emitidas depois do fim das vendas, antes do início ou num dia de descanso.'),
    (b, ft, fechar) => {
      b.append(el('p', 'note', t('A carregar…')));
      ft.append(botao(t('Fechar'), 'ghost', fechar));
      apiJson('fora-horario').then((d) => {
        b.textContent = '';
        if (!d || !d.ok) {
          b.append(el('p', 'erro', t((d && d.erro) || 'Não foi possível ler os dados.')));
          return;
        }
        if (!d.lista.length) {
          b.append(el('p', 'note', t('Nenhuma factura fora do horário nos últimos 30 dias.')));
          return;
        }
        b.append(el('p', 'note', t('Tolerância de {m} minutos depois do fim das vendas. As autorizadas pelo coordenador não contam.', { m: d.tolerancia })));
        const tp = el('table', 'cc-tab');
        const hp = el('tr');
        [t('Rota'), t('Promotor'), t('Facturas'), t('Valor'), t('Dias'), t('Mais tarde'), t('Autorizadas')].forEach((x, i) =>
          hp.append(el('th', i >= 2 ? 'n' : null, x)),
        );
        const thp = el('thead');
        thp.append(hp);
        const bp = el('tbody');
        d.por_promotor.forEach((x) => {
          const tr = el('tr', x.facturas ? 'cc-falta' : null);
          tr.append(
            el('td', null, x.rota),
            el('td', null, x.promotor),
            el('td', 'n', n0(x.facturas)),
            el('td', 'n', kz(x.valor)),
            el('td', 'n', n0(x.dias)),
            el('td', 'n', x.mais_tarde || '—'),
            el('td', 'n', n0(x.autorizadas)),
          );
          bp.append(tr);
        });
        tp.append(thp, bp);
        b.append(tp);
        const tl = el('table', 'cc-tab');
        const hl = el('tr');
        [t('Dia'), t('Hora'), t('Rota'), t('Documento'), t('Cliente'), t('Valor'), t('Quando')].forEach((x, i) => hl.append(el('th', i === 5 ? 'n' : null, x)));
        const thl = el('thead');
        thl.append(hl);
        const bl = el('tbody');
        d.lista.forEach((f) => {
          const tr = el('tr', f.autorizada ? 'cc-fh-aut' : null);
          tr.append(
            el('td', null, dl(f.data)),
            el('td', null, f.hora),
            el('td', null, f.rota),
            el('td', null, f.documento),
            el('td', null, f.cliente || '—'),
            el('td', 'n', kz(f.total)),
            el('td', null, t(MOTIVO_FH[f.motivo] || f.motivo) + (f.autorizada ? ' · ' + t('autorizada') : '')),
          );
          bl.append(tr);
        });
        tl.append(thl, bl);
        b.append(el('h4', null, t('Facturas')), tl);
      });
    },
  );
}

/** O coordenador autoriza antes uma venda fora do horário (rota, dia, até que horas, motivo). */
function autorizarForaHorario() {
  janela(
    t('Autorizar venda fora do horário'),
    t('As facturas dessa rota, nesse dia, até à hora indicada, não contam como fora do horário.'),
    (b, ft, fechar) => {
      const rotas = (CC.rotas || []).map((r) => r.rota);
      const rota = el('select', 'inp');
      rota.setAttribute('aria-label', t('Rota'));
      (rotas.length ? rotas : []).forEach((r) => rota.append(new Option(r, r)));
      const d = entrada('date', hoje(), { min: hoje() });
      d.setAttribute('aria-label', t('Dia'));
      const ate = entrada('time', '17:00');
      ate.setAttribute('aria-label', t('Até às'));
      const motivo = entrada('text', '', { maxLength: 200, placeholder: t('Motivo (ex.: entrega combinada com o cliente)') });
      motivo.setAttribute('aria-label', t('Motivo'));
      const erro = msgErro();
      b.append(campo(t('Rota'), rota), campo(t('Dia'), d), campo(t('Até às'), ate), campo(t('Motivo'), motivo), erro);
      const ok = botao(t('Autorizar'), null, () => {
        ok.disabled = true;
        postJson('fora-horario', { accao: 'autorizar', rota: rota.value, dia: d.value, ate: ate.value, motivo: motivo.value }).then((x) => {
          ok.disabled = false;
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.');
            return;
          }
          toast(t('Venda fora do horário autorizada.'), 4000);
          fechar();
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
    },
  );
}

/** Botão que gera um PDF da contagem (fica inactivo enquanto o gera; avisa se falhar). */
/**
 * Depois de validar a contagem: o painel da rota fecha, pergunta-se se quer imprimir a contagem e mostra-se a PO de
 * reposição criada sozinha com o que foi vendido (rascunho, para o promotor rever e emitir).
 */
function depoisDeValidar(r, x, eu, motivos) {
  aberta = null;
  draw();
  const validada = {
    ...r,
    linhas: x.linhas || r.linhas,
    resumo: x.resumo || r.resumo,
    validado_por: eu || r.validado_por,
    validado_em: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' '), // hora local
  };
  janela(t('Contagem validada · rota {r}', { r: r.rota }), t('Segue para a aprovação do coordenador.'), (b, ft, fechar) => {
    if (x.requisicao)
      b.append(
        el(
          'p',
          'note',
          t(
            'Foi criada a PO {n} com o que foi vendido (rascunho): o promotor revê as quantidades e emite-a; depois de aprovada fica disponível para a carga.',
            {
              n: x.requisicao,
            },
          ),
        ),
      );
    b.append(el('p', null, t('Quer imprimir a contagem?')));
    const abrirPO = x.requisicao
      ? botao(t('Abrir a PO'), 'ghost', () => {
          try {
            sessionStorage.setItem('vm-requisicao-abrir', x.requisicao);
          } catch (e) {
            /* sem armazenamento: abre a lista */
          }
          fechar();
          location.hash = '#requisicoes-carrinha';
        })
      : null;
    ft.append(
      botao(t('Agora não'), 'ghost', fechar),
      ...(abrirPO ? [abrirPO] : []),
      botao(t('Imprimir a contagem'), null, () => {
        fechar();
        Promise.resolve()
          .then(() => resultadoContagem(validada, dia, motivos))
          .catch((e) => {
            console.error(e);
            toast(t('Não foi possível gerar o PDF.'), 5000);
          });
      }),
    );
  });
}

function pdfBotao(txt, gerar) {
  const b = botao(txt, 'ghost sm cc-pdf', () => {
    b.disabled = true;
    Promise.resolve()
      .then(gerar)
      .catch((e) => {
        console.error(e);
        toast(t(e.message || 'Não foi possível gerar o PDF.'), 5000);
      })
      .then(() => (b.disabled = false));
  });
  return b;
}

async function imprimirFolhaContagem(r) {
  const x = await postJson('conciliacao-carrinha', { accao: 'preparar_folha', data: dia, rota: r.rota });
  if (!x || !x.ok) throw new Error((x && x.erro) || t('Não foi possível congelar a base da contagem.'));
  const actualizada = await apiJson('conciliacao-carrinha?data=' + dia + '&actualizar=1');
  if (!actualizada || !actualizada.ok) throw new Error((actualizada && actualizada.erro) || t('Não foi possível ler os dados.'));
  CC = actualizada;
  draw();
  const rota = actualizada.rotas.find((v) => v.rota === r.rota) || r;
  return folhaContagemCega({ ...rota, linhas: x.linhas, base_congelada_em: x.congelada_em }, dia);
}

/** Contagem cega: só o artigo e o campo da quantidade que voltou na carrinha. */
function formContagem(r) {
  const f = el('div', 'cc-contagem');
  const tab = el('table', 'cc-tab');
  const th = el('tr');
  [t('Código'), t('Artigo'), t('Quantidade que voltou')].forEach((x) => th.append(el('th', null, x)));
  tab.append(th);
  const campos = {};
  const linhasLc = [];
  let gravar = null;
  // Rascunho da contagem: cada número escrito fica guardado neste navegador até gravar; a página pode actualizar
  // (ou voltar a abrir) sem apagar o que já foi contado
  const chave = 'contagem-' + dia + '-' + r.rota;
  const rasc = lerRascunho(chave);
  const guardar = () => {
    const v = {};
    Object.entries(campos).forEach(([c, i]) => (v[c] = i.value));
    guardarRascunho(chave, v);
    estado.textContent = Object.values(v).some((x) => x !== '')
      ? t('Rascunho guardado neste navegador às {h}; só fica registado ao gravar.', { h: new Date().toTimeString().slice(0, 5) })
      : '';
  };
  const estado = el('p', 'note cc-rascunho');
  estado.setAttribute('aria-live', 'polite');
  if (rasc)
    estado.textContent = t('Rascunho reposto: {n} artigo(s) já contados. Confira e grave.', { n: n0(Object.values(rasc.v).filter((x) => x !== '').length) });
  r.linhas.forEach((l) => {
    const tr = el('tr');
    const inicial = rasc && rasc.v[l.codigo] != null ? rasc.v[l.codigo] : l.contado == null ? '' : l.contado;
    const i = entrada('number', inicial, { min: 0, step: 'any', inputMode: 'decimal' });
    i.setAttribute('aria-label', tArtigo(l.artigo));
    i.addEventListener('input', guardar);
    campos[l.codigo] = i;
    const td = el('td');
    td.append(i, el('span', 'cc-un', l.unidade || ''));
    tr.append(el('td', 'cc-cod', l.codigo), el('td', null, tArtigo(l.artigo)), td);
    tab.append(tr);
    // contagem às cegas: sem o esperado, só as casas decimais num artigo à unidade e os números muito altos
    linhasLc.push({ tr, campos: [i], codigo: l.codigo, texto: tArtigo(l.artigo), atipico: () => quantidadeAtipica(i.value, { unidade: l.unidade }) });
  });
  const erro = msgErro();
  // primeiro o que falta preencher; depois, os valores fora do normal pedem confirmação
  gravar = botao(t('Gravar contagem'), null, () => (Object.values(campos).some((i) => i.value === '') ? gravarContagem() : lc.confirmar(gravarContagem)));
  // linha activa destacada, Enter para a seguinte, visto verde, «N de M preenchidos», procurar e valores fora do normal
  const lc = lancamento({ tabela: tab, linhas: linhasLc, gravar });
  if (r.exige_fecho && !r.fecho_pinguim)
    f.append(
      el(
        'p',
        'aviso cc-fecho',
        t(
          'Feche primeiro o dia no telemóvel: a contagem só se grava com o dia da rota fechado no Pinguim BO, para não sair mais nenhum documento durante a contagem.',
        ),
      ),
    );
  else if (r.fecho_pinguim)
    f.append(
      el('p', 'note', t('Dia da rota fechado no Pinguim BO às {h}: o telemóvel já não emite documentos.', { h: String(r.fecho_pinguim).slice(11, 16) })),
    );
  f.append(
    el('p', 'note', t('Conte o que voltou na carrinha (artigos por vender e devoluções). Os números do sistema só aparecem depois de gravar a contagem.')),
    lc.barra,
    tab,
    estado,
    erro,
    gravar,
  );
  function gravarContagem() {
    const contagem = {};
    for (const [c, i] of Object.entries(campos)) {
      if (i.value === '') {
        erro.hidden = false;
        erro.textContent = t('Preencha todos os artigos (0 se não voltou nada).');
        i.focus();
        return;
      }
      contagem[c] = Number(i.value);
    }
    enviarContagem(contagem, '');
  }
  function enviarContagem(contagem, excepcao) {
    postJson('conciliacao-carrinha', { accao: 'contar', data: dia, rota: r.rota, contagem, excepcao_fecho: excepcao }).then((x) => {
      if (!x.ok) {
        erro.hidden = false;
        erro.textContent = t(x.erro || 'Não foi possível gravar.');
        // dia por fechar no Pinguim BO: só o super administrador pode gravar, com o motivo registado
        if ((x.codigo === 'dia_aberto_pinguim' || x.codigo === 'fecho_por_confirmar') && CC && CC.pode && CC.pode.corrigir) {
          const mot = el('input');
          mot.placeholder = t('Motivo da excepção (ex.: Pinguim BO parado)');
          const b = botao(t('Gravar com excepção (super administrador)'), 'sec', () => {
            if (mot.value.trim().length < 5) return mot.focus();
            enviarContagem(contagem, mot.value.trim());
          });
          erro.append(el('br'), mot, b);
        }
        return;
      }
      toast(
        x.resumo.diferencas
          ? t('Contagem gravada com {n} diferença(s): confira e valide.', { n: x.resumo.diferencas })
          : t('Contagem gravada, sem diferenças: confira e valide.'),
        4000,
      );
      apagarRascunho(chave);
      CC = null;
      ler();
      draw();
    });
  }
  if (rasc) guardarRascunho(chave, rasc.v);
  return f;
}

// Grupos de colunas com cor própria (prática dos sistemas de inventário físico): o que diz o sistema, o que foi
// contado, a diferença entre os dois e a justificação. A mesma legenda e as mesmas cores vão para o PDF.
const GRUPOS = [
  { id: 'sis', nome: 'Sistema · Pinguim BO', cols: ['Inicial', 'Carregado', 'Vendido', 'Devolvido', 'Tem de voltar'] },
  { id: 'cont', nome: 'Contagem física', cols: ['Contado'] },
  { id: 'dif', nome: 'Diferença', cols: ['Diferença', 'Valor'] },
];
const quando = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');
/** Com acertos de inventário do Pinguim BO no dia, o sistema ganha a coluna «Inventário» (contado − teórico). */
const comInventario = (r) => r.linhas.some((l) => Math.abs(+l.inventario || 0) > 0.0001);
const gruposDe = (r) =>
  comInventario(r)
    ? GRUPOS.map((x) => (x.id === 'sis' ? { ...x, cols: ['Inicial', 'Carregado', 'Vendido', 'Devolvido', 'Inventário', 'Tem de voltar'] } : x))
    : GRUPOS;

/** Circuito de aprovação: contada → validada por quem contou → coordenador (1.º nível) → responsável (2.º nível, fecha). */
function circuito(r) {
  const auto = r.aprovado_por === 'automatico';
  const passos = [
    ['Contado', r.contado_por, r.contado_em],
    ['Validada por quem contou', r.validado_por, r.validado_em],
    ['Aprovada pelo coordenador', auto ? null : r.coordenado_por, auto ? null : r.coordenado_em],
    [auto ? 'Fechada sozinha (sem diferenças)' : 'Aprovada pelo responsável · fechada', r.aprovado_por, r.aprovado_em],
  ];
  const ol = el('ol', 'cc-circuito');
  ol.setAttribute('aria-label', t('Circuito de aprovação'));
  let actual = false;
  passos.forEach(([nome, quem, em], i) => {
    if (auto && i === 2) return;
    const feito = !!em;
    const li = el('li', feito ? 'feito' : !actual ? 'actual' : null);
    if (!feito) actual = true;
    li.append(el('b', null, t(nome)), el('span', null, feito ? (assinante(quem) || '') + ' · ' + quando(em) : t('pendente')));
    // a prova de que se contou com o dia já fechado no Pinguim BO (o telemóvel já não emitia documentos)
    if (i === 0 && feito && r.fecho_pinguim)
      li.append(
        el(
          'span',
          'cc-fecho-hora' + (String(r.fecho_pinguim) > String(em) ? ' aviso' : ''),
          t('Contada às {c} · dia fechado no Pinguim BO às {f}', { c: String(em).slice(11, 16), f: String(r.fecho_pinguim).slice(11, 16) }),
        ),
      );
    ol.append(li);
  });
  return ol;
}

/** Tabela completa (inicial, carregado, vendido, devolvido, esperado, contado, diferença) e as acções de cada passo. */
function tabelaRota(r) {
  const est = r.resumo.estado,
    eu = String(CC.eu || '').toLowerCase(),
    pode = CC.pode || { contar: CC.gere },
    fui = (k) => !!eu && String(r[k] || '').toLowerCase() === eu;
  const aprovar = est === 'por_aprovar' && pode.coordenar && !fui('contado_por');
  const box = el('div', 'cc-detalhe');
  box.append(circuito(r));
  // contagem às cegas: antes de a rota ser contada, quem só consulta vê os artigos, sem as quantidades do sistema
  if (r.quantidades_ocultas) {
    box.append(el('p', 'note cc-oculta', t('Contagem às cegas: as quantidades do sistema só aparecem depois de a carrinha ser contada.')));
    const tb = el('table', 'cc-tab');
    const cab = el('tr');
    [t('Código'), t('Artigo'), t('Unidade')].forEach((x) => cab.append(el('th', null, x)));
    tb.append(cab);
    r.linhas.forEach((l) => {
      const tr = el('tr');
      tr.append(el('td', 'cc-cod', l.codigo || '—'), el('td', null, tArtigo(l.artigo)), el('td', null, l.unidade || ''));
      tb.append(tr);
    });
    const w = el('div', 'cc-scroll');
    w.append(tb);
    box.append(w);
    return box;
  }
  // corte do dia (início do carregamento): a carga para o dia seguinte e as devoluções depois do corte não contam hoje
  if (r.corte) {
    const depois = r.linhas.reduce((a, l) => a + l.depois_do_corte, 0);
    const nota =
      r.corte.origem === 'contagem'
        ? t('Base da conciliação congelada às {h}, com o stock e os movimentos registados no Pinguim BO nesse momento.', { h: r.corte.hora })
        : t(
            'Conta-se o que estava na carrinha no corte das {h} ({o}). A carga para o dia seguinte não entra nesta contagem: passa a stock da carrinha amanhã, às 00:00.',
            {
              h: r.corte.hora,
              o: t(
                {
                  fotografia: 'fotografia do stock antes do corte',
                  documentos: 'inicial − vendido + guias e saídas de armazém do Pinguim BO registadas antes do corte',
                }[r.corte.origem] || 'estimado: inicial − vendido + cargas confirmadas antes do corte',
              ),
            },
          );
    box.append(
      el(
        'p',
        'cc-corte',
        nota +
          (Math.abs(depois) > 0.001 ? ' ' + t('Variação líquida do stock depois do corte: {q}.', { q: q(depois) }) : '') +
          (r.corte.vendido_depois > 0 ? ' ' + t('Vendido depois do corte (já não está na carrinha): {q}.', { q: q(r.corte.vendido_depois) }) : ''),
      ),
    );
  }
  // porque é que o Pinguim BO pode não ter as vendas do dia: telemóvel sem sincronizar, rota sem facturas
  (r.avisos_rota || []).forEach((a) => box.append(el('p', 'cc-aviso cc-aviso-rota', t(a.texto))));
  // vendas por depósito (facturadas no SAP): o que já está registado e, a quem aprova, o registo de uma nova
  const deps = r.depositos || [];
  const podeDep = CC.pode && (CC.pode.coordenar || CC.pode.fechar);
  if (deps.length || (podeDep && r.linhas.some((l) => (l.diferenca || 0) < 0))) {
    const dv = el('div', 'cc-depositos');
    deps.forEach((x) => {
      const p = el('p', x.factura_sap ? 'cc-regularizado' : 'cc-aviso');
      p.append(
        document.createTextNode(
          t('Venda por depósito {doc} de {d}: {a} · {v} ({p})', {
            doc: x.documento,
            d: dl(x.data),
            a: x.linhas.map((l) => n0(l.quantidade) + ' × ' + tArtigo(l.artigo || l.codigo)).join(', '),
            v: kz(x.total),
            p: t(x.pagamento_nome || x.pagamento),
          }) +
            ' · ' +
            (x.factura_sap ? t('factura SAP {f}', { f: x.factura_sap }) : t('factura SAP por emitir')),
        ),
      );
      if (!x.factura_sap && podeDep)
        p.append(
          ' ',
          botao(t('Indicar a factura SAP'), 'ghost sm', () => facturaDeposito(x)),
        );
      dv.append(p);
    });
    if (podeDep && r.linhas.some((l) => (l.diferenca || 0) < 0))
      dv.append(botao(t('Registar venda por depósito (SAP)'), 'ghost sm', () => registarDeposito(r)));
    box.append(dv);
  }
  // vendas depois do fecho do dia no Pinguim BO (o vendedor reabriu o dia no telemóvel)
  if ((r.depois_fecho || []).length) {
    const df = el('div', 'cc-aviso cc-depois-fecho');
    df.append(
      el(
        'b',
        null,
        t('{n} venda(s) depois do fecho do dia no Pinguim BO (às {f}): o vendedor reabriu o dia no telemóvel', {
          n: n0(r.depois_fecho.length),
          f: String(r.depois_fecho[0].fechado_em).slice(11, 16),
        }),
      ),
    );
    const ul = el('ul');
    r.depois_fecho.forEach((x) => ul.append(el('li', null, x.doc + ' · ' + String(x.hora).slice(11, 16) + ' · ' + kz(x.total))));
    df.append(ul);
    box.append(df);
  }
  // facturas fora do horário de vendas neste dia (autorizadas pelo coordenador ou não)
  const fhs = r.fora_horario || [];
  if (fhs.length) {
    const fh = el('div', 'cc-aviso cc-fora-horario');
    const nao = fhs.filter((f) => !f.autorizada);
    fh.append(
      el(
        'b',
        null,
        nao.length
          ? t('{n} factura(s) fora do horário de vendas, sem autorização', { n: n0(nao.length) })
          : t('Facturas fora do horário, autorizadas pelo coordenador'),
      ),
    );
    const ul = el('ul');
    fhs.forEach((f) => {
      const quando =
        f.motivo === 'turno_aberto'
          ? t('emitida em {e} com a data de {d}: o turno do Winrest desse dia não foi fechado', { e: dl(f.emitida), d: dl(f.data) })
          : f.motivo === 'descanso'
            ? t('dia de descanso')
            : f.motivo === 'antes'
              ? t('antes do início das vendas ({h})', { h: f.inicio })
              : t('depois do fim das vendas ({h})', { h: f.fim });
      ul.append(
        el(
          'li',
          f.autorizada ? 'cc-fh-aut' : null,
          f.documento +
            ' · ' +
            f.hora +
            ' · ' +
            kz(f.total) +
            ' · ' +
            quando +
            (f.autorizada ? ' · ' + t('autorizada por {p}: {m}', { p: f.autorizacao.por, m: f.autorizacao.motivo }) : ''),
        ),
      );
    });
    fh.append(ul);
    box.append(fh);
  }
  // o Pinguim BO mudou depois de a base ser congelada (guia anulada ou corrigida): actualizada (aberta) ou assinalada (aprovada)
  const actualizada = (r.historico || []).filter((h) => h.accao === 'base_actualizada_pinguim').pop();
  const artigosMudados = (dif) => (dif || []).map((d) => tArtigo(d.artigo)).join(', ');
  if (r.revisao_pinguim)
    box.append(
      el(
        'p',
        'cc-aviso',
        t(
          'O Pinguim BO mudou depois desta conciliação ter sido aprovada ({d}): {a}. A conciliação aprovada não se altera; reveja com a Logística e a Direcção.',
          {
            d: String(r.revisao_pinguim.em).slice(0, 16),
            a: artigosMudados(r.revisao_pinguim.diferencas),
          },
        ),
      ),
    );
  else if (actualizada && r.resumo.estado !== 'aprovada')
    box.append(
      el(
        'p',
        'cc-aviso',
        t('Base actualizada em {d}: o Pinguim BO mudou depois de congelada (documento anulado ou corrigido) em {a}. A contagem ficou; volte a validar.', {
          d: String(actualizada.em).slice(0, 16),
          a: artigosMudados(actualizada.diferencas),
        }),
      ),
    );
  // carga de um dia seguinte autorizada antes do fecho: já está na carrinha, soma-se ao que se conta (fora da conciliação)
  if ((r.cargas_posteriores || []).length)
    box.append(
      el(
        'p',
        'aviso',
        t('Carga posterior já na carrinha ({n}): conta-se com o resto, mas não entra na conciliação deste dia (somada ao esperado).', {
          n: r.cargas_posteriores.map((c) => c.numero).join(', '),
        }),
      ),
    );
  // a prova do dia: o stock desta carrinha no Pinguim BO não se explica pelos documentos (aprovação bloqueada)
  if ((r.prova || []).length)
    box.append(
      el(
        'p',
        'cc-aviso',
        t(
          'O stock desta carrinha neste dia não bate com o Pinguim BO em {n} artigo(s): {a}. A aprovação fica bloqueada até a diferença ser explicada no Pinguim BO.',
          {
            n: r.prova.length,
            a: r.prova
              .slice(0, 3)
              .map((f) => tArtigo(f.artigo) + ' (' + t('esperado {e}, Pinguim BO {f}', { e: q(f.esperado), f: q(f.final) }) + ')')
              .join('; '),
          },
        ),
      ),
    );
  const inv = comInventario(r);
  const grupos = gruposDe(r);
  const tab = el('table', 'cc-tab cc-cores');
  const g = el('tr', 'cc-grupos');
  const vazio = el('th', null, '');
  vazio.colSpan = 2;
  g.append(vazio);
  grupos.forEach((x) => {
    const th = el('th', 'cc-g-' + x.id, t(x.nome));
    th.colSpan = x.cols.length;
    th.scope = 'colgroup';
    g.append(th);
  });
  g.append(el('th', 'cc-g-mot', t(est === 'por_contar' ? 'Alertas' : 'Justificação')));
  const th = el('tr');
  th.append(el('th', null, t('Código')), el('th', null, t('Artigo')));
  grupos.forEach((x) => x.cols.forEach((c) => th.append(el('th', 'n cc-g-' + x.id, t(c)))));
  th.append(el('th', 'cc-g-mot', t(est === 'por_contar' ? 'Alertas' : 'Motivo')));
  const cab = el('thead');
  cab.append(g, th);
  tab.append(cab);
  const corpo = el('tbody');
  const escolhas = {};
  r.linhas.forEach((l) => {
    const tr = el('tr', l.diferenca ? (l.diferenca < 0 ? 'cc-falta' : 'cc-sobra') : l.alertas.length ? 'cc-alerta' : null);
    tr.append(el('td', 'cc-cod', l.codigo || '—'), el('td', null, tArtigo(l.artigo)));
    [l.inicial, l.carregado, l.vendido, l.devolvido].forEach((v) => tr.append(el('td', 'n cc-g-sis', q(v))));
    if (inv) {
      const ci = el('td', 'n cc-g-sis', l.inventario ? (l.inventario > 0 ? '+' : '') + q(l.inventario) : q(0));
      if ((l.inventario_docs || []).length) ci.title = t('Inventário no Pinguim BO: {d}', { d: l.inventario_docs.join(', ') });
      tr.append(ci);
    }
    tr.append(el('td', 'n cc-g-sis', q(l.esperado)));
    tr.append(el('td', 'n cc-g-cont', q(l.contado)));
    const sinal = l.diferenca ? (l.diferenca < 0 ? ' cc-neg' : ' cc-pos') : '';
    // a diferença da contagem e a parte regularizada no Pinguim BO ficam à vista («−5 → 0»): as contas da linha batem
    const cDif = el('td', 'n cc-g-dif' + sinal);
    if (l.regularizado && l.diferenca_bruta != null) {
      cDif.append(el('s', 'cc-bruta', difTxt(l.diferenca_bruta)), document.createTextNode(' → ' + difTxt(l.diferenca)));
      cDif.title = t('Diferença na contagem {a}; regularizada no Pinguim BO {b} ({doc}); fica {c}', {
        a: difTxt(l.diferenca_bruta),
        b: (l.regularizado.q > 0 ? '+' : '') + q(l.regularizado.q),
        doc: l.regularizado.doc,
        c: difTxt(l.diferenca),
      });
    } else cDif.textContent = difTxt(l.diferenca);
    tr.append(cDif, el('td', 'n cc-g-dif' + sinal, l.valor == null ? '—' : kz(l.valor)));
    const ult = el('td', 'cc-g-mot');
    if (est === 'por_contar') l.alertas.forEach((a) => ult.append(pill(t(ALERTAS[a] || a), 'p-warn')));
    else if (l.diferenca && aprovar) {
      const motivo = entrada('text', '', { maxLength: 255, placeholder: t('Escreva o motivo') });
      motivo.setAttribute('aria-label', t('Motivo') + ' · ' + tArtigo(l.artigo));
      escolhas[l.codigo] = motivo;
      ult.append(motivo);
    } else if (l.diferenca) {
      const m = (r.motivos || {})[l.codigo];
      ult.textContent = m ? t(CC.motivos[m.motivo] || m.motivo) + (m.nota ? ' · ' + m.nota : '') : '—';
    }
    // regularização documentada: a parte da diferença que um acerto posterior no Pinguim BO já corrigiu
    if (l.regularizado) {
      const rg = el(
        'span',
        'cc-regularizado',
        t('Regularizado no Pinguim BO: {doc} de {d} ({q})', {
          doc: l.regularizado.doc,
          d: dl(String(l.regularizado.em).slice(0, 10)),
          q: (l.regularizado.q > 0 ? '+' : '') + q(l.regularizado.q),
        }),
      );
      if (ult.textContent && ult.textContent !== '—') ult.append(document.createTextNode(' · '));
      else ult.textContent = '';
      ult.append(rg);
    }
    // diagnóstico automático do que fica por explicar (o documento do Pinguim BO a corrigir)
    if (l.diagnostico) {
      const dg = el('span', 'cc-diagnostico', t(l.diagnostico.texto));
      if (ult.textContent && ult.textContent !== '—') ult.append(document.createTextNode(' · '));
      else ult.textContent = '';
      ult.append(dg);
    }
    tr.append(ult);
    corpo.append(tr);
  });
  tab.append(corpo);
  const wrap = el('div', 'cc-scroll');
  wrap.append(tab);
  box.append(wrap);
  const legenda = el('p', 'note cc-legenda');
  grupos.forEach((x) => legenda.append(el('span', 'cc-g-' + x.id, t(x.nome))));
  legenda.append(el('span', 'cc-neg', t('falta')), el('span', 'cc-pos', t('sobra')));
  box.append(legenda);
  if (est !== 'por_contar') box.prepend(pdfBotao(t('PDF da contagem'), () => resultadoContagem(r, dia, CC.motivos)));

  // Acções do passo em que a conciliação está, só para quem o pode fazer (segregação de funções)
  const erro = msgErro();
  const acc = el('div', 'cc-acc');
  const enviar = (corpo, ok, depois) =>
    postJson('conciliacao-carrinha', { data: dia, rota: r.rota, ...corpo }).then((x) => {
      if (!x.ok) {
        erro.hidden = false;
        erro.textContent = t(x.erro || 'Não foi possível gravar.') + (x.erros ? ' ' + x.erros.join(', ') : '');
        return;
      }
      if (ok) toast(t(ok), 4000);
      CC = null;
      ler();
      draw();
      if (depois) depois(x);
    });
  const recontar = botao(t('Recontar'), 'ghost', () => {
    r.linhas = r.linhas.map((l) => ({ ...l, contado: null }));
    r.resumo.estado = 'por_contar';
    r.cega = true;
    draw();
  });
  const espera = (txt) => box.append(el('p', 'note cc-espera', txt));
  if (est === 'por_validar') {
    if (fui('contado_por')) {
      acc.append(
        recontar,
        botao(t('Validar contagem'), null, () => {
          const eu0 = CC.eu,
            mot0 = CC.motivos;
          enviar({ accao: 'validar' }, null, (x) => depoisDeValidar(r, x, eu0, mot0));
        }),
      );
      espera(t('Confira os números: se estiverem certos, valide; se não, reconte. Depois de validada segue para o coordenador.'));
    } else {
      // recontagem independente por outra pessoa da contagem (só no próprio dia: depois a carrinha já não é a mesma);
      // a nova contagem substitui a anterior, que fica no histórico, e volta a precisar de validação
      if (pode.contar && dia === hoje()) acc.append(recontar);
      espera(
        t('Aguarda a validação de {p}, que fez a contagem.', { p: r.contado_por || '—' }) +
          (pode.contar && dia === hoje() ? ' ' + t('Se os números não estiverem certos, pode recontar.') : ''),
      );
    }
  } else if (est === 'por_aprovar') {
    if (aprovar) {
      if (pode.contar) acc.append(recontar);
      acc.append(
        botao(t('Aprovar (coordenador)'), null, () => {
          const motivos = {};
          Object.entries(escolhas).forEach(([c, campo]) => (motivos[c] = { motivo: campo.value }));
          enviar({ accao: 'aprovar', motivos }, 'Aprovada pelo coordenador; segue para o responsável.');
        }),
      );
    } else espera(fui('contado_por') ? t('Validada. Aguarda a aprovação do coordenador (quem contou não aprova).') : t('Aguarda a aprovação do coordenador.'));
  } else if (est === 'por_fechar') {
    if (pode.fechar && !fui('contado_por') && !fui('coordenado_por'))
      acc.append(botao(t('Aprovar e fechar'), null, () => enviar({ accao: 'fechar' }, 'Conciliação aprovada e fechada.')));
    else espera(t('Aguarda a aprovação final do responsável (quem contou ou aprovou o 1.º nível não aprova o 2.º).'));
  }
  if (acc.childNodes.length) box.append(erro, acc);
  return box;
}

R['conciliacao-carrinha'] = (pg) => {
  dia = dia || hoje();
  pg.append(barra());
  if (!CC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (CC.falhou) {
    pg.append(el('div', 'glass empty', t(CC.falhou)));
    return;
  }
  const rs = CC.rotas;
  const n = (e) => rs.filter((r) => r.resumo.estado === e).length;
  const soma = (k) => rs.reduce((s, r) => s + (r.resumo[k] || 0), 0);
  pg.append(
    tiles([
      { l: t('Carrinhas com movimento'), v: n0(rs.length), s: t('rotas no dia') },
      { l: t('Por contar'), v: n0(n('por_contar')), tone: n('por_contar') ? 'warn' : null, s: t('técnico do armazém') },
      { l: t('Por validar'), v: n0(n('por_validar')), tone: n('por_validar') ? 'warn' : null, s: t('por quem contou') },
      {
        l: t('Por aprovar'),
        v: n0(n('por_aprovar') + n('por_fechar')),
        tone: n('por_aprovar') + n('por_fechar') ? 'serio' : null,
        s: t('coordenador: {a} · responsável: {b}', { a: n0(n('por_aprovar')), b: n0(n('por_fechar')) }),
      },
      { l: t('Fechadas'), v: n0(n('aprovada')), s: t('aprovadas nos dois níveis ou sem diferenças') },
      {
        l: t('Faltas'),
        v: kz(soma('falta_kz')),
        tone: soma('falta_kz') ? 'bad' : null,
        s: t('ao preço médio de venda do dia'),
        abrir: () => (location.hash = '#perdas'),
        abrirTitulo: t('Ver as perdas por rota'),
      },
      { l: t('Alertas automáticos'), v: n0(soma('alertas')), tone: soma('alertas') ? 'warn' : null, s: t('artigos assinalados pelo sistema') },
    ]),
  );
  if (CC.pode && CC.pode.corrigir) {
    pg.append(botao(t('Actualizar bases históricas em aberto'), 'ghost sm cc-corrigir', () => actualizarHistoricosAbertos()));
  }
  if (CC.movimentos === 'saldo')
    pg.append(
      el(
        'p',
        'note',
        t(
          'Esta base de dados não separa as cargas das devoluções: carregado e devolvido vêm do saldo do dia e «tem de voltar» é o stock da carrinha no sistema.',
        ),
      ),
    );
  if (!rs.length) {
    pg.append(el('div', 'glass empty', t('Nenhuma carrinha com movimento neste dia.')));
    return;
  }
  const lista = el('div', 'gst-lista');
  // calendário de trabalho: horário do dia e prazo do fecho (contagem e validação até ao fim da janela do fecho)
  if (CC.horario) pg.append(linhaHorario(CC.horario));
  const atrasoFecho = foraDoPrazo(CC.horario, 'fecho');
  rs.forEach((r) => {
    const [txt, cls] = ESTADOS[r.resumo.estado] || [r.resumo.estado, 'p-mut'];
    const c = el('article', 'gst-alerta cc-rota cc-e-' + cls);
    c.dataset.rota = r.rota;
    c.dataset.estado = r.resumo.estado;
    const h = el('div', 'cc-cab');
    h.append(el('b', null, r.rota + ' · ' + r.promotor), pill(t(txt), cls));
    if (atrasoFecho && ['por_contar', 'por_validar'].includes(r.resumo.estado))
      h.append(pill(t('Fora do prazo do fecho ({h})', { h: CC.horario.fecho[1] }), 'p-bad'));
    // diferenças e alertas a vermelho no texto (não mudam a cor do cartão, que é a do estado)
    const det = el('p', 'note');
    const parte = (txt, cl) => {
      if (det.childNodes.length) det.append(document.createTextNode(' · '));
      det.append(cl ? el('span', cl, txt) : document.createTextNode(txt));
    };
    parte(t('{n} artigos', { n: n0(r.resumo.artigos) }));
    if (r.resumo.alertas) parte(t('{n} com alerta automático', { n: n0(r.resumo.alertas) }), 'cc-neg');
    if (r.resumo.estado !== 'por_contar') parte(t('{n} diferença(s)', { n: n0(r.resumo.diferencas) }), r.resumo.diferencas ? 'cc-neg' : null);
    const nReg = (r.linhas || []).filter((l) => l.regularizado).length;
    if (nReg) parte(t('{n} regularizada(s) no Pinguim BO', { n: n0(nReg) }), 'cc-regularizado');
    if (r.resumo.falta_kz) parte(t('falta {v}', { v: kz(r.resumo.falta_kz) }), 'cc-neg');
    (r.avisos_rota || []).forEach((a) => parte(a.tipo === 'sincronizacao' ? t('telemóvel por sincronizar') : t('sem facturas no Pinguim BO'), 'cc-neg'));
    const fhn = (r.fora_horario || []).filter((f) => !f.autorizada).length;
    if (fhn) parte(t('{n} factura(s) fora do horário', { n: n0(fhn) }), 'cc-neg');
    c.append(h, det);
    const abrir = botao(
      aberta === r.rota ? t('Fechar') : r.cega ? t('Contar') : t('Ver'),
      'ghost sm',
      () => ((aberta = aberta === r.rota ? null : r.rota), draw()),
    );
    c.append(abrir);
    // folha de contagem às cegas: disponível para toda a logística que vê a rota (quem conta no papel, quem acompanha,
    // quem coordena), enquanto a carrinha está por contar; só leva os artigos, nunca as quantidades do sistema
    if (r.resumo.estado === 'por_contar') c.append(pdfBotao(t('Imprimir folha de contagem'), () => imprimirFolhaContagem(r)));
    // requisição de reposição criada pela conciliação (o que foi vendido), para o promotor rever e emitir
    if (r.requisicao) {
      const a = el('a', 'btn ghost sm cc-req', t('Requisição de reposição {n}', { n: r.requisicao }));
      a.href = '#requisicoes-carrinha';
      a.addEventListener('click', () => {
        try {
          sessionStorage.setItem('vm-requisicao-abrir', r.requisicao);
        } catch (e) {
          /* sem armazenamento: abre a lista */
        }
      });
      c.append(a);
    }
    if (aberta === r.rota) c.append(r.cega ? formContagem(r) : tabelaRota(r));
    lista.append(c);
  });
  const caixaDia = secBox(
    t('Carrinhas do dia'),
    t('Carregado, vendido e devolvido por artigo; diferenças entre o que tinha de voltar e o que foi contado.'),
    lista,
  );
  const contadas = rs.filter((r) => r.resumo.estado !== 'por_contar');
  if (CC.pode && CC.pode.corrigir && contadas.length)
    lista.before(botao(t('Corrigir quem contou / validou'), 'ghost sm cc-corrigir', () => corrigirRegisto(contadas)));
  pg.append(caixaDia);
};

function actualizarHistoricosAbertos() {
  janela(
    t('Actualizar bases históricas em aberto'),
    t(
      'Compara as conciliações anteriores a hoje com o Pinguim BO de agora, no instante em que cada folha de contagem foi congelada. Só muda as que mudaram no Pinguim BO (documento anulado ou corrigido): a contagem fica e volta a pedir a validação. As iguais ficam como estão; as fechadas não se alteram e ficam assinaladas.',
    ),
    (b, ft, fechar) => {
      const resultado = msgErro();
      const actualizar = botao(t('Actualizar'), null, () => {
        actualizar.disabled = true;
        const total = { actualizadas: 0, contagensPreservadas: 0, ignoradas: 0, assinaladas: 0, falhas: [] };
        let cursor = '';
        const executar = async () => {
          while (true) {
            const x = await postJson('conciliacao-carrinha', { accao: 'actualizar_abertas_anteriores', depois_de: cursor });
            if (!x || !x.ok) {
              resultado.hidden = false;
              resultado.textContent = t(x && x.erro ? x.erro : 'Não foi possível actualizar os históricos.');
              actualizar.disabled = false;
              return;
            }
            const r = x.resultado || {};
            total.actualizadas += (r.actualizadas || []).length;
            total.contagensPreservadas += (r.actualizadas || []).filter((a) => a.contagem_preservada).length;
            total.ignoradas += r.ignoradas || 0;
            total.assinaladas += r.assinaladas || 0;
            total.falhas.push(...(r.falhas || []));
            if (!r.mais) break;
            cursor = r.continuar_de || cursor;
          }
          const resumo = t('Actualizadas: {n} (contagens a revalidar: {c}); iguais ao Pinguim BO: {i}; fechadas assinaladas: {a}; falhas: {f}.', {
            n: n0(total.actualizadas),
            c: n0(total.contagensPreservadas),
            i: n0(total.ignoradas),
            a: n0(total.assinaladas),
            f: n0(total.falhas.length),
          });
          CC = null;
          ler(true);
          // sem falhas: fecha e mostra o resultado; com falhas, fica aberto com o detalhe e pode tentar de novo
          if (!total.falhas.length) {
            fechar();
            toast(resumo, 8000);
            return;
          }
          resultado.hidden = false;
          resultado.textContent =
            resumo +
            ' ' +
            total.falhas
              .slice(0, 10)
              .map((f) => [f.data, f.rota, f.erro].filter(Boolean).join(' · '))
              .join(' | ');
          actualizar.disabled = false;
        };
        executar().catch((e) => {
          console.error(e);
          resultado.hidden = false;
          resultado.textContent = t('Não foi possível actualizar os históricos.');
          actualizar.disabled = false;
        });
      });
      b.append(resultado);
      ft.append(botao(t('Fechar'), 'ghost', fechar), actualizar);
    },
  );
}

/**
 * Correcção do registo de quem contou e de quem validou (super administrador): não muda números nem aprovações,
 * exige motivo e fica no histórico de cada carrinha.
 */
function corrigirRegisto(contadas) {
  janela(
    t('Corrigir quem contou / validou'),
    t('Só muda os nomes registados; os números, o estado e as aprovações ficam como estão. O que estava antes e o motivo ficam no histórico de cada carrinha.'),
    (b, ft, fechar) => {
      // escolha da lista de utilizadores do portal (primeiro.último); vazio mantém o que está registado
      const campo = (rotulo) => {
        const i = el('select');
        i.append(new Option(t('— manter como está —'), ''));
        (CC.utilizadores || []).forEach((u) => i.append(new Option(u, u)));
        i.setAttribute('aria-label', rotulo);
        const l = el('label', 'cc-campo');
        l.append(el('span', null, rotulo), i);
        b.append(l);
        return i;
      };
      const cont = campo(t('Contado por'));
      const val = campo(t('Validada por'));
      const marcas = contadas.map((r) => {
        const c = entrada('checkbox', '');
        c.checked = true;
        c.setAttribute('aria-label', r.rota + ' · ' + r.promotor);
        const l = el('label', 'cc-campo-rota');
        l.append(c, el('span', null, r.rota + ' · ' + r.promotor + (r.contado_por ? ' (' + assinante(r.contado_por) + ')' : '')));
        b.append(l);
        return [r.rota, c];
      });
      const m = el('textarea');
      m.rows = 2;
      m.maxLength = 300;
      m.setAttribute('aria-label', t('Motivo da correcção'));
      const e2 = msgErro();
      b.append(el('span', 'note', t('Motivo da correcção')), m, e2);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Corrigir'), null, () =>
          postJson('conciliacao-carrinha', {
            accao: 'corrigir_registo',
            data: dia,
            rotas: marcas.filter(([, c]) => c.checked).map(([r]) => r),
            contado_por: cont.value,
            validado_por: val.value,
            motivo: m.value,
          }).then((x) => {
            if (!x.ok) {
              e2.hidden = false;
              e2.textContent = t(x.erro || 'Não foi possível gravar.');
              return;
            }
            toast(t('Registo corrigido em {n} carrinha(s).', { n: n0((x.rotas || []).length) }), 5000);
            fechar();
            CC = null;
            ler();
            draw();
          }),
        ),
      );
    },
  );
}
