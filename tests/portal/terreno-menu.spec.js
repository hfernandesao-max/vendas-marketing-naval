// Terreno: menu por subgrupos (Operação do dia, Planeamento, Território e clientes, Análise), contadores do que pede
// acção (rotas propostas pendentes, ocorrências por tratar) e a página Ocorrências com o tratamento dos alertas.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const ocorrencias = (trata = true) => ({
  ok: true,
  dia: '2026-10-26',
  trata,
  abertas: 2,
  fim_do_dia: true,
  tipos: { venda_longe: 'Venda longe do cliente', parado: 'Parado sem vender', cliente_parado: 'Cliente regular sem comprar' },
  ocorrencias: [
    {
      id: 'oco|cliente_parado|C1|60',
      tipo: 'cliente_parado',
      tipo_nome: 'Cliente regular sem comprar',
      gravidade: 'critico',
      rota: '1002',
      promotor: 'Promotor Dois',
      cliente: 'Loja C',
      titulo: 'Sem comprar há 79 dias',
      detalhe: 'Última compra 01-08-2026 · 6 compras antes',
      pagina: 'geo-prospeccao',
      activo: true,
      tratamento: { estado: 'novo' },
    },
    {
      id: 'oco|venda_longe|2026-10-26|1001|FR1',
      tipo: 'venda_longe',
      tipo_nome: 'Venda longe do cliente',
      gravidade: 'aviso',
      rota: '1001',
      promotor: 'Promotor Um',
      cliente: 'Loja B',
      titulo: 'Venda a 222 m do cliente',
      detalhe: '11:14 · FR 1001/18 · 156 000,00 Kz',
      pagina: 'geo-terreno?data=2026-10-26&rota=1001',
      activo: true,
      tratamento: { estado: 'novo' },
    },
    {
      id: 'oco|parado|2026-10-26|1001|1200',
      tipo: 'parado',
      tipo_nome: 'Parado sem vender',
      gravidade: 'info',
      rota: '1001',
      promotor: 'Promotor Um',
      cliente: '',
      titulo: 'Parado 20 min sem vender',
      detalhe: '12:00–12:20',
      pagina: 'geo-terreno?data=2026-10-26&rota=1001',
      activo: false,
      tratamento: { estado: 'resolvido', por: 'coord', em: '2026-10-26 15:00:00', nota: 'Almoço' },
    },
  ],
});

test('terreno: menu por subgrupos pela tarefa, pela ordem do dia ao planeamento, território e análise', async ({ page }) => {
  await page.route('**/v1/ocorrencias**', (r) => r.fulfill({ json: ocorrencias() }));
  const erros = await abrir(page, '#ocorrencias');
  const area = page.locator('#navl-terreno');
  await expect(area.locator('.navl-subg')).toHaveText(['Operação do dia', 'Planeamento', 'Território e clientes', 'Análise']);
  // o nome de cada ligação (sem o contador, que a página acerta ao ler as ocorrências)
  await expect(area.locator('a > span:first-of-type')).toHaveText([
    'Visitas do dia',
    'Percurso do dia',
    'Ocorrências',
    'Plano de visitas',
    'Optimização de rotas',
    'Rotas propostas',
    'Prospecção de clientes',
    'Zonas de entrega',
    'Distribuidores',
    'Conversão das visitas',
  ]);
  // rótulos: cabeçalhos de nível 3, fora da tabulação; o subgrupo da página aberta fica realçado
  await expect(area.locator('.navl-subg').first()).toHaveAttribute('role', 'heading');
  await expect(area.locator('.navl-subg').first()).not.toHaveAttribute('tabindex');
  await expect(area.locator('.navl-subg.atual')).toHaveText('Operação do dia');
  await expect(area.locator('a[aria-current="page"] > span:first-of-type')).toHaveText('Ocorrências');
  expect(erros).toEqual([]);
});

