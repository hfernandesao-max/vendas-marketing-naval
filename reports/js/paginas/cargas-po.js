// Logística › Cargas (PO): o circuito da Requisição Interna / PO até à Guia de Remessa, todo no portal.
//   1. Conferir no cais (Fiel de Armazém): número ou QR da PO, visto linha a linha, quantidade carregada e motivo de cada
//      diferença. Não gera guia nem transfere stock: a carga fica conferida e reserva o stock do armazém principal.
//   2. Validar (Back-Office): revê a conferência e valida com 1 clique (ou todas as sem diferenças); o portal dá a ordem
//      da Guia de Remessa ao Pinguim BO.
//   3. Ordens ao Pinguim BO: o robô (ou o Back-Office, até o robô estar ligado) cria a guia no Pinguim BO, o software
//      certificado; o portal encontra-a sozinho no Pinguim BO, confere-a com a carga e fecha o ciclo.
// Servidor: nucleo/api/v1/requisicoes.php (conferir, validar) e nucleo/api/v1/ordens-pinguim.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { diferencaViva, lancamento, quantidadeAtipica } from '../componentes/lancamento.js';
import { campoMotivo, MOTIVOS_CARGA } from '../componentes/motivos-carga.js';
import { requisicaoPdf } from '../componentes/requisicao-pdf.js';
import { n0 } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { atual, podeGerir, podeVer, SESSAO } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';
import { tArtigo } from '../i18n/artigos.js';

let vista = '';
let PO = null; // PO aberta no cais: {requisicao, motivos} | {falhou}
let numeroPO = '';
let V = null; // v1/ordens-pinguim
let aLer = false;
const ESTADO_ORDEM = {
  por_executar: ['Por executar', 'p-warn'],
  em_execucao: ['Em execução pelo robô', 'p-warn'],
  gerada: ['Gerada · a confirmar', 'p-mut'],
  confirmada: ['Confirmada no Pinguim BO', 'p-ok'],
  recusada: ['Recusada', 'p-bad'],
  divergente: ['Divergente', 'p-bad'],
  cancelada: ['Cancelada', 'p-mut'],
};
const quando = (s) => (s ? String(s).slice(8, 10) + '-' + String(s).slice(5, 7) + ' ' + String(s).slice(11, 16) : '—');
const vistas = () =>
  [
    ['conferir', 'Conferir no cais', podeGerir('requisicoes-carrinha')],
    ['validar', 'Validar', podeVer('validacao-carga')],
    ['ordens', 'Ordens ao Pinguim BO', podeVer('validacao-carga')],
  ].filter((x) => x[2]);

function lerOrdens() {
  if (aLer || !podeVer('validacao-carga')) return;
  aLer = true;
  apiJson('ordens-pinguim?data=' + hoje()).then((d) => {
    aLer = false;
    V = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'cargas-po') draw();
  });
}
function recarregar() {
  V = null;
  lerOrdens();
  draw();
}

