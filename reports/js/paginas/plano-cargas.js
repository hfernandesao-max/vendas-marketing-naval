// Plano de cargas: preenchimento, importação, publicação e envio por email.
import { draw } from '../app.js';
import { pill, secBox, tabela } from '../componentes/blocos.js';
import { botao, campo, confirmarAccao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { carregarExcelJS } from '../excel.js';
import { dl, ds, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { DB, b64, chaveTxt, el, somaDias, toast } from '../util.js';

const PC = { data: null, resp: null, rotas: null, sujo: false, mud: 0, gravando: false, erro: null, guardadoEm: null, hist: null };
const somaQ = (r) => Object.values(r.quantidades || {}).reduce((s, v) => s + (+v || 0), 0);
const postPlano = (corpo) => postJson('data/plano.php', corpo);

function carregarPlano(data) {
  return fetch('data/plano.php?data=' + encodeURIComponent(data), { cache: 'no-store' })
    .then((r) => r.json())
    .then((d) => {
      if (d.erro) {
        PC.erro = d.erro;
        return;
      }
      PC.erro = null;
      PC.resp = d;
      PC.data = d.data;
      PC.sujo = false;
      PC.guardadoEm = null;
      PC.rotas = montarRotasPlano(d);
    })
    .catch(() => {
      PC.erro = t('O plano de cargas precisa do servidor da plataforma (PHP).');
    });
}
// Uma linha por rota do portal; o plano guardado (ou copiado) preenche-a
function montarRotasPlano(d, base) {
  const arts = d.listas.artigos;
  const guardadas = base || (d.plano ? d.plano.rotas : []);
  const por = {};
  guardadas.forEach((r) => {
    por[r.rota] = r;
  });
  const vazia = (rt) => ({
    rota: rt.rota,
    vendedor: rt.promotor || '',
    sai: false,
    armazem: d.listas.armazens[0] || '',
    viagens: 1,
    zonas: [],
    quantidades: {},
    motorista: '',
    telefone: '',
    matricula: '',
    viatura: '',
    transportadora: '',
    factura: '',
    obs: '',
  });
  const lista = d.rotas.map((rt) => ({ ...vazia(rt), ...(por[rt.rota] || {}) }));
  guardadas.filter((r) => !d.rotas.some((x) => x.rota === r.rota)).forEach((r) => lista.push({ ...vazia({ rota: r.rota }), ...r }));
  lista.forEach((r) => {
    r.zonas = [...(r.zonas || [])];
    r.quantidades = { ...r.quantidades };
    arts.forEach((a) => {
      if (r.quantidades[a.id] == null) r.quantidades[a.id] = 0;
    });
  });
  return lista;
}
const estadoPlano = () => {
  const p = PC.resp && PC.resp.plano;
  if (!p || !p.rev) return PC.sujo ? 'rascunho' : 'novo';
  if (!p.publicado) return 'rascunho';
  if (!PC.resp.gere) return 'publicado';
  return PC.sujo || p.rev > p.publicado.rev ? 'alterado' : 'publicado';
};
function validarPlano(rotas) {
  const erros = [],
    avisos = [],
    saem = rotas.filter((r) => r.sai);
  if (!saem.length) erros.push({ msg: t('Nenhuma rota sai neste dia.') });
  const vistos = { m: {}, p: {} };
  saem.forEach((r) => {
    if (!r.motorista.trim()) erros.push({ rota: r.rota, msg: t('falta o motorista') });
    if (!r.matricula.trim()) erros.push({ rota: r.rota, msg: t('falta a matrícula') });
    if (!r.zonas.length) erros.push({ rota: r.rota, msg: t('indique pelo menos uma zona') });
    if (somaQ(r) <= 0) erros.push({ rota: r.rota, msg: t('a carga está a zero') });
    if (r.motorista.trim() && !r.telefone.trim()) avisos.push({ rota: r.rota, msg: t('sem telefone do motorista') });
    if (!r.factura.trim()) avisos.push({ rota: r.rota, msg: t('sem número de factura') });
    const km = chaveTxt(r.motorista),
      kp = chaveTxt(r.matricula);
    if (km) {
      if (vistos.m[km]) avisos.push({ rota: r.rota, msg: t('o motorista também está na rota {r}', { r: vistos.m[km] }) });
      else vistos.m[km] = r.rota;
    }
    if (kp) {
      if (vistos.p[kp]) avisos.push({ rota: r.rota, msg: t('a viatura também está na rota {r}', { r: vistos.p[kp] }) });
      else vistos.p[kp] = r.rota;
    }
  });
  return { erros, avisos };
}
let pcTimer = null;
function marcarSujo() {
  PC.sujo = true;
  PC.mud++;
  actualizarPlanoUI();
  clearTimeout(pcTimer);
  pcTimer = setTimeout(guardarPlano, 1500);
}
function guardarPlano() {
  clearTimeout(pcTimer);
  if (!PC.sujo || PC.gravando || !PC.resp || !PC.resp.gere) return Promise.resolve(true);
  PC.gravando = true;
  actualizarPlanoUI();
  const n = PC.mud;
  return postPlano({ accao: 'guardar', data: PC.data, rev: PC.resp.plano ? PC.resp.plano.rev : 0, rotas: PC.rotas }).then((d) => {
    PC.gravando = false;
    if (d.ok) {
      PC.resp.plano = d.plano;
      PC.guardadoEm = new Date();
      if (PC.mud === n) PC.sujo = false;
      else pcTimer = setTimeout(guardarPlano, 800);
    } else toast(t(d.erro || 'Não foi possível guardar.'), 8000);
    actualizarPlanoUI();
    return !!d.ok;
  });
}
function mudarDiaPlano(nova) {
  if (!nova || nova === PC.data) return;
  guardarPlano().then(() => {
    PC.resp = null;
    PC.rotas = null;
    PC.data = nova;
    draw();
  });
}
addEventListener('beforeunload', (e) => {
  if (PC.sujo) {
    guardarPlano();
    e.preventDefault();
    e.returnValue = '';
  }
});
function gerarExcelPlano(rotas, data, versao, artigos) {
  return carregarExcelJS().then(() => {
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Portal RTM · Grupo Naval';
    const ws = wb.addWorksheet('GENERAL SALES PLAN', {
      views: [{ state: 'frozen', ySplit: 2 }],
      pageSetup: { orientation: 'landscape', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0 },
    });
    ws.columns = [22, 22, 12, 42, 12, 16, 12, 24, 15, 17, 16, 18, 18].map((w) => ({ width: w }));
    const [y, m, dd] = data.split('-');
    ws.mergeCells('A1:M1');
    const c1 = ws.getCell('A1');
    c1.value = `GENERAL SALES PLAN (DATE: ${dd}/${m}/${y.slice(2)})` + (versao > 1 ? ` · RECTIFICADO (V${versao})` : '');
    c1.font = { bold: true, size: 18, color: { argb: 'FF1E4FB0' } };
    c1.alignment = { horizontal: 'center', vertical: 'middle' };
    ws.getRow(1).height = 28;
    const borda = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } };
    const r2 = ws.getRow(2);
    r2.height = 30;
    [
      'REQUESTED DATE',
      'SALES PERSON',
      'LOADING',
      'DISCHARGED',
      'NUMBER OF TRIPS',
      'ITEM',
      'QUANTITY',
      'DRIVER',
      'PHONE NUMBER',
      'PLATE',
      'VEHICLE TYPE',
      'COMPANY',
      'INVOICE NUMBER',
    ].forEach((h, i) => {
      const c = r2.getCell(i + 1);
      c.value = h;
      c.border = borda;
      c.font = { bold: true, size: 11, color: { argb: 'FFFFFFFF' } };
      c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E4FB0' } };
      c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    });
    const dataXl = new Date(Date.UTC(+y, +m - 1, +dd));
    let lin = 3;
    rotas
      .filter((r) => r.sai)
      .forEach((r) => {
        const n = Math.max(r.zonas.length, artigos.length, 1),
          ini = lin,
          fim = lin + n - 1;
        for (let i = 0; i < n; i++) {
          const row = ws.getRow(lin + i);
          if (r.zonas[i]) row.getCell(4).value = r.zonas[i].toUpperCase();
          if (artigos[i]) {
            row.getCell(6).value = artigos[i].nome.toUpperCase();
            row.getCell(7).value = +r.quantidades[artigos[i].id] || 0;
          }
        }
        const tel = String(r.telefone || '').replace(/\s/g, '');
        const unico = {
          1: dataXl,
          2: (r.vendedor || '').toUpperCase(),
          3: (r.armazem || '').toUpperCase(),
          5: +r.viagens || null,
          8: (r.motorista || '').toUpperCase(),
          9: /^\d{9}$/.test(tel) ? +tel : tel || null,
          10: r.matricula || null,
          11: (r.viatura || '').toUpperCase(),
          12: (r.transportadora || '').toUpperCase(),
          13: r.factura || null,
        };
        Object.entries(unico).forEach(([col, v]) => {
          ws.getRow(ini).getCell(+col).value = v === '' ? null : v;
          if (n > 1) ws.mergeCells(ini, +col, fim, +col);
        });
        for (let i = ini; i <= fim; i++)
          for (let c = 1; c <= 13; c++) {
            const cell = ws.getRow(i).getCell(c);
            cell.border = borda;
            cell.font = { bold: true, size: 10 };
            cell.alignment = { vertical: 'middle', horizontal: c === 4 || c === 6 ? 'left' : 'center', wrapText: c === 4 || c === 2 };
          }
        ws.getRow(ini).getCell(1).numFmt = 'dd-mm-yyyy';
        lin = fim + 1;
        ws.mergeCells(lin, 1, lin, 13);
        lin++;
      });
    return wb.xlsx.writeBuffer();
  });
}

