// Ajuda viva: painel com a base de conhecimento do portal (esta página, regras, novidades, procura), «Porquê?» nos
// estados e a pergunta ao assistente com os dados de quem pergunta.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const PAGINA = {
  ok: true,
  pagina: { tipo: 'pagina', titulo: 'Consolidado diário de vendas', texto: 'Resumo no formato do SALES REPORT.', paginas: ['relatorio-vendas'] },
  novidades: [
    {
      tipo: 'novidade',
      titulo: 'Abertura e fecho do dia no Pinguim BO ligados ao portal',
      data: '25-11-2026',
      texto: 'O fecho prova as vendas completas.',
      paginas: ['relatorio-vendas', 'sincronizacao'],
    },
  ],
  regras: [
    { tipo: 'regra', titulo: 'prontidão: só gera com tudo completo; cada fonte em falta diz o que falta', texto: '', paginas: ['relatorio-vendas'] },
    { tipo: 'regra', titulo: 'fecho do dia: o dia fechado no Pinguim BO (FCL) prova as vendas completas', texto: '', paginas: ['relatorio-vendas'] },
  ],
  funcionamento: [],
};
const CTL = {
  pinguim: { estado: 'pendente', detalhe: 'Rotas sem o dia fechado no Pinguim BO e sem sincronizar depois das 15:00: 1002, 1004.', rotas: ['1002', '1004'] },
  conciliacao: { estado: 'ok', detalhe: '10 carrinhas conciliadas e fechadas.' },
  sap_ficheiro: { estado: 'ok', detalhe: '40 documentos carregados no SAP.' },
  caixa: { estado: 'ok', detalhe: 'Fecho de caixa aprovado.' },
};

async function preparar(page, pedidosIA) {
  await page.route('**/v1/conhecimento**', (r) => {
    const u = new URL(r.request().url());
    if (u.searchParams.get('q'))
      return r.fulfill({
        json: {
          ok: true,
          artigos: [
            {
              tipo: 'novidade',
              titulo: 'Ficheiro para o SAP: lotes complementares',
              data: '24-11-2026',
              texto: 'Nunca se anula um lote carregado.',
              paginas: ['sap-exportar'],
            },
          ],
        },
      });
    return r.fulfill({ json: PAGINA });
  });
  await page.route('**/v1/relatorio-vendas**', (r) =>
    r.fulfill({ json: { ok: true, pode_gerar: true, dia: '2026-10-25', pronto: false, controlos: CTL, versoes: [] } }),
  );
  await page.route('**/v1/assistente', (r) => {
    if (r.request().method() === 'POST') {
      pedidosIA.push(JSON.parse(r.request().postData()));
      return r.fulfill({
        json: { ok: true, resposta: 'Faltam as rotas **1002** e **1004**: não fecharam o dia no Pinguim BO.', fontes: ['fecho_do_dia', 'conhecimento'] },
      });
    }
    return r.fulfill({ json: { ok: true, configurada: true, modelo: 'm', uso: { minhas_hoje: 0 }, limites: { perguntas_dia: 30 } } });
  });
}

test('ajuda: o botão da barra (e F1) abre a página explicada, as regras, as novidades e a procura', async ({ page }) => {
  const ia = [];
  await preparar(page, ia);
  const erros = await abrir(page, '#relatorio-vendas');
  await page.locator('#ajuda-b').click();
  const p = page.locator('dialog.aj-dlg');
  await expect(p).toBeVisible();
  await expect(p.locator('.aj-pag')).toContainText('Resumo no formato do SALES REPORT.');
  await expect(p.locator('.aj-regra')).toHaveCount(2);
  await expect(p.locator('.aj-novidade')).toContainText('Abertura e fecho do dia no Pinguim BO');
  await p.getByRole('searchbox', { name: 'Procurar na ajuda' }).fill('lote complementar');
  await expect(p.locator('.aj-res')).toContainText('Ficheiro para o SAP: lotes complementares');
  // Esc: o primeiro limpa a procura (como no navegador), o segundo fecha o painel
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  await expect(p).toHaveCount(0);
  await page.keyboard.press('F1');
  await expect(page.locator('dialog.aj-dlg')).toBeVisible();
  expect(erros).toEqual([]);
});

test('«Porquê?» no Fecho do dia: o motivo com os dados, as regras do tema e «Perguntar ao assistente» já escrito', async ({ page }) => {
  const ia = [];
  await preparar(page, ia);
  const erros = await abrir(page, '#relatorio-vendas');
  const li = page.locator('.rv-lista li.rv-pendente');
  await expect(li.locator('.aj-porque-b')).toHaveCount(1);
  await expect(page.locator('.rv-lista li.rv-ok .aj-porque-b')).toHaveCount(0);
  await li.locator('.aj-porque-b').click();
  const p = page.locator('dialog.aj-dlg');
  await expect(p.locator('.aj-porque-c')).toContainText('1002, 1004');
  // a regra do tema (Pinguim BO) vem primeiro
  await expect(p.locator('.aj-regra').first()).toContainText('Pinguim BO');
  const ta = p.getByRole('textbox', { name: 'Pergunta ao assistente' });
  await expect(ta).toHaveValue(/Fecho do dia de .* está «Em falta»/);
  await p.getByRole('button', { name: 'Perguntar' }).click();
  await expect(p.locator('.aj-resp strong').first()).toHaveText('1002');
  expect(ia[0].pergunta).toContain('Vendas do Pinguim BO completas');
  expect(erros).toEqual([]);
});
