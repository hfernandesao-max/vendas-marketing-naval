// Criar relatório: explorador sobre a camada semântica (áreas, linhas, colunas, medidas, filtros), detalhe,
// guardar (só a definição), certificar e exportar.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const LISTA = { ok: true, eu: 'hf', pode: { partilhar: true, certificar: true }, relatorios: [] };

async function preparar(page, extra = {}) {
  const pedidos = [];
  let lista = JSON.parse(JSON.stringify(LISTA));
  await page.route('**/v1/relatorios**', async (r) => {
    const req = r.request();
    if (req.method() === 'POST') {
      const b = req.postDataJSON();
      pedidos.push(b);
      if (b.accao === 'guardar') {
        const rel = {
          id: 'REL-0123456789',
          versao: 1,
          nome: b.nome,
          descricao: b.descricao,
          visibilidade: b.visibilidade,
          definicao: b.definicao,
          dono: 'hf',
          dono_nome: 'Henrique',
          certificado: false,
        };
        lista.relatorios = [rel];
        return r.fulfill({ json: { ok: true, relatorio: rel, relatorios: lista.relatorios, pode: lista.pode } });
      }
      if (b.accao === 'subscrever') {
        lista.relatorios[0] = { ...lista.relatorios[0], minha_subscricao: b.frequencia === 'nenhuma' ? null : b.frequencia };
        return r.fulfill({ json: { ok: true, relatorio: lista.relatorios[0], relatorios: lista.relatorios, pode: lista.pode } });
      }
      if (b.accao === 'certificar') {
        lista.relatorios[0] = { ...lista.relatorios[0], certificado: true, certificado_por: 'dir' };
        return r.fulfill({ json: { ok: true, relatorio: lista.relatorios[0], relatorios: lista.relatorios, pode: lista.pode } });
      }
    }
    const u = new URL(req.url());
    if (u.searchParams.get('fonte') === 'caixa')
      return r.fulfill({
        json: {
          ok: true,
          dias: [
            { dia: '2026-10-20', estado: 'aprovada', entregue: 1000, multicaixa: 300, depositos: 800, diferenca: 0 },
            { dia: '2026-10-21', estado: 'fechada', entregue: 2000, multicaixa: 0, depositos: 0, diferenca: -50 },
          ],
          movimentos: [],
        },
      });
    return r.fulfill({ json: lista });
  });
  await page.route('**/v1/exportacoes**', (r) => r.fulfill({ json: { ok: true } }));
  const erros = await abrir(page, extra.hash || '#relatorios');
  return { pedidos, erros };
}

test('relatórios: vendas por promotor com medidas, total, gráfico e detalhe ao clicar numa linha', async ({ page }) => {
  const { erros } = await preparar(page);
  await page.getByRole('combobox', { name: 'Área de dados' }).selectOption('vendas');
  const tab = page.locator('#s-rel-tabela');
  await expect(tab.locator('tbody tr')).toHaveCount(4);
  await expect(tab.locator('thead')).toContainText('Promotor');
  await expect(tab.locator('thead')).toContainText('Vendas sem IVA');
  await expect(tab.locator('tfoot')).toContainText('Total');
  await expect(page.locator('.viz-card')).toHaveCount(1);
  // segunda medida e uma coluna por tipo de documento
  await page.locator('.rel-medidas label', { hasText: 'Documentos de venda' }).locator('input').check();
  await expect(tab.locator('thead th')).toHaveCount(3);
  // detalhe
  await tab.locator('tbody tr').first().click();
  await expect(page.locator('#s-rel-detalhe')).toBeVisible();
  await expect(page.locator('#s-rel-detalhe tbody tr').first()).toBeVisible();
  expect(erros).toEqual([]);
});

test('relatórios: filtro por valores, data por mês e linhas em segundo nível', async ({ page }) => {
  const { erros } = await preparar(page);
  await page.getByRole('combobox', { name: 'Área de dados' }).selectOption('vendas');
  await page.getByRole('combobox', { name: 'Linhas', exact: true }).selectOption('data:mes');
  const tab = page.locator('#s-rel-tabela');
  await expect(tab.locator('tbody tr')).toHaveCount(1);
  await expect(tab.locator('tbody tr').first()).toContainText('10-2026');
  await page.getByRole('combobox', { name: 'Depois por' }).first().selectOption('rota');
  await expect(tab.locator('tbody tr')).toHaveCount(4);
  await page.getByLabel('Acrescentar filtro').selectOption('rota');
  await page.locator('.rel-filtro-vals label', { hasText: '1001' }).locator('input').check();
  await expect(tab.locator('tbody tr')).toHaveCount(1);
  await expect(tab).toContainText('1001');
  expect(erros).toEqual([]);
});

test('relatórios: guardar envia só a definição; abre pelo endereço; certificar mostra o selo', async ({ page }) => {
  const { pedidos, erros } = await preparar(page);
  await page.getByRole('combobox', { name: 'Área de dados' }).selectOption('vendas');
  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await page.locator('dialog').getByLabel('Nome').fill('Vendas por promotor');
  await page.locator('dialog').getByLabel('Quem o vê').selectOption('partilhado');
  await page.locator('#rel-guardar-ok').click();
  await expect(page.locator('.rel-lista')).toContainText('Vendas por promotor');
  expect(pedidos[0]).toMatchObject({
    accao: 'guardar',
    nome: 'Vendas por promotor',
    visibilidade: 'partilhado',
    definicao: { dominio: 'vendas', medidas: ['vendas_sem_iva'] },
  });
  expect(JSON.stringify(pedidos[0])).not.toContain('CLIENTE'); // nunca dados, só a definição
  await expect(page).toHaveURL(/#relatorios\?r=REL-0123456789$/);
  // subscrição por correio: só a frequência, e o aviso de que os números não seguem no correio
  await expect(page.locator('.rel-subs')).toContainText('os números vêem-se no portal');
  await page.getByRole('combobox', { name: 'Receber por correio' }).selectOption('semanal');
  await expect.poll(() => pedidos.find((p) => p.accao === 'subscrever')).toMatchObject({ accao: 'subscrever', id: 'REL-0123456789', frequencia: 'semanal' });
  await expect(page.getByRole('combobox', { name: 'Receber por correio' })).toHaveValue('semanal');
  await page.getByRole('button', { name: 'Certificar' }).click();
  await expect(page.locator('.rel-lista .rel-selo')).toHaveText('certificado');
  expect(erros).toEqual([]);
});

test('relatórios: área do fecho de caixa lida do servidor por período; Excel regista a exportação', async ({ page }) => {
  const { erros } = await preparar(page);
  await page.getByRole('combobox', { name: 'Área de dados' }).selectOption('caixa');
  const tab = page.locator('#s-rel-tabela');
  await expect(tab.locator('tbody tr')).toHaveCount(2);
  await expect(tab).toContainText('Aprovado');
  const exp = page.waitForRequest((r) => r.url().includes('/v1/exportacoes') && r.method() === 'POST');
  const [d] = await Promise.all([page.waitForEvent('download'), tab.getByRole('button', { name: 'Excel' }).click()]);
  expect((await exp).postDataJSON()).toMatchObject({ pagina: 'caixa-marketing' });
  expect(d.suggestedFilename()).toMatch(/\.xlsx$/);
  expect(erros).toEqual([]);
});
