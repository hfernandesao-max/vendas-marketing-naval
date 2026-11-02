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
