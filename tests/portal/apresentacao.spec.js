// Gestão › Apresentação do portal: negócio e automação com os dados da API, horas configuradas, evolução e roteiro.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const MESES = ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'].map((mes, i) => ({
  mes,
  total: [20e6, 24e6, 26e6, 28e6, 30.5e6, 3.2e6][i],
  documentos: 100 + i,
  clientes: 50 + i,
  parcial: i === 5,
}));
const DADOS = {
  ok: true,
  hora: '2026-11-03 22:30',
  versao: 'v2026.11.03.26',
  perfis: 11,
  ve_valores: true,
  novidades_total: 42,
  horas: { aprovar: '02:45', instalar: '03:00', calculo: '04:00', resumo: '07:00', plano: '18:00', carrinha: '19:30', fecho: '21:15' },
  negocio: {
    meses: MESES,
    mes: { de: '2026-11-01', total: 3200000, anterior: 2500000, variacao: 0.28, documentos: 105, clientes: 55, rotas: 9, valor_medio: 58000 },
    clientes_30: 812,
    dias_com_dados: 140,
    desde: '2026-06-01',
    ate: '2026-11-03',
  },
  automacao: {
    dias: 30,
    resumos: 21,
    calculos: 14,
    planos: 9,
    fechos: 12,
    versoes: 26,
    carteira: {
      em_dia: 600,
      a_abrandar: 150,
      parado: 50,
      calculado: '2026-11-03 04:12:00',
      encomendas: 420,
      valor_parados: 2500000,
      anomalias: 7,
      qualidade: 3,
    },
  },
  novidades: [{ data: '03-11-2026', titulo: 'Novidade de teste', itens: ['**Função nova** que chegou nesta versão.'] }],
  roteiro: {
    actualizado: '2026-11-04',
    futuro: [{ titulo: 'Ver', resumo: 'Cada visita confirmada no mapa', data: '2026-11-09', estado: 'em_curso' }],
    ia: [{ titulo: 'Encomendas por WhatsApp', resumo: 'O cliente encomenda por mensagem', data: '2026-11-23', estado: 'planeado' }],
    fases: [
      { titulo: 'Ver o terreno', inicio: '2026-11-09', fim: '2026-11-20', estado: 'em_curso' },
      { titulo: 'Prever e automatizar', inicio: '2026-11-23', fim: '2026-12-18', estado: 'planeado' },
    ],
    proximos: [{ etiqueta: 'Segurança', texto: 'Servidor web dedicado e isolado', data: '2026-11-20' }],
  },
};

test('apresentação para o Conselho: números reais, automação, futuro com datas e calendário', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) => r.fulfill({ json: DADOS }));
  const erros = await abrir(page, '#apresentacao');
  const apx = page.locator('.apx');
  const activa = apx.locator('.apx-cena.activa');
  await expect(apx.locator('.apx-ponto')).toHaveCount(6);
  await expect(activa).toContainText('3,2 M Kz');
  await expect(activa).toContainText('▲ 28');
  await expect(activa).toContainText('812');
  await expect(activa).toContainText('03-11-2026');
  // negócio: gráfico dos 6 meses, o actual em curso
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-barra')).toHaveCount(6);
  await expect(activa.locator('.apx-barra.parcial')).toHaveCount(1);
  await expect(activa).toContainText('30,5');
  // clientes
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-segmentos .apx-seg')).toHaveCount(3);
  await expect(activa).toContainText('Parados 50');
  await expect(activa).toContainText('2,5 M Kz');
  // automação: hora configurada e contagem
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-passo', { hasText: '21:15' })).toContainText('Fecho do dia');
  await expect(activa.locator('.apx-passo', { hasText: '21:15' })).toContainText('12 em 30 dias');
  await expect(activa).toContainText('82');
  // futuro: datas concretas
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-cartao', { hasText: 'Encomendas por WhatsApp' })).toContainText('23-11-2026');
  // calendário e recomendações (para conhecimento, sem pedidos)
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-barra-f')).toHaveCount(2);
  await expect(activa.locator('.apx-barra-f').first()).toContainText('09-11-2026 – 20-11-2026');
  await expect(activa).toContainText('Recomendações · para conhecimento do Conselho');
  await expect(activa).toContainText('Servidor web dedicado e isolado');
  await expect(activa).toContainText('20-11-2026');
  await expect(activa).not.toContainText('decisões');
  expect(erros).toEqual([]);
});

test('apresentação: sem acesso às vendas mostra a automação e não os valores', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) =>
    r.fulfill({ json: { ...DADOS, ve_valores: false, negocio: null, automacao: { ...DADOS.automacao, carteira: null } } }),
  );
  const erros = await abrir(page, '#apresentacao');
  const activa = page.locator('.apx .apx-cena.activa');
  await expect(activa).toContainText('tarefas automáticas');
  await page.locator('.apx').press('ArrowRight');
  await expect(activa).toContainText('Disponível para quem acompanha as vendas');
  await expect(activa.locator('.apx-barra')).toHaveCount(0);
  expect(erros).toEqual([]);
});