test('ocorrências: por tratar primeiro (crítico no topo), filtro por tipo, ligação ao mapa e resolver com nota', async ({ page }) => {
  let estado = ocorrencias();
  const pedidos = [];
  await page.route('**/v1/ocorrencias**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      if (b.accao === 'resolver' && (b.nota || '').length < 5)
        return r.fulfill({ json: { ok: false, erro: 'Diga o que foi feito para resolver (pelo menos 5 caracteres).' } });
      const o = estado.ocorrencias.find((x) => x.id === b.id);
      o.tratamento = { estado: b.accao === 'assumir' ? 'assumido' : 'resolvido', por: 'coord', em: '2026-10-26 16:00:00', nota: b.nota || '' };
      o.activo = b.accao === 'assumir';
      estado = { ...estado, abertas: estado.ocorrencias.filter((x) => x.activo).length };
      return r.fulfill({ json: { ok: true, tratamento: o.tratamento } });
    }
    return r.fulfill({ json: estado });
  });
  const erros = await abrir(page, '#ocorrencias');
  const itens = page.locator('.oc-item');
  await expect(itens).toHaveCount(2); // a resolvida só em «Todas»
  await expect(itens.first()).toContainText('Cliente regular sem comprar · Loja C');
  await expect(itens.first().locator('.pill').first()).toHaveText('Crítico');
  await expect(page.locator('.tile').filter({ hasText: 'Por tratar' }).locator('.v')).toHaveText('2');
  const longe = page.locator('.oc-item[data-id="oco|venda_longe|2026-10-26|1001|FR1"]');
  await expect(longe.getByRole('link', { name: 'Ver no mapa' })).toHaveAttribute('href', '#geo-terreno?data=2026-10-26&rota=1001');
  // filtro por tipo (com as contagens)
  await page.getByLabel('Tipo de ocorrência').selectOption('venda_longe');
  await expect(itens).toHaveCount(1);
  await page.getByLabel('Tipo de ocorrência').selectOption('');
  // resolver exige a nota
  await longe.getByRole('button', { name: 'Resolver' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByRole('button', { name: 'Resolver' }).click();
  await expect(dlg.locator('.erro')).toContainText('Diga o que foi feito');
  await dlg.getByLabel('Acção tomada').fill('Cliente mudou de loja; GPS actualizado');
  await dlg.getByRole('button', { name: 'Resolver' }).click();
  await expect(page.locator('#toast')).toContainText('Ocorrência resolvida.');
  expect(pedidos.at(-1)).toMatchObject({
    accao: 'resolver',
    id: 'oco|venda_longe|2026-10-26|1001|FR1',
    nota: 'Cliente mudou de loja; GPS actualizado',
    data: '2026-10-26',
  });
  await expect(itens).toHaveCount(1);
  // todas: também as resolvidas, com quem e a nota
  await page.getByRole('tab', { name: 'Todas' }).click();
  await expect(page.locator('.oc-item')).toHaveCount(3);
  await expect(page.locator('.oc-item[data-id="oco|parado|2026-10-26|1001|1200"]')).toContainText('Almoço');
  expect(erros).toEqual([]);
});

test('terreno: contadores do que pede acção no menu e na área; quem só vê não trata', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({
      json: {
        ok: true,
        csrf: 'x',
        login: 'sup',
        nome: 'Supervisor',
        perfil: 'SUP',
        perfil_nome: 'Supervisor',
        rotas: ['1001'],
        permissoes: { ocorrencias: 'V', 'rotas-propostas': 'Vr', 'geo-terreno': 'Vr' },
      },
    }),
  );
  await page.route('**/v1/terreno/contagens*', (r) => r.fulfill({ json: { ok: true, contagens: { 'rotas-propostas': 1, ocorrencias: 2 } } }));
  await page.route('**/v1/ocorrencias**', (r) => r.fulfill({ json: ocorrencias(false) }));
  const erros = await abrir(page, '#ocorrencias');
  const area = page.locator('#navl-terreno');
  await expect(area.locator('a[href="#ocorrencias"] .n')).toHaveText('2');
  await expect(area.locator('a[href="#ocorrencias"] .n')).toHaveAttribute('aria-label', '2 ocorrências por tratar');
  await expect(area.locator('a[href="#rotas-propostas"] .n')).toHaveText('1');
  await expect(page.locator('.navg', { hasText: 'Terreno' }).locator('.navg-n')).toHaveText('3');
  // com só três páginas do Terreno visíveis, os subgrupos continuam a separar o dia do planeamento
  await expect(area.locator('.navl-subg')).toHaveText(['Operação do dia', 'Planeamento']);
  // quem só vê: sem botões de tratamento
  await expect(page.locator('.oc-item').first()).toBeVisible();
  await expect(page.locator('.oc-item button')).toHaveCount(0);
  await expect(page.locator('#page')).toContainText('Pode consultar as ocorrências');
  expect(erros).toEqual([]);
});
