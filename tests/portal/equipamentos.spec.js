// Equipamentos: lista, ficha (pelo endereço do código QR), documento e assinatura no ecrã, com a API simulada.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const eq = (o) => ({
  id: 'NAV-SMT-0001',
  tipo: 'SMT',
  marca: 'Blackview',
  modelo: 'BV6200 Pro',
  num_serie: 'BV6200ProNEU0031328',
  imei1: '353326512163561',
  imei2: '353326512163579',
  mac: null,
  telefone: null,
  mdm_id: '1001@naval.co.ao',
  estado: 'ATRIBUIDO',
  rota: '1001',
  id_colaborador: 1,
  responsavel: 'Andrea Santos',
  associado: 'NAV-IMP-0001',
  sem_termo: true,
  criado_em: '2026-10-31 09:00:00',
  ...o,
});
const LISTA = {
  disponivel: true,
  equipamentos: [
    eq({}),
    eq({ id: 'NAV-IMP-0001', tipo: 'IMP', marca: 'Datecs', modelo: 'DD-RMT9BT', mac: '00:12:5B:04:2E:BC', associado: 'NAV-SMT-0001' }),
    eq({ id: 'NAV-SMT-0002', rota: '1002', responsavel: 'Cristina Sachuma', associado: null, sem_termo: false }),
    eq({ id: 'NAV-SMT-0004', estado: 'EM_STOCK', rota: null, responsavel: null, associado: null, sem_termo: false, id_colaborador: null }),
  ],
  resumo: {},
  colaboradores: [
    { id: 1, nome: 'Andrea Santos', funcao: 'Comercial', rota: '1001', activo: true, convencao: 'CPE-2026-000001' },
    { id: 2, nome: 'Cristina Sachuma', funcao: 'Comercial', rota: '1002', activo: true, convencao: null },
  ],
  tipos: [],
  pode: { movimentar: true, administrar: true, exportar: true },
};
const FICHA = {
  equipamento: eq({}),
  associado: { id: 'NAV-IMP-0001', tipo: 'IMP', estado: 'ATRIBUIDO', modelo: 'DD-RMT9BT' },
  eventos: [
    { data: '2026-10-31 10:15:00', tipo: 'LEITURA_QR', utilizador: 'Henrique Fernandes' },
    {
      data: '2026-10-31 09:30:00',
      tipo: 'ATRIBUICAO',
      utilizador: 'Logística',
      estado_antes: 'EM_STOCK',
      estado_depois: 'ATRIBUIDO',
      colaborador_depois: 'Andrea Santos',
      rota_depois: '1001',
    },
  ],
  associacoes: [],
  documentos: [{ id: 1, numero: 'TER-2026-000001', tipo: 'TERMO_ENTREGA', estado: 'A_ASSINAR', emissao: '2026-10-31 10:00:00' }],
  mdm_url: 'https://mdm.teste/1001',
  pode: LISTA.pode,
};
const DOC = {
  documento: {
    id: 1,
    numero: 'TER-2026-000001',
    tipo: 'TERMO_ENTREGA',
    estado: 'A_ASSINAR',
    emissao: '2026-10-31 10:00:00',
    colaborador: 'Andrea Santos',
    id_colaborador: 1,
    colaborador_utilizador: null,
    emitido_por: 'Logística',
    hash_original: 'ab'.repeat(32),
    hash_final: null,
  },
  equipamentos: [
    { id: 'NAV-SMT-0001', tipo: 'SMT', marca: 'Blackview', modelo: 'BV6200 Pro', papel: 'ENTREGUE', acessorios: 'carregador' },
    { id: 'NAV-IMP-0001', tipo: 'IMP', marca: 'Datecs', modelo: 'DD-RMT9BT', papel: 'ENTREGUE', acessorios: 'cabo' },
  ],
  assinaturas: [],
  pode: LISTA.pode,
};

async function simular(page, posts = []) {
  await page.route('**/v1/equipamentos**', async (r) => {
    const u = new URL(r.request().url());
    if (r.request().method() === 'POST') {
      const corpo = r.request().postDataJSON();
      posts.push(corpo);
      if (corpo.accao === 'assinar') return r.fulfill({ json: { ok: true, documento: { ...DOC.documento }, assinaturas: [{ ordem: 1 }] } });
      return r.fulfill({ json: { ok: true } });
    }
    if (u.pathname.endsWith('/equipamentos/ficha')) {
      if (u.searchParams.get('id') === 'NAV-SMT-0999')
        return r.fulfill({ status: 404, json: { ok: false, erro: 'Equipamento não registado.', codigo: 'atv_nao_registado' } });
      return r.fulfill({ json: FICHA });
    }
    if (u.pathname.endsWith('/equipamentos/documentos')) {
      if (u.searchParams.get('numero')) return r.fulfill({ json: DOC });
      return r.fulfill({ json: { documentos: [{ ...DOC.documento, equipamentos: ['NAV-SMT-0001', 'NAV-IMP-0001'], rota: '1001' }], pode: LISTA.pode } });
    }
    return r.fulfill({ json: LISTA });
  });
}

