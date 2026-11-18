// Pedidos de acesso (Suporte), revisão de acessos pelo dono da função e governação dos acessos (Acessos), com o
// servidor simulado.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const SUP = {
  ok: true,
  eu: 'chefe',
  gere: false,
  tipos: { pedido: 'Pedido de serviço', incidente: 'Incidente' },
  prioridades: { P1: 4, P2: 8, P3: 24, P4: 72 },
  resposta: { P1: 0.25, P2: 1, P3: 4, P4: 8 },
  servicos: { portal: 'Portal RTM' },
  impactos: { baixo: 'Só eu' },
  urgencias: { media: 'Trabalho com dificuldade' },
  matriz: { baixo: { media: 'P4' } },
  pedidos: [],
  indicadores: { abertos: 0, fora_prazo: 0, seguranca_abertos: 0, horas_medias: null, no_prazo_pct: null, satisfacao: null },
};
const FUNCOES = [
  { codigo: 'P-LOG', nome: 'Logística', descricao: '', tipo: 'base', conflitos: ['C-CONC-COORD'], tem_dono: true },
  { codigo: 'C-CONC-CONTAR', nome: 'Contar as carrinhas', descricao: '', tipo: 'complementar', conflitos: ['C-CONC-COORD'], tem_dono: true },
  {
    codigo: 'C-CONC-COORD',
    nome: 'Aprovar conciliações (coordenador)',
    descricao: '',
    tipo: 'complementar',
    conflitos: ['P-LOG', 'C-CONC-CONTAR'],
    tem_dono: false,
  },
];
const pedido = (estado, extra) => ({
  id: 'PA-000001',
  conta: 'ana',
  conta_nome: 'Ana',
  funcoes: [
    {
      funcao: 'C-CONC-CONTAR',
      nome: 'Contar as carrinhas',
      aprovador: 'chefe',
      aprovador_nome: 'Chefe Logística',
      sem_dono: false,
      aprovado_por: null,
      aprovado_em: null,
      posso_aprovar: estado === 'pendente',
    },
  ],
  fim: '2026-12-31',
  motivo: 'Substitui o técnico do armazém',
  estado,
  criado_por: 'rui',
  criado_nome: 'Rui',
  criado_em: '2026-11-17 10:00:00',
  recusa: null,
  aplicado: null,
  historico: [{ em: '2026-11-17 10:00:00', por: 'rui', accao: 'pedido', texto: 'Substitui o técnico do armazém' }],
  posso_anular: false,
  posso_recusar: estado === 'pendente',
  ...extra,
});

