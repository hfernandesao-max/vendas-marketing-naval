// Logística › Conciliação da carrinha (contagem cega, diferenças e aprovação com motivo) e limite de peso no plano de cargas.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linhaCompleta = (codigo, artigo, esperado, contado = null) => ({
  codigo,
  artigo,
  unidade: 'CX',
  inicial: 10,
  carregado: 20,
  vendido: 30 - esperado,
  devolvido: 0,
  final: esperado,
  esperado,
  contado,
  diferenca: contado == null ? null : contado - esperado,
  preco: 1000,
  valor: contado == null ? null : (contado - esperado) * 1000,
  alertas: [],
});
const MOTIVOS = { avaria: 'Produto danificado', falta: 'Falta sem justificação', outro: 'Outro (explicar na nota)' };

test('conciliação: contagem cega, PDF, validação de quem contou e dois níveis de aprovação', async ({ page }) => {
  let estado = 'por_contar',
    eu = 'tec';
  const reg = {};
  const pedidos = [];
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      const agora = '2026-10-26 18:30:00';
      if (b.accao === 'contar') {
        estado = 'por_validar';
        Object.assign(reg, { contado_por: eu, contado_em: agora });
        return r.fulfill({ json: { ok: true, resumo: { diferencas: 1 } } });
      }
      if (b.accao === 'validar') {
        estado = 'por_aprovar';
        Object.assign(reg, { validado_por: eu, validado_em: agora });
        return r.fulfill({ json: { ok: true } });
      }
      if (b.accao === 'aprovar') {
        if (!b.motivos.A1 || !b.motivos.A1.motivo) return r.fulfill({ json: { ok: false, erro: 'Indique o motivo de cada diferença:', erros: ['Fraldas M'] } });
        estado = 'por_fechar';
        Object.assign(reg, { coordenado_por: eu, coordenado_em: agora });
        return r.fulfill({ json: { ok: true } });
      }
      estado = 'aprovada';
      Object.assign(reg, { aprovado_por: eu, aprovado_em: agora });
      return r.fulfill({ json: { ok: true } });
    }
    const contada = estado !== 'por_contar';
    const linhas = contada
      ? [linhaCompleta('A1', 'Fraldas M', 4, 3), linhaCompleta('B2', 'Sabão', 2, 2)]
      : [
          { codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX', contado: null, alertas: [] },
          { codigo: 'B2', artigo: 'Sabão', unidade: 'CX', contado: null, alertas: [] },
        ];
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        pode: { contar: true, coordenar: true, fechar: true },
        eu,
        data: '2026-10-26',
        movimentos: 'separados',
        motivos: MOTIVOS,
        rotas: [
          {
            rota: '1001',
            promotor: 'Andrea Santos',
            cega: !contada,
            linhas,
            ...reg,
            motivos: ['por_fechar', 'aprovada'].includes(estado) ? { A1: { motivo: 'avaria', nota: '' } } : {},
            resumo: { estado, artigos: 2, alertas: 0, diferencas: contada ? 1 : 0, falta_kz: contada ? 1000 : 0, sobra_kz: 0 },
            historico: [],
          },
        ],
      },
    });
  });
  // PDF: sem separador novo (bloqueado), descarrega; o texto do PDF lê-se do ficheiro (jsPDF sem compressão)
  await page.addInitScript(() => (window.open = () => null));
  const pdfTexto = async (botao) => {
    const [d] = await Promise.all([page.waitForEvent('download'), botao.click()]);
    const fs = await import('node:fs');
    if (process.env.GUARDAR_PDF) fs.copyFileSync(await d.path(), process.env.GUARDAR_PDF + d.suggestedFilename());
    return { nome: d.suggestedFilename(), texto: fs.readFileSync(await d.path(), 'latin1') };
  };
  const actualizar = async () => {
    await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
    await page.locator('.pc-barra').getByRole('button', { name: 'Actualizar' }).click();
  };
  const erros = await abrir(page, '#conciliacao-carrinha');
  const rota = page.locator('.cc-rota', { hasText: '1001' });
  await expect(rota).toContainText('Por contar');
  await rota.getByRole('button', { name: 'Contar' }).click();
  // cega: só o artigo e a quantidade que voltou
  await expect(rota.locator('th')).toHaveText(['Artigo', 'Quantidade que voltou']);
  // folha de contagem às cegas para imprimir: artigos, sem as quantidades do sistema
  const folha = await pdfTexto(rota.getByRole('button', { name: 'Imprimir folha de contagem' }));
  expect(folha.nome).toBe('CC-1001-20261026_folha-de-contagem.pdf');
  expect(folha.texto).toContain('Fraldas M');
  expect(folha.texto).toContain('Quantidade');
  expect(folha.texto).not.toContain('Tem de voltar');
  await rota.getByLabel('Fraldas M').fill('3');
  // o que já foi escrito não se perde: a página redesenha (Actualizar) e o número volta
  await expect(rota.locator('.cc-rascunho')).toContainText('Rascunho guardado');
  await page.locator('.pc-barra').getByRole('button', { name: 'Actualizar' }).click();
  await expect(rota.getByLabel('Fraldas M')).toHaveValue('3');
  await expect(rota.locator('.cc-rascunho')).toContainText('Rascunho reposto');
  // guardado neste navegador (para voltar a abrir depois de recarregar ou fechar o separador)
  const rasc = await page.evaluate(() => JSON.parse(localStorage.getItem('vm-rascunho-contagem-2026-10-26-1001')));
  expect(rasc.v).toEqual({ A1: '3', B2: '' });
  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect(rota.locator('.erro')).toContainText('Preencha todos os artigos');
  await rota.getByLabel('Sabão').fill('2');
  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'contar', rota: '1001', contagem: { A1: 3, B2: 2 } });
  // gravado: o rascunho apaga-se quando chega a resposta do servidor
  await expect.poll(() => page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('vm-rascunho-')))).toEqual([]);

  // 1. quem contou confere (colunas por cor: sistema, contagem física, diferença) e valida
  await expect(rota).toContainText('Por validar');
  const det = page.locator('.cc-detalhe');
  await expect(det.locator('th.cc-g-sis', { hasText: 'Sistema · Pinguim BO' })).toBeVisible();
  await expect(det.locator('th.cc-g-cont', { hasText: 'Contagem física' })).toBeVisible();
  await expect(det.locator('tr.cc-falta td.cc-neg').first()).toContainText('-1');
  await expect(det.locator('.cc-circuito li')).toHaveCount(4);
  await expect(det.locator('.cc-circuito li.actual')).toContainText('Validada por quem contou');
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Validar contagem' }).click();
  await expect(rota).toContainText('Por aprovar (coordenador)');
  // quem contou não aprova (segregação de funções)
  await expect(det.getByRole('button', { name: 'Aprovar (coordenador)' })).toHaveCount(0);
  await expect(det).toContainText('quem contou não aprova');

  // 2. coordenador: motivo obrigatório em cada diferença
  eu = 'coord';
  await actualizar();
  await det.getByRole('button', { name: 'Aprovar (coordenador)' }).click();
  await expect(det.locator('.erro')).toContainText('Fraldas M');
  await det.getByLabel('Motivo · Fraldas M').selectOption('avaria');
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Aprovar (coordenador)' }).click();
  await expect(rota).toContainText('Por aprovar (responsável)');
  expect(pedidos.find((x) => x.accao === 'aprovar' && x.motivos.A1.motivo).motivos.A1.motivo).toBe('avaria');
  await expect(det.getByRole('button', { name: 'Aprovar e fechar' })).toHaveCount(0);

  // 3. responsável: aprovação final, fica fechada
  eu = 'resp';
  await actualizar();
  await det.getByRole('button', { name: 'Aprovar e fechar' }).click();
  await expect(rota).toContainText('Fechada');
  await expect(det.locator('.cc-circuito li.feito')).toHaveCount(4);

  // PDF com o resultado, igual à tabela do ecrã, com o circuito de aprovação
  const res = await pdfTexto(page.getByRole('button', { name: 'PDF da contagem' }));
  expect(res.nome).toBe('CC-1001-20261026_resultado-da-contagem.pdf');
  expect(res.texto).toContain('Sistema');
  expect(res.texto).toContain('Devolvido');
  expect(res.texto).toContain('Produto danificado');
  expect(res.texto).toContain('coord');
  expect(res.texto).toContain('resp');
  expect(erros).toEqual([]);
});