// ── 1. Conferir no cais ──────────────────────────────────────────────────────────────────────────────────────
function abrirPO(n) {
  numeroPO = String(n || '')
    .trim()
    .toUpperCase();
  if (!/^(PO|RQ)-\d{4}-\d{6}$/.test(numeroPO)) {
    PO = { falhou: t('Número da PO inválido: PO-AAAA-NNNNNN.') };
    return draw();
  }
  PO = { aLer: true };
  draw();
  apiJson('requisicoes?numero=' + encodeURIComponent(numeroPO)).then((d) => {
    PO = d && d.ok ? d : { falhou: (d && d.erro) || t('PO não encontrada.') };
    if (atual === 'cargas-po') draw();
  });
}
/** Leitura do QR da PO com a câmara (BarcodeDetector do navegador; sem ele, escreve-se o número). */
async function lerQR(depois) {
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices) {
    toast(t('Este navegador não lê códigos QR: escreva o número da PO.'), 5000);
    return;
  }
  janela(t('Ler o QR da PO'), t('Aponte a câmara para o QR impresso na PO.'), (b, ft, fechar) => {
    const v = el('video', 'cp-video');
    v.setAttribute('playsinline', '');
    v.muted = true;
    b.append(v);
    let parar = false;
    let fluxo = null;
    const acabar = () => {
      parar = true;
      if (fluxo) fluxo.getTracks().forEach((x) => x.stop());
    };
    ft.append(
      botao(t('Cancelar'), 'ghost', () => {
        acabar();
        fechar();
      }),
    );
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'environment' } })
      .then(async (s) => {
        fluxo = s;
        v.srcObject = s;
        await v.play();
        const det = new window.BarcodeDetector({ formats: ['qr_code'] });
        const passo = async () => {
          if (parar) return;
          const r = await det.detect(v).catch(() => []);
          const m = r.length && String(r[0].rawValue).match(/(PO|RQ)-\d{4}-\d{6}/);
          if (m) {
            acabar();
            fechar();
            depois(m[0]);
            return;
          }
          setTimeout(passo, 250);
        };
        passo();
      })
      .catch(() => {
        acabar();
        fechar();
        toast(t('Sem acesso à câmara: escreva o número da PO.'), 5000);
      });
  });
}
function vistaConferir() {
  const w = el('div', 'cp-conf');
  const f = el('form', 'cp-abrir');
  const i = entrada('text', numeroPO, { placeholder: 'PO-2026-000000', autocomplete: 'off', spellcheck: false });
  i.setAttribute('aria-label', t('Número da PO'));
  const abrir = botao(t('Abrir'), null, null);
  abrir.type = 'submit';
  f.append(
    campo(t('Número da PO'), i),
    abrir,
    botao(t('Ler QR da PO'), 'ghost', () => lerQR(abrirPO)),
  );
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    abrirPO(i.value);
  });
  w.append(f);
  if (!PO) {
    w.append(el('p', 'note', t('Escreva o número da PO que o vendedor trouxe (ou leia o QR) para conferir a carga linha a linha.')));
    return w;
  }
  if (PO.aLer) {
    w.append(el('p', 'note', t('A carregar…')));
    return w;
  }
  if (PO.falhou) {
    w.append(el('p', 'aviso', tv(PO.falhou)));
    return w;
  }
  const r = PO.requisicao;
  const porAprovar = r.estado === 'emitida' && r.pronta_carga === false;
  const cab = el('div', 'cp-cab');
  cab.append(
    el('b', null, r.numero),
    el('span', 'note', [t('rota {r}', { r: r.rota }), r.promotor, r.data].filter(Boolean).join(' · ')),
    pill(
      t(
        porAprovar
          ? 'Por aprovar'
          : r.estado === 'emitida'
            ? 'Por conferir'
            : r.estado === 'carregada'
              ? 'Conferida no cais'
              : r.estado === 'fechada'
                ? 'Fechada'
                : r.estado,
      ),
      r.estado === 'emitida' ? 'p-warn' : r.estado === 'carregada' ? 'p-ok' : 'p-mut',
    ),
  );
  w.append(cab);
  // a PO só fica disponível para a carga depois de aprovada (o coordenador ou a Direcção)
  if (porAprovar) {
    w.append(
      el(
        'p',
        'aviso',
        t('Esta PO ainda não foi aprovada: só fica disponível para a carga depois de aprovada pelo coordenador (Logística › Requisições). Não carregue.'),
      ),
    );
    return w;
  }
  if (r.estado !== 'emitida') {
    const fase =
      { conferida: 'à espera do Back-Office', validada: 'validada: guia pedida ao Pinguim BO', transferida: 'guia gerada no Pinguim BO' }[r.fase] || '';
    w.append(
      el(
        'p',
        'note',
        r.estado === 'carregada'
          ? t('Conferida por {p} em {d}.', { p: r.confirmado_por || '—', d: quando(r.confirmado_em) }) + (fase ? ' ' + t(fase) + '.' : '')
          : t('Esta PO não está à espera de conferência.'),
      ),
    );
    return w;
  }
  if (SESSAO && r.emitida_por && SESSAO.login === r.emitida_por)
    w.append(el('p', 'aviso', t('Segregação de funções: quem emitiu a PO não confere a carga. Peça a outro Fiel de Armazém.')));
  const tab = el('table', 'cc-tab cp-tab');
  const h = el('tr');
  [t('Código'), t('Artigo'), t('Pedido'), t('Carregado'), t('Visto')].forEach((x, k) => h.append(el('th', k >= 2 ? 'n' : null, x)));
  tab.append(h);
  const linhas = r.linhas.map((l) => {
    const tr = el('tr');
    const q = entrada('number', l.quantidade, { min: 0, step: 'any', inputMode: 'decimal' });
    q.setAttribute('aria-label', t('Carregado') + ' · ' + tArtigo(l.artigo));
    const ck = el('input');
    ck.type = 'checkbox';
    ck.setAttribute('aria-label', t('Visto') + ' · ' + tArtigo(l.artigo));
    const m = campoMotivo(t('Motivo') + ' · ' + tArtigo(l.artigo));
    m.w.hidden = true;
    const actual = () => {
      const dif = q.value !== '' && Number(q.value) !== Number(l.quantidade);
      m.w.hidden = !dif;
      tr.classList.toggle('cp-dif', dif);
      resumo();
    };
    q.addEventListener('input', actual);
    ck.addEventListener('change', resumo);
    const tq = el('td', 'n');
    // diferença viva: ✓ verde se bate com o pedido, senão a diferença a vermelho
    tq.append(q, diferencaViva(q, l.quantidade), m.w);
    const tc = el('td', 'n');
    tc.append(ck);
    tr.append(el('td', 'cc-cod', l.codigo), el('td', null, tArtigo(l.artigo)), el('td', 'n', n0(l.quantidade)), tq, tc);
    tab.append(tr);
    return { l, q, ck, m, tr };
  });
  const res = el('p', 'note cp-res');
  function resumo() {
    const vistos = linhas.filter((x) => x.ck.checked).length;
    const difs = linhas.filter((x) => !x.m.w.hidden);
    const dq = difs.reduce((s, x) => s + (Number(x.q.value) - Number(x.l.quantidade)), 0);
    res.textContent =
      t('{a} de {b} conferidas', { a: n0(vistos), b: n0(linhas.length) }) +
      ' · ' +
      (difs.length ? t('diferenças: {n} ({q} un.)', { n: n0(difs.length), q: (dq > 0 ? '+' : '') + n0(dq) }) : t('sem diferenças'));
  }
  resumo();
  const erro = msgErro();
  const todas = botao(t('Marcar todas iguais ao pedido'), 'ghost', () => {
    linhas.forEach((x) => {
      x.q.value = x.l.quantidade;
      x.ck.checked = true;
      x.q.dispatchEvent(new Event('input'));
      x.ck.dispatchEvent(new Event('change'));
    });
    resumo();
    lc.actualizar();
  });
  const ok = botao(t('Concluir conferência'), null, () => {
    if (linhas.some((x) => !x.ck.checked || x.q.value === '')) concluir();
    else lc.confirmar(concluir);
  });
  function concluir() {
    const entregue = {};
    const motivos = {};
    for (const x of linhas) {
      if (!x.ck.checked) {
        erro.hidden = false;
        erro.textContent = t('Ponha o visto em todas as linhas antes de concluir.');
        return x.ck.focus();
      }
      if (x.q.value === '' || Number(x.q.value) < 0) {
        erro.hidden = false;
        erro.textContent = t('Indique a quantidade carregada de cada artigo (0 se não foi carregado).');
        return x.q.focus();
      }
      entregue[x.l.codigo] = Number(x.q.value);
      if (!x.m.w.hidden) {
        const v = x.m.valor();
        if (!v || (v.codigo === 'outro' && v.texto.length < 3)) {
          erro.hidden = false;
          erro.textContent = t('Indique o motivo de cada diferença entre o pedido e o carregado.');
          return x.m.sel.focus();
        }
        motivos[x.l.codigo] = v;
      }
    }
    ok.disabled = true;
    postJson('requisicoes', { accao: 'confirmar_carga', numero: r.numero, entregue, motivos }).then((d) => {
      ok.disabled = false;
      if (!d || !d.ok) {
        erro.hidden = false;
        erro.textContent = tv((d && d.erro) || 'Não foi possível gravar.');
        return;
      }
      toast(t('Carga da PO {n} conferida. Segue para a validação do Back-Office.', { n: r.numero }), 5000);
      lc.limparRascunho();
      PO = { requisicao: d.requisicao };
      V = null;
      draw();
    });
  }
  const acc = el('div', 'cp-acc');
  acc.append(todas, ok);
  // linha activa, Enter para a seguinte, Ctrl+Enter conclui, procurar, rascunho e valores fora do normal (o visto e o
  // contador já são os da conferência)
  const lc = lancamento({
    tabela: tab,
    linhas: linhas.map((x) => ({
      tr: x.tr,
      campos: [x.q, x.ck],
      codigo: x.l.codigo,
      texto: tArtigo(x.l.artigo),
      preenchida: () => x.ck.checked && x.q.value !== '',
      atipico: () => quantidadeAtipica(x.q.value, { unidade: x.l.unidade, esperado: Number(x.l.quantidade) }),
    })),
    gravar: ok,
    visto: false,
    progresso: false,
    fixar: acc,
    chave: 'po-conferencia-' + r.numero,
  });
  // repõe o visto do rascunho no contador
  resumo();
  w.append(
    el(
      'p',
      'note',
      t('Confirme linha a linha o que foi realmente carregado. Nesta fase não se gera a Guia de Remessa nem se transfere stock: a carga fica reservada.'),
    ),
    lc.barra,
    tab,
    res,
    erro,
    acc,
  );
  return w;
}

