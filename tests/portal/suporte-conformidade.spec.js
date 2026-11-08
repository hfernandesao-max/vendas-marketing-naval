// Páginas Suporte e Conformidade com o servidor simulado.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { abrir } from './dados.js';

const P = (id, estado, extra) => ({
  id,
  tipo: 'incidente',
  prioridade: 'P2',
  titulo: 'Telemóvel não sincroniza ' + id,
  descricao: 'Rota 1005',
  estado,
  criado_por: 'ana',
  criado_nome: 'Ana',
  criado_em: '2026-11-08 09:00:00',
  prazo: '2026-11-08 17:00:00',
  atribuido: '',
  dados_pessoais: false,
  resolucao: '',
  causa: '',
  licoes: '',
  resolvido_em: null,
  fechado_em: null,
  satisfacao: null,
  fora_prazo: false,
  historico: [{ em: '2026-11-08 09:00:00', por: 'ana', accao: 'criado', texto: '' }],
  ...extra,
});
const SUP = {
  ok: true,
  eu: 'ana',
  gere: false,
  tipos: { pedido: 'Pedido de serviço', incidente: 'Incidente', seguranca: 'Incidente de segurança', melhoria: 'Sugestão de melhoria' },
  prioridades: { P1: 4, P2: 8, P3: 24, P4: 72 },
  pedidos: [
    P('SUP-000002', 'resolvido', { resolucao: 'Reinstalada a app', resolvido_em: '2026-11-08 11:00:00' }),
    P('SUP-000001', 'novo', { fora_prazo: true }),
  ],
  indicadores: { abertos: 1, fora_prazo: 1, seguranca_abertos: 0, horas_medias: 2, no_prazo_pct: 100, satisfacao: null },
};

test('suporte: indicadores, lista, abrir um pedido novo e avaliar o resolvido', async ({ page }) => {
  const posts = [];
  await page.route('**/v1/suporte**', (r) => {
    if (r.request().method() === 'POST') {
      const b = r.request().postDataJSON();
      posts.push(b);
      const pedido = b.accao === 'criar' ? P('SUP-000003', 'novo', { titulo: b.titulo }) : P(b.id, 'fechado', { satisfacao: b.satisfacao });
      return r.fulfill({ json: { ...SUP, pedido, pedidos: [pedido, ...SUP.pedidos.filter((p) => p.id !== pedido.id)] } });
    }
    return r.fulfill({ json: SUP });
  });
  const erros = await abrir(page, '#suporte');
  await expect(page.locator('.tile').filter({ hasText: 'Fora do prazo' }).locator('.v')).toHaveText('1');
  await expect(page.locator('#s-sup-l tbody tr')).toHaveCount(2);
  await page.click('.sup-barra button:has-text("Novo pedido")');
  await page.locator('dialog.dlg input[type=text]').first().fill('Impressora da rota 1002 avariada');
  await page.locator('dialog.dlg button', { hasText: 'Abrir o pedido' }).click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0]).toMatchObject({ accao: 'criar', tipo: 'incidente', prioridade: 'P3', titulo: 'Impressora da rota 1002 avariada' });
  await page.click('#s-sup-l button.linkbtn:has-text("SUP-000002")');
  await expect(page.locator('dialog.dlg')).toContainText('Reinstalada a app');
  await page.locator('dialog.dlg button', { hasText: 'Avaliar e fechar' }).click();
  await expect.poll(() => posts.length).toBe(2);
  expect(posts[1]).toMatchObject({ accao: 'avaliar', id: 'SUP-000002', satisfacao: 5 });
  expect(erros).toEqual([]);
});

test('conformidade: estado dos controlos, avisos do que falta configurar e confirmação da revisão', async ({ page }) => {
  let confirmado = null;
  const base = {
    ok: true,
    pode_confirmar: true,
    revisao_actual: '2026-T4',
    cifra: false,
    copia_externa: false,
    registos_externos: true,
    contas_dias: 90,
    estado: {
      inicio: '2026-11-08 02:00:05',
      problemas: [],
      registos: { ok: true, linhas: 1200, encadeadas: 1180, selo: 'ab', falhas: 0 },
      contas: {
        ok: true,
        dias: 90,
        desligadas: [{ login: 'velha', nome: 'Conta velha', perfil: 'COM', ultima_entrada: '2026-07-01', dias: 130 }],
        a_desligar: [],
      },
      copia: {
        ok: true,
        copia: { ficheiro: 'dados-20261108-020005.copia', bytes: 2097152, ficheiros: 340, cifrada: false, externa: null },
        recuperacao: { ok: true, ficheiros: 340, falhas: [] },
      },
      retencao: { ok: true, apagados: { email: 3 } },
    },
    revisoes: [{ trimestre: '2026-T4', aberta_em: '2026-10-01 02:00:00', contas: 42, confirmada: null }],
    copias: [{ ficheiro: 'dados-20261108-020005.copia', bytes: 2097152, em: '2026-11-08 02:00' }],
    retencao: [
      { id: 'registos', descricao: 'Registos de actividade e auditoria', dias: 1095 },
      { id: 'email', descricao: 'Cópias dos correios enviados', dias: 90 },
    ],
  };
  await page.route('**/v1/conformidade**', (r) => {
    if (r.request().method() === 'POST') {
      confirmado = r.request().postDataJSON();
      return r.fulfill({ json: { ...base, revisoes: [{ ...base.revisoes[0], confirmada: { por: 'henrique', em: '2026-11-08 10:00:00' } }] } });
    }
    return r.fulfill({ json: base });
  });
  const erros = await abrir(page, '#conformidade');
  await expect(page.locator('.tile').filter({ hasText: 'Registos de auditoria' }).locator('.v')).toHaveText('Íntegros');
  await expect(page.locator('.tile').filter({ hasText: 'Teste de recuperação' }).locator('.v')).toHaveText('Passou');
  await expect(page.locator('main')).toContainText('COPIAS_CHAVE');
  await expect(page.locator('#s-conf-contas tbody tr')).toHaveCount(1);
  await page.click('button:has-text("Confirmar a revisão de acessos")');
  await page.locator('dialog.dlg input').fill('revisto com a Direcção');
  await page.locator('dialog.dlg button', { hasText: 'Confirmar' }).click();
  await expect.poll(() => confirmado).toMatchObject({ accao: 'confirmar_revisao', trimestre: '2026-T4', notas: 'revisto com a Direcção' });
  await expect(page.locator('.tile').filter({ hasText: 'Revisão de acessos 2026-T4' }).locator('.v')).toHaveText('Confirmada');
  expect(erros).toEqual([]);
});

