// Idiomas do portal (PT/EN/TI): o tigrínia cobre todos os textos da interface, mantém os marcadores {x}, não deixa
// palavras em português ou inglês (fora de siglas e nomes de sistemas) e o portal funciona em todas as páginas.
import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { EN } from '../../reports/js/i18n/en.js';
import { TI } from '../../reports/js/i18n/ti.js';
import { abrir } from './dados.js';

// Textos passados a t() no código (literais)
function literais() {
  const out = new Set();
  const andar = (d) =>
    readdirSync(d).forEach((f) => {
      const p = join(d, f);
      if (statSync(p).isDirectory()) {
        if (f !== 'i18n') andar(p);
      } else if (f.endsWith('.js')) {
        const s = readFileSync(p, 'utf8');
        for (const m of s.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)*)'/g)) out.add(m[1].replace(/\\'/g, "'"));
      }
    });
  andar('reports/js');
  return out;
}
const marcadores = (s) => (s.match(/\{[a-z0-9_]+\}/gi) || []).sort().join(',');
// Nomes de produtos e sistemas, siglas técnicas e identificadores (ficheiros, variáveis do .env, colunas do SAP) que
// ficam iguais em todas as línguas, como manda a prática internacional de localização
const PROTEGIDAS = new Set(
  (
    'SAP Alt PMR kg Excel Multicaixa MULTICAIXA Pinguim BO RTM Kz Microsoft Google WhatsApp Teams Authenticator GeoMobilidade Naval Grupo ' +
    'Foodtec FOODTEC HIGITEC REFITEC FHR PIV SADM TI DIR COM SUP PRO FIN LOG GEO AUD EXT OLAP QR PDF CSV SQL Server HTTP XML PHP SimpleXML ' +
    'SHA env DB_HOST DB_NAME DB_USER DB_PASS EMAIL_TENANT_ID EMAIL_CLIENT_ID EMAIL_CLIENT_SECRET EMAIL_REMETENTE RTM_CLIENTES_JSON ' +
    'rtm clientes geo auth config_users php ferramentas utilizadores importar antigo joao silva admin SYNC_PHSE_HISTORY SELECT sys ' +
    'INFORMATION_SCHEMA nvarchar n decimal p e datetime max NETSALES TAXAMOUNT GROSS_TOTAL SALES PERSON VENDAS RTM2 N A PDO MR AGT DOCHEADEREXTRA IP VPN DB Geolocation by MDM IMEI MAC Bluetooth ID NAV SMT IMP ATV_DB_NAME ATV_DB_DSN GitHub commit actualizar configurar ACME HTTPS ia RESUMO_ACTIVO B C ABC Quatenus CCTV NVR GPS REST ManageEngine Plus Maps ISO IEC ODBC XMLA OAuth ONVIF RTSP iPhone COPIAS_CHAVE COPIAS_PASTA_EXTERNA REGISTOS_COPIA_PASTA MB P V G TPA OData S HANA ZBV ZDP ZF v Higitec Refitech'
  ).split(' '),
);
// Palavras portuguesas que não podem aparecer nas traduções inglesas
const PT_EM_EN =
  /\b(de|da|das|dos|com|sem|para|pelo|pela|não|uma|cliente|clientes|vendas|rota|rotas|armazém|carrinha|factura|cobrança|numerário|página|período|gerado|utilizador)\b/i;
const SO_PT_EN = /rtm-clientes|RTM_CLIENTES_JSON|«Clientes»|geo\/auth|ferramentas\/|joao\.silva|Pinguim|Foodtec|Multicaixa/g;

test('tigrínia: todos os textos traduzidos, com os mesmos marcadores e sem português nem inglês', () => {
  const chaves = new Set([...Object.keys(EN), ...literais()]);
  const faltam = [...chaves].filter((k) => TI[k] == null || TI[k] === '');
  expect(faltam, 'textos sem tradução para tigrínia').toEqual([]);
  const marcas = [...chaves].filter((k) => marcadores(k) !== marcadores(TI[k]));
  expect(marcas, 'marcadores {x} diferentes').toEqual([]);
  const latinas = [...chaves].filter((k) =>
    (TI[k].replace(/<[^>]+>|\{[a-z0-9_]+\}/gi, '').match(/[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ_]*/g) || []).some((w) => !PROTEGIDAS.has(w)),
  );
  expect(latinas, 'palavras latinas fora das siglas e nomes de sistemas').toEqual([]);
  const ptEmEn = [...chaves].filter((k) => EN[k] != null && PT_EM_EN.test(EN[k].replace(SO_PT_EN, ' ')));
  expect(ptEmEn, 'palavras portuguesas nas traduções inglesas').toEqual([]);
});

