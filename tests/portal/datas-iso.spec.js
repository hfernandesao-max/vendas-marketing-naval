// Campos de data (componentes/data.js): escrever dd-mm-aaaa (com máscara) ou escolher no calendário; o valor é sempre
// ISO 8601 (aaaa-mm-dd); datas inválidas ou fora dos limites ficam marcadas e não mudam o período; ↑ ↓ mudam um dia.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('datas: escrever à mão (com máscara e ISO colado), validar, ↑ ↓ e botão do calendário', async ({ page }) => {
  const erros = await abrir(page, '#vendas-dia', { periodo: null });
  const de = page.locator('#de-txt');
  await expect(de).toHaveValue('26-10-2026');
  await expect(de).toHaveAttribute('aria-labelledby', 'l-de');
  await expect(page.locator('#de')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.getByRole('button', { name: 'Abrir o calendário' }).first()).toBeVisible();
  // escrever só os algarismos: os hífenes entram sozinhos e o período muda (valor ISO)
  await de.fill('');
  await de.pressSequentially('01102026');
  await expect(de).toHaveValue('01-10-2026');
  await expect(page.locator('#de')).toHaveValue('2026-10-01');
  await expect(page.locator('#presets button.on')).toHaveCount(0);
  // data que não existe: marcada, com explicação, e o período não muda
  await de.fill('31-02-2026');
  await expect(de).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#de-erro')).toContainText('Data inválida');
  await expect(page.locator('#de')).toHaveValue('2026-10-01');
  // ISO colado também serve
  await de.fill('2026-10-05');
  await de.press('Enter');
  await expect(de).toHaveValue('05-10-2026');
  await expect(page.locator('#de')).toHaveValue('2026-10-05');
  await expect(de).toHaveAttribute('aria-invalid', 'false');
  // ↑ um dia; PgDn um mês
  await de.press('ArrowUp');
  await expect(page.locator('#de')).toHaveValue('2026-10-06');
  await de.press('PageDown');
  await expect(page.locator('#de')).toHaveValue('2026-09-06');
  // mudar por código (ex.: «Ontem») actualiza o texto
  await page.click('#presets button:has-text("Ontem")');
  await expect(de).toHaveValue('25-10-2026');
  expect(erros).toEqual([]);
});
