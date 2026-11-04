// GeoMobilidade com os dados do portal: percursos optimizados sozinhos (v1/rotas-optimizadas), clientes do Pinguim BO
// (v1/geo-clientes) e zonas guardadas no servidor (v1/geo-zonas), com a matriz de acessos e o âmbito por rota.
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
const hoje = new Date().toISOString().slice(0, 10);
const par = (c, o) => ({ ordem: o, c, n: 'Loja ' + c, lat: -8.8, lng: 13.2 + o / 100, km: o, chegada: '08:0' + o, estado: 'em_dia' });
const percurso = (cs) => ({
  paragens: cs.map((c, i) => par(c, i + 1)),
  sem_coordenadas: [],
  km: 12.5,
  minutos: 300,
  saida: '08:00',
  regresso: '13:00',
  acima_jornada: false,
  partida: null,
});

test.beforeAll(() => {
  mkdirSync(`${PRIV}/roteamento`, { recursive: true });
  writeFileSync(
    `${PRIV}/roteamento/${hoje}.json`,
    JSON.stringify({ gerado: hoje + ' 04:20:00', cfg: { regressa: true }, dias: { [hoje]: { 1001: percurso(['A', 'B']), 1002: percurso(['C']) } } }),
  );
  mkdirSync(`${PRIV}/geo`, { recursive: true });
  writeFileSync(
    `${PRIV}/geo/clientes-${hoje}.json`,
    JSON.stringify({
      origem: 'teste',
      gerado: hoje + ' 04:00:00',
      clientes: [
        { c: 'A', n: 'Loja A', lat: -8.8, lng: 13.2, r: '1001', d: 3, f: 1 },
        { c: 'C', n: 'Loja C', lat: -8.81, lng: 13.21, r: '1002', d: 2, f: 1 },
        { c: 'Z', n: 'Loja Z', lat: -8.82, lng: 13.22, r: '', d: null, f: 1 },
      ],
    }),
  );
});

test('percursos optimizados: âmbito por rota e matriz de acessos', async () => {
  const dir = await (await (await entrar('dir')).ctx.get(API + 'rotas-optimizadas?dia=' + hoje)).json();
  expect(dir.ok).toBe(true);
  expect(Object.keys(dir.percursos)).toEqual(['1001', '1002']);
  expect(dir.percursos['1001'].paragens.map((p) => p.c)).toEqual(['A', 'B']);
  expect(dir.dias).toEqual([hoje]);
  const sup = await (await (await entrar('sup')).ctx.get(API + 'rotas-optimizadas')).json();
  expect(Object.keys(sup.percursos)).toEqual(['1001']);
  expect((await (await entrar('fin')).ctx.get(API + 'rotas-optimizadas')).status()).toBe(403);
});

test('visitas do dia pela ordem do percurso optimizado', async () => {
  // carteira de teste com B antes de A pela prioridade; o percurso optimizado põe A primeiro
  const ds = new Date(hoje + 'T12:00:00Z').getUTCDay() || 7;
  const cli = (c) => ({
    c,
    n: 'Loja ' + c,
    r: '1001',
    dia: ds,
    media: c === 'B' ? 9000 : 100,
    intervalo: 7,
    ult: hoje,
    dias: 1,
    atraso: 0,
    estado: 'em_dia',
    proxima: hoje,
    compras: 5,
    encomenda: [],
    deixou: [],
    propor: [],
  });
  mkdirSync(`${PRIV}/carteira`, { recursive: true });
  writeFileSync(
    `${PRIV}/carteira/${hoje}.json`,
    JSON.stringify({ gerado: hoje + ' 04:10:00', dia: hoje, clientes: { A: cli('A'), B: cli('B') }, anomalias: [], qualidade: [] }),
  );
  const r = await (await (await entrar('dir')).ctx.get(API + 'carteira?vista=visitas&dia=' + hoje)).json();
  expect(r.visitas['1001'].map((x) => [x.c, x.ordem])).toEqual([
    ['A', 1],
    ['B', 2],
  ]);
});

test('clientes do Pinguim BO para os mapas: estado, dia e âmbito; zonas no servidor só para quem gere', async () => {
  const dir = await (await (await entrar('dir')).ctx.get(API + 'geo-clientes')).json();
  expect(dir.clientes.map((c) => c['Código'])).toEqual(['A', 'C', 'Z']);
  expect(dir.clientes[0]).toMatchObject({ Nome: 'Loja A', Rota: '1001', Dia: 'QUARTA-FEIRA', Estado: 'Activo', Latitude: -8.8 });
  expect(dir.clientes[2]).toMatchObject({ Estado: 'Inactivo', Vendedor: 'Sem rota' });
  const sup = await (await (await entrar('sup')).ctx.get(API + 'geo-clientes')).json();
  expect(sup.clientes.map((c) => c['Código'])).toEqual(['A']);
  // zonas: quem gere grava, quem só vê não
  const zona = {
    id: 'z1',
    label: 'Zona Norte',
    paths: [
      { lat: -8.8, lng: 13.2 },
      { lat: -8.8, lng: 13.3 },
      { lat: -8.9, lng: 13.3 },
    ],
  };
  const geo = (await entrar('sadm')).ctx;
  const g = await geo.post(API + 'geo-zonas', { data: { zonas: [zona], csrf: await csrf(geo) } });
  expect(g.status()).toBe(200);
  const lidas = await (await (await entrar('dir')).ctx.get(API + 'geo-zonas')).json();
  expect(lidas.zonas).toHaveLength(1);
  expect(lidas.zonas[0]).toMatchObject({ id: 'z1', label: 'Zona Norte' });
  expect(lidas.alterado.por).toBe('sadm');
  const d = (await entrar('dir')).ctx;
  expect((await d.post(API + 'geo-zonas', { data: { zonas: [], csrf: await csrf(d) } })).status()).toBe(403);
  expect((await (await entrar('fin')).ctx.get(API + 'geo-zonas')).status()).toBe(403);
});