test('conformidade › SGSI: documentos com aprovação, objectivos medidos, 93 controlos, riscos e RoPA', async ({ page }) => {
  const d = JSON.parse(readFileSync('nucleo/conformidade/sgsi.json', 'utf8'));
  const docs = ['ambito', 'manual', 'objectivos', 'soa', 'riscos', 'ropa', 'auditoria'];
  const nomes = {
    ambito: 'Âmbito do SGSI',
    manual: 'Manual do SGSI: política e procedimentos',
    objectivos: 'Objectivos de segurança',
    soa: 'Declaração de aplicabilidade',
    riscos: 'Registo de riscos e plano de tratamento',
    ropa: 'Registo das actividades de tratamento',
    auditoria: 'Programa de auditoria e revisão pela direcção',
  };
  const sgsi = (aprovado) => ({
    versao: d.versao,
    data: d.data,
    ambito: d.ambito,
    normas: d.normas,
    documentos: docs.map((id) => ({
      id,
      nome: nomes[id],
      hash: 'a'.repeat(64),
      estado: aprovado.includes(id) ? 'aprovado' : 'por_aprovar',
      aprovacao: aprovado.includes(id) ? { em: '2026-11-09 10:00:00', nome: 'Henrique', hash: 'a'.repeat(64) } : null,
    })),
    objectivos: d.objectivos.map((o, i) => ({ ...o, valor: i === 3 ? null : 100, cumpre: i === 3 ? null : true })),
    controlos: d.controlos,
    evidencias: d.evidencias,
    temas: d.temas,
    responsaveis: d.responsaveis,
    resumo_controlos: { total: 93, por_estado: { implementado: 49, parcial: 37, planeado: 6, nao_aplicavel: 1 } },
    riscos: d.riscos.map((r) => {
      const n = r.residual.probabilidade * r.residual.impacto;
      return { ...r, nivel: r.probabilidade * r.impacto, nivel_residual: n, aceitavel: n <= 6 };
    }),
    risco_aceitavel: 6,
    ropa: d.ropa,
    auditoria: d.auditoria,
    nao_conformidades: { abertas: 1, total: 2 },
  });
  const base = {
    ok: true,
    pode_confirmar: true,
    revisao_actual: '2026-T4',
    cifra: true,
    copia_externa: true,
    registos_externos: true,
    contas_dias: 90,
    estado: {},
    revisoes: [],
    copias: [],
    retencao: [],
    sgsi: sgsi([]),
  };
  let pedido = null;
  await page.route('**/v1/conformidade**', (r) => {
    if (r.request().method() === 'POST') {
      pedido = r.request().postDataJSON();
      return r.fulfill({ json: { ...base, sgsi: sgsi(['soa']) } });
    }
    return r.fulfill({ json: base });
  });
  const erros = await abrir(page, '#conformidade');
  await expect(page.locator('.tile').filter({ hasText: 'Documentos do SGSI aprovados' }).locator('.v')).toHaveText('0 / 7');
  await expect(page.locator('.tile').filter({ hasText: 'Controlos implementados' }).locator('.v')).toHaveText('49 / 92');
  await expect(page.locator('#s-conf-soa')).toContainText('A mostrar 20 de 93');
  await expect(page.locator('#s-conf-riscos tbody tr')).toHaveCount(d.riscos.length);
  await expect(page.locator('#s-conf-ropa tbody tr')).toHaveCount(d.ropa.length);
  await expect(page.locator('#s-conf-objectivos')).toContainText('Sem dados');
  await page.evaluate(() => {
    location.hash = '#conformidade/conf-docs';
  });
  await page.locator('#s-conf-docs tr', { hasText: 'Declaração de aplicabilidade' }).locator('button', { hasText: 'Aprovar' }).click();
  await page.locator('dialog.dlg input').fill('Reunião da Administração');
  await page.locator('dialog.dlg button', { hasText: 'Aprovar' }).click();
  await expect.poll(() => pedido).toMatchObject({ accao: 'aprovar_documento', doc: 'soa', notas: 'Reunião da Administração' });
  await expect(page.locator('.tile').filter({ hasText: 'Documentos do SGSI aprovados' }).locator('.v')).toHaveText('1 / 7');
  expect(erros).toEqual([]);
});