// ---- Importar o Excel feito à mão (transição) ----
function lerExcelPlano(file) {
  return carregarExcelJS()
    .then(() => file.arrayBuffer())
    .then((buf) => {
      const wb = new ExcelJS.Workbook();
      return wb.xlsx.load(buf).then(() => wb);
    })
    .then((wb) => {
      const ws = wb.worksheets[0];
      let hdr = 0;
      ws.eachRow((row, i) => {
        if (!hdr && row.values.some((v) => typeof v === 'string' && /SALES\s*PERSON/i.test(v))) hdr = i;
      });
      if (!hdr) throw new Error(t('Não encontrei o cabeçalho do plano (SALES PERSON) no ficheiro.'));
      const val = (row, c) => {
        const cel = row.getCell(c);
        if (cel.isMerged && cel.master && cel.master.address !== cel.address) return null;
        let v = cel.value;
        if (v && typeof v === 'object' && !(v instanceof Date))
          v = v.result != null ? v.result : v.text != null ? v.text : v.richText ? v.richText.map((x) => x.text).join('') : null;
        return v;
      };
      const txt = (v) => (v == null || v instanceof Date ? '' : String(v).replace(/\s+/g, ' ').trim());
      const blocos = [];
      let b = null,
        dataF = null;
      for (let i = hdr + 1; i <= ws.rowCount; i++) {
        const row = ws.getRow(i),
          vend = txt(val(row, 2));
        if (vend) {
          b = {
            vendedor: vend,
            armazem: txt(val(row, 3)),
            viagens: +val(row, 5) || 1,
            zonas: [],
            itens: [],
            motorista: txt(val(row, 8)),
            telefone: txt(val(row, 9)),
            matricula: txt(val(row, 10)),
            viatura: txt(val(row, 11)),
            transportadora: txt(val(row, 12)),
            factura: txt(val(row, 13)),
          };
          blocos.push(b);
          const a = val(row, 1);
          if (a instanceof Date && !dataF) dataF = a.toISOString().slice(0, 10);
        }
        if (!b) continue;
        const z = txt(val(row, 4));
        if (z) b.zonas.push(z);
        const it = txt(val(row, 6));
        if (it) b.itens.push([it, +val(row, 7) || 0]);
      }
      return { blocos, dataF };
    });
}
const titulo = (s) =>
  String(s || '')
    .toLowerCase()
    .replace(/(^|[\s(-])(\p{L})/gu, (m, a, l) => a + l.toUpperCase())
    .replace(/ (E|De|Do|Da|Dos|Das) /g, (m) => m.toLowerCase());
// Associa cada bloco do Excel a uma rota: nome do vendedor e, quando não chega, viatura e motorista do histórico
function rotaDoBloco(b, d, usadas) {
  const kv = chaveTxt(b.vendedor).split(' '),
    hist = (DB.cargas || []).slice().sort((x, y) => (x.data < y.data ? 1 : -1));
  let melhor = null,
    pts = 0;
  d.rotas.forEach((rt) => {
    if (usadas.has(rt.rota)) return;
    const kp = chaveTxt(rt.promotor).split(' ');
    let s = 0;
    if (kp.join(' ') === kv.join(' ')) s += 5;
    if (kp[0] && kp[0] === kv[0]) s += 1;
    if (kp.length > 1 && kp[kp.length - 1] === kv[kv.length - 1]) s += 1;
    const hp = hist.find((h) => chaveTxt(h.matricula) === chaveTxt(b.matricula));
    if (hp && hp.rota === rt.rota) s += 2;
    const hm = hist.find((h) => chaveTxt(h.motorista) === chaveTxt(b.motorista));
    if (hm && hm.rota === rt.rota) s += 2;
    if (s > pts) {
      pts = s;
      melhor = rt;
    }
  });
  return pts >= 2 ? melhor : null;
}
function importarExcelPlano(file) {
  lerExcelPlano(file)
    .then(({ blocos, dataF }) => {
      if (!blocos.length) {
        toast(t('O ficheiro não tem rotas.'), 6000);
        return;
      }
      const alvo = dataF || PC.data;
      const passo = alvo !== PC.data ? guardarPlano().then(() => carregarPlano(alvo)) : Promise.resolve();
      return passo.then(() => {
        const d = PC.resp,
          arts = d.listas.artigos,
          usadas = new Set(),
          falhas = [];
        const L = d.listas,
          achar = (lista, v) => lista.find((x) => chaveTxt(x) === chaveTxt(v)) || titulo(v);
        PC.rotas.forEach((r) => {
          r.sai = false;
        });
        blocos.forEach((b) => {
          const rt = rotaDoBloco(b, d, usadas);
          if (!rt) {
            falhas.push(b.vendedor);
            return;
          }
          usadas.add(rt.rota);
          const r = PC.rotas.find((x) => x.rota === rt.rota);
          const q = {};
          arts.forEach((a) => {
            q[a.id] = 0;
          });
          b.itens.forEach(([nome, v]) => {
            const a = arts.find((x) => chaveTxt(x.nome) === chaveTxt(nome));
            if (a) q[a.id] += v;
          });
          const mot = L.motoristas.find((x) => chaveTxt(x.nome) === chaveTxt(b.motorista));
          Object.assign(r, {
            sai: true,
            vendedor: rt.promotor || titulo(b.vendedor),
            armazem: b.armazem ? achar(L.armazens, b.armazem) : r.armazem,
            viagens: b.viagens,
            zonas: [...new Map(b.zonas.map((z) => [chaveTxt(z), achar(L.zonas, z)])).values()],
            quantidades: q,
            motorista: mot ? mot.nome : titulo(b.motorista),
            telefone: b.telefone || (mot ? mot.telefone : ''),
            matricula: b.matricula.toUpperCase(),
            viatura: b.viatura ? achar(L.viaturas, b.viatura) : '',
            transportadora: b.transportadora ? achar(L.transportadoras, b.transportadora) : '',
            factura: b.factura,
          });
        });
        marcarSujo();
        draw();
        const msg =
          t('Importadas {n} rotas do Excel de {d}.', { n: usadas.size, d: dl(alvo) }) +
          (falhas.length ? ' ' + t('Sem rota identificada: {v}. Preencha essas rotas à mão.', { v: falhas.join(', ') }) : '');
        toast(msg, falhas.length ? 12000 : 6000);
      });
    })
    .catch((e) => toast(t('Não foi possível ler o Excel.') + ' ' + (e && e.message ? e.message : ''), 8000));
}

// ---- Publicar ----
function publicarPlano() {
  const d = PC.resp,
    v = validarPlano(PC.rotas),
    dest = d.listas.destinatarios;
  if (v.erros.length || !dest.length) {
    janela(t('Ainda não pode publicar'), t('Corrija estes pontos:'), (b, ft, fechar) => {
      const ul = el('ul', 'pc-lista');
      v.erros.forEach((x) => ul.append(el('li', null, (x.rota ? t('Rota') + ' ' + x.rota + ': ' : '') + x.msg)));
      if (!dest.length) ul.append(el('li', null, t('A lista de destinatários está vazia (Listas → Destinatários).')));
      b.append(ul);
      ft.append(botao(t('Fechar'), '', fechar));
    });
    return;
  }
  const versao = ((d.plano && d.plano.publicado && d.plano.publicado.versao) || 0) + 1;
  const saem = PC.rotas.filter((r) => r.sai);
  janela(
    versao > 1 ? t('Publicar rectificação') : t('Publicar e enviar'),
    t('Plano de {d}: {n} rotas, {c} caixas. Vai por email, com o Excel em anexo, para:', {
      d: dl(PC.data),
      n: saem.length,
      c: n0(saem.reduce((s, r) => s + somaQ(r), 0)),
    }),
    (b, ft, fechar) => {
      const ul = el('ul', 'pc-lista');
      dest.forEach((x) => ul.append(el('li', null, (x.nome ? x.nome + ' · ' : '') + x.email)));
      b.append(ul);
      if (v.avisos.length)
        b.append(el('p', 'aviso', t('{n} avisos (por exemplo, falta de número de factura). Pode publicar na mesma.', { n: v.avisos.length })));
      if (!d.email_configurado)
        b.append(
          el('p', 'aviso', t('O envio de email ainda não está configurado no servidor: o plano fica publicado e o Excel disponível, e pode reenviar depois.')),
        );
      const nota = entrada('text', '', { maxLength: 300, placeholder: versao > 1 ? t('O que mudou (aparece no email)') : t('Opcional') });
      b.append(campo(versao > 1 ? t('Nota da rectificação') : t('Nota para os destinatários'), nota));
      const erro = msgErro();
      b.append(erro);
      const go = botao(versao > 1 ? t('Publicar rectificação') : t('Publicar e enviar'), '', () => {
        go.disabled = true;
        go.textContent = t('A publicar…');
        guardarPlano()
          .then(() => gerarExcelPlano(PC.rotas, PC.data, versao, d.listas.artigos))
          .then((buf) =>
            postPlano({ accao: 'publicar', data: PC.data, rev: d.plano ? d.plano.rev : 0, rotas: PC.rotas, xlsx: b64(buf), nota: nota.value.trim() }),
          )
          .then((r) => {
            go.disabled = false;
            go.textContent = versao > 1 ? t('Publicar rectificação') : t('Publicar e enviar');
            if (!r.ok) {
              erro.textContent = t(r.erro || 'Não foi possível publicar.') + (r.erros ? ' ' + r.erros.join(' ') : '');
              erro.hidden = false;
              return;
            }
            PC.resp.plano = r.plano;
            if (r.listas) PC.resp.listas = r.listas;
            PC.sujo = false;
            PC.hist = null;
            fechar();
            draw();
            toast(
              r.email && r.email.ok
                ? t('Plano publicado e enviado a {n} destinatários.', { n: r.email.para.length })
                : t('Plano publicado, mas o email não seguiu: {e}', { e: r.email ? r.email.erro : '' }),
              9000,
            );
          })
          .catch(() => {
            go.disabled = false;
            erro.textContent = t('Não foi possível publicar.');
            erro.hidden = false;
          });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
    },
  );
}

// ---- Listas (destinatários, motoristas, zonas, ...) ----
function janelaListas() {
  const L = JSON.parse(JSON.stringify(PC.resp.listas));
  janela(
    t('Listas do plano de cargas'),
    t('Usadas no preenchimento. Motoristas, zonas e viaturas novos entram sozinhos quando o plano é publicado.'),
    (b, ft, fechar, dlg) => {
      dlg.classList.add('largo');
      const seg = el('div', 'seg'),
        corpo = el('div', 'pc-listas');
      const linhas = (arr, cols, novo) => {
        const w = el('div');
        const desenhar = () => {
          w.replaceChildren();
          arr.forEach((x, i) => {
            const l = el('div', 'pc-linha');
            cols.forEach(([k, ph, tipo]) => {
              const inp = entrada(tipo || 'text', x[k] || '', { placeholder: ph });
              inp.addEventListener('input', () => {
                x[k] = inp.value;
              });
              l.append(inp);
            });
            const rm = botao('×', 'ghost sm', () => {
              arr.splice(i, 1);
              desenhar();
            });
            rm.setAttribute('aria-label', t('Retirar'));
            l.append(rm);
            w.append(l);
          });
          w.append(
            botao(t('Acrescentar'), 'ghost sm', () => {
              arr.push(novo());
              desenhar();
              const ins = w.querySelectorAll('.pc-linha');
              const u = ins[ins.length - 1];
              if (u) u.querySelector('input').focus();
            }),
          );
        };
        desenhar();
        return w;
      };
      const area = (k) => {
        const ta = el('textarea', 'pc-ta');
        ta.value = L[k].join('\n');
        ta.rows = 12;
        ta.addEventListener('input', () => {
          L[k] = ta.value
            .split('\n')
            .map((s) => s.trim())
            .filter(Boolean);
        });
        return ta;
      };
      const abas = [
        [
          'destinatarios',
          t('Destinatários'),
          () => {
            const w = el('div');
            w.append(
              el('p', 'nota-dlg', t('Recebem o plano por email sempre que é publicado ou rectificado.')),
              linhas(
                L.destinatarios,
                [
                  ['nome', t('Nome')],
                  ['email', 'email@naval.co.ao', 'email'],
                ],
                () => ({ nome: '', email: '' }),
              ),
            );
            const te = botao(t('Enviar email de teste para mim'), 'ghost sm', () => {
              te.disabled = true;
              postPlano({ accao: 'teste_email' }).then((r) => {
                te.disabled = false;
                toast(r.ok ? t('Email de teste enviado para {e}.', { e: r.para }) : t(r.erro || 'Não foi possível enviar.'), 9000);
              });
            });
            w.append(te);
            return w;
          },
        ],
        [
          'motoristas',
          t('Motoristas'),
          () =>
            linhas(
              L.motoristas,
              [
                ['nome', t('Nome')],
                ['telefone', t('Telefone'), 'tel'],
                ['matricula', t('Matrícula')],
                ['viatura', t('Viatura')],
                ['transportadora', t('Transportadora')],
              ],
              () => ({ nome: '', telefone: '', matricula: '', viatura: '', transportadora: '' }),
            ),
        ],
        [
          'zonas',
          t('Zonas'),
          () => {
            const w = el('div');
            w.append(el('p', 'nota-dlg', t('Uma zona por linha.')), area('zonas'));
            return w;
          },
        ],
        [
          'outras',
          t('Armazéns e viaturas'),
          () => {
            const w = el('div', 'campos2');
            w.append(
              campo(t('Armazéns de carga (um por linha)'), area('armazens')),
              campo(t('Tipos de viatura'), area('viaturas')),
              campo(t('Transportadoras'), area('transportadoras')),
            );
            return w;
          },
        ],
        [
          'artigos',
          t('Artigos'),
          () => {
            const w = el('div');
            w.append(
              el('p', 'nota-dlg', t('Artigos carregados nas carrinhas, pela ordem do Excel.')),
              linhas(L.artigos, [['nome', t('Artigo')]], () => ({ id: '', nome: '' })),
            );
            return w;
          },
        ],
      ];
      let aba = 'destinatarios';
      const mostrar = () => {
        seg.replaceChildren();
        abas.forEach(([k, nome]) => {
          const x = el('button', k === aba ? 'on' : null, nome);
          x.type = 'button';
          x.addEventListener('click', () => {
            aba = k;
            mostrar();
          });
          seg.append(x);
        });
        corpo.replaceChildren(abas.find((a) => a[0] === aba)[2]());
      };
      mostrar();
      b.append(seg, corpo);
      const erro = msgErro();
      b.append(erro);
      const gd = botao(t('Guardar listas'), '', () => {
        gd.disabled = true;
        L.artigos.forEach((a) => {
          if (!a.id) a.id = chaveTxt(a.nome).toLowerCase().replace(/ /g, '');
        });
        postPlano({ accao: 'listas', listas: L }).then((r) => {
          gd.disabled = false;
          if (!r.ok) {
            erro.textContent = t(r.erro || 'Não foi possível guardar.');
            erro.hidden = false;
            return;
          }
          PC.resp.listas = r.listas;
          PC.rotas = montarRotasPlano(PC.resp, PC.rotas);
          fechar();
          draw();
          toast(t('Listas guardadas.'), 4000);
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), gd);
    },
  );
}

// ---- Página ----
let pcUI = null; // elementos actualizados sem redesenhar (estado, resumo, avisos de cada rota)
function actualizarPlanoUI() {
  if (!pcUI || !pcUI.estado.isConnected) return;
  const d = PC.resp,
    p = d.plano,
    est = estadoPlano();
  const NOMES = { novo: 'Por preencher', rascunho: 'Rascunho', publicado: 'Publicado', alterado: 'Alterações por publicar' };
  const cls = { novo: 'p-warn', rascunho: 'p-warn', publicado: 'p-ok', alterado: 'p-warn' };
  const linha = [];
  if (p && p.publicado)
    linha.push(
      t('Versão {v} publicada por {p} às {h} de {d}', {
        v: p.publicado.versao,
        p: p.publicado.por_nome || p.publicado.por,
        h: p.publicado.em.slice(11, 16),
        d: dl(p.publicado.em),
      }),
    );
  if (d.gere)
    linha.push(
      PC.gravando
        ? t('A guardar…')
        : PC.sujo
          ? t('Alterações por guardar')
          : PC.guardadoEm
            ? t('Guardado às {h}', { h: PC.guardadoEm.toTimeString().slice(0, 5) })
            : p && p.alterado
              ? t('Última alteração às {h} de {d}', { h: p.alterado.slice(11, 16), d: dl(p.alterado) })
              : '',
    );
  pcUI.estado.replaceChildren(pill(t(NOMES[est]), cls[est]), el('span', 'pc-estado-t', linha.filter(Boolean).join(' · ')));
  if (pcUI.publicar) {
    pcUI.publicar.textContent = p && p.publicado ? t('Publicar rectificação') : t('Publicar e enviar');
    pcUI.publicar.disabled = est === 'publicado' || PC.gravando;
  }
  const v = validarPlano(PC.rotas),
    saem = PC.rotas.filter((r) => r.sai),
    arts = d.listas.artigos;
  pcUI.resumo.replaceChildren(
    ...[
      [t('Rotas a sair'), n0(saem.length) + ' / ' + n0(PC.rotas.length)],
      ...arts.map((a) => [t(a.nome), n0(saem.reduce((s, r) => s + (+r.quantidades[a.id] || 0), 0))]),
      [t('Total de caixas'), n0(saem.reduce((s, r) => s + somaQ(r), 0))],
      ...(d.gere ? [[t('Por corrigir'), n0(v.erros.length), v.erros.length ? 'bad' : null]] : []),
    ].map(([l, val, tone]) => {
      const x = el('div', 'glass tile');
      x.append(el('span', 'l', l));
      const vv = el('span', 'v', val);
      if (tone) vv.style.color = `var(--${tone})`;
      x.append(vv);
      return x;
    }),
  );
  Object.entries(pcUI.cartoes).forEach(([rota, c]) => {
    const r = PC.rotas.find((x) => x.rota === rota);
    c.el.classList.toggle('fora', !r.sai);
    c.corpo.hidden = !r.sai;
    c.total.textContent = r.sai ? t('{n} cx', { n: n0(somaQ(r)) }) : t('Não sai');
    const e = v.erros.filter((x) => x.rota === rota).map((x) => x.msg),
      a = v.avisos.filter((x) => x.rota === rota).map((x) => x.msg);
    c.av.replaceChildren(...e.map((m) => el('span', 'pc-err', m)), ...a.map((m) => el('span', 'pc-av', m)));
    c.av.hidden = !r.sai || !(e.length + a.length);
  });
}
function cartaoRota(r, d, dls) {
  const c = el('section', 'glass pc-card'),
    cab = el('div', 'pc-cab');
  const sai = entrada('checkbox');
  sai.checked = r.sai;
  sai.setAttribute('aria-label', t('A rota {r} sai neste dia', { r: r.rota }));
  const tit = el('label', 'pc-tit');
  tit.append(sai, el('b', null, r.rota), el('span', null, r.vendedor || ''));
  const total = el('span', 'pc-total');
  cab.append(tit, total);
  c.append(cab);
  const corpo = el('div', 'pc-corpo'),
    av = el('div', 'pc-avs');
  sai.addEventListener('change', () => {
    r.sai = sai.checked;
    marcarSujo();
  });
  const inp = (k, rot, attrs, lista) => {
    const i = entrada((attrs && attrs.type) || 'text', r[k], attrs);
    if (lista) i.setAttribute('list', lista);
    i.addEventListener('input', () => {
      r[k] = i.type === 'number' ? +i.value || 0 : i.value;
      marcarSujo();
    });
    return [campo(rot, i), i];
  };
  const [fMot, iMot] = inp('motorista', t('Motorista'), { autocomplete: 'off' }, dls.mot);
  const [fTel, iTel] = inp('telefone', t('Telefone'), { type: 'tel', inputMode: 'tel' });
  const [fMat, iMat] = inp('matricula', t('Matrícula'), { autocomplete: 'off' });
  const [fVia, iVia] = inp('viatura', t('Viatura'), { autocomplete: 'off' }, dls.via);
  const [fTra, iTra] = inp('transportadora', t('Transportadora'), { autocomplete: 'off' }, dls.tra);
  iMot.addEventListener('change', () => {
    const m = d.listas.motoristas.find((x) => chaveTxt(x.nome) === chaveTxt(iMot.value));
    if (!m) return;
    r.motorista = iMot.value = m.nome;
    [
      [iTel, 'telefone'],
      [iMat, 'matricula'],
      [iVia, 'viatura'],
      [iTra, 'transportadora'],
    ].forEach(([i, k]) => {
      if (m[k]) {
        r[k] = i.value = m[k];
      }
    });
    marcarSujo();
  });
  iMat.addEventListener('blur', () => {
    iMat.value = r.matricula = iMat.value.toUpperCase().trim();
  });
  const [fArm] = inp('armazem', t('Armazém de carga'), { autocomplete: 'off' }, dls.arm);
  const [fVg] = inp('viagens', t('Viagens'), { type: 'number', min: 0, max: 20, inputMode: 'numeric' });
  const [fFac] = inp('factura', t('N.º da factura'));
  const [fObs] = inp('obs', t('Observações'));
  const qs = el('div', 'pc-qs');
  d.listas.artigos.forEach((a) => {
    const i = entrada('number', r.quantidades[a.id] || '', { min: 0, inputMode: 'numeric', placeholder: '0' });
    i.addEventListener('input', () => {
      r.quantidades[a.id] = Math.max(0, Math.round(+i.value || 0));
      marcarSujo();
    });
    qs.append(campo(t(a.nome), i));
  });
  qs.append(fVg);
  const linha = (...xs) => {
    const w = el('div', 'campos2');
    w.append(...xs);
    return w;
  };
  const l3 = el('div', 'campos3');
  l3.append(fMat, fVia, fTra);
  const zonas = el('div', 'campo');
  zonas.append(el('span', null, t('Zonas de descarga')), editorZonas(r, d, dls.zon));
  corpo.append(linha(fMot, fTel), l3, zonas, qs, linha(fArm, fFac), fObs);
  c.append(corpo, av);
  return { el: c, corpo, total, av };
}
function editorZonas(r, d, dl2) {
  const w = el('div', 'pc-zonas'),
    chips = el('div', 'pc-chips');
  const inp = entrada('text', '', { placeholder: t('Acrescentar zona…'), autocomplete: 'off' });
  inp.setAttribute('list', dl2);
  const desenhar = () => {
    chips.replaceChildren(
      ...r.zonas.map((z, i) => {
        const ch = el('span', 'pc-chip', z),
          x = el('button', null, '×');
        x.type = 'button';
        x.setAttribute('aria-label', t('Retirar {z}', { z }));
        x.addEventListener('click', () => {
          r.zonas.splice(i, 1);
          desenhar();
          marcarSujo();
        });
        ch.append(x);
        return ch;
      }),
    );
  };
  const juntar = () => {
    inp.value
      .split(/[,;]/)
      .map((s) => s.trim())
      .filter(Boolean)
      .forEach((z) => {
        const ex = d.listas.zonas.find((x) => chaveTxt(x) === chaveTxt(z)),
          v = ex || z;
        if (!r.zonas.some((x) => chaveTxt(x) === chaveTxt(v))) r.zonas.push(v);
      });
    inp.value = '';
    desenhar();
    marcarSujo();
  };
  inp.addEventListener('keydown', (e) => {
    if ((e.key === 'Enter' || e.key === ',') && inp.value.trim()) {
      e.preventDefault();
      juntar();
    } else if (e.key === 'Backspace' && !inp.value && r.zonas.length) {
      r.zonas.pop();
      desenhar();
      marcarSujo();
    }
  });
  inp.addEventListener('input', (e) => {
    if (!e.inputType && d.listas.zonas.includes(inp.value)) juntar();
  }); // escolhido na lista
  inp.addEventListener('blur', () => {
    if (inp.value.trim()) juntar();
  });
  desenhar();
  w.append(chips, inp);
  return w;
}
R['plano-cargas'] = (pg) => {
  if (!PC.data) PC.data = hoje();
  if (!PC.resp && !PC.erro) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregarPlano(PC.data).then(() => {
      if (atual === 'plano-cargas') draw();
    });
    return;
  }
  if (PC.erro) {
    pg.append(secBox(t('Plano de cargas'), '', el('p', null, PC.erro)));
    PC.erro = null;
    return;
  }
  const d = PC.resp,
    gere = d.gere,
    p = d.plano;
  // barra: dia, estado e acções
  const barra = el('section', 'glass sec pc-barra fixo'),
    dia = el('div', 'pc-dia');
  const ant = botao('‹', 'ghost sm', () => mudarDiaPlano(somaDias(PC.data, -1)));
  ant.setAttribute('aria-label', t('Dia anterior'));
  const seg = botao('›', 'ghost sm', () => mudarDiaPlano(somaDias(PC.data, 1)));
  seg.setAttribute('aria-label', t('Dia seguinte'));
  const di = entrada('date', PC.data);
  di.setAttribute('aria-label', t('Dia do plano'));
  di.addEventListener('change', () => mudarDiaPlano(di.value));
  const lbl = el('b', 'pc-dia-t', ds(PC.data));
  dia.append(ant, lbl, di, seg);
  if (PC.data !== hoje()) dia.append(botao(t('Hoje'), 'ghost sm', () => mudarDiaPlano(hoje())));
  const estado = el('div', 'pc-estado'),
    acc = el('div', 'pc-acc');
  pcUI = { estado, resumo: el('div', 'tiles pc-resumo'), cartoes: {}, publicar: null };
  if (gere) {
    if (d.anterior)
      acc.append(
        botao(t('Copiar de {d}', { d: dl(d.anterior).slice(0, 5) }), 'ghost sm', () => {
          const go = () =>
            fetch('data/plano.php?data=' + d.anterior, { cache: 'no-store' })
              .then((r) => r.json())
              .then((x) => {
                const src = x.plano ? (x.plano.publicado ? x.plano.publicado.rotas : x.plano.rotas) : [];
                PC.rotas = montarRotasPlano(
                  PC.resp,
                  src.map((r) => ({ ...r, factura: '', obs: '' })),
                );
                marcarSujo();
                draw();
                toast(t('Copiado o plano de {d}. Corrija o que mudou hoje.', { d: dl(d.anterior) }), 6000);
              });
          if (PC.rotas.some((r) => r.sai))
            confirmarAccao(
              t('Copiar do dia anterior'),
              t('O que já preencheu para este dia é substituído pelo plano de {d}.', { d: dl(d.anterior) }),
              t('Copiar'),
              () => go().then(() => ({ ok: true })),
            );
          else go();
        }),
      );
    const fi = el('input');
    fi.type = 'file';
    fi.accept = '.xlsx';
    fi.hidden = true;
    fi.addEventListener('change', () => {
      if (fi.files[0]) importarExcelPlano(fi.files[0]);
      fi.value = '';
    });
    acc.append(
      fi,
      botao(t('Importar Excel'), 'ghost sm', () => fi.click()),
      botao(t('Listas'), 'ghost sm', janelaListas),
    );
  }
  if (p && p.publicado && d.exportar) {
    const a = el('a', 'btn ghost sm', t('Excel enviado'));
    a.href = 'data/plano.php?xlsx=' + PC.data;
    acc.append(a);
  }
  if (gere) {
    pcUI.publicar = botao(t('Publicar e enviar'), 'sm', publicarPlano);
    acc.append(pcUI.publicar);
  }
  barra.append(dia, estado, acc);
  pg.append(barra);
  // email que não seguiu
  const env = gere && p && p.envios && p.envios.length ? p.envios[p.envios.length - 1] : null;
  if (env && !env.ok) {
    const box = el('div', 'glass sec pc-alerta fixo');
    box.append(el('p', null, t('O email da versão {v} não seguiu: {e}', { v: env.versao, e: env.erro })));
    const rb = botao(t('Reenviar'), 'sm', () => {
      rb.disabled = true;
      postPlano({ accao: 'reenviar', data: PC.data }).then((r) => {
        rb.disabled = false;
        if (r.plano) PC.resp.plano = r.plano;
        toast(r.ok ? t('Email reenviado.') : t(r.erro || 'Não foi possível enviar.'), 8000);
        draw();
      });
    });
    box.append(rb);
    pg.append(box);
  } else if (gere && !d.email_configurado)
    pg.append(
      el(
        'p',
        'note',
        t(
          'O envio de email ainda não está configurado no servidor. Pode preencher e publicar; o email segue quando a configuração estiver feita (botão Reenviar).',
        ),
      ),
    );
  pg.append(pcUI.resumo);
  if (gere) {
    // listas para escolher
    const dls = { mot: 'pc-dl-mot', via: 'pc-dl-via', tra: 'pc-dl-tra', arm: 'pc-dl-arm', zon: 'pc-dl-zon' };
    [
      [dls.mot, d.listas.motoristas.map((m) => m.nome)],
      [dls.via, d.listas.viaturas],
      [dls.tra, d.listas.transportadoras],
      [dls.arm, d.listas.armazens],
      [dls.zon, d.listas.zonas],
    ].forEach(([id, vals]) => {
      const x = el('datalist');
      x.id = id;
      vals.forEach((v) => {
        const o = el('option');
        o.value = v;
        x.append(o);
      });
      pg.append(x);
    });
    const grelha = el('div', 'pc-grid');
    PC.rotas.forEach((r) => {
      const c = cartaoRota(r, d, dls);
      pcUI.cartoes[r.rota] = c;
      grelha.append(c.el);
    });
    pg.append(grelha);
  } else {
    const saem = PC.rotas.filter((r) => r.sai);
    if (!p || !p.publicado) pg.append(secBox(t('Plano de cargas'), '', el('p', null, t('O plano deste dia ainda não foi publicado pela Logística.'))));
    else
      pg.append(
        tabela({
          id: 'pc-t',
          titulo: t('Plano de {d}', { d: dl(PC.data) }),
          nota: t('Versão {v} publicada por {p}.', { v: p.publicado.versao, p: p.publicado.por_nome || p.publicado.por }),
          sort: 'rota',
          dir: 1,
          total: true,
          rows: saem.map((r) => ({
            ...r,
            chave: r.rota,
            zonas_t: r.zonas.join(', '),
            total: somaQ(r),
            ...Object.fromEntries(d.listas.artigos.map((a) => ['q_' + a.id, +r.quantidades[a.id] || 0])),
          })),
          cols: [
            { key: 'rota', label: t('Rota'), p: 0 },
            { key: 'vendedor', label: t('Vendedor') },
            { key: 'motorista', label: t('Motorista') },
            { key: 'telefone', label: t('Telefone') },
            { key: 'matricula', label: t('Matrícula') },
            { key: 'zonas_t', label: t('Zonas'), wrap: true },
            ...d.listas.artigos.map((a) => ({ key: 'q_' + a.id, label: t(a.nome), type: 'n', sum: true })),
            { key: 'total', label: t('Total'), type: 'n', sum: true },
            { key: 'factura', label: t('Factura') },
          ],
        }),
      );
  }
  // histórico
  if (!PC.hist)
    fetch('data/plano.php?historico=1', { cache: 'no-store' })
      .then((r) => r.json())
      .then((h) => {
        PC.hist = h.dias || [];
        if (atual === 'plano-cargas') pg.append(historicoPlano());
      })
      .catch(() => {});
  else pg.append(historicoPlano());
  actualizarPlanoUI();
};
function historicoPlano() {
  const NOMES = { rascunho: 'Rascunho', publicado: 'Publicado', alterado: 'Alterações por publicar' };
  return tabela({
    id: 'pc-h',
    titulo: t('Dias anteriores'),
    nota: t('Planos guardados e publicados. Clique no dia para o abrir.'),
    sort: 'data',
    total: false,
    search: false,
    rows: PC.hist.map((x) => ({
      ...x,
      chave: x.data,
      estado_t: t(NOMES[x.estado] || x.estado),
      email_t: x.email === 'enviado' ? t('Enviado') : x.email === 'falhou' ? t('Falhou') : '—',
    })),
    cols: [
      {
        key: 'data',
        label: t('Dia'),
        p: 0,
        render: (v) => {
          const b = el('button', 'linkbtn', ds(v));
          b.type = 'button';
          b.addEventListener('click', () => {
            mudarDiaPlano(v);
            scrollTo(0, 0);
          });
          return b;
        },
        xl: (r) => dl(r.data),
      },
      { key: 'estado_t', label: t('Estado') },
      { key: 'versao', label: t('Versão'), type: 'n' },
      { key: 'rotas', label: t('Rotas'), type: 'n' },
      { key: 'carga', label: t('Caixas'), type: 'n' },
      {
        key: 'publicado_por',
        label: t('Publicado por'),
        render: (v, r) => document.createTextNode(v ? v + ' · ' + dl(r.publicado_em) + ' ' + r.publicado_em.slice(11, 16) : '—'),
      },
      { key: 'email_t', label: t('Email') },
    ],
  });
}
