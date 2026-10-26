// Terreno: plano de visitas e histórico de auditoria.
import { diaSemana, isCF, nomeDia, ocorrencias, pendenteDe, promotorRota, recebidoDe, vendasR, visitasPlaneadas } from '../calculos.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { mkz, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { inR } from '../periodo.js';
import { DB, group, sum } from '../util.js';

R.auditoria = (pg) => {
  const V = vendasR(),
    O = ocorrencias(V),
    docsCom = new Set(O.map((o) => o.x.chave || o.x.documento));
  const cnt = (tp) => O.filter((o) => o.tipo === tp),
    dup = cnt('dup'),
    pend = cnt('pend'),
    meio = cnt('meio');
  const VP = visitasPlaneadas(),
    falt = VP.filter((v) => !v.visitado);
  pg.append(
    tiles([
      { l: t('Documentos verificados'), v: n0(V.length), s: t('{n} com ocorrências', { n: n0(docsCom.size) }), tone: docsCom.size ? 'warn' : null },
      { l: t('Possíveis duplicados'), v: n0(dup.length), s: mkz(sum(dup, (o) => o.x.total)), tone: dup.length ? 'warn' : null },
      { l: t('Pendentes há mais de 3 dias'), v: n0(pend.length), s: mkz(sum(pend, (o) => pendenteDe(o.x))), tone: pend.length ? 'warn' : null },
      { l: t('Cobrados sem meio indicado'), v: n0(meio.length), s: mkz(sum(meio, (o) => recebidoDe(o.x))), tone: meio.length ? 'warn' : null },
      {
        l: t('Visitas marcadas não realizadas'),
        v: n0(falt.length),
        s: t('{p} das visitas marcadas', { p: pct(VP.length ? falt.length / VP.length : null) }),
        tone: falt.length ? 'warn' : null,
      },
    ]),
  );
  const gV = group(V, 'data'),
    gVP = group(VP, 'data');
  const porDia = [...new Set([...Object.keys(gV), ...Object.keys(gVP)])].map((d) => {
    const rs = gV[d] || [],
      od = O.filter((o) => o.x.data === d),
      n = (tp) => od.filter((o) => o.tipo === tp).length;
    const vp = gVP[d] || [],
      nv = vp.filter((v) => !v.visitado).length;
    const com = new Set(od.map((o) => o.x.chave || o.x.documento)).size + nv;
    return {
      chave: d,
      data: d,
      docs: rs.length,
      total: sum(rs, 'total'),
      dup: n('dup'),
      pend: n('pend'),
      meio: n('meio'),
      marcadas: vp.length,
      nao_visitadas: nv,
      com,
      estado: com ? t('A rever') : t('Sem ocorrências'),
    };
  });
  pg.append(
    tabela({
      id: 'au-dia',
      titulo: t('Histórico por dia'),
      nota: t('Verificações automáticas a todos os documentos do Pinguim BO no período.'),
      rows: porDia,
      sort: 'data',
      total: true,
      search: false,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'dup', label: t('Possíveis duplicados'), type: 'n', sum: true },
        { key: 'pend', label: t('Pendentes há mais de 3 dias'), type: 'n', sum: true },
        { key: 'meio', label: t('Cobrados sem meio indicado'), type: 'n', sum: true },
        { key: 'marcadas', label: t('Visitas marcadas'), type: 'n', sum: true },
        { key: 'nao_visitadas', label: t('Visitas não realizadas'), type: 'n', sum: true },
        { key: 'estado', label: t('Estado'), render: (v, r) => pill(v, r.com ? 'p-warn' : 'p-ok'), xl: (r) => r.estado },
      ],
    }),
  );
  const porProm = Object.values(group(VP, 'rota')).map((vs) => {
    const nv = vs.filter((v) => !v.visitado).length;
    return {
      chave: vs[0].rota,
      promotor: promotorRota(vs[0].rota),
      marcadas: vs.length,
      visitadas: vs.length - nv,
      nao_visitadas: nv,
      cumprimento: vs.length ? (vs.length - nv) / vs.length : null,
    };
  });
  pg.append(
    tabela({
      id: 'au-vis',
      titulo: t('Visitas marcadas por promotor'),
      nota: t('Visita realizada = documento do Pinguim BO para o cliente no dia marcado, na mesma rota. Só contam dias já terminados.'),
      rows: porProm,
      sort: 'nao_visitadas',
      total: true,
      search: false,
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'marcadas', label: t('Visitas marcadas'), type: 'n', sum: true },
        { key: 'visitadas', label: t('Realizadas'), type: 'n', sum: true },
        { key: 'nao_visitadas', label: t('Visitas não realizadas'), type: 'n', sum: true },
        {
          key: 'cumprimento',
          label: t('Cumprimento'),
          type: 'pct',
          sum: (rs) => {
            const a = sum(rs, 'marcadas');
            return a ? sum(rs, 'visitadas') / a : null;
          },
          render: (v) => (v == null ? pill('—', 'p-mut') : pill(pct(v), v < 0.5 ? 'p-bad' : v < 0.8 ? 'p-warn' : 'p-ok')),
        },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'au-oc',
      titulo: t('Ocorrências'),
      nota: t('Documentos a confirmar com a área comercial ou corrigir no Pinguim BO.'),
      rows: [
        ...O.map((o, i) => ({
          chave: (o.x.chave || o.x.documento) + '|' + o.tipo + '|' + i,
          data: o.x.data,
          documento: o.x.documento,
          promotor: promotorRota(o.x.rota),
          cliente: o.x.cliente,
          total: +o.x.total || 0,
          ocorrencia: o.txt,
        })),
        ...falt.map((v) => ({
          chave: v.data + '|' + v.rota + '|' + v.codigo + '|visita',
          data: v.data,
          documento: '—',
          promotor: promotorRota(v.rota),
          cliente: v.cliente + ' (' + v.codigo + ')',
          total: 0,
          ocorrencia: t('Visita marcada não realizada (sem venda registada)'),
        })),
      ],
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'documento', label: t('Documento'), nowrap: true },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'ocorrencia', label: t('Ocorrência'), wrap: true },
      ],
    }),
  );
};

