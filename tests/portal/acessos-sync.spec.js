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
  await expect(page.locator('.rel-tab')).toHaveText(['Utilizadores', 'Perfis', 'Matriz de acessos', 'Registos de actividade']);
  await page.locator('.rel-tab', { hasText: 'Matriz de acessos' }).click();
  await expect(page.locator('#s-ac-m')).toBeVisible();
  await expect(page.locator('#s-ac-u')).toBeHidden();
  await expect(page.locator('.ac-activar')).toBeVisible();

  await page.getByRole('button', { name: 'Activar a gestão' }).click();
  const dlg = page.locator('dialog[open]');
  await expect(dlg.locator('tbody tr')).toHaveCount(2);
  // espera o foco inicial da janela (primeiro campo) antes de preencher
  await expect(dlg.getByLabel('Email · admin', { exact: true })).toBeFocused();
  await dlg.getByLabel('Email · admin', { exact: true }).fill('admin@naval.co.ao');
  await dlg.getByLabel('Email · michael.samson', { exact: true }).fill('michael.samson@naval.co.ao');
  await expect(dlg.getByLabel('Email · admin', { exact: true })).toHaveValue('admin@naval.co.ao');
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

test('Sincronização dos smartphones: estado por limites, ordem por gravidade e histórico de 14 dias', async ({ page }) => {
  await page.route('**/v1/sincronizacao*', (r) =>
    r.fulfill({
      json: {
        disponivel: true,
        agora: '2026-10-26 12:00:00',
        limites: { alerta_horas: 2, critico_horas: 24 },
        rotas: [
          { rota: '1001', promotor: 'Ana', ultima: '2026-10-26 11:30:00', minutos: 30, hoje: 4, sete_dias: 20, dias: { '2026-10-26': 4 }, estado: 'em_dia' },
          { rota: '1002', promotor: 'Rui', ultima: '2026-10-26 07:00:00', minutos: 300, hoje: 1, sete_dias: 9, dias: { '2026-10-26': 1 }, estado: 'atencao' },
          { rota: '1003', promotor: 'Eva', ultima: '2026-10-21 16:07:00', minutos: 6953, hoje: 0, sete_dias: 2, dias: { '2026-10-21': 2 }, estado: 'critico' },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#sincronizacao');
  const tiles = page.locator('#page .tile');
  await expect(tiles.nth(0)).toContainText('1 / 3');
  await expect(tiles.nth(2)).toContainText('1');
  const linhas = page.locator('#page table:not(.hm) tbody tr');
  await expect(linhas.first()).toContainText('Crítico');
  await expect(linhas.first()).toContainText('21-10-2026 16:07');
  await expect(linhas.first()).toContainText('há 4 dias');
  await expect(page.locator('table.hm thead th')).toHaveCount(15);
  await expect(page.locator('table.hm .hs-3')).toHaveCount(0);
  await expect(page.locator('table.hm .hs-2')).toHaveCount(1);
  expect(erros).toEqual([]);
});
