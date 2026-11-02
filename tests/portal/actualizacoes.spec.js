// Administração › Actualizações: versões à espera de decisão com o resumo, aprovar (com confirmação da identidade),
// reprovar (motivo obrigatório), histórico e reposição; a página só existe para o super administrador.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const VISTA = {
  ok: true,
  instalada: { versao: 'v2026.11.02.5', commit: 'd3b70f5', aplicada_em: '2026-11-03 02:15:09', recusada: '' },
  configurada: true,
  automatica: true,
  exige_aprovacao: true,
  reversao_pedida: false,
  pode_reverter: true,
  ultima_execucao: { data: '2026-11-03 02:15:09', accao: 'actualizada', versao: 'v2026.11.02.5' },
  erro: '',
  pendentes: [
    {
      versao: 'v2026.11.04.7',
      publicada_em: '2026-11-04T10:00:00Z',
      commit: 'aaaaaaa',
      seccoes: [{ id: 'x', data: '04-11-2026', titulo: 'Plano de cargas', itens: ['**Zonas:** novo campo `zona`.'] }],
      notas: '',
      decisao: null,
      resultado: null,
    },
    {
      versao: 'v2026.11.03.6',
      publicada_em: '2026-11-03T15:00:00Z',
      commit: 'bbbbbbb',
      seccoes: [],
      notas: 'Alterações:\n- Correcção',
      decisao: null,
      resultado: null,
    },
  ],
  historico: [
    {
      versao: 'v2026.11.02.5',
      publicada_em: '2026-11-02T14:00:00Z',
      commit: 'd3b70f5',
      seccoes: [],
      notas: '',
      decisao: { decisao: 'aprovada', por: 'henrique', em: '2026-11-02 18:00:00' },
      resultado: { accao: 'actualizada', data: '2026-11-03 02:15:09' },
    },
  ],
};

async function preparar(page, perfil = 'SADM') {
  const pedidos = [];
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({ json: { login: 'henrique', perfil, csrf: 'x', permissoes: perfil === 'SADM' ? { actualizacoes: 'A' } : {} } }),
  );
  await page.route('**/v1/novidades*', (r) => r.fulfill({ json: { ok: true, actualizacoes: [] } }));
  await page.route('**/v1/actualizacoes*', (r) => {
    if (r.request().method() !== 'POST') return r.fulfill({ json: VISTA });
    const corpo = r.request().postDataJSON();
    pedidos.push(corpo);
    if (!corpo.confirmacao) return r.fulfill({ json: { ok: false, confirmar: 'senha' } });
    return r.fulfill({ json: { ok: true, decisao: { decisao: corpo.accao === 'reprovar' ? 'reprovada' : 'aprovada' } } });
  });
  return pedidos;
}

test('versões à espera: resumo, aprovar com confirmação da identidade', async ({ page }) => {
  const pedidos = await preparar(page);
  const erros = await abrir(page, '#actualizacoes');
  await expect(page.locator('.tile').first()).toContainText('v2026.11.02.5');
  await expect(page.locator('.tile').nth(1)).toContainText('2');
  const cartoes = page.locator('.act-cartao');
  await expect(cartoes).toHaveCount(2);
  await expect(cartoes.first().locator('h3')).toHaveText('Versão v2026.11.04.7');
  await expect(cartoes.first().locator('details')).toHaveAttribute('open', '');
  await expect(cartoes.first().locator('.act-resumo li strong')).toHaveText('Zonas:');
  await expect(cartoes.first().locator('.act-resumo li code')).toHaveText('zona');
  await cartoes.nth(1).locator('summary').click();
  await expect(cartoes.nth(1).locator('.act-resumo li')).toHaveText('Correcção');
  await cartoes.first().getByRole('button', { name: 'Aprovar', exact: true }).click();
  await expect(page.locator('dialog h3')).toHaveText('Aprovar a versão v2026.11.04.7');
  await page.locator('dialog').getByRole('button', { name: 'Aprovar' }).click();
  // o servidor pede a palavra-passe; o pedido repete-se com ela
  await expect(page.locator('dialog[open] h3').last()).toHaveText('Confirme a sua identidade');
  await page.locator('dialog input[type=password]').fill('segredo');
  await page.locator('dialog[open]').last().getByRole('button', { name: 'Confirmar' }).click();
  await expect.poll(() => pedidos.length).toBe(2);
  expect(pedidos[1]).toMatchObject({ accao: 'aprovar', versao: 'v2026.11.04.7', urgente: false, confirmacao: 'segredo', csrf: 'x' });
  await expect(page.locator('dialog[open]')).toHaveCount(0);
  await expect(page.locator('.act-hist-l').first()).toContainText('henrique');
  expect(erros).toEqual([]);
});