// ── 2. Validar (Back-Office) ────────────────────────────────────────────────────────────────────────────────
function validar(numeros, fechar, erro) {
  const um = (n) => postJson('requisicoes', { accao: 'validar', numero: n });
  return numeros
    .reduce((p, n) => p.then((feitos) => um(n).then((d) => (d && d.ok ? feitos.concat(n) : Promise.reject(d)))), Promise.resolve([]))
    .then(
      (feitos) => {
        if (fechar) fechar();
        toast(t('{n} carga(s) validada(s): guia pedida ao Pinguim BO.', { n: n0(feitos.length) }), 5000);
        recarregar();
      },
      (d) => {
        const m = tv((d && d.erro) || 'Não foi possível gravar.');
        if (erro) {
          erro.hidden = false;
          erro.textContent = m;
        } else toast(m, 6000);
        recarregar();
      },
    );
}
/** PDF da PO com o que foi pedido e o que foi carregado (diferenças e motivos, quem conferiu e validou, guia). */
const pdfCarga = (r) => requisicaoPdf(r, true).catch(() => toast(t('Não foi possível gerar o PDF.'), 5000));

function rever(r) {
  janela(t('Rever a carga {n}', { n: r.numero }), t('rota {r}', { r: r.rota }) + ' · ' + (r.promotor || ''), (b, ft, fechar) => {
    const tab = el('table', 'cc-tab');
    const h = el('tr');
    [t('Artigo'), t('Pedido'), t('Carregado'), t('Motivo')].forEach((x, k) => h.append(el('th', k === 1 || k === 2 ? 'n' : null, x)));
    tab.append(h);
    r.linhas.forEach((l) => {
      const tr = el('tr', Math.abs((l.entregue || 0) - l.quantidade) > 0.0001 ? 'cp-dif' : null);
      const m = l.motivo ? t(MOTIVOS_CARGA[l.motivo.codigo] || l.motivo.codigo) + (l.motivo.texto ? ' · ' + l.motivo.texto : '') : '';
      tr.append(el('td', null, tArtigo(l.artigo)), el('td', 'n', n0(l.quantidade)), el('td', 'n', n0(l.entregue)), el('td', null, m));
      tab.append(tr);
    });
    const erro = msgErro();
    b.append(el('p', 'note', t('Conferida por {p} em {d}.', { p: r.confirmado_por || '—', d: quando(r.confirmado_em) })), tab, erro);
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(t('PDF: pedido e carregado'), 'ghost', () => pdfCarga(r)),
    );
    if (V.gere) ft.append(botao(t('Validar e pedir a guia'), null, () => validar([r.numero], fechar, erro)));
  });
}
function vistaValidar() {
  const w = el('div');
  const ps = V.por_validar || [];
  if (!ps.length) {
    w.append(el('p', 'note', t('Nenhuma carga à espera de validação.')));
    return w;
  }
  const semDif = ps.filter((r) => !r.diferencas);
  if (V.gere && semDif.length > 1) {
    const b = botao(t('Validar todas sem diferenças ({n})', { n: n0(semDif.length) }), null, () => {
      b.disabled = true;
      validar(semDif.map((r) => r.numero));
    });
    w.append(b);
  }
  const tab = el('table', 'cc-tab');
  const h = el('tr');
  [t('PO'), t('Rota'), t('Conferida por'), t('Às'), t('Diferenças'), ''].forEach((x) => h.append(el('th', null, x)));
  tab.append(h);
  ps.forEach((r) => {
    const tr = el('tr');
    const acc = el('td');
    if (V.gere && !r.diferencas)
      acc.append(
        botao(t('Validar'), 'sec sm', (e) => {
          e.target.disabled = true;
          validar([r.numero]);
        }),
      );
    acc.append(
      botao(t('Rever'), 'ghost sm', () => rever(r)),
      botao(t('PDF'), 'ghost sm', () => pdfCarga(r)),
    );
    tr.append(
      el('td', null, r.numero),
      el('td', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')),
      el('td', null, r.confirmado_por || '—'),
      el('td', null, quando(r.confirmado_em)),
      el('td', null, r.diferencas ? n0(r.diferencas) : '—'),
      acc,
    );
    tab.append(tr);
  });
  w.append(tab);
  if (!V.gere) w.append(el('p', 'note', t('Pode consultar; validar as cargas é do Back-Office.')));
  return w;
}

// ── 3. Ordens ao Pinguim BO ─────────────────────────────────────────────────────────────────────────────────
function accaoOrdem(o, accao, extra, fechar, erro) {
  return postJson('ordens-pinguim', { accao, id: o.id, ...extra }).then((d) => {
    if (!d || !d.ok) {
      const m = tv((d && d.erro) || 'Não foi possível gravar.');
      if (erro) {
        erro.hidden = false;
        erro.textContent = m;
      } else toast(m, 6000);
      return;
    }
    if (fechar) fechar();
    toast(t('Ordem {n} actualizada.', { n: o.id }), 3500);
    recarregar();
  });
}
function janelaManual(o) {
  janela(t('Criar a guia no Pinguim BO'), o.id, (b, ft, fechar) => {
    b.append(
      el(
        'p',
        'note',
        t(
          'Crie a Guia de Remessa no Pinguim BO com estes dados (o número da PO na referência) e escreva aqui o número do documento. O portal confere-o sozinho com o Pinguim BO.',
        ),
      ),
    );
    const dl = el('dl', 'cp-dados');
    [
      [t('Referência'), o.referencia],
      [t('Armazém de destino (viatura)'), o.armazem_destino],
      [t('Rota'), o.rota + (o.promotor ? ' · ' + o.promotor : '')],
    ].forEach(([k, v]) => dl.append(el('dt', null, k), el('dd', null, v)));
    const tab = el('table', 'cc-tab');
    const h = el('tr');
    [t('Código'), t('Artigo'), t('Quantidade'), t('Armazém de origem')].forEach((x, k) => h.append(el('th', k === 2 ? 'n' : null, x)));
    tab.append(h);
    o.linhas.forEach((l) => {
      const tr = el('tr');
      tr.append(el('td', 'cc-cod', l.codigo), el('td', null, tArtigo(l.artigo)), el('td', 'n', n0(l.quantidade)), el('td', null, l.armazem_origem || '—'));
      tab.append(tr);
    });
    const copiar = botao(t('Copiar as linhas'), 'ghost sm', () => {
      const txt = o.linhas.map((l) => [l.codigo, l.quantidade].join('\t')).join('\n');
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(
        () => toast(t('Linhas copiadas.'), 2500),
        () => toast(t('Não foi possível copiar.'), 3000),
      );
    });
    const doc = entrada('text', '', { maxLength: 60, placeholder: 'GTC 2026/0000' });
    doc.setAttribute('aria-label', t('Número do documento no Pinguim BO'));
    const erro = msgErro();
    b.append(dl, tab, copiar, campo(t('Número do documento no Pinguim BO'), doc), erro);
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(t('Gravar'), null, () => accaoOrdem(o, 'executada_manual', { documento: doc.value.trim() }, fechar, erro)),
    );
    setTimeout(() => doc.focus(), 0);
  });
}
function janelaCancelar(o) {
  janela(t('Cancelar a ordem'), o.id, (b, ft, fechar) => {
    const m = el('textarea');
    m.rows = 3;
    m.maxLength = 300;
    m.setAttribute('aria-label', t('Motivo'));
    const erro = msgErro();
    b.append(campo(t('Motivo'), m), erro);
    ft.append(
      botao(t('Voltar'), 'ghost', fechar),
      botao(t('Cancelar a ordem'), null, () => accaoOrdem(o, 'cancelar', { motivo: m.value.trim() }, fechar, erro)),
    );
  });
}
function vistaOrdens() {
  const w = el('div', 'gst-lista');
  const os = V.ordens || [];
  w.append(
    el(
      'p',
      'note',
      V.modo === 'robo'
        ? t('O robô do portal cria cada guia no Pinguim BO sozinho; o portal confere-a no Pinguim BO e fecha a carga.')
        : t('Robô ainda por ligar: crie cada guia no Pinguim BO com os dados da ordem e escreva o número. O portal confere-a sozinho no Pinguim BO.'),
    ),
  );
  if (!os.length) w.append(el('p', 'note', t('Sem ordens nos últimos 30 dias.')));
  os.forEach((o) => {
    const c = el('article', 'gst-alerta cp-ordem');
    const h = el('div', 'cc-cab');
    const [en, ec] = ESTADO_ORDEM[o.estado] || [o.estado, 'p-mut'];
    h.append(el('b', null, t('Guia de Remessa') + ' · ' + o.referencia), pill(t(en), ec));
    c.append(h);
    const det = [
      t('rota {r}', { r: o.rota }) + (o.promotor ? ' · ' + o.promotor : ''),
      t('{n} artigos', { n: n0(o.linhas.length) }),
      t('destino {a}', { a: o.armazem_destino }),
      o.documento ? t('documento {d}', { d: o.documento.numero }) : '',
      quando(o.criada_em),
    ].filter(Boolean);
    c.append(el('p', 'note', det.join(' · ')));
    if (o.erro) c.append(el('p', 'aviso', tv(o.erro)));
    const acc = el('div', 'gst-trat-acc');
    if (V.gere) {
      if (['por_executar', 'recusada', 'divergente'].includes(o.estado) && (o.modo !== 'robo' || o.estado !== 'por_executar'))
        acc.append(botao(t('Executada no Pinguim BO'), 'sec sm', () => janelaManual(o)));
      if (['recusada', 'divergente'].includes(o.estado)) acc.append(botao(t('Repetir'), 'ghost sm', () => accaoOrdem(o, 'repetir', {})));
      if (['por_executar', 'gerada', 'recusada', 'divergente'].includes(o.estado)) acc.append(botao(t('Cancelar'), 'ghost sm', () => janelaCancelar(o)));
    }
    if (acc.childNodes.length) c.append(acc);
    w.append(c);
  });
  return w;
}

