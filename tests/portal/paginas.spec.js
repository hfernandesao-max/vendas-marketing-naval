// Todas as páginas, em vários tamanhos de ecrã: sem erros, nada fora da largura e números dos quadros alinhados.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const PAGINAS = [
  'inicio',
  'vendas-promotor',
  'vendas-dia',
  'vendas-cliente',
  'vendas-artigo',
  'vendas-promotor-artigo',
  'ranking',
  'documentos',
  'pagamentos',
  'pendentes',
  'clientes',
  'cadastros',
  'cadastros-analise',
  'cadastros-clientes',
  'plano-visitas',
  'auditoria',
  'registo-cargas',
  'stock-carrinha',
  'stock-armazem',
  'conversao-visitas',
];
const ECRAS = [
  [360, 740],
  [768, 1024],
  [1366, 768],
  [1920, 1080],
];

for (const [w, h] of ECRAS) {
  test.describe(`${w}×${h}`, () => {
    test.use({ viewport: { width: w, height: h }, isMobile: w < 800, hasTouch: w < 800 });
    for (const pg of PAGINAS) {
      test(pg, async ({ page }) => {
        const erros = await abrir(page, '#' + pg);
        const r = await page.evaluate(() => {
          const W = document.documentElement.clientWidth,
            fora = [];
          document.querySelectorAll('body *').forEach((e) => {
            const b = e.getBoundingClientRect();
            if (
              b.width &&
              b.right > W + 1 &&
              !e.closest('.tw, .gwrap, .hm-wrap, .rel-tabs, .fam-tabs, #side, .fb-pop') &&
              getComputedStyle(e).position !== 'fixed'
            )
              fora.push(e.className || e.tagName);
          });
          const desalinhados = [],
            cortados = [];
          document.querySelectorAll('.tiles').forEach((t) => {
            const filas = {};
            [...t.children].forEach((q) => {
              const v = q.querySelector('.v');
              if (!v) return;
              (filas[Math.round(q.getBoundingClientRect().top)] ??= []).push(Math.round(v.getBoundingClientRect().top));
              if (v.scrollWidth > v.clientWidth + 1) cortados.push(v.textContent);
            });
            Object.values(filas).forEach((a) => {
              if (Math.max(...a) - Math.min(...a) > 1) desalinhados.push(a.join('/'));
            });
          });
          // quando há deslocação, os elementos que passam a largura (também dentro das tabelas), para o diagnóstico
          const excede = [];
          if (document.documentElement.scrollWidth > W)
            document.querySelectorAll('body *').forEach((e) => {
              const b = e.getBoundingClientRect();
              if (b.width && b.right > W && getComputedStyle(e).position !== 'fixed')
                excede.push(e.tagName + '.' + String(e.className).slice(0, 40) + '@' + Math.round(b.right) + (e.closest('.tw') ? ' (tabela)' : ''));
            });
          return {
            largura: document.documentElement.scrollWidth - W,
            fora: [...new Set(fora)].slice(0, 5),
            desalinhados,
            cortados,
            excede: [...new Set(excede)].slice(0, 12),
          };
        });
        expect(erros, 'erros de JavaScript').toEqual([]);
        expect(r.largura, 'deslocação horizontal: ' + r.excede.join(', ')).toBeLessThanOrEqual(0);
        expect(r.fora, 'elementos fora do ecrã').toEqual([]);
        expect(r.desalinhados, 'números dos quadros desalinhados').toEqual([]);
        expect(r.cortados, 'valores cortados nos quadros').toEqual([]);
      });
    }
  });
}
