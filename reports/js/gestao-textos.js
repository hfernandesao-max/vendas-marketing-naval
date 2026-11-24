// Textos da área Gestão partilhados pelo portal (paginas/gestao.js) e pela app de gestão (gestao/app.js): alertas na língua
// escolhida (a partir dos parâmetros do servidor), variações em percentagem e respostas do assistente (Markdown simples
// transformado em elementos seguros, nunca HTML).
import { dl, kz, n0 } from './formatos.js';
import { t } from './i18n/i18n.js';
import { el } from './util.js';

/** Variação com sinal (+12%, −35%). */
export const sinal = (v) => (v == null ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + n0(Math.abs(Math.round(v * 100))) + '%');

const DIA_SEMANA = ['', 'segundas-feiras', 'terças-feiras', 'quartas-feiras', 'quintas-feiras', 'sextas-feiras', 'sábados', 'domingos'];
/** Título e detalhe do alerta na língua do portal (os parâmetros vêm do servidor). */
export function textoAlerta(a) {
  const p = a.p || {};
  switch (a.tipo) {
    case 'rota-sem-vendas':
      return [
        t('Rota {r} sem vendas em {d}', { r: p.nome, d: dl(p.dia) }),
        t('Vendeu em {c} de {n} {s} anteriores (mediana {v}). Confirmar se a rota saiu e se o equipamento sincronizou.', {
          c: p.com,
          n: p.n,
          s: t(DIA_SEMANA[p.ds] || ''),
          v: kz(p.esperado),
        }),
      ];
    case 'queda-dia':
      return [
        t('Rota {r}: vendas de {d} {p} face ao habitual', { r: p.nome, d: dl(p.dia), p: sinal(p.var) }),
        t('{v} contra uma mediana de {m} em {n} {s} anteriores.', { v: kz(p.real), m: kz(p.esperado), n: p.n, s: t(DIA_SEMANA[p.ds] || '') }),
      ];
    case 'queda-semana':
    case 'subida-semana':
      return [
        t('Rota {r}: semana {p} face às 4 anteriores', { r: p.nome, p: sinal(p.var) }),
        t('{v} de {i} a {f}, contra uma média semanal de {m}.', { v: kz(p.semana), i: dl(p.de), f: dl(p.dia), m: kz(p.media) }),
      ];
    case 'cliente-parado':
      return [
        t('{c} (rota {r}) sem compras há {n} dias', { c: p.nome, r: p.rota, n: p.sem }),
        t('Comprava em média a cada {h} dias ({k} compras, {v} no período analisado). Última compra: {d}.', {
          h: p.habitual,
          k: p.n,
          v: kz(p.t),
          d: dl(p.ultimo),
        }),
      ];
    case 'agt-por-comunicar':
      return [
        t('{n} documentos da rota {r} por comunicar à AGT', { n: p.n, r: p.rota }),
        t('O mais antigo é de {d} ({k} dias); total {v}.', { d: dl(p.data), k: p.antigo, v: kz(p.t) }),
      ];
    case 'agt-erro':
      return [
        t('{n} documentos da rota {r} recusados pela AGT', { n: p.n, r: p.rota }),
        t('O mais antigo é de {d}; total {v}.', { d: dl(p.data), v: kz(p.t) }) + (p.msg ? ' ' + t('Mensagem da AGT') + ': ' + p.msg : ''),
      ];
    case 'mdm-sem-contacto':
      return [
        t('Equipamento «{e}» sem contacto com o MDM há {n} dias', { e: p.nome, n: p.dias }),
        t('Último contacto: {d}. Pode estar desligado, sem rede ou fora de uso.', { d: dl(p.quando) + ' ' + String(p.quando || '').slice(11, 16) }),
      ];
  }
  return [a.titulo, a.detalhe];
}