R['cargas-po'] = (pg) => {
  const vs = vistas();
  if (!vs.some((x) => x[0] === vista)) vista = vs.length ? vs[0][0] : '';
  if (podeVer('validacao-carga') && !V) lerOrdens();
  if (V && !V.falhou) {
    const os = V.ordens || [];
    const n = (e) => os.filter((o) => o.estado === e).length;
    pg.append(
      tiles([
        { l: t('Por validar'), v: n0((V.por_validar || []).length), tone: (V.por_validar || []).length ? 'warn' : 'ok', s: t('cargas conferidas no cais') },
        { l: t('Ordens por executar'), v: n0(n('por_executar') + n('em_execucao')), tone: n('por_executar') ? 'warn' : null },
        { l: t('A confirmar no Pinguim BO'), v: n0(n('gerada')) },
        { l: t('Recusadas ou divergentes'), v: n0(n('recusada') + n('divergente')), tone: n('recusada') + n('divergente') ? 'bad' : null },
        { l: t('Confirmadas'), v: n0(n('confirmada')), s: t('últimos 30 dias') },
      ]),
    );
  }
  const seg = el('div', 'rel-tabs');
  seg.setAttribute('role', 'tablist');
  vs.forEach(([k, nome]) => {
    const b = el('button', 'rel-tab' + (vista === k ? ' on' : ''), t(nome));
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', vista === k ? 'true' : 'false');
    b.addEventListener('click', () => ((vista = k), draw()));
    seg.append(b);
  });
  let corpo;
  if (vista === 'conferir') corpo = vistaConferir();
  else if (!V) corpo = el('p', 'note', t('A carregar…'));
  else if (V.falhou) corpo = el('p', 'aviso', tv(V.falhou));
  else corpo = vista === 'validar' ? vistaValidar() : vistaOrdens();
  const box = secBox(
    t('Cargas (PO)'),
    t(
      'Do pedido à Guia de Remessa sem papel: o Fiel de Armazém confere a PO no cais, o Back-Office valida com um clique e o portal ordena a guia ao Pinguim BO, que a gera e assina. O stock só passa para a viatura quando a guia existe no Pinguim BO.',
    ),
    corpo,
  );
  box.querySelector('.sec-h').after(seg);
  pg.append(box);
};