// Textos mostrados sem tradução: a função t() regista-os quando a página o pede (controlo de qualidade).
// Só podem faltar códigos de perfil, que são iguais em todas as línguas.
for (const lang of ['ti', 'en']) {
  test(`${lang}: todas as páginas sem textos por traduzir`, async ({ page }) => {
    await page.addInitScript((l) => {
      localStorage.setItem('vm-lang', l);
      globalThis.__vmSemTraducao = {};
    }, lang);
    const erros = await abrir(page, '#inicio', { manterMemoria: true });
    const paginas = await page.locator('#nav a[href^="#"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    for (const h of [...new Set(paginas)]) {
      await page.evaluate((x) => (location.hash = x), h);
      await page.waitForTimeout(150);
    }
    const falta = await page.evaluate(() => Object.keys(globalThis.__vmSemTraducao));
    // textos já traduzidos que voltam a passar por t() (mensagens guardadas) não contam
    const ja = new Set(Object.values(lang === 'ti' ? TI : EN));
    expect(falta.filter((s) => !/^[A-Z]{2,4}$/.test(s) && !ja.has(s))).toEqual([]);
    expect(erros).toEqual([]);
  });
}

test('tigrínia: escolha no topo, atributo lang, dias em ge’ez e todas as páginas sem erros', async ({ page }) => {
  const erros = await abrir(page, '#inicio');
  await page.click('#lang button[data-l="ti"]');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ti');
  await expect(page.locator('#lang button[data-l="ti"]')).toHaveClass(/on/);
  await expect(page.locator('#voltar-t')).toHaveText(TI['Página principal']);
  await expect(page.locator('#l-de')).toHaveText(TI['De']);
  // o tipo de letra ge'ez é servido pelo próprio portal
  const fonte = await page.request.get('/reports/css/fontes/noto-sans-ethiopic-ethiopic-400-normal.woff2');
  expect(fonte.ok()).toBe(true);
  const paginas = await page.locator('#nav a[href^="#"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  for (const h of [...new Set(paginas)]) {
    await page.evaluate((x) => (location.hash = x), h);
    await page.waitForTimeout(150);
  }
  expect(erros).toEqual([]);
  // a escolha fica guardada no navegador
  expect(await page.evaluate(() => localStorage.getItem('vm-lang'))).toBe('ti');
});

test('ao entrar, o portal abre no idioma da ficha da conta; a escolha no topo vale até à entrada seguinte', async ({ page }) => {
  let entrada = 1000;
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({ json: { login: 'ana', nome: 'Ana', perfil: 'SADM', perfil_nome: 'Super administrador', permissoes: {}, idioma: 'ti', entrada } }),
  );
  await abrir(page, '#inicio', { manterMemoria: true }); // contexto novo; manter a memória entre recargas
  await expect(page.locator('html')).toHaveAttribute('lang', 'ti');
  await expect(page.locator('#voltar-t')).toHaveText(TI['Página principal']);
  // muda para inglês no topo: mantém-se ao recarregar, na mesma sessão
  await page.click('#lang button[data-l="en"]');
  await page.reload();
  await page.waitForSelector('#page > *');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  // nova entrada: volta o idioma da ficha
  entrada = 2000;
  await page.reload();
  await page.waitForSelector('#page > *');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ti');
});

test('cartão da conta no menu: nome genérico e nome do perfil na língua escolhida; nomes de pessoas não mudam', async ({ page }) => {
  let nome = 'Administrador';
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({ json: { login: 'admin', nome, perfil: 'SADM', perfil_nome: 'Super administrador', permissoes: {}, idioma: 'ti', entrada: 1 } }),
  );
  await abrir(page, '#inicio', { manterMemoria: true });
  await expect(page.locator('#quem b')).toHaveText(TI['Administrador']);
  await expect(page.locator('#quem span')).toHaveText(TI['Super administrador']);
  await page.click('#lang button[data-l="en"]');
  await expect(page.locator('#quem b')).toHaveText('Administrator');
  await page.click('#lang button[data-l="pt"]');
  await expect(page.locator('#quem b')).toHaveText('Administrador');
  nome = 'Andrea Santos';
  await page.reload();
  await page.waitForSelector('#page > *');
  await expect(page.locator('#quem b')).toHaveText('Andrea Santos');
});