/** Texto da resposta (Markdown simples) em elementos seguros: parágrafos, listas, tabelas e negrito; nunca HTML. */
export function textoRico(txt) {
  const w = el('div', 'gst-txt');
  const inline = (s) => {
    const p = document.createDocumentFragment();
    String(s)
      .split(/(\*\*[^*]+\*\*)/g)
      .forEach((x) => p.append(/^\*\*[^*]+\*\*$/.test(x) ? el('strong', null, x.slice(2, -2)) : document.createTextNode(x)));
    return p;
  };
  const linhas = String(txt || '').split('\n');
  for (let i = 0; i < linhas.length; i++) {
    const l = linhas[i].trim();
    if (!l) continue;
    if (/^\|.*\|$/.test(l)) {
      const tab = el('table'),
        tb = el('tbody');
      let primeira = true;
      for (; i < linhas.length && /^\|.*\|$/.test(linhas[i].trim()); i++) {
        const cel = linhas[i].trim().slice(1, -1).split('|');
        if (cel.every((c) => /^\s*:?-{2,}:?\s*$/.test(c))) continue;
        const tr = el('tr');
        cel.forEach((c) => {
          const td = el(primeira ? 'th' : 'td');
          td.append(inline(c.trim()));
          tr.append(td);
        });
        (primeira ? tab.createTHead() : tb).append(tr);
        primeira = false;
      }
      i--;
      tab.append(tb);
      const tw = el('div', 'tw');
      tw.append(tab);
      w.append(tw);
    } else if (/^([-*•]|\d+\.)\s+/.test(l)) {
      const ul = el(/^\d+\./.test(l) ? 'ol' : 'ul');
      for (; i < linhas.length && /^([-*•]|\d+\.)\s+/.test(linhas[i].trim()); i++) {
        const li = el('li');
        li.append(inline(linhas[i].trim().replace(/^([-*•]|\d+\.)\s+/, '')));
        ul.append(li);
      }
      i--;
      w.append(ul);
    } else if (/^#{1,4}\s+/.test(l)) {
      const h = el('h4');
      h.append(inline(l.replace(/^#+\s+/, '')));
      w.append(h);
    } else {
      const p = el('p');
      p.append(inline(l));
      w.append(p);
    }
  }
  return w;
}

/** Valor arredondado ao kwanza (listas curtas). */
const kzI = (v) => (v == null || isNaN(v) ? '—' : n0(Math.round(v)) + ' Kz');
/** Nome de quem fez o passo (com a conta), a partir dos nomes que o servidor envia. */
const quem = (login, nomes) => (login ? (login === 'automatico' ? t('automático') : (nomes[login] || login) + (nomes[login] ? ' (' + login + ')' : '')) : '');

// Como as caixas de tarefas das apps de referência (SAP Fiori «My Inbox», Microsoft Approvals): só o que esta pessoa
// pode fazer agora (nível e separação de funções decididos no servidor), os atrasados primeiro. Aprovar faz-se no
// portal, com a verificação em dois passos e a assinatura registada (ISO/IEC 27001 A.5.3 e A.8.5).
export function textoPendente(x, nomes) {
  const d = dl(x.dia);
  const por = x.por ? quem(x.por, nomes) : '';
  if (x.tipo === 'caixa')
    return [
      x.nivel === 1 ? t('Fecho de caixa de {d}: aprovar (1.º nível)', { d }) : t('Fecho de caixa de {d}: aprovação final', { d }),
      [
        por ? (x.apos === 'verificada' ? t('aprovado no 1.º nível por {n}', { n: por }) : t('validado por {n}', { n: por })) : '',
        x.diferenca ? t('diferença {v}', { v: kzI(x.diferenca) }) : '',
        x.depositos ? t('primeiro os depósitos ({n})', { n: x.depositos }) : '',
      ]
        .filter(Boolean)
        .join(' · '),
    ];
  if (x.tipo === 'po')
    return [
      t('PO {n}: aprovar para a carga', { n: x.numero }),
      [
        t('rota {r}', { r: x.rota }),
        x.promotor,
        t('{a} artigos · {q} unidades', { a: n0(x.artigos), q: n0(x.quantidade) }),
        por ? t('emitida por {n}', { n: por }) : '',
      ]
        .filter(Boolean)
        .join(' · '),
    ];
  if (x.tipo === 'po_conferir' || x.tipo === 'po_validar')
    return [
      x.tipo === 'po_conferir' ? t('PO {n}: conferir a carga no cais', { n: x.numero }) : t('PO {n}: validar a carga conferida', { n: x.numero }),
      [
        t('rota {r}', { r: x.rota }),
        x.promotor,
        t('{a} artigos · {q} unidades', { a: n0(x.artigos), q: n0(x.quantidade) }),
        por ? (x.tipo === 'po_conferir' ? t('aprovada por {n}', { n: por }) : t('conferida por {n}', { n: por })) : '',
      ]
        .filter(Boolean)
        .join(' · '),
    ];
  if (x.tipo === 'sap')
    return [
      t('Ficheiro para o SAP, lote {n}: confirmar o carregamento', { n: x.numero }),
      [x.de && x.de !== x.dia ? dl(x.de) + ' – ' + d : d, t('{n} documentos', { n: n0(x.documentos) }), por ? t('emitido por {n}', { n: por }) : '']
        .filter(Boolean)
        .join(' · '),
    ];
  if (x.tipo === 'plano') return [t('Plano de cargas de {d}: confirmar e publicar', { d }), t('{n} rotas', { n: n0(x.rotas) })];
  if (x.tipo === 'entrega')
    return [
      t('Rota {r}: contar o dinheiro entregue', { r: x.rota }),
      [t('{v} declarados', { v: kzI(x.valor) }), por ? t('pelo vendedor {n}', { n: por }) : ''].filter(Boolean).join(' · '),
    ];
  if (x.tipo === 'ordem')
    return [
      {
        por_executar: t('Guia da {n}: gerar no Pinguim BO', { n: x.numero }),
        recusada: t('Guia da {n}: recusada, tratar', { n: x.numero }),
        divergente: t('Guia da {n}: diferente do pedido, corrigir', { n: x.numero }),
      }[x.estado] || x.numero,
      [t('rota {r}', { r: x.rota }), x.promotor].filter(Boolean).join(' · '),
    ];
  if (x.tipo === 'deposito')
    return [
      t('Depósito no banco de {d}: aprovar', { d }),
      [x.banco, t('lacre {l}', { l: x.lacre }), kzI(x.valor), por ? t('requisitado por {n}', { n: por }) : ''].filter(Boolean).join(' · '),
    ];
  const tit = {
    contar: t('Rota {r}: contar a carrinha', { r: x.rota }),
    validar: t('Rota {r}: validar a sua contagem', { r: x.rota }),
    coordenar: t('Rota {r}: aprovar as diferenças (coordenador)', { r: x.rota }),
    fechar: t('Rota {r}: aprovação final da conciliação', { r: x.rota }),
  }[x.accao];
  return [
    tit,
    [x.promotor, x.diferencas ? t('{n} diferença(s)', { n: x.diferencas }) : '', por && x.accao !== 'validar' ? t('último passo: {n}', { n: por }) : '']
      .filter(Boolean)
      .join(' · '),
  ];
}
