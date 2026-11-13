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
            { codigo: 'A1', artigo: 'Fraldas M', disponivel: 100 },
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
  // stock do armazém principal ao lado; acima do stock fica marcado e não se grava
  await expect(ed.locator('tr', { hasText: 'Fraldas M' }).locator('.req-stock')).toHaveText('100');
  await ed.getByLabel('Quantidade · Fraldas M').fill('200');
  await expect(ed.getByLabel('Quantidade · Fraldas M')).toHaveAttribute('aria-invalid', 'true');
  await ed.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect(ed).toContainText('Quantidade acima do stock do armazém principal: Fraldas M (200 pedidos, 100 em stock)');
  expect(pedidos.length).toBe(0);
  await ed.getByLabel('Quantidade · Fraldas M').fill('30');
  await expect(ed.locator('.req-estado')).toContainText('Alterações por gravar');
  // só com o teclado: Enter mostra a lista dos artigos activos, Enter selecciona e passa à quantidade, Enter junta
  const art = ed.getByRole('combobox', { name: 'Artigo a adicionar' });
  await art.focus();
  await art.press('Enter');
  await expect(ed.getByRole('listbox')).toBeVisible();
  await expect(ed.getByRole('option')).toHaveCount(3);
  // cada artigo com o stock do armazém principal
  await expect(ed.getByRole('option', { name: /Detergente/ })).toContainText('em stock: 40');
  await art.pressSequentially('deter');
  await expect(ed.getByRole('option')).toHaveCount(1);
  await expect(ed.getByRole('option', { name: /Detergente/ })).toHaveAttribute('aria-selected', 'true');
  await art.press('Enter');
  await expect(art).toHaveValue('C3 · Detergente');
  await expect(ed.getByLabel('Quantidade a adicionar')).toBeFocused();
  await page.keyboard.type('5');
  await page.keyboard.press('Enter');
  // volta ao artigo, pronto para o seguinte
  await expect(ed.getByRole('combobox', { name: 'Artigo a adicionar' })).toBeFocused();
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
  // requisição automática (conciliação): o promotor da rota já vem preenchido na assinatura de quem requisita
  expect(pdf).toContain('1001 \u00b7 Andrea Santos');
  expect(pdf).toContain('Assinatura e data');
  expect(pedidos.map((x) => x.accao)).toEqual(['gravar', 'gravar', 'emitir']);
  await expect(ed).toContainText('Emitida · a carregar');
  await expect(ed.locator('input')).toHaveCount(0);
  await expect(ed.getByRole('button', { name: 'Gravar', exact: true })).toHaveCount(0);
  const [d2] = await Promise.all([page.waitForEvent('download'), ed.getByRole('button', { name: 'Reimprimir (2.ª via)' }).click()]);
  expect(d2.suggestedFilename()).toBe('RQ-2026-000012_2via.pdf');
  await expect(ed.getByRole('button', { name: 'Nova requisição para esta rota' })).toBeVisible();
  expect(erros).toEqual([]);
});

test('nova requisição: «Criar» só abre o formulário (nada se regista); escolher o promotor; regista-se ao gravar com artigos', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/requisicoes**', (rt) => {
    const req = rt.request();
    if (req.method() === 'POST') {
      const b = JSON.parse(req.postData());
      pedidos.push(b);
      return rt.fulfill({
        json: {
          ok: true,
          requisicao: {
            numero: 'RQ-2026-000020',
            ano: '2026',
            rota: b.rota,
            promotor: 'Olindo Baião',
            data: b.data,
            origem: { tipo: 'manual' },
            estado: 'rascunho',
            versao: 1,
            nota: '',
            criado_por: 'sup',
            impressoes: [],
            linhas: b.linhas,
          },
        },
      });
    }
    const u = new URL(req.url());
    if (u.searchParams.get('catalogo')) return rt.fulfill({ json: { ok: true, artigos: [{ codigo: 'C3', artigo: 'Detergente', disponivel: 40 }] } });
    return rt.fulfill({ json: { ok: true, gere: true, rotas: { 1001: 'Andrea Santos', 1005: 'Olindo Baião' }, requisicoes: [] } });
  });
  await abrir(page, '#requisicoes-carrinha');
  const nova = page.locator('.req-nova');
  await nova.getByRole('button', { name: 'Criar' }).click();
  await expect(nova).toContainText('Escolha o promotor.');
  await nova.getByLabel('Rota').selectOption('1005');
  await nova.getByRole('button', { name: 'Criar' }).click();
  const ed = page.locator('#req-editor');
  await expect(ed).toContainText('Nova requisição');
  await expect(ed).toContainText('Por gravar');
  // descartar: nada foi registado
  await ed.getByRole('button', { name: 'Descartar' }).click();
  await expect(page.locator('#req-editor')).toHaveCount(0);
  expect(pedidos.length).toBe(0);
  // de novo: com um artigo regista e ganha número
  await nova.getByLabel('Rota').selectOption('1005');
  await nova.getByRole('button', { name: 'Criar' }).click();
  await expect(ed.getByRole('button', { name: 'Anular' })).toHaveCount(0);
  const art = ed.getByRole('combobox', { name: 'Artigo a adicionar' });
  await art.focus();
  await art.press('Enter');
  await art.press('Enter');
  await page.keyboard.type('3');
  await page.keyboard.press('Enter');
  await ed.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'criar', rota: '1005', linhas: [{ codigo: 'C3', quantidade: 3 }] });
  await expect(ed).toContainText('Requisição RQ-2026-000020');
});
