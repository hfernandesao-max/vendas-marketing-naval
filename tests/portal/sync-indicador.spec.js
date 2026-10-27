// Indicador discreto da comunicação dos smartphones no menu lateral.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

// Dados como os da consulta ao SYNC_PHSE_HISTORY (última sincronização por rota), avaliados às 10:43 de 27-10
const ULT = {
  1004: ['2026-10-26 07:22:35', 1641, 'atencao'],
  1008: ['2026-10-24 10:48:38', 4315, 'critico'],
  1002: ['2026-10-24 07:31:54', 4511, 'critico'],
  1009: ['2026-10-23 18:52:44', 5390, 'critico'],
  1001: ['2026-10-27 10:20:00', 23, 'em_dia'],
  1011: [null, null, 'sem_registo'],
};
const SYNC = {
  disponivel: true,
  agora: '2026-10-27 10:43:00',
  limites: { alerta_horas: 2, critico_horas: 24 },
  rotas: Object.entries(ULT).map(([rota, [ultima, minutos, estado]]) => ({
    rota,
    promotor: 'P ' + rota,
    ultima,
    minutos,
    hoje: 0,
    sete_dias: 1,
    dias: {},
    estado,
  })),
};

test('menu lateral: um ponto por smartphone com a cor do estado, resumo em texto e detalhe na dica', async ({ page }) => {
  await page.route('**/data/sincronizacao.php*', (r) => r.fulfill({ json: SYNC }));
  const erros = await abrir(page, '#inicio');
  const ind = page.locator('#sync-mini');
  await expect(ind).toBeVisible();
  await expect(ind.locator('.sync-p')).toHaveCount(6);
  await expect(ind.locator('.sync-p.em_dia')).toHaveCount(1);
  await expect(ind.locator('.sync-p.critico')).toHaveCount(3);
  await expect(ind.locator('.sync-p.sem_registo')).toHaveCount(1);
  await expect(ind).toContainText('1/6 em dia');
  await expect(ind).toHaveAttribute('aria-label', /Smartphones: 1 de 6 em dia · 4 sem comunicar/);
  await expect(ind.locator('.sync-p').first()).toHaveAttribute('title', /1001 .* Em dia · 27-10-2026 10:20 · há 23 min/);
  await expect(ind.locator('.sync-p').last()).toHaveAttribute('title', /Sem registo · nunca sincronizou/);
  await ind.click();
  await expect(page.locator('#titulo')).toHaveText('Sincronização dos smartphones');
  expect(erros).toEqual([]);
});

test('sem acesso à página de sincronização: o indicador não aparece', async ({ page }) => {
  await page.route('**/data/sincronizacao.php*', (r) => r.fulfill({ status: 403, json: { erro: 'sem acesso' } }));
  await abrir(page, '#inicio');
  await expect(page.locator('#sync-mini')).toBeHidden();
});
