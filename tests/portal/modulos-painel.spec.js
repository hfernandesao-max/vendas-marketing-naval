// Administração › Módulos (as peças do portal e o estado de cada uma) e Logística › Painel da carrinha (fecho de rota
// por excepção e origem dos dados), com o servidor simulado.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const MOD = {
  ok: true,
  modulos: [
    {
      id: 'nucleo',
      nome: 'Núcleo da plataforma',
      versao: '1.0.0',
      descricao: 'Entrada, sessão, página principal, alertas, apresentação, exportações e preferências. Não se desliga.',
      nucleo: true,
      estado: 'ligado',
      alterado_por: '',
      alterado_em: '',
      paginas: ['inicio'],
      api: 3,
      depende: [],
      eventos: { publica: ['sessao.aberta'], escuta: [] },
      conectores: [{ id: 'pinguim-sql', nome: 'Pinguim BO (SQL Server, só leitura)', estado: 'ligado' }],
    },
    {
      id: 'sap',
      nome: 'Integração SAP',
      versao: '1.0.0',
      descricao: 'Ficheiro de vendas para o SAP (estrutura do modelo original, sem alterações) e lotes emitidos.',
      nucleo: false,
      estado: 'piloto',
      alterado_por: 'henrique',
      alterado_em: '2026-11-07 14:00:00',
      paginas: ['sap-exportar'],
      api: 3,
      depende: ['nucleo'],
      eventos: { publica: ['sap.lote_emitido'], escuta: [] },
      conectores: [{ id: 'sap', nome: 'SAP (ficheiro de carregamento)', estado: 'ligado' }],
    },
    {
      id: 'logistica',
      nome: 'Logística e carrinhas',
      versao: '1.0.0',
      descricao: 'Plano de cargas, conciliação e painel da carrinha (fecho de rota), registo de cargas e stock por carrinha e armazém.',
      nucleo: false,
      estado: 'ligado',
      alterado_por: '',
      alterado_em: '',
      paginas: ['painel-carrinha'],
      api: 7,
      depende: ['nucleo'],
      eventos: { publica: [], escuta: [] },
      conectores: [{ id: 'quatenus', nome: 'Quatenus (GPS das viaturas)', estado: 'por_ligar' }],
    },
  ],
  conectores: [
    { id: 'pinguim-sql', nome: 'Pinguim BO (SQL Server, só leitura)', estado: 'ligado' },
    { id: 'quatenus', nome: 'Quatenus (GPS das viaturas)', estado: 'por_ligar' },
  ],
};

test('módulos: estado de cada peça, núcleo sem botões, piloto pede motivo e confirmação', async ({ page }) => {
  let corpo = null;
  await page.route('**/v1/modulos**', (r) => {
    if (r.request().method() === 'POST') {
      corpo = r.request().postDataJSON();
      return r.fulfill({ json: { ...MOD, modulos: MOD.modulos.map((m) => (m.id === 'sap' ? { ...m, estado: corpo.estado } : m)) } });
    }
    return r.fulfill({ json: MOD });
  });
  const erros = await abrir(page, '#modulos');
  await expect(page.locator('.mod-cartao')).toHaveCount(3);
  const nucleo = page.locator('.mod-cartao').filter({ hasText: 'Núcleo da plataforma' });
  await expect(nucleo.locator('button')).toHaveCount(0);
  const sap = page.locator('.mod-cartao').filter({ hasText: 'Integração SAP' });
  await expect(sap.locator('.pill').first()).toHaveText('Piloto');
  await expect(page.locator('.tile').filter({ hasText: 'Conectores por ligar' }).locator('.v')).toHaveText('1');
  await sap.locator('button', { hasText: 'Passar a ligado' }).click();
  await page.locator('dialog.dlg input').fill('validado com a Tesouraria');
  await page.locator('dialog.dlg button', { hasText: 'Confirmar' }).click();
  await expect.poll(() => corpo).toMatchObject({ id: 'sap', estado: 'ligado', motivo: 'validado com a Tesouraria' });
  await expect(sap.locator('.pill').first()).toHaveText('Ligado');
  expect(erros).toEqual([]);
});

test('painel da carrinha: primeiro as que pedem atenção; fechadas sozinhas; origem dos dados', async ({ page }) => {
  await page.route('**/v1/painel-carrinha**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        data: '2026-10-26',
        gerado: '2026-10-26 18:00:00',
        movimentos: 'separados',
        rotas: [
          {
            rota: '1001',
            promotor: 'Ana',
            fecho: 'fechada_auto',
            artigos: 12,
            alertas: 0,
            diferencas: 0,
            falta_kz: 0,
            sobra_kz: 0,
            carregado: 120,
            vendido: 100,
            contado_por: 'tec',
            contado_em: '2026-10-26 18:10:00',
            aprovado_por: 'automatico',
            aprovado_em: '2026-10-26 18:10:00',
            peso_kg: 310,
          },
          {
            rota: '1002',
            promotor: 'Bruno',
            fecho: 'por_aprovar',
            artigos: 9,
            alertas: 1,
            diferencas: 2,
            falta_kz: 4500,
            sobra_kz: 0,
            carregado: 90,
            vendido: 80,
            contado_por: 'tec',
            contado_em: '2026-10-26 18:20:00',
            aprovado_por: null,
            aprovado_em: null,
            peso_kg: null,
          },
          {
            rota: '1003',
            promotor: 'Carla',
            fecho: 'por_contar',
            artigos: 7,
            alertas: null,
            diferencas: null,
            falta_kz: null,
            sobra_kz: null,
            carregado: null,
            vendido: null,
            contado_por: null,
            contado_em: null,
            aprovado_por: null,
            aprovado_em: null,
            peso_kg: null,
          },
        ],
        fontes: [
          { id: 'pinguim-sql', bloco: 'Stock, vendas e movimentos', estado: 'ligado' },
          { id: 'contagens', bloco: 'Contagens da solução do Pinguim BO', estado: 'por_ligar' },
          { id: 'quatenus', bloco: 'Percurso, paragens e aberturas de porta (Quatenus)', estado: 'por_ligar' },
          { id: 'cctv', bloco: 'Imagens da carga e da descarga (CCTV)', estado: 'por_ligar' },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#painel-carrinha');
  await expect(page.locator('.pcar-cartao')).toHaveCount(3);
  await expect(page.locator('.pcar-cartao').first()).toHaveAttribute('data-rota', '1002');
  await expect(page.locator('.pcar-cartao[data-rota="1001"] .pill')).toHaveText('Fechada sozinha (sem diferenças)');
  await expect(page.locator('.pcar-cartao[data-rota="1003"]')).not.toContainText('Carregado');
  await expect(page.locator('.pcar-cartao[data-rota="1003"] a')).toHaveAttribute('href', '#conciliacao-carrinha');
  await expect(page.locator('.tile').filter({ hasText: 'Fechadas' }).locator('.v')).toHaveText('1');
  await expect(page.locator('.pcar-fontes li')).toHaveCount(4);
  expect(erros).toEqual([]);
});
