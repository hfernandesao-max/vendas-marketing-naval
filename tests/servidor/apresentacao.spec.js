// Apresentação do portal: todos os perfis vêem; negócio só a quem vê vendas e no seu âmbito; automação e roteiro reais.
import { test, expect } from '@playwright/test';
import { entrar, API } from './ajuda.js';

test('apresentação: negócio no âmbito, automação, horas, novidades e roteiro', async () => {
  for (const conta of ['dir', 'pro', 'log', 'fin', 'sup']) {
    const r = await (await entrar(conta)).ctx.get(API + 'apresentacao');
    expect(r.status(), conta).toBe(200);
  }
  const d = await (await (await entrar('dir')).ctx.get(API + 'apresentacao')).json();
  expect(d.ok).toBe(true);
  expect(d.ve_valores).toBe(true);
  expect(d.negocio.meses).toHaveLength(6);
  expect(d.negocio.mes).toHaveProperty('total');
  expect(d.automacao).toMatchObject({ dias: 30 });
  for (const k of ['resumos', 'calculos', 'planos', 'fechos', 'versoes']) expect(typeof d.automacao[k], k).toBe('number');
  expect(d.horas).toMatchObject({ calculo: '04:00' });
  expect(d.novidades.length).toBeGreaterThan(0);
  expect(d.novidades_total).toBeGreaterThanOrEqual(d.novidades.length);
  expect(d.roteiro.futuro.length).toBe(4);
  expect(d.roteiro.proximos.length).toBeGreaterThan(0);
  expect(d.roteiro._nota).toBeUndefined();
  expect(JSON.stringify(d.negocio)).not.toMatch(/Loja|NIF/);
  const sup = await (await (await entrar('sup')).ctx.get(API + 'apresentacao')).json();
  expect(sup.ambito).toBe(1);
});
