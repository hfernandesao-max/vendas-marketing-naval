#!/usr/bin/env node
// Robô do Pinguim BO: executa as ordens do portal (Guia de Remessa…) nos ecrãs do próprio Pinguim BO, no navegador,
// com uma conta técnica. Quem gera, numera e assina o documento é o Pinguim BO (software certificado); o robô só
// preenche e grava, como uma pessoa. O portal confere depois cada documento no Pinguim BO (SQL, só leitura).
//
//   node ferramentas/robo-pinguim.mjs                 corre sempre: pede uma ordem de 30 em 30 segundos
//   node ferramentas/robo-pinguim.mjs --uma-vez       executa no máximo uma ordem e sai (para a tarefa agendada)
//   node ferramentas/robo-pinguim.mjs --ver           com a janela do navegador visível (para afinar a receita)
//
// Configuração (variáveis de ambiente ou ficheiro .env do portal):
//   ROBO_PORTAL_URL         endereço do portal (ex.: https://portal.naval.ao)
//   ROBO_PINGUIM_CHAVE      a mesma chave do .env do portal (v1/robo/ordens)
//   ROBO_PINGUIM_URL        endereço do Pinguim BO no navegador
//   ROBO_PINGUIM_UTILIZADOR / ROBO_PINGUIM_SENHA   conta técnica (só Guias e Transferências)
//   ROBO_RECEITA            receita dos ecrãs (omissão: ferramentas/robo-pinguim.receita.json)
//   ROBO_NOME               nome do robô nos registos (omissão: o nome do computador)
// A receita diz, passo a passo, onde clicar e o que escrever (ver ferramentas/robo-pinguim.receita.exemplo.json).
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { hostname, tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const AQUI = dirname(fileURLToPath(import.meta.url));
const RAIZ = resolve(AQUI, '..');
const args = new Set(process.argv.slice(2));

/** .env do portal (para quem corre o robô no mesmo servidor); as variáveis de ambiente mandam. */
function lerEnv() {
  const f = process.env.NAVAL_ENV || join(RAIZ, '.env');
  const out = {};
  if (existsSync(f))
    for (const l of readFileSync(f, 'utf8').split(/\r?\n/)) {
      const m = l.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/);
      if (m) out[m[1]] = m[2].trim().replace(/^["']|["']$/g, '');
    }
  return { ...out, ...process.env };
}
const E = lerEnv();
const CFG = {
  portal: String(E.ROBO_PORTAL_URL || E.PORTAL_URL || '').replace(/\/$/, ''),
  chave: E.ROBO_PINGUIM_CHAVE || '',
  pinguim: String(E.ROBO_PINGUIM_URL || '').replace(/\/$/, ''),
  utilizador: E.ROBO_PINGUIM_UTILIZADOR || '',
  senha: E.ROBO_PINGUIM_SENHA || '',
  receita: E.ROBO_RECEITA || join(AQUI, 'robo-pinguim.receita.json'),
  nome: (E.ROBO_NOME || hostname()).replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 40) || 'robo',
  // capturas dos erros: na pasta privada do portal (fora da pasta pública), nunca junto do código
  capturas: join(E.DADOS_PRIVADOS || tmpdir(), 'robo-pinguim'),
};

const registar = (...m) => console.log(new Date().toISOString().slice(0, 19).replace('T', ' '), ...m);

async function portal(metodo, corpo) {
  const r = await fetch(CFG.portal + '/api/index.php/v1/robo/ordens' + (metodo === 'GET' ? '?robo=' + encodeURIComponent(CFG.nome) : ''), {
    method: metodo,
    headers: { Authorization: 'Bearer ' + CFG.chave, 'Content-Type': 'application/json' },
    body: corpo ? JSON.stringify({ robo: CFG.nome, ...corpo }) : undefined,
  });
  const d = await r.json().catch(() => ({}));
  if (!r.ok || !d.ok) throw new Error('portal: HTTP ' + r.status + ' ' + (d.erro || ''));
  return d;
}

/** Substitui {campo}, {linha.campo}, {url}, {utilizador}, {senha} num texto da receita. */
function valor(txt, ctx) {
  return String(txt).replace(/\{([a-z_.]+)\}/gi, (_, k) => {
    const v = k.split('.').reduce((o, p) => (o == null ? o : o[p]), ctx);
    return v == null ? '' : String(v);
  });
}

/** Executa uma lista de passos da receita na página. Devolve as variáveis guardadas (ex.: documento). */
async function passos(page, lista, ctx, guardar = {}) {
  for (const p of lista || []) {
    if (p.ir) await page.goto(valor(p.ir, ctx), { waitUntil: 'domcontentloaded' });
    else if (p.preencher) await page.locator(valor(p.preencher, ctx)).first().fill(valor(p.valor, ctx));
    else if (p.escolher)
      await page
        .locator(valor(p.escolher, ctx))
        .first()
        .selectOption({ label: valor(p.valor, ctx) })
        .catch(() => page.locator(valor(p.escolher, ctx)).first().selectOption(valor(p.valor, ctx)));
    else if (p.clicar) await page.locator(valor(p.clicar, ctx)).first().click();
    else if (p.tecla) await page.keyboard.press(p.tecla);
    else if (p.esperar)
      await page
        .locator(valor(p.esperar, ctx))
        .first()
        .waitFor({ timeout: p.tempo || 20000 });
    else if (p.esperar_ms) await page.waitForTimeout(Math.min(10000, p.esperar_ms));
    else if (p.ler_texto)
      guardar[p.guardar || 'texto'] = (
        await page
          .locator(valor(p.ler_texto, ctx))
          .first()
          .innerText({ timeout: p.tempo || 20000 })
      ).trim();
    else if (p.para_cada_linha) for (const linha of ctx.linhas) await passos(page, p.para_cada_linha, { ...ctx, linha }, guardar);
    else throw new Error('passo desconhecido na receita: ' + JSON.stringify(p));
  }
  return guardar;
}

/** Texto de um erro de negócio que o Pinguim BO mostrou (seletor erro_negocio da receita), ou ''. */
async function erroNegocio(page, receita) {
  if (!receita.erro_negocio) return '';
  const l = page.locator(receita.erro_negocio).first();
  return (await l.isVisible().catch(() => false)) ? (await l.innerText()).trim() : '';
}

async function executar(ordem) {
  const receita = JSON.parse(readFileSync(CFG.receita, 'utf8'));
  const tipo = receita.documentos && receita.documentos[ordem.tipo];
  if (!tipo) return { ok: false, erro: 'A receita do robô não tem o documento «' + ordem.tipo + '».', tipo_erro: 'negocio' };
  const browser = await chromium.launch({ headless: !args.has('--ver') });
  const page = await (await browser.newContext({ locale: 'pt-PT', ignoreHTTPSErrors: !!receita.ignorar_certificado })).newPage();
  const ctx = { ...ordem, url: CFG.pinguim, utilizador: CFG.utilizador, senha: CFG.senha };
  try {
    await passos(page, receita.entrar, ctx);
    // idempotência: se o documento desta ordem já existe no Pinguim BO (procura pela referência), não cria outro
    if (tipo.procurar) {
      const g = await passos(page, tipo.procurar, ctx).catch(() => ({}));
      if (g.documento) return { ok: true, documento: g.documento };
    }
    const g = await passos(page, tipo.criar, ctx);
    const neg = await erroNegocio(page, receita);
    if (neg) return { ok: false, erro: neg, tipo_erro: 'negocio' };
    if (!g.documento) return { ok: false, erro: 'O robô gravou mas não leu o número do documento.', tipo_erro: 'tecnico' };
    return { ok: true, documento: g.documento };
  } catch (e) {
    const neg = await erroNegocio(page, receita).catch(() => '');
    mkdirSync(CFG.capturas, { recursive: true });
    const cap = join(CFG.capturas, ordem.id + '-' + Date.now() + '.png');
    await page.screenshot({ path: cap, fullPage: true }).catch(() => {});
    registar('falhou', ordem.id, String(e.message).split('\n')[0], '· captura:', cap);
    return neg ? { ok: false, erro: neg, tipo_erro: 'negocio' } : { ok: false, erro: String(e.message).split('\n')[0].slice(0, 280), tipo_erro: 'tecnico' };
  } finally {
    await browser.close();
  }
}

async function umaVez() {
  const { ordem } = await portal('GET');
  if (!ordem) return false;
  registar('ordem', ordem.id, '·', ordem.linhas.length, 'linhas · rota', ordem.rota);
  const r = await executar(ordem);
  const d = await portal('POST', { id: ordem.id, ...r });
  registar(ordem.id, '→', d.estado, r.documento || r.erro || '');
  return true;
}

async function principal() {
  const falta = ['portal', 'chave', 'pinguim'].filter((k) => !CFG[k]);
  if (falta.length) throw new Error('Configuração em falta: ' + falta.join(', ') + ' (ver o cabeçalho deste ficheiro).');
  if (!existsSync(CFG.receita)) throw new Error('Receita em falta: ' + CFG.receita + ' (copiar e preencher ferramentas/robo-pinguim.receita.exemplo.json).');
  if (args.has('--uma-vez')) return umaVez();
  for (;;) {
    try {
      // depois de uma ordem, a seguinte logo; sem ordens, espera 30 segundos
      if (await umaVez()) continue;
    } catch (e) {
      registar('erro', e.message);
    }
    await new Promise((ok) => setTimeout(ok, 30000));
  }
}

principal().then(
  () => process.exit(0),
  (e) => {
    registar('erro', e.message);
    process.exit(1);
  },
);
