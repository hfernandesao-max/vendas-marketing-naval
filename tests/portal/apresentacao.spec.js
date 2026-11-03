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
    actualizado: '2026-11-03',
    futuro: [{ titulo: 'Ver', resumo: 'Resumo ver.', detalhe: 'Detalhe ver.', estado: 'em_curso' }],
    ia: [{ titulo: 'WhatsApp com IA', resumo: 'Resumo IA.', detalhe: 'Detalhe IA.', estado: 'planeado' }],
    fases: [{ quando: 'Semanas 1 e 2', titulo: 'Ver o terreno', texto: 'Texto fase.', estado: 'em_curso' }],
    proximos: [{ etiqueta: 'Servidor', texto: 'Servidor web dedicado.', quando: 'Esta semana' }],
  },
};

test('apresentação: negócio e automação reais, horas configuradas, evolução e roteiro', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) => r.fulfill({ json: DADOS }));
  const erros = await abrir(page, '#apresentacao');
  const apx = page.locator('.apx');
  const activa = apx.locator('.apx-cena.activa');
  await expect(apx.locator('.apx-ponto')).toHaveCount(7);
  await expect(activa).toContainText('3,2 M Kz');
  await expect(activa).toContainText('▲ 28');
  await expect(activa).toContainText('812');
  await expect(activa).toContainText('03-11-2026 22:30');
  // negócio: gráfico com os 6 meses, o actual parcial
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-barra')).toHaveCount(6);
  await expect(activa.locator('.apx-barra.parcial')).toHaveCount(1);
  await expect(activa).toContainText('30,5');
  await expect(activa).toContainText('140 dias com vendas desde 01-06-2026');
  // carteira calculada esta noite
  await apx.press('ArrowRight');
  await expect(activa.locator('.apx-segmentos .apx-seg')).toHaveCount(3);
  await expect(activa).toContainText('Parados 50');
  await expect(activa).toContainText('420');
  await expect(activa).toContainText('2,5 M Kz');
  // o dia: a hora do fecho vem da configuração e a contagem do que foi feito
  await apx.press('ArrowRight');
  await apx.locator('.apx-hora', { hasText: '21:15' }).click();
  await expect(apx.locator('.apx-evento')).toContainText('Fecho do dia');
  await expect(apx.locator('.apx-evento')).toContainText('12 fechos enviados nos últimos 30 dias');
  await expect(activa).toContainText('82 tarefas feitas sozinhas');
  // evolução: novidades do registo de alterações
  await apx.press('ArrowRight');
  await expect(activa).toContainText('42');
  await expect(activa).toContainText('Função nova que chegou');
  // futuro
  await apx.press('ArrowRight');
  const c = activa.locator('.apx-cartao').first();
  await c.click();
  await expect(c).toHaveAttribute('aria-expanded', 'true');
  await expect(c).toContainText('Detalhe ver.');
  // fases e próximos passos (comunicação, sem pedidos)
  await apx.locator('.apx-ponto', { hasText: 'Fases' }).click();
  await expect(activa).toContainText('Servidor web dedicado.');
  await expect(activa).toContainText('Esta semana');
  await expect(activa).not.toContainText('decisões');
  await expect(activa).toContainText('Recomendações para os próximos passos');
  await expect(activa).toContainText('03-11-2026');
  expect(erros).toEqual([]);
});

test('apresentação: sem acesso às vendas mostra a automação e não os valores', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) =>
    r.fulfill({ json: { ...DADOS, ve_valores: false, negocio: null, automacao: { ...DADOS.automacao, carteira: null } } }),
  );
  const erros = await abrir(page, '#apresentacao');
  const activa = page.locator('.apx .apx-cena.activa');
  await expect(activa).toContainText('resumos do dia enviados sozinhos');
  await page.locator('.apx').press('ArrowRight');
  await expect(activa).toContainText('aparecem a quem tem acesso às páginas de vendas');
  await expect(activa.locator('.apx-barra')).toHaveCount(0);
  expect(erros).toEqual([]);
});
