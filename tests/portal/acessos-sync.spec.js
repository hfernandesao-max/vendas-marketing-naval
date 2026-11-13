// Página Acessos (separadores e activação da gestão de contas) e página de sincronização dos smartphones.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const PERFIS = { SADM: 'Super administrador', TI: 'Administrador da plataforma', DIR: 'Administração e Direcção', SUP: 'Supervisor' };
const vistaAntiga = {
  perfis: PERFIS,
  perfis_info: Object.entries(PERFIS).map(([codigo, nome]) => ({ codigo, nome, sistema: true, activo: true, contas: 0 })),
  matriz: { ranking: { TI: 'V', DIR: 'V', SUP: 'Vr' } },
  matriz_base: { ranking: { TI: 'V', DIR: 'V', SUP: 'Vr' } },
  niveis: { ranking: ['', 'V'] },
  por_rota: ['SUP'],
  perfis_dois_passos: ['SADM', 'TI', 'DIR'],
  rotas: [{ rota: '1001', promotor: 'Ana' }],
  utilizadores: [
    { login: 'admin', nome: 'Administrador', email: '', perfil: 'TI', perfil_nome: PERFIS.TI, rotas: null, activo: true, ligada: true, compatibilidade: true },
    {
      login: 'michael.samson',
      nome: 'Michael Samson',
      email: '',
      perfil: 'DIR',
      perfil_nome: PERFIS.DIR,
      rotas: null,
      activo: true,
      ligada: true,
      compatibilidade: true,
    },
  ],
  registos: [],
  pode_gerir: false,
  pode_activar: true,
  compatibilidade: true,
  eu: 'admin',
};

test('Acessos: separadores e activação da gestão de contas com email, perfil e duas aprovações', async ({ page }) => {
  let enviado = null;
  await page.route('**/v1/gestao*', (r) => {
    if (r.request().method() === 'POST') {
      enviado = r.request().postDataJSON();
      return r.fulfill({ json: { ok: true, contas: 2 } });
    }
    return r.fulfill({ json: vistaAntiga });
  });
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { csrf: 'x' } }));
  const erros = await abrir(page, '#acessos');
  await expect(page.locator('.ac-activar')).toContainText('Activar a gestão de contas no portal');
  // os separadores dos relatórios (iguais aos das outras páginas) ficam por baixo do aviso de activação
  await expect(page.locator('.rel-tab')).toHaveText(['Utilizadores', 'Funções', 'Perfis', 'Matriz de acessos', 'Registos de actividade']);
  await page.locator('.rel-tab', { hasText: 'Matriz de acessos' }).click();
  await expect(page.locator('#s-ac-m')).toBeVisible();
  await expect(page.locator('#s-ac-u')).toBeHidden();
  await expect(page.locator('.ac-activar')).toBeVisible();

  await page.getByRole('button', { name: 'Activar a gestão' }).click();
  const dlg = page.locator('dialog[open]');
  await expect(dlg.locator('tbody tr')).toHaveCount(2);
  // espera o foco inicial da janela (primeiro campo) antes de preencher
  await expect(dlg.getByLabel('Correio electrónico · admin', { exact: true })).toBeFocused();
  await dlg.getByLabel('Correio electrónico · admin', { exact: true }).fill('admin@naval.co.ao');
  await dlg.getByLabel('Correio electrónico · michael.samson', { exact: true }).fill('michael.samson@naval.co.ao');
  await expect(dlg.getByLabel('Correio electrónico · admin', { exact: true })).toHaveValue('admin@naval.co.ao');
  await expect(dlg.getByLabel('Perfil · admin')).toBeDisabled();
  await dlg.getByPlaceholder('Nome', { exact: true }).nth(0).fill('Pessoa A');
  await dlg.getByPlaceholder('Nome', { exact: true }).nth(1).fill('Pessoa B');
  await dlg.getByRole('button', { name: 'Activar a gestão' }).click();
  await expect.poll(() => enviado && enviado.accao).toBe('activar_gestao');
  expect(enviado.contas.admin).toMatchObject({ email: 'admin@naval.co.ao', perfil: 'SADM', activo: true });
  expect(enviado.contas['michael.samson']).toMatchObject({ perfil: 'DIR', activo: true });
  expect(enviado.aprovado_por).toEqual(['Pessoa A', 'Pessoa B']);
  expect(erros).toEqual([]);
});