test('pedidos de acesso: o dono autoriza; pedir com a separação de funções ao vivo; revisão da área com «Retirar»', async ({ page }) => {
  const posts = [];
  let PA = {
    ok: true,
    eu: 'chefe',
    pedidos: [pedido('pendente')],
    funcoes: FUNCOES,
    contas: [
      { login: 'ana', nome: 'Ana' },
      { login: 'chefe', nome: 'Chefe Logística' },
    ],
    funcoes_contas: { ana: ['P-LOG'], chefe: ['P-DIR'] }, // no servidor, só as da própria pessoa; aqui simula-se a Ana
    por_decidir: 1,
    revisao: {
      trimestre: '2026-T4',
      contas: [{ conta: 'tomas', nome: 'Tomás', funcao: 'C-CONC-CONTAR', funcao_nome: 'Contar as carrinhas', ate: '' }],
      confirmada: null,
    },
  };
  await page.route('**/v1/suporte**', (r) => r.fulfill({ json: SUP }));
  await page.route('**/v1/pedidos-acesso**', (r) => {
    if (r.request().method() !== 'POST') return r.fulfill({ json: PA });
    const b = r.request().postDataJSON();
    posts.push(b);
    if (b.accao === 'aprovar') PA = { ...PA, pedidos: [pedido('aprovado', { posso_recusar: false })], por_decidir: 0 };
    if (b.accao === 'revisao') PA = { ...PA, revisao: { ...PA.revisao, confirmada: { em: '2026-11-17 11:00:00', contas: 1, retirar: b.retirar } } };
    return r.fulfill({ json: { ...PA, id: b.id || 'PA-000002' } });
  });
  const erros = await abrir(page, '#suporte');
  // a secção abre-se pela navegação da página (#suporte/pa-l)
  await page.locator('main').getByRole('link', { name: 'Pedidos de acesso' }).click();
  const linha = page.locator('#s-pa-l tbody tr', { hasText: 'PA-000001' });
  await expect(linha).toContainText('Por autorizar');
  await expect(linha).toContainText('Para si decidir');
  await linha.getByRole('button', { name: 'PA-000001' }).click();
  let d = page.locator('dialog[open]');
  await expect(d).toContainText('Acesso temporário: até 31-12-2026');
  await expect(d).toContainText('Chefe Logística');
  await d.getByRole('button', { name: 'Autorizar' }).click();
  await expect.poll(() => posts[0]).toMatchObject({ accao: 'aprovar', id: 'PA-000001' });
  await expect(linha).toContainText('Autorizado, por aplicar');

  // novo pedido: a Ana já é da logística; coordenar a conciliação é proibido e avisa logo
  await page.getByRole('button', { name: 'Pedir acesso' }).click();
  d = page.locator('dialog[open]');
  await d.locator('select').first().selectOption('ana');
  await expect(d.getByLabel('Logística', { exact: true })).toHaveCount(0);
  await d.getByLabel('Aprovar conciliações (coordenador)').check();
  await expect(d.locator('.aviso')).toContainText('Separação de funções: a mesma pessoa não pode ter «Aprovar conciliações (coordenador)» e «Logística»');
  await d.getByLabel('Aprovar conciliações (coordenador)').uncheck();
  await expect(d.locator('.aviso')).toBeHidden();
  await d.getByLabel('Contar as carrinhas').check();
  await d.getByLabel('Último dia (opcional, para acessos temporários)').fill('2026-12-31');
  await d.getByLabel('Motivo').fill('Férias do técnico do armazém');
  await d.getByRole('button', { name: 'Enviar o pedido' }).click();
  await expect
    .poll(() => posts[1])
    .toMatchObject({ accao: 'criar', conta: 'ana', funcoes: ['C-CONC-CONTAR'], fim: '2026-12-31', motivo: 'Férias do técnico do armazém' });

  // revisão do trimestre: o dono marca a quem retirar a função
  const rev = page.locator('.pa-revisao');
  await expect(rev).toContainText('Revisão de acessos da sua área · 2026-T4');
  await rev.getByLabel('Retirar · Tomás · Contar as carrinhas').check();
  await rev.getByLabel('Notas').fill('Mudou de função');
  await rev.getByRole('button', { name: 'Confirmar a minha parte' }).click();
  await expect.poll(() => posts[2]).toMatchObject({ accao: 'revisao', retirar: [{ conta: 'tomas', funcao: 'C-CONC-CONTAR' }], notas: 'Mudou de função' });
  await expect(rev).toContainText('Confirmou a sua parte em 17-11-2026 11:00: 1 acesso(s) revistos, 1 a retirar.');
  expect(erros).toEqual([]);
});