R['plano-visitas'] = (pg) => {
  const dias = [...new Set(DB.vendas.map((x) => x.data))].filter(inR).sort();
  const act = DB.clientes.filter((x) => x.estado === 'Ativo' && !isCF(x.nif));
  const rows = [];
  dias.forEach((d) => {
    const ds3 = diaSemana(d),
      V = DB.vendas.filter((x) => x.data === d);
    Object.keys(DB.mapRota).forEach((r) => {
      const plan = new Set(act.filter((x) => x.rota === r && x.dia === ds3).map((x) => x.nif));
      const vr = V.filter((x) => x.rota === r),
        comp = new Set(vr.filter((x) => !isCF(x.nif)).map((x) => x.nif));
      const ok = [...comp].filter((n) => plan.has(n)).length;
      if (!plan.size && !vr.length) return;
      rows.push({
        chave: d + '|' + r,
        data: d,
        dia: nomeDia(ds3),
        rota: r,
        promotor: promotorRota(r),
        planeados: plan.size,
        compraram: ok,
        fora: comp.size - ok,
        docs: vr.length,
        total: sum(vr, 'total'),
        cumprimento: plan.size ? ok / plan.size : null,
      });
    });
  });
  pg.append(
    tiles([
      { l: t('Clientes planeados'), v: n0(sum(rows, 'planeados')) },
      { l: t('Planeados que compraram'), v: n0(sum(rows, 'compraram')) },
      { l: t('Cumprimento'), v: pct(sum(rows, 'planeados') ? sum(rows, 'compraram') / sum(rows, 'planeados') : null), tone: 'warn' },
      { l: t('Vendas fora do plano'), v: n0(sum(rows, 'fora')), s: t('clientes de outro dia ou rota') },
    ]),
  );
  pg.append(
    tabela({
      id: 'pv',
      titulo: t('Plano de visitas por dia e rota'),
      nota: t('Planeados = clientes activos da carteira com visita nesse dia da semana. Cumprimento = planeados que compraram.'),
      rows,
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'dia', label: t('Dia da semana') },
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'planeados', label: t('Planeados'), type: 'n', sum: true },
        { key: 'compraram', label: t('Compraram'), type: 'n', sum: true },
        { key: 'fora', label: t('Fora do plano'), type: 'n', sum: true },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        {
          key: 'cumprimento',
          label: t('Cumprimento'),
          type: 'pct',
          sum: (rs) => {
            const a = sum(rs, 'planeados');
            return a ? sum(rs, 'compraram') / a : null;
          },
          render: (v) => (v == null ? pill('—', 'p-mut') : pill(pct(v), v < 0.2 ? 'p-bad' : v < 0.5 ? 'p-warn' : 'p-ok')),
        },
      ],
    }),
  );
};
