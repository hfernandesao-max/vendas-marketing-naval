// Requisições de reposição da carrinha ao armazém: rascunho criado pela conciliação, editar (quantidades e artigos
// novos), gravar, emitir com PDF para imprimir (fica fechada) e 2.ª via.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('requisição: rever, juntar artigo, gravar, emitir e imprimir (fica fechada), 2.ª via', async ({ page }) => {
  const r = {
    numero: 'RQ-2026-000012',
    ano: '2026',
    rota: '1001',
    promotor: 'Andrea Santos',
    data: '2026-10-26',
    origem: { tipo: 'conciliacao', data: '2026-10-26', rota: '1001' },
    estado: 'rascunho',
    versao: 1,
    nota: '',
    criado_por: 'automatico',
    impressoes: [],
    linhas: [{ codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX', sugerida: 26, quantidade: 26, origem: 'vendido' }],
  };
  const pedidos = [];
  await page.route('**/v1/requisicoes**', (rt) => {
    const req = rt.request();
    if (req.method() === 'POST') {
      const b = JSON.parse(req.postData());
      pedidos.push(b);
      if (b.accao === 'gravar') Object.assign(r, { linhas: b.linhas, nota: b.nota, versao: r.versao + 1 });
      if (b.accao === 'emitir')
        Object.assign(r, { estado: 'emitida', emitida_por: 'sup', emitida_em: '2026-10-26 18:10:00', impressoes: [{ via: 1 }], versao: r.versao + 1 });
      if (b.accao === 'reimprimir') r.impressoes = [...r.impressoes, { via: 2 }];
      return rt.fulfill({ json: { ok: true, requisicao: { ...r } } });
    }
    const u = new URL(req.url());
    if (u.searchParams.get('catalogo'))
      return rt.fulfill({
        json: {
          ok: true,
          artigos: [
            { codigo: 'C3', artigo: 'Detergente', disponivel: 40 },
            { codigo: 'D4', artigo: 'Sabonete', disponivel: 12 },
          ],
        },
      });
    if (u.searchParams.get('numero')) return rt.fulfill({ json: { ok: true, gere: true, requisicao: { ...r } } });
    return rt.fulfill({
      json: { ok: true, gere: true, rotas: { 1001: 'Andrea Santos' }, requisicoes: [{ ...r, n_linhas: r.linhas.length, quantidade: 26 }] },
    });
  });
  await page.addInitScript(() => (window.open = () => null));
  const erros = await abrir(page, '#requisicoes-carrinha');
  await expect(page.locator('#s-req tbody tr')).toHaveCount(1);
  await expect(page.locator('#s-req tbody tr')).toContainText('Rascunho');
  await page.getByRole('button', { name: 'RQ-2026-000012' }).click();
  const ed = page.locator('#req-editor');
  await expect(ed).toContainText('criada pela conciliação da carrinha de 26-10-2026');
  await ed.getByLabel('Quantidade · Fraldas M').fill('30');
  await expect(ed.locator('.req-estado')).toContainText('Alterações por gravar');
  // só com o teclado: Enter mostra a lista dos artigos activos, Enter selecciona e passa à quantidade, Enter junta
  const art = ed.getByRole('combobox', { name: 'Artigo a juntar' });
  await art.focus();
  await art.press('Enter');
  await expect(ed.getByRole('listbox')).toBeVisible();
  await expect(ed.getByRole('option')).toHaveCount(2);
  await art.pressSequentially('deter');
  await expect(ed.getByRole('option')).toHaveCount(1);
  await expect(ed.getByRole('option', { name: /Detergente/ })).toHaveAttribute('aria-selected', 'true');
  await art.press('Enter');
  await expect(art).toHaveValue('C3 · Detergente');
  await expect(ed.getByLabel('Quantidade a juntar')).toBeFocused();
  await page.keyboard.type('5');
  await page.keyboard.press('Enter');
  // volta ao artigo, pronto para o seguinte
  await expect(ed.getByRole('combobox', { name: 'Artigo a juntar' })).toBeFocused();
  await expect(ed.locator('tr', { hasText: 'Detergente' })).toBeVisible();
  await ed.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'gravar', numero: 'RQ-2026-000012', versao: 1 });
  expect(pedidos[0].linhas.map((l) => [l.codigo, l.quantidade])).toEqual([
    ['A1', 30],
    ['C3', 5],
  ]);
  // emitir: confirmação, grava, emite e abre o PDF para imprimir; depois não se edita
  await ed.getByRole('button', { name: 'Gravar, emitir e imprimir' }).click();
  const [d] = await Promise.all([page.waitForEvent('download'), page.locator('dialog[open]').getByRole('button', { name: 'Emitir e imprimir' }).click()]);
  expect(d.suggestedFilename()).toBe('RQ-2026-000012.pdf');
  const fs = await import('node:fs');
  const pdf = fs.readFileSync(await d.path(), 'latin1');
  expect(pdf).toContain('Detergente');
  expect(pdf).toContain('Entregue');
  expect(pedidos.map((x) => x.accao)).toEqual(['gravar', 'gravar', 'emitir']);
  await expect(ed).toContainText('Emitida · a carregar');
  await expect(ed.locator('input')).toHaveCount(0);
  await expect(ed.getByRole('button', { name: 'Gravar', exact: true })).toHaveCount(0);
  const [d2] = await Promise.all([page.waitForEvent('download'), ed.getByRole('button', { name: 'Reimprimir (2.ª via)' }).click()]);
  expect(d2.suggestedFilename()).toBe('RQ-2026-000012_2via.pdf');
  await expect(ed.getByRole('button', { name: 'Nova requisição para esta rota' })).toBeVisible();
  expect(erros).toEqual([]);
});
