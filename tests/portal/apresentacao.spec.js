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
    ultimo_mes: { mes: '2026-10', total: 30.5e6, variacao: 0.089 },
    media_mensal: 25.7e6,
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
  governanca: {
    contas: 20,
    mfa: 15,
    mfa_pct: 0.75,
    perfis: 12,
    recursos: 60,
    registos_mes: 1234,
    agt: { documentos: 400, comunicados: 390, erros: 4, pct: 0.975 },
  },
  sistemas: [
    { nome: 'Pinguim BO', funcao: 'Vendas, clientes e documentos', ligado: true },
    { nome: 'Servidor OLAP', funcao: 'Localização das equipas no terreno', ligado: false },
  ],
  novidades: [{ data: '03-11-2026', titulo: 'Novidade de teste', itens: ['**Função nova** que chegou nesta versão.'] }],
  roteiro: {
    actualizado: '2026-11-04',
    projecto: {
      titulo: 'Portal RTM',
      subtitulo: 'Transformação digital da operação comercial',
      publico: 'Conselho de Administração',
      apresentador: 'Henrique Fernandes',
      funcao: 'Gestor de Projectos Digitais',
    },
    ponto_partida: 'Informação dispersa.',
    visao: {
      periodo: 'Janeiro de 2027 a Junho de 2028',
      titulo: 'Plano Tecnológico do Grupo Naval',
      pilares: [{ titulo: 'Dados únicos do Grupo', texto: 'Um repositório de dados.' }],
    },
    futuro: [{ titulo: 'Ver', resumo: 'Cada visita confirmada no mapa', data: '2026-11-09', estado: 'em_curso' }],
    ia: [{ titulo: 'Encomendas por WhatsApp', resumo: 'O cliente encomenda por mensagem', data: '2026-11-23', estado: 'planeado' }],
    fases: [
      { titulo: 'Ver o terreno', inicio: '2026-11-09', fim: '2026-11-20', estado: 'em_curso' },
      { titulo: 'Prever e automatizar', inicio: '2026-11-23', fim: '2026-12-18', estado: 'planeado' },
    ],
    proximos: [{ etiqueta: 'Segurança', texto: 'Servidor web dedicado e isolado', data: '2026-11-20' }],
  },
};

test('apresentação ao Conselho: 10 diapositivos corporativos com dados reais, governação, arquitectura, roteiro e visão', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) => r.fulfill({ json: DADOS }));
  const erros = await abrir(page, '#apresentacao');
  const cx = page.locator('.cx');
  const activa = cx.locator('.cx-slide.activa');
  await expect(cx.locator('.cx-ponto')).toHaveCount(10);
  // capa
  await expect(activa).toContainText('Portal RTM');
  await expect(activa).toContainText('Conselho de Administração');
  await expect(activa).toContainText('Henrique Fernandes');
  // sumário executivo
  await cx.press('ArrowRight');
  await expect(activa).toContainText('Sumário executivo');
  await expect(activa).toContainText('131,7 M Kz de vendas em 6 meses');
  await expect(activa.locator('.cx-rodape')).toContainText('Confidencial');
  await expect(activa.locator('.cx-rodape')).toContainText('2 / 10');
  // desempenho: mensagem com o último mês completo
  await cx.press('ArrowRight');
  await expect(activa).toContainText('Outubro de 2026 fechou com 30,5 M Kz, mais 8,9%');
  await expect(activa.locator('.cx-barra')).toHaveCount(6);
  // carteira
  await cx.press('ArrowRight');
  await expect(activa).toContainText('50 clientes parados representam 2,5 M Kz');
  // automação
  await cx.press('ArrowRight');
  await expect(activa).toContainText('82 tarefas feitas sem intervenção humana');
  await expect(activa.locator('.cx-linha li', { hasText: '21:15' })).toContainText('12 em 30 dias');
  // governação
  await cx.press('ArrowRight');
  await expect(activa).toContainText('75,0%');
  await expect(activa).toContainText('97,5%');
  await expect(activa).toContainText('ISO/IEC 27001');
  // arquitectura
  await cx.press('ArrowRight');
  await expect(activa.locator('.cx-sis.on')).toHaveCount(1);
  await expect(activa).toContainText('Por ligar');
  // roteiro
  await cx.press('ArrowRight');
  await expect(activa.locator('.cx-fase').first()).toContainText('09-11-2026 – 20-11-2026');
  await expect(activa).toContainText('Encomendas por WhatsApp');
  // visão
  await cx.press('ArrowRight');
  await expect(activa).toContainText('Plano Tecnológico do Grupo Naval');
  await expect(activa).toContainText('Janeiro de 2027 a Junho de 2028');
  // recomendações
  await cx.press('ArrowRight');
  await expect(activa).toContainText('Recomendações · para conhecimento do Conselho');
  await expect(activa).toContainText('Servidor web dedicado e isolado');
  await expect(activa).toContainText('20-11-2026');
  // o diapositivo cabe na página (ajuste por escala, sem deslocação horizontal)
  const caixa = await cx.locator('.cx-area').boundingBox();
  const pagina = await page.locator('#page').boundingBox();
  expect(caixa.width).toBeLessThanOrEqual(pagina.width + 1);
  expect(erros).toEqual([]);
});

test('apresentação: sem acesso às vendas mostra a automação e a governação, sem valores', async ({ page }) => {
  await page.route('**/v1/apresentacao**', (r) =>
    r.fulfill({ json: { ...DADOS, ve_valores: false, negocio: null, automacao: { ...DADOS.automacao, carteira: null } } }),
  );
  const erros = await abrir(page, '#apresentacao');
  const cx = page.locator('.cx');
  const activa = cx.locator('.cx-slide.activa');
  await cx.locator('.cx-ponto', { hasText: 'Desempenho' }).click();
  await expect(activa).toContainText('Disponível para quem acompanha as vendas');
  await expect(activa.locator('.cx-barra')).toHaveCount(0);
  await cx.locator('.cx-ponto', { hasText: 'Governação' }).click();
  await expect(activa).toContainText('contas activas');
  expect(erros).toEqual([]);
});
