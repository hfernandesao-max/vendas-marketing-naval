// Páginas como grupos de relatórios: separadores, sub-itens do menu, endereço próprio e memória do último.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('mostra um relatório de cada vez e muda pelos separadores', async ({ page }) => {
  await abrir(page, '#vendas-dia');
  await expect(page.locator('.rel-tab')).toHaveText(['Vendas por dia', 'Promotor × dia']);
  await expect(page.locator('#s-vd')).toBeVisible();
  await expect(page.locator('#s-vd-mat')).toBeHidden();
  await page.click('.rel-tab:has-text("Promotor × dia")');
  await expect(page).toHaveURL(/#vendas-dia\/vd-mat$/);
  await expect(page.locator('#s-vd-mat')).toBeVisible();
  await expect(page.locator('#s-vd')).toBeHidden();
  await expect(page.locator('.nav-sub a.on')).toHaveText('Promotor × dia');
});

test('escolher uma página no menu abre o primeiro relatório; abrir uma área abre a primeira página', async ({ page }) => {
  await abrir(page, '#vendas-dia/vd-mat');
  await page.click('#nav a[href="#vendas-promotor"]');
  await page.click('#nav a[href="#vendas-dia"]');
  const primeiro = await page.locator('.rel-tab').first().textContent();
  await expect(page.locator('.rel-tab.on')).toHaveText(primeiro);
  await expect(page.locator('.nav-sub a.on')).toHaveText(primeiro);
  // abrir a área Finanças selecciona a primeira página dela (Cobranças)
  await page.locator('.navg', { hasText: 'Finanças' }).click();
  await expect(page).toHaveURL(/#pagamentos/);
  await expect(page.locator('#nav a[href="#pagamentos"]')).toHaveClass(/on/);
  // o Início continua a fechar as áreas sem seleccionar nada
  await page.click('#nav a[href="#inicio"]');
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
});

test('menu em acordeão: áreas fechadas, só uma aberta de cada vez, a da página actual abre sozinha', async ({ page }) => {
  await abrir(page, '#inicio');
  const grupo = (nome) => page.locator('.navg', { hasText: nome });
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
  await expect(page.locator('#nav a[href="#clientes"]')).not.toBeInViewport();
  await grupo('Clientes').click();
  await expect(grupo('Clientes')).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#nav a[href="#clientes"]')).toBeVisible();
  await grupo('Vendas').click();
  await expect(grupo('Vendas')).toHaveAttribute('aria-expanded', 'true');
  await expect(grupo('Clientes')).toHaveAttribute('aria-expanded', 'false');
  await grupo('Vendas').click();
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
  // ao abrir uma página por endereço, a sua área abre e fica destacada
  await page.goto(page.url().replace(/#.*$/, '#pendentes'));
  await expect(grupo('Finanças')).toHaveAttribute('aria-expanded', 'true');
  await expect(grupo('Finanças')).toHaveClass(/atual/);
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(1);
});

test('menu organizado por secções da cadeia de valor (Comercial, Operações, Análise) e Sistema em baixo', async ({ page }) => {
  await abrir(page, '#inicio');
  await expect(page.locator('#nav .nav-sec')).toHaveText(['Comercial', 'Operações', 'Análise', 'Sistema']);
  await expect(page.locator('#nav .navg .navg-t')).toHaveText([
    'Vendas',
    'Clientes',
    'Finanças',
    'Terreno',
    'Logística',
    'Inteligência de negócio',
    'Equipamentos',
    'Integrações',
    'Administração',
  ]);
  // Terreno junta as visitas e os mapas; o controlo interno das vendas em Inteligência de negócio; a sincronização em
  // Integrações; o valor e a apresentação do portal em Administração; Classificação e marca fora do menu
  const naArea = (area, href) => page.locator('.navg', { hasText: area }).locator('xpath=following-sibling::div[1]').locator(`a[href="${href}"]`);
  await expect(naArea('Terreno', '#geo-rotas')).toHaveCount(1);
  await expect(naArea('Terreno', '#visitas-dia')).toHaveCount(1);
  await expect(naArea('Inteligência de negócio', '#auditoria')).toHaveCount(1);
  await expect(naArea('Integrações', '#sincronizacao')).toHaveCount(1);
  await expect(naArea('Administração', '#valor-portal')).toHaveCount(1);
  await expect(page.locator('#nav a[href="#ranking"]')).toHaveCount(0);
  await expect(page.locator('#nav a[href="#vendas-marca"]')).toHaveCount(0);
  // os Alertas estão no sino da barra superior, não no menu
  await expect(page.locator('#nav a[href="#alertas"]')).toHaveCount(0);
  await expect(page.locator('#sino')).toHaveAttribute('href', '#alertas');
  // um ícone, um significado: nenhuma página repete o ícone de outra
  const desenhos = await page.locator('#nav a[href^="#"] svg path').evaluateAll((ps) => ps.map((p) => p.getAttribute('d')));
  expect(new Set(desenhos).size).toBe(desenhos.length);
  // o número ao lado de uma área diz o que conta (texto acessível, não só a cor)
  await expect(page.locator('.navg', { hasText: 'Clientes' }).locator('.navg-n')).toHaveAttribute('aria-label', /clientes não activos/);
  // localização no cabeçalho: secção › área
  await page.click('.navg:has-text("Vendas")');
  await expect(page.locator('#eyebrow')).toContainText('Comercial › Vendas');
});