test('Acessos: governação — contas em conflito, passar para funções sem mudar acessos, aplicar pedidos; regra do sistema e dono', async ({ page }) => {
  const posts = [];
  const F = {
    'P-LOG': {
      nome: 'Logística',
      descricao: 'Cargas e contagem',
      tipo: 'base',
      permissoes: { ranking: 'V' },
      conflitos: ['C-CONC-COORD'],
      conflitos_sistema: ['C-CONC-COORD'],
      dono: 'chefe',
      activo: true,
    },
    'C-CONC-COORD': {
      nome: 'Aprovar conciliações (coordenador)',
      descricao: '',
      tipo: 'complementar',
      permissoes: {},
      conflitos: ['P-LOG'],
      conflitos_sistema: ['P-LOG'],
      dono: '',
      activo: true,
    },
  };
  const u = (login, nome, extra) => ({
    login,
    nome,
    email: login + '@naval.co.ao',
    email_ok: true,
    perfil: 'LOG',
    perfil_nome: 'Logística',
    rotas: null,
    activo: true,
    ligada: true,
    funcoes: [],
    ...extra,
  });
  const vista = {
    perfis: { SADM: 'Super administrador', LOG: 'Logística', COM: 'Comercial' },
    perfis_info: [],
    funcoes: F,
    ordem_niveis: { '': 0, Vr: 1, V: 2, Er: 3, E: 4, G: 5, A: 6 },
    gerados: [],
    matriz: { ranking: { SADM: 'A', LOG: 'V', COM: 'V' } },
    matriz_base: { ranking: { SADM: 'A', LOG: 'V', COM: 'V' } },
    niveis: { ranking: ['', 'V'] },
    por_rota: [],
    perfis_dois_passos: ['SADM'],
    rotas: [],
    utilizadores: [
      u('hf', 'Henrique', { perfil: 'SADM', perfil_nome: 'Super administrador' }),
      u('chefe', 'Chefe Logística'),
      u('ana', 'Ana', { funcoes: ['C-CONC-COORD', 'P-LOG'] }),
    ],
    ajustes_contas: [
      { em: '2026-11-18 09:00', ok: true, conta: 'chefe', funcoes: ['P-LOG'] },
      { em: '2026-11-18 09:00', ok: false, nome: 'Nathan Alemayehu', motivo: 'não há nenhuma conta activa com este nome' },
    ],
    contas_conflito: [{ login: 'ana', nome: 'Ana', funcoes: ['C-CONC-COORD', 'P-LOG'], nomes: ['Aprovar conciliações (coordenador)', 'Logística'] }],
    sem_funcoes: [
      { login: 'chefe', nome: 'Chefe Logística', perfil: 'LOG', perfil_nome: 'Logística', funcao: 'P-LOG', diferencas: [] },
      {
        login: 'rui',
        nome: 'Rui',
        perfil: 'COM',
        perfil_nome: 'Comercial',
        funcao: 'P-COM',
        diferencas: [{ pagina: 'ranking', actual: 'V', funcao: '' }],
      },
    ],
    pedidos_acesso: [{ ...pedido('aprovado'), funcoes: [{ ...pedido('aprovado').funcoes[0], aprovado_por: 'chefe' }], posso_aplicar: true }],
    registos: [],
    pode_gerir: true,
    pode_activar: false,
    compatibilidade: false,
    eu: 'hf',
    confirmado: true,
  };
  await page.route('**/v1/gestao*', (r) => {
    const q = r.request();
    if (q.method() !== 'POST') return r.fulfill({ json: vista });
    const b = q.postDataJSON();
    posts.push(b);
    if (b.accao === 'converter_funcoes') return r.fulfill({ json: { ok: true, convertidas: ['chefe'], ficam: [] } });
    return r.fulfill({ json: { ok: true, pedido: {} } });
  });
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { csrf: 'x' } }));
  const erros = await abrir(page, '#acessos');
  const gov = page.locator('.ac-gov');
  await expect(gov).toContainText('18-11-2026 09:00 · Chefe Logística: Logística');
  await expect(gov.locator('li.aviso')).toContainText(
    'Nathan Alemayehu: não aplicada (não há nenhuma conta activa com este nome). Atribua as funções em Utilizadores › Gerir.',
  );
  await expect(gov).toContainText('Ana (ana): «Aprovar conciliações (coordenador)» e «Logística»');
  await expect(gov).toContainText('Nada (mesmos acessos)');
  await expect(gov).toContainText('V → —');
  await gov.getByRole('button', { name: 'Passar 1 conta(s) para funções' }).click();
  await expect.poll(() => posts[0]).toMatchObject({ accao: 'converter_funcoes', contas: ['chefe'] });
  await gov.getByRole('button', { name: 'Aplicar PA-000001' }).click();
  await expect.poll(() => posts[1]).toMatchObject({ accao: 'aplicar_pedido_acesso', id: 'PA-000001' });
  // função: dono e regra do sistema (não se retira)
  await page.locator('main').getByRole('link', { name: 'Funções' }).click();
  await expect(page.locator('#s-ac-f tbody tr', { hasText: 'Cargas e contagem' })).toContainText('Chefe Logística');
  await page.getByRole('button', { name: 'Gerir Logística' }).click();
  const d = page.locator('dialog[open]');
  await expect(d.getByLabel('Dono da função (autoriza os pedidos e revê quem a tem)')).toHaveValue('chefe');
  const regra = d.getByLabel('Aprovar conciliações (coordenador)');
  await expect(regra).toBeChecked();
  await expect(regra).toBeDisabled();
  await d.getByRole('button', { name: 'Guardar' }).click();
  await expect.poll(() => posts[2]).toMatchObject({ accao: 'guardar_funcao', codigo: 'P-LOG', dono: 'chefe' });
  expect(erros).toEqual([]);
});
