// Logística › Registo de cargas: cargas expedidas (planos publicados), separadas do stock.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const carga = (data, rota, matricula, f, s, zonas) => ({
  chave: data + '|' + rota,
  data,
  rota,
  promotor: 'Promotor ' + rota,
  motorista: 'Motorista ' + rota,
  matricula,
  viatura: 'Canter',
  transportadora: 'Velotrans',
  zonas: zonas.join(', '),
  n_zonas: zonas.length,
  carga_fraldas: f,
  carga_sabao: s,
  carga_sabonete: 0,
  carga: f + s,
});
const CARGAS = [
  carga('2026-10-22', '1001', 'LD-58-73-GY', 23, 132, ['Samba', 'Prenda', 'Rocha', 'Maianga', 'Cazenga']),
  carga('2026-10-22', '1002', 'LD-51-12-IJ', 64, 78, ['Viana']),
  carga('2026-10-23', '1001', 'LD-58-73-GY', 30, 100, ['Samba']),
];

test('registo de cargas: no menu Logística, por rota e viatura, por dia e por viatura; o stock deixa de mostrar cargas', async ({ page }) => {
  await page.route('**/data/dados.php?f=cargas*', (r) => r.fulfill({ json: CARGAS }));
  await page.route('**/cargas.json*', (r) => r.fulfill({ json: CARGAS }));
  const erros = await abrir(page, '#registo-cargas');
  await expect(page.locator('#nav a[href="#registo-cargas"]').first()).toContainText('Registo de cargas');
  await expect(page.locator('.rel-tab')).toHaveText(['Cargas por rota e viatura', 'Cargas por dia', 'Cargas por viatura']);
  const cab = await page.locator('#s-rc-saidas thead th').allTextContents();
  for (const c of ['Matrícula', 'Tipo de viatura', 'Transportadora', 'Fraldas (cx)', 'Total (cx)']) expect(cab.join('|')).toContain(c);
  await expect(page.locator('#s-rc-saidas tbody')).toContainText('Samba, Prenda, Rocha +2');
  await expect(page.locator('.tile', { hasText: 'Saídas de viatura' })).toContainText('3');
  await page.locator('.rel-tab', { hasText: 'Cargas por viatura' }).click();
  await expect(page.locator('#s-rc-viaturas tbody tr').first()).toContainText('LD-58-73-GY');
  await abrir(page, '#stock-carrinha');
  await expect(page.locator('#page')).not.toContainText('Carga por promotor e carrinha');
  expect(erros).toEqual([]);
});
