// Selo «Novo» durante 24 horas (componentes/novo.js): cheio nas primeiras 6 h, contorno até às 12 h, discreto até às
// 24 h; depois de abrir a página fica logo discreto, com a faixa do que mudou no topo.
import { test, expect } from '@playwright/test';
import { abrir, HOJE } from './dados.js';

const PERM = { inicio: 'V', pagamentos: 'V', 'vendas-promotor': 'V', documentos: 'V' };
const ts = (h) => Math.round(HOJE.getTime() / 1000 - h * 3600);
async function comNovo(page, novo) {
  // sem servidor PHP nos testes do portal: a sessão simulada só traz as novidades
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({ json: { login: 'henrique', nome: 'Henrique', perfil: 'SADM', csrf: 'x', rotas: null, nif: true, permissoes: PERM, novo } }),
  );
}

test('selo «Novo»: esmorece com o tempo, ponto na área, faixa na página e discreto depois de visto', async ({ page }) => {
  await comNovo(page, [
    { pagina: 'pagamentos', titulo: 'Substituição de facturas', desde: '', desde_ts: ts(2) },
    { pagina: 'vendas-promotor', titulo: 'Substituição de facturas', desde: '', desde_ts: ts(8) },
    { pagina: 'documentos', titulo: 'Antiga', desde: '', desde_ts: ts(30) },
  ]);
  const erros = await abrir(page, '#inicio');
  const selo = (id) => page.locator(`#nav a[href="#${id}"] .novo-selo`);
  await expect(selo('pagamentos')).toHaveClass(/f1/);
  await expect(selo('pagamentos')).toHaveText('Novo');
  await expect(selo('pagamentos')).toHaveAttribute('title', 'Novidade: Substituição de facturas · há 2 h');
  await expect(selo('vendas-promotor')).toHaveClass(/f2/);
  await expect(selo('documentos')).toHaveCount(0);
  await expect(page.locator('#nav .navg .novo-ponto').first()).toBeAttached();
  // abrir a página: faixa no topo e o selo passa a discreto
  await page.goto('/reports/index.html#pagamentos');
  await expect(page.locator('#page .novo-faixa')).toContainText('Substituição de facturas · actualizado há 2 h');
  await expect(selo('pagamentos')).toHaveClass(/f3/);
  await expect(selo('pagamentos')).toHaveClass(/visto/);
  expect(erros).toEqual([]);
});