test('Sincronização dos smartphones: rota, data da última sincronização e distribuição das horas, pela ordem da consulta', async ({ page }) => {
  await page.route('**/v1/sincronizacao*', (r) =>
    r.fulfill({
      json: {
        disponivel: true,
        agora: '2026-10-26 12:00:00',
        limites: { alerta_horas: 2, critico_horas: 24 },
        rotas: [
          { rota: '1001', descricao: '1001 - FHR PIV', promotor: 'Ana', ultima: '2026-10-26 11:30:00', minutos: 30, horas: 0.5, estado: 'em_dia' },
          { rota: '1002', descricao: '1002 - FHR PIV', promotor: 'Rui', ultima: '2026-10-26 07:00:00', minutos: 300, horas: 5, estado: 'atencao' },
          { rota: '1003', descricao: '1003 - FHR PIV', promotor: 'Eva', ultima: '2026-10-21 16:07:00', minutos: 6953, horas: 115.9, estado: 'critico' },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#sincronizacao');
  const tiles = page.locator('#page .tile');
  await expect(tiles.nth(0)).toContainText('1 / 3');
  await expect(tiles.nth(2)).toContainText('1');
  // colunas por esta ordem
  await expect(page.locator('#s-sy-estado thead th')).toHaveText([/Rota/, /Data última sincronização/, /Distribuição das horas/]);
  const linhas = page.locator('#s-sy-estado tbody tr');
  await expect(linhas).toHaveCount(3);
  await expect(linhas.nth(0)).toContainText('1001 - FHR PIV');
  await expect(linhas.nth(0)).toContainText('26-10-2026 11:30');
  await expect(linhas.nth(2)).toContainText('1003 - FHR PIV');
  await expect(linhas.nth(2)).toContainText('há 4 dias');
  await expect(linhas.nth(2).locator('.sy-dist-b.critico')).toHaveCount(1);
  await expect(page.locator('table.hm')).toHaveCount(0); // sem histórico: a página usa só a consulta da última sincronização
  expect(erros).toEqual([]);
});

test('Acessos: última entrada e última alteração da palavra-passe com local e IP; registos de recuperação legíveis', async ({ page }) => {
  const local = { pais: 'AO', pais_nome: 'Angola', cidade: 'Luanda', raio_km: 20, lat: -8.8, lon: 13.2 };
  const vista = {
    ...vistaAntiga,
    compatibilidade: false,
    pode_activar: false,
    utilizadores: [
      {
        login: 'ana',
        nome: 'Ana',
        email: 'ana@naval.co.ao',
        email_ok: true,
        perfil: 'DIR',
        perfil_nome: PERFIS.DIR,
        rotas: null,
        activo: true,
        ligada: true,
        ultima_entrada: '2026-11-05 08:34:47',
        ultima_entrada_local: local,
        ultima_entrada_ip: '196.216.51.188',
        ultima_senha: { data: '2026-11-05 08:30:02', como: 'recuperacao', por: 'ana', ip: '196.216.51.188', local },
      },
    ],
    registos: [
      {
        data: '2026-11-05 08:30:02',
        utilizador: 'ana',
        perfil: '',
        ip: '196.216.51.188',
        accao: 'conta_recuperada',
        detalhe: { ip: '196.216.51.188', dois_passos: true, estava_bloqueada: true },
        local,
      },
      {
        data: '2026-11-05 08:28:00',
        utilizador: 'ana',
        perfil: '',
        ip: '196.216.51.188',
        accao: 'recuperacao_pedida',
        detalhe: { resultado: 'enviado' },
        local,
      },
    ],
  };
  await page.route('**/v1/gestao*', (r) => r.fulfill({ json: vista }));
  const erros = await abrir(page, '#acessos');
  const linha = page.locator('#s-ac-u tbody tr', { hasText: 'ana@naval.co.ao' });
  await expect(linha).toContainText('Luanda, Angola');
  await expect(linha).toContainText('196.216.51.188');
  await expect(linha).toContainText('Recuperação por correio electrónico');
  await expect(linha).toContainText('05-11-2026 08:30');
  await page.locator('.rel-tab', { hasText: 'Registos de actividade' }).click();
  const reg = page.locator('#s-ac-r');
  await expect(reg).toContainText('Acesso recuperado (nova palavra-passe)');
  await expect(reg).toContainText('Estava bloqueada: Sim');
  await expect(reg).toContainText('Pedido de recuperação do acesso');
  await expect(reg).toContainText('Resultado: enviado');
  await expect(reg).toContainText('Luanda, Angola');
  expect(erros).toEqual([]);
});
