// Modo «estrutura» do api.php: só o super administrador, sem dados pessoais.
import { test, expect } from '@playwright/test';
import { entrar, DIA } from './ajuda.js';

test('mostra secções e tipos de documento do Pinguim BO, sem NIF', async () => {
  const d = await (await (await entrar('sadm')).ctx.get(`/reports/data/api.php?estrutura=1&${DIA}`)).json();
  expect(Object.keys(d.tipos_de_documento).sort()).toEqual(['FR', 'FT']);
  expect(d.tipos_de_documento.FR.exemplo.tax_number).toBe('…');
  expect(d.seccoes.entities).toEqual({ elementos: 2 });
});

test('outros perfis não têm acesso', async () => {
  expect((await (await entrar('fin')).ctx.get(`/reports/data/api.php?estrutura=1&${DIA}`)).status()).toBe(403);
});