test('Equipamentos: menu próprio, quadros, lista com estados e ligação à ficha', async ({ page }) => {
  await simular(page);
  const erros = await abrir(page, '#equipamentos');
  await expect(page.locator('h1')).toHaveText('Equipamentos');
  await expect(page.locator('#nav')).toContainText('Documentos dos equipamentos');
  await expect(
    page
      .locator('.tile')
      .filter({ has: page.locator('.l', { hasText: /^Atribuídos$/ }) })
      .locator('.v'),
  ).toHaveText('3');
  await expect(page.locator('.tile', { hasText: 'Sem termo assinado' }).locator('.v')).toHaveText('2');
  await expect(page.locator('tr', { hasText: 'NAV-SMT-0004' })).toContainText('Em stock');
  await expect(page.locator('#s-equipamentos-colaboradores tr', { hasText: 'Cristina Sachuma' })).toContainText('Por assinar');
  // o período não se aplica a esta página
  await expect(page.locator('.filters')).toBeHidden();
  await page.locator('a', { hasText: 'NAV-SMT-0001' }).first().click();
  await expect(page.locator('.atv-cab h2')).toHaveText('NAV-SMT-0001');
  expect(page.url()).toContain('#equipamentos?id=NAV-SMT-0001');
  expect(erros).toEqual([]);
});

test('Equipamentos: ficha aberta pelo código QR, associado, documento por assinar e histórico', async ({ page }) => {
  let comQr = 0;
  await simular(page);
  // registada depois de simular(): as rotas mais recentes respondem primeiro
  await page.route('**/v1/equipamentos/ficha**', (r) => {
    if (new URL(r.request().url()).searchParams.get('origem') === 'qr') comQr++;
    return r.fallback();
  });
  const erros = await abrir(page, '#equipamentos?id=nav-smt-0001&origem=qr');
  await expect(page.locator('.atv-cab h2')).toHaveText('NAV-SMT-0001');
  await expect(page.locator('.atv-cab')).toContainText('Atribuído');
  await expect(page.locator('.atv-caixa', { hasText: 'Impressora associada' })).toContainText('NAV-IMP-0001');
  await expect(page.locator('.atv-pendente')).toContainText('Termo de Entrega e Responsabilidade por assinar');
  await expect(page.locator('.atv-hist')).toContainText('Leitura do código QR');
  await expect(page.locator('a', { hasText: 'Abrir no MDM' })).toHaveAttribute('href', 'https://mdm.teste/1001');
  expect(comQr).toBe(1);
  // a leitura do QR conta uma vez: o endereço deixa de levar «origem=qr»
  expect(page.url()).not.toContain('origem=qr');
  expect(erros).toEqual([]);
});

test('Equipamentos: ID não registado mostra a mensagem e o botão Registar', async ({ page }) => {
  await simular(page);
  await abrir(page, '#equipamentos?id=NAV-SMT-0999');
  await expect(page.locator('#page')).toContainText('Este equipamento ainda não está registado.');
  await expect(page.locator('#page button', { hasText: 'Registar equipamento' })).toBeVisible();
});

test('Equipamentos: registar evento envia a acção certa', async ({ page }) => {
  const posts = [];
  await simular(page, posts);
  await abrir(page, '#equipamentos?id=NAV-SMT-0001');
  await page.locator('button', { hasText: 'Registar evento' }).click();
  const dlg = page.locator('dialog.dlg');
  await expect(dlg).toBeVisible();
  await dlg.locator('select').first().selectOption('DEVOLUCAO');
  await dlg.locator('textarea').fill('Fim do contrato');
  await dlg.locator('button', { hasText: /^Registar$/ }).click();
  await expect(dlg).toBeHidden();
  expect(posts[0]).toMatchObject({ accao: 'evento', id: 'NAV-SMT-0001', tipo: 'DEVOLUCAO', observacoes: 'Fim do contrato', incluir_associado: true });
});

test('Equipamentos: assinatura no ecrã só com o desenho e «Li e aceito»', async ({ page }) => {
  const posts = [];
  await simular(page, posts);
  const erros = await abrir(page, '#equipamentos-documentos?doc=TER-2026-000001');
  await expect(page.locator('.atv-cab h2')).toHaveText('Termo de Entrega e Responsabilidade');
  await expect(page.locator('.atv-assinar')).toContainText('Assinatura 1 de 2');
  await expect(page.locator('.atv-assinar')).toContainText('Andrea Santos');
  const go = page.locator('button', { hasText: 'Assinar documento' });
  await expect(go).toBeDisabled();
  await expect(page.locator('#loader')).toBeHidden(); // o ecrã de carregamento já saiu de cima da página
  const tela = page.locator('canvas.atv-tela');
  await tela.scrollIntoViewIfNeeded();
  const c = await tela.boundingBox();
  await page.mouse.move(c.x + 20, c.y + c.height / 2);
  await page.mouse.down();
  for (let i = 1; i <= 30; i++) await page.mouse.move(c.x + 20 + i * 8, c.y + c.height / 2 + Math.sin(i / 3) * 30);
  await page.mouse.up();
  await expect(go).toBeDisabled();
  await page.locator('.atv-assinar input[type=checkbox]').check();
  await expect(go).toBeEnabled();
  await go.click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].accao).toBe('assinar');
  expect(posts[0].aceito).toBe(true);
  expect(posts[0].imagem_png).toMatch(/^data:image\/png;base64,/);
  expect(posts[0].imagem_jpeg).toMatch(/^data:image\/jpeg;base64,/);
  expect(posts[0].tracos[0].length).toBeGreaterThan(10);
  expect(erros).toEqual([]);
});

test('Equipamentos: sem o servidor do módulo mostra o motivo', async ({ page }) => {
  await page.route('**/v1/equipamentos**', (r) =>
    r.fulfill({
      json: { disponivel: false, erro: 'A base de dados do módulo Equipamentos ainda não está configurada no servidor (.env: ATV_DB_NAME ou ATV_DB_DSN).' },
    }),
  );
  await abrir(page, '#equipamentos');
  await expect(page.locator('#page')).toContainText('Não foi possível ler os equipamentos');
  await expect(page.locator('#page')).toContainText('ATV_DB_NAME');
});