test('reprovar exige motivo; «instalar já» envia urgente', async ({ page }) => {
  const pedidos = await preparar(page);
  await abrir(page, '#actualizacoes');
  const c = page.locator('.act-cartao').nth(1);
  await c.getByRole('button', { name: 'Reprovar' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByRole('button', { name: 'Reprovar' }).click();
  await expect(dlg.locator('.erro')).toHaveText('Indique o motivo da reprovação.');
  expect(pedidos).toEqual([]);
  await dlg.locator('textarea').fill('Falta validar com a Logística');
  await dlg.getByRole('button', { name: 'Reprovar' }).click();
  await page.locator('dialog input[type=password]').fill('segredo');
  await page.locator('dialog[open]').last().getByRole('button', { name: 'Confirmar' }).click();
  await expect.poll(() => pedidos.length).toBe(2);
  expect(pedidos[1]).toMatchObject({ accao: 'reprovar', versao: 'v2026.11.03.6', motivo: 'Falta validar com a Logística' });
  await page.locator('.act-cartao').first().getByRole('button', { name: 'Aprovar e instalar já' }).click();
  await expect(page.locator('dialog[open] .dlg-h p')).toContainText('próximos 5 minutos');
  await page.locator('dialog[open]').getByRole('button', { name: 'Aprovar' }).click();
  await page.locator('dialog input[type=password]').fill('segredo');
  await page.locator('dialog[open]').last().getByRole('button', { name: 'Confirmar' }).click();
  await expect.poll(() => pedidos.length).toBe(4);
  expect(pedidos[3]).toMatchObject({ accao: 'aprovar', urgente: true });
});

test('outros perfis: a página não aparece no menu', async ({ page }) => {
  await preparar(page, 'TI');
  await abrir(page);
  await expect(page.locator('a[href="#actualizacoes"]')).toHaveCount(0);
});

test('ficheiros alterados à mão: lista e «Aplicar mesmo assim»; certificados HTTPS com aviso', async ({ page }) => {
  const vista = structuredClone(VISTA);
  vista.pendentes[0].decisao = { decisao: 'aprovada', por: 'henrique', em: '2026-11-04 11:00:00', urgente: false };
  vista.pendentes[0].resultado = { accao: 'conflito', data: '2026-11-05 02:15:04', ficheiros: ['nucleo/texto.php', 'geo/login.php'] };
  vista.certificados = {
    verificado_em: '2026-11-05 02:15:00',
    lista: [
      { endereco: 'geo.naval.co.ao:2051', dias: 21, expira: '2026-11-26 10:00', emitente: "Let's Encrypt", erro: '' },
      { endereco: 'id.naval.co.ao:443', dias: 80, expira: '2027-01-24 10:00', emitente: "Let's Encrypt", erro: '' },
    ],
  };
  const pedidos = await preparar(page);
  await page.route('**/v1/actualizacoes*', (r) => {
    if (r.request().method() !== 'POST') return r.fulfill({ json: vista });
    const corpo = r.request().postDataJSON();
    pedidos.push(corpo);
    return r.fulfill({ json: corpo.confirmacao ? { ok: true, decisao: {} } : { ok: false, confirmar: 'senha' } });
  });
  await abrir(page, '#actualizacoes');
  const c = page.locator('.act-cartao').first();
  await expect(c.locator('.act-conflito li')).toHaveText(['nucleo/texto.php', 'geo/login.php']);
  await c.getByRole('button', { name: 'Aplicar mesmo assim' }).click();
  await expect(page.locator('dialog[open] .dlg-h p')).toContainText('ficam guardados na cópia de segurança');
  await page.locator('dialog[open]').getByRole('button', { name: 'Aplicar mesmo assim' }).click();
  await page.locator('dialog input[type=password]').fill('segredo');
  await page.locator('dialog[open]').last().getByRole('button', { name: 'Confirmar' }).click();
  await expect.poll(() => pedidos.length).toBe(2);
  expect(pedidos[1]).toMatchObject({ accao: 'forcar', versao: 'v2026.11.04.7', urgente: true });
  // quadro: o primeiro a expirar, com aviso abaixo de 30 dias
  const tile = page.locator('.tile', { hasText: 'Certificados HTTPS' });
  await expect(tile).toContainText('21 dias');
  await expect(tile).toContainText('geo.naval.co.ao:2051');
});
