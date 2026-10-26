// «Estrutura de dados»: dicionário de dados num só Excel (catálogo da base de dados, modelo do portal, matriz).
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

const ESTRUTURA = {
  gerado: '2026-10-26 23:59:00',
  perfis: { SADM: 'Super administrador', TI: 'Administrador da plataforma' },
  matriz: { 'estrutura-dados': { SADM: 'A', TI: 'E' }, ranking: { SADM: 'A', TI: 'V' } },
  bd: {
    disponivel: true,
    base: 'PingWinBO',
    motor: 'Microsoft SQL Server 16.0 Standard',
    objectos: [
      {
        esquema: 'dbo',
        objecto: 'DOCHEADER',
        tipo: 'Tabela',
        linhas: 125000,
        colunas: 3,
        chave_primaria: 'ID',
        relacoes: 1,
        criado: '2024-01-10 10:00:00',
        alterado: '2026-10-01 09:00:00',
        descricao: '',
      },
      {
        esquema: 'dbo',
        objecto: 'STORE',
        tipo: 'Tabela',
        linhas: 12,
        colunas: 2,
        chave_primaria: 'ID',
        relacoes: 0,
        criado: null,
        alterado: null,
        descricao: 'Lojas',
      },
    ],
    colunas: [
      {
        esquema: 'dbo',
        objecto: 'DOCHEADER',
        ordem: 1,
        coluna: 'ID',
        tipo: 'int',
        nulo: false,
        chave_primaria: true,
        identidade: true,
        calculada: false,
        omissao: null,
        descricao: '',
        chave_estrangeira: '',
      },
      {
        esquema: 'dbo',
        objecto: 'DOCHEADER',
        ordem: 2,
        coluna: 'STORE_ID',
        tipo: 'int',
        nulo: false,
        chave_primaria: false,
        identidade: false,
        calculada: false,
        omissao: null,
        descricao: '',
        chave_estrangeira: 'dbo.STORE.ID',
      },
      {
        esquema: 'dbo',
        objecto: 'DOCHEADER',
        ordem: 3,
        coluna: 'CALC_TOTAL_W_TAX',
        tipo: 'decimal(18,4)',
        nulo: true,
        chave_primaria: false,
        identidade: false,
        calculada: false,
        omissao: '((0))',
        descricao: '',
        chave_estrangeira: '',
      },
      {
        esquema: 'dbo',
        objecto: 'STORE',
        ordem: 1,
        coluna: 'ID',
        tipo: 'int',
        nulo: false,
        chave_primaria: true,
        identidade: true,
        calculada: false,
        omissao: null,
        descricao: '',
        chave_estrangeira: '',
      },
      {
        esquema: 'dbo',
        objecto: 'STORE',
        ordem: 2,
        coluna: 'DESCRIPTION',
        tipo: 'nvarchar(100)',
        nulo: true,
        chave_primaria: false,
        identidade: false,
        calculada: false,
        omissao: null,
        descricao: '',
        chave_estrangeira: '',
      },
    ],
    relacoes: [
      {
        relacao: 'FK_DOCHEADER_STORE',
        esquema: 'dbo',
        tabela: 'DOCHEADER',
        coluna: 'STORE_ID',
        tabela_ref: 'dbo.STORE',
        coluna_ref: 'ID',
        ao_apagar: 'NO_ACTION',
        ao_alterar: 'NO_ACTION',
      },
    ],
    indices: [
      { esquema: 'dbo', tabela: 'DOCHEADER', indice: 'PK_DOCHEADER', tipo: 'CLUSTERED', unico: true, chave_primaria: true, colunas: 'ID', incluidas: '' },
    ],
  },
};

test('estrutura de dados: um Excel com capa, catálogo da base de dados, modelo do portal e matriz', async ({ page }) => {
  await page.route('**/data/estrutura-dados.php*', (r) => r.fulfill({ json: ESTRUTURA }));
  const erros = await abrir(page, '#inicio');
  await expect(page.locator('#xl-est')).toContainText('Estrutura de dados');
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 60_000 }), page.click('#xl-est')]);
  expect(d.suggestedFilename()).toBe('RTM - Estrutura de Dados-26-10-2026.xlsx');
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  expect(wb.worksheets.map((w) => w.name)).toEqual([
    'Capa',
    'Tabelas e vistas',
    'Colunas',
    'Relações',
    'Índices',
    'Modelo do portal',
    'Matriz de acessos',
    'Perfis',
    'Fontes de dados',
  ]);
  const col = wb.getWorksheet('Colunas');
  expect(col.getRow(4).values.slice(1, 8)).toEqual(['Esquema', 'Objecto', 'Ordem', 'Coluna', 'Tipo de dados', 'Aceita nulos', 'PK']);
  expect(col.rowCount - 4).toBe(5);
  expect(col.getRow(6).getCell(8).value).toBe('dbo.STORE.ID');
  expect(col.getRow(5).getCell(7).value).toBe('PK');
  expect(col.views[0]).toMatchObject({ state: 'frozen', ySplit: 4 });
  expect(Object.keys(col.tables || {}).length + (col.getTables ? col.getTables().length : 0)).toBeGreaterThan(0);
  expect(wb.getWorksheet('Tabelas e vistas').getRow(5).getCell(4).value).toBe(125000);
  expect(wb.getWorksheet('Modelo do portal').rowCount).toBeGreaterThan(30);
  const capa = [];
  wb.getWorksheet('Capa').eachRow((r) => capa.push(r.values.map((v) => (v && v.text) || v).join(' ')));
  expect(capa.join(' ')).toContain('PingWinBO');
  expect(capa.join(' ')).toContain('2 tabelas, 0 vistas, 5 colunas, 1 relações, 1 índices');
  expect(capa.join(' ')).toContain('não contém dados de clientes');
  expect(erros).toEqual([]);
});

test('estrutura de dados sem base de dados: segue com o modelo do portal e indica o motivo na capa', async ({ page }) => {
  await page.route('**/data/estrutura-dados.php*', (r) =>
    r.fulfill({
      json: {
        perfis: ESTRUTURA.perfis,
        matriz: ESTRUTURA.matriz,
        bd: { disponivel: false, erro: 'A ligação à base de dados do Pinguim BO não está configurada no .env do servidor.' },
      },
    }),
  );
  await abrir(page, '#inicio');
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 60_000 }), page.click('#xl-est')]);
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  expect(wb.worksheets.map((w) => w.name)).toEqual(['Capa', 'Modelo do portal', 'Matriz de acessos', 'Perfis', 'Fontes de dados']);
  const capa = [];
  wb.getWorksheet('Capa').eachRow((r) => capa.push(r.values.join(' ')));
  expect(capa.join(' ')).toContain('não está configurada');
});
