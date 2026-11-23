// Logística › Conciliação da carrinha (contagem cega, diferenças e aprovação com motivo) e limite de peso no plano de cargas.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linhaCompleta = (codigo, artigo, esperado, contado = null) => ({
  codigo,
  artigo,
  unidade: 'CX',
  inicial: 10,
  carregado: 20,
  vendido: 30 - esperado,
  devolvido: 0,
  final: esperado,
  esperado,
  contado,
  diferenca: contado == null ? null : contado - esperado,
  preco: 1000,
  valor: contado == null ? null : (contado - esperado) * 1000,
  alertas: [],
});
const MOTIVOS = { avaria: 'Produto danificado', falta: 'Falta sem justificação', outro: 'Outro (explicar na nota)' };

test('conciliação: contagem cega, PDF, validação de quem contou e dois níveis de aprovação', async ({ page }) => {
  let estado = 'por_contar',
    eu = 'tec';
  const reg = {};
  const pedidos = [];
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      const agora = '2026-10-26 18:30:00';
      if (b.accao === 'preparar_folha') {
        return r.fulfill({
          json: {
            ok: true,
            linhas: [
              { codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX' },
              { codigo: 'B2', artigo: 'Sabão', unidade: 'CX' },
            ],
            congelada_em: '2026-10-26 15:34:00',
          },
        });
      }
      if (b.accao === 'contar') {
        estado = 'por_validar';
        Object.assign(reg, { contado_por: eu, contado_em: agora });
        return r.fulfill({ json: { ok: true, resumo: { diferencas: 1 } } });
      }
      if (b.accao === 'validar') {
        estado = 'por_aprovar';
        Object.assign(reg, { validado_por: eu, validado_em: agora });
        return r.fulfill({ json: { ok: true } });
      }
      if (b.accao === 'aprovar') {
        if (!b.motivos.A1 || !b.motivos.A1.motivo)
          return r.fulfill({ json: { ok: false, erro: 'Indique um motivo de até 255 caracteres para cada diferença:', erros: ['Fraldas M'] } });
        estado = 'por_fechar';
        Object.assign(reg, { coordenado_por: eu, coordenado_em: agora, motivos: b.motivos });
        return r.fulfill({ json: { ok: true } });
      }
      estado = 'aprovada';
      Object.assign(reg, { aprovado_por: eu, aprovado_em: agora });
      return r.fulfill({ json: { ok: true } });
    }
    const contada = estado !== 'por_contar';
    const linhas = contada
      ? [linhaCompleta('A1', 'Fraldas M', 4, 3), linhaCompleta('B2', 'Sabão', 2, 2)]
      : [
          { codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX', contado: null, alertas: [] },
          { codigo: 'B2', artigo: 'Sabão', unidade: 'CX', contado: null, alertas: [] },
        ];
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        pode: { contar: true, coordenar: true, fechar: true },
        eu,
        data: '2026-10-26',
        movimentos: 'separados',
        motivos: MOTIVOS,
        nomes: { coord: 'Sara Coordenadora', resp: 'Michael Samson' },
        horario: {
          dia: '2026-10-26',
          tipo: 'completo',
          vendas: ['07:30', '15:00'],
          fecho: ['15:30', '16:00'],
          carga: ['16:00', '17:00'],
          agora: '2026-10-26 16:10',
        },
        rotas: [
          {
            rota: '1001',
            promotor: 'Andrea Santos',
            cega: !contada,
            linhas,
            ...reg,
            motivos: ['por_fechar', 'aprovada'].includes(estado) ? reg.motivos : {},
            resumo: { estado, artigos: 2, alertas: 0, diferencas: contada ? 1 : 0, falta_kz: contada ? 1000 : 0, sobra_kz: 0 },
            historico: [],
          },
        ],
      },
    });
  });
  // PDF: sem separador novo (bloqueado), descarrega; o texto do PDF lê-se do ficheiro (jsPDF sem compressão)
  await page.addInitScript(() => (window.open = () => null));
  const pdfTexto = async (botao) => {
    const [d] = await Promise.all([page.waitForEvent('download'), botao.click()]);
    const fs = await import('node:fs');
    if (process.env.GUARDAR_PDF) fs.copyFileSync(await d.path(), process.env.GUARDAR_PDF + d.suggestedFilename());
    return { nome: d.suggestedFilename(), texto: fs.readFileSync(await d.path(), 'latin1') };
  };
  const actualizar = async () => {
    await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
    await page.locator('.pc-barra').getByRole('button', { name: 'Actualizar' }).click();
  };
  const erros = await abrir(page, '#conciliacao-carrinha');
  const rota = page.locator('.cc-rota', { hasText: '1001' });
  await expect(rota).toContainText('Por contar');
  // uma só cor por estado: o risco do cartão tem a cor da etiqueta (por contar: cinzento)
  await expect(rota).toHaveClass(/cc-e-p-mut/);
  // calendário de trabalho: horário do dia e prazo do fecho (16:00) ultrapassado
  await expect(page.locator('.hor-linha')).toContainText('Contagem e PO15:30–16:00');
  await expect(rota).toContainText('Fora do prazo do fecho (16:00)');
  await rota.getByRole('button', { name: 'Contar' }).click();
  // cega: só o artigo e a quantidade que voltou
  await expect(rota.locator('th')).toHaveText(['Código', 'Artigo', 'Quantidade que voltou', '']);
  // folha de contagem às cegas para imprimir: artigos, sem as quantidades do sistema
  const folha = await pdfTexto(rota.getByRole('button', { name: 'Imprimir folha de contagem' }));
  expect(folha.nome).toBe('CC-1001-20261026_folha-de-contagem.pdf');
  expect(folha.texto).toContain('Fraldas M');
  expect(folha.texto).toContain('Base do sistema congelada em: 2026-10-26 15:34:00.');
  expect(folha.texto).toContain('Quantidade');
  expect(folha.texto).not.toContain('Tem de voltar');
  await rota.getByLabel('Fraldas M').fill('3');
  // o que já foi escrito não se perde: a página redesenha (Actualizar) e o número volta
  await expect(rota.locator('.cc-rascunho')).toContainText('Rascunho guardado');
  await page.locator('.pc-barra').getByRole('button', { name: 'Actualizar' }).click();
  await expect(rota.getByLabel('Fraldas M')).toHaveValue('3');
  await expect(rota.locator('.cc-rascunho')).toContainText('Rascunho reposto');
  // mudar de página com a contagem por gravar: pergunta antes; «Ficar e gravar» fica na conciliação
  const antes = await page.evaluate(() => location.hash);
  await page.evaluate(() => (location.hash = '#inicio'));
  const sair = page.locator('dialog[open]');
  await expect(sair).toContainText('Sair sem gravar?');
  await sair.getByRole('button', { name: 'Ficar e gravar' }).click();
  await expect(sair).toHaveCount(0);
  expect(await page.evaluate(() => location.hash)).toBe(antes);
  await expect(rota.getByLabel('Fraldas M')).toHaveValue('3');
  // guardado neste navegador (para voltar a abrir depois de recarregar ou fechar o separador)
  const rasc = await page.evaluate(() => JSON.parse(localStorage.getItem('vm-rascunho-contagem-2026-10-26-1001')));
  expect(rasc.v).toEqual({ A1: '3', B2: '' });
  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect(rota.locator('.erro')).toContainText('Preencha todos os artigos');
  // a linha em que se escreve fica destacada; a já contada leva o visto; Enter passa à seguinte e, na última, ao «Gravar»
  const linhaFraldas = rota.locator('.cc-contagem tr', { has: page.getByLabel('Fraldas M') });
  const linhaSabao = rota.locator('.cc-contagem tr', { has: page.getByLabel('Sabão') });
  await expect(linhaFraldas).toHaveClass(/lc-feita/);
  await expect(linhaSabao).not.toHaveClass(/lc-feita/);
  await rota.getByLabel('Fraldas M').focus();
  await expect(linhaFraldas).toHaveClass(/lc-activa/);
  await page.keyboard.press('Enter');
  await expect(rota.getByLabel('Sabão')).toBeFocused();
  await expect(linhaSabao).toHaveClass(/lc-activa/);
  await expect(linhaFraldas).not.toHaveClass(/lc-activa/);
  await page.keyboard.type('2');
  await expect(linhaSabao).toHaveClass(/lc-feita/);
  await page.keyboard.press('Enter');
  await expect(rota.getByRole('button', { name: 'Gravar contagem' })).toBeFocused();

  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect.poll(() => pedidos.filter((p) => p.accao === 'contar').length).toBe(1);
  expect(pedidos.find((p) => p.accao === 'contar')).toMatchObject({ accao: 'contar', rota: '1001', contagem: { A1: 3, B2: 2 } });
  // gravado: o rascunho apaga-se quando chega a resposta do servidor
  await expect.poll(() => page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('vm-rascunho-')))).toEqual([]);

  // 1. quem contou confere (colunas por cor: sistema, contagem física, diferença) e valida
  await expect(rota).toContainText('Por validar');
  const det = page.locator('.cc-detalhe');
  await expect(det.locator('th.cc-g-sis', { hasText: 'Sistema · Pinguim BO' })).toBeVisible();
  await expect(det.locator('th.cc-g-cont', { hasText: 'Contagem física' })).toBeVisible();
  await expect(det.locator('tr.cc-falta td.cc-neg').first()).toContainText('-1');
  await expect(det.locator('.cc-circuito li')).toHaveCount(4);
  await expect(det.locator('.cc-circuito li.actual')).toContainText('Validada por quem contou');
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Validar contagem' }).click();
  // depois de validada: o painel da rota fecha e pergunta se quer imprimir a contagem
  const dlg = page.getByRole('dialog');
  await expect(dlg).toContainText('Contagem validada · rota 1001');
  await expect(dlg).toContainText('Quer imprimir a contagem?');
  await expect(det).toHaveCount(0);
  const impressa = await pdfTexto(dlg.getByRole('button', { name: 'Imprimir a contagem' }));
  expect(impressa.nome).toMatch(/CC-1001-.*\.pdf$/);
  await expect(dlg).toHaveCount(0);
  await expect(rota).toContainText('Por aprovar (coordenador)');
  await rota.getByRole('button', { name: 'Ver' }).click();
  // quem contou não aprova (segregação de funções)
  await expect(det.getByRole('button', { name: 'Aprovar (coordenador)' })).toHaveCount(0);
  await expect(det).toContainText('quem contou não aprova');

  // 2. coordenador: motivo obrigatório em cada diferença
  eu = 'coord';
  await actualizar();
  await det.getByRole('button', { name: 'Aprovar (coordenador)' }).click();
  await expect(det.locator('.erro')).toContainText('Fraldas M');
  const campoMotivo = det.getByLabel('Motivo · Fraldas M');
  await expect(campoMotivo).toHaveAttribute('maxlength', '255');
  const textoMotivo = 'Contagem manual';
  await campoMotivo.click();
  await page.keyboard.type(textoMotivo, { delay: 30 });
  await expect(campoMotivo).toHaveValue(textoMotivo);
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Aprovar (coordenador)' }).click();
  await expect(rota).toContainText('Por aprovar (responsável)');
  expect(pedidos.find((x) => x.accao === 'aprovar' && x.motivos.A1.motivo).motivos.A1.motivo).toBe(textoMotivo);
  await expect(det.getByRole('button', { name: 'Aprovar e fechar' })).toHaveCount(0);

  // 3. responsável: aprovação final, fica fechada
  eu = 'resp';
  await actualizar();
  await det.getByRole('button', { name: 'Aprovar e fechar' }).click();
  await expect(rota).toContainText('Fechada');
  await expect(det.locator('.cc-circuito li.feito')).toHaveCount(4);

  // PDF com o resultado, igual à tabela do ecrã, com o circuito de aprovação
  const res = await pdfTexto(page.getByRole('button', { name: 'PDF da contagem' }));
  expect(res.nome).toBe('CC-1001-20261026_resultado-da-contagem.pdf');
  expect(res.texto).toContain('Sistema');
  expect(res.texto).toContain('Devolvido');
  expect(res.texto).toContain(textoMotivo);
  expect(res.texto).toContain('coord');
  expect(res.texto).toContain('resp');
  // rastreabilidade: nome completo e utilizador de quem assinou, no registo e nos blocos de assinatura
  expect(res.texto).toContain('Michael Samson \\(resp\\)');
  expect(res.texto).toContain('Registo das assinaturas');
  expect(res.texto).toContain('Assinado no portal');
  expect(erros).toEqual([]);
});

test('conciliação: conta-se o stock no corte do dia; a carga para o dia seguinte fica de fora', async ({ page }) => {
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      return r.fulfill({
        json: {
          ok: true,
          linhas: [{ codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX' }],
          congelada_em: '2026-10-23 23:59:59',
        },
      });
    }
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        pode: { contar: true, coordenar: true, fechar: true },
        eu: 'tec',
        data: '2026-11-16',
        movimentos: 'saldo',
        motivos: MOTIVOS,
        nomes: {},
        rotas: [
          {
            rota: '1005',
            promotor: 'Olindo Baião',
            cega: false,
            linhas: [{ ...linhaCompleta('A1', 'Fraldas M', 37, 37), inicial: 64, carregado: 0, vendido: 27, depois_do_corte: 150 }],
            corte: { hora: '16:00', origem: 'estimado' },
            contado_por: 'tec',
            contado_em: '2026-11-16 17:32:00',
            motivos: {},
            resumo: { estado: 'por_validar', artigos: 1, alertas: 0, diferencas: 0, falta_kz: 0, sobra_kz: 0 },
            historico: [],
          },
        ],
      },
    });
  });
  const erros = await abrir(page, '#conciliacao-carrinha');
  await page.locator('.cc-rota', { hasText: '1005' }).getByRole('button', { name: 'Ver' }).click();
  const nota = page.locator('.cc-corte');
  await expect(nota).toContainText('Conta-se o que estava na carrinha no corte das 16:00');
  await expect(nota).toContainText('passa a stock da carrinha amanhã, às 00:00');
  await expect(nota).toContainText('Variação líquida do stock depois do corte: 150');
  expect(erros).toEqual([]);
});

test('conciliação: a folha de contagem imprime-se para toda a logística, mesmo sem poder contar; vendas depois do corte', async ({ page }) => {
  const rota = (codigo, estado, extra) => ({
    rota: codigo,
    promotor: 'Efrem',
    cega: false,
    linhas: [{ ...linhaCompleta('A1', 'Fraldas M', 10, null), inicial: 20, carregado: 0, vendido: 10 }],
    corte: { hora: '16:00', origem: 'fotografia', vendido_depois: 3 },
    motivos: {},
    resumo: { estado, artigos: 1, alertas: 0, diferencas: 0, falta_kz: 0, sobra_kz: 0 },
    historico: [],
    ...extra,
  });
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      return r.fulfill({
        json: {
          ok: true,
          linhas: [{ codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX' }],
          congelada_em: '2026-10-23 23:59:59',
        },
      });
    }
    return r.fulfill({
      json: {
        ok: true,
        gere: false,
        pode: { contar: false, coordenar: false, fechar: false },
        eu: 'log',
        data: '2026-10-23',
        movimentos: 'saldo',
        motivos: MOTIVOS,
        nomes: {},
        rotas: [rota('1009', 'por_contar', { quantidades_ocultas: true }), rota('1001', 'aprovada', { promotor: 'Outro' })],
      },
    });
  });
  await page.addInitScript(() => (window.open = () => null));
  const erros = await abrir(page, '#conciliacao-carrinha');
  const r1009 = page.locator('.cc-rota', { hasText: '1009' });
  // sem abrir a rota nem ter a função de contar: a folha às cegas está no cartão
  const botaoFolha = r1009.getByRole('button', { name: 'Imprimir folha de contagem' });
  const [d] = await Promise.all([page.waitForEvent('download'), botaoFolha.click()]);
  const fs = await import('node:fs');
  const folha = { nome: d.suggestedFilename(), texto: fs.readFileSync(await d.path(), 'latin1') };
  expect(folha.nome).toBe('CC-1009-20261026_folha-de-contagem.pdf');
  expect(folha.texto).toContain('Fraldas M');
  expect(folha.texto).not.toContain('Tem de voltar');
  // rota já contada: deixa de haver folha para imprimir
  await expect(page.locator('.cc-rota', { hasText: '1001' }).getByRole('button', { name: 'Imprimir folha de contagem' })).toHaveCount(0);
  // contagem às cegas também para quem só consulta: artigos sem as quantidades do sistema
  await r1009.getByRole('button', { name: 'Ver' }).click();
  await expect(r1009.locator('.cc-oculta')).toContainText('Contagem às cegas');
  await expect(r1009.locator('th')).toHaveText(['Código', 'Artigo', 'Unidade']);
  await r1009.getByRole('button', { name: 'Fechar' }).click();
  await page.locator('.cc-rota', { hasText: '1001' }).getByRole('button', { name: 'Ver' }).click();
  await expect(page.locator('.cc-corte')).toContainText('Vendido depois do corte (já não está na carrinha): 3');
  expect(erros).toEqual([]);
});

test('conciliação: a diferença regularizada no Pinguim BO fica à vista na linha (−5 → 0) e no resumo', async ({ page }) => {
  await page.route('**/v1/conciliacao-carrinha**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        gere: true,
        pode: { contar: true, coordenar: true, fechar: true },
        eu: 'tec',
        data: '2026-11-21',
        movimentos: 'saldo',
        motivos: MOTIVOS,
        nomes: {},
        rotas: [
          {
            rota: '1007',
            promotor: 'Awet Weldemenkorios',
            cega: false,
            linhas: [
              {
                ...linhaCompleta('10000015', 'Fraldas M', 15, 10),
                inicial: 0,
                carregado: 15,
                vendido: 0,
                diferenca: 0,
                valor: 0,
                diferenca_bruta: -5,
                regularizado: { q: -5, doc: 'FR FR0126S77774N/5, contado devolvido em SA A1000/130', em: '2026-11-23 08:40:00' },
              },
            ],
            contado_por: 'tec',
            contado_em: '2026-11-21 14:13:00',
            motivos: {},
            resumo: { estado: 'por_validar', artigos: 1, alertas: 0, diferencas: 0, falta_kz: 0, sobra_kz: 0 },
            historico: [],
          },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#conciliacao-carrinha');
  const cartao = page.locator('.cc-rota', { hasText: '1007' });
  await cartao.getByRole('button', { name: 'Ver' }).click();
  await expect(cartao.locator('.note').first()).toContainText('1 regularizada(s) no Pinguim BO');
  const dif = cartao.locator('td.cc-g-dif').first();
  await expect(dif.locator('s.cc-bruta')).toHaveText('-5');
  await expect(dif).toContainText('→ 0');
  await expect(dif).toHaveAttribute('title', /regularizada no Pinguim BO -5 \(FR FR0126S77774N\/5, contado devolvido em SA A1000\/130\)/);
  await expect(cartao.locator('.cc-regularizado').last()).toContainText('SA A1000/130');
  expect(erros).toEqual([]);
});

test('conciliação: facturas fora do horário de vendas assinaladas na rota (autorizadas à parte) e horário do dia numa linha', async ({ page }) => {
  await page.route('**/v1/conciliacao-carrinha**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        gere: true,
        pode: { contar: true, coordenar: true, fechar: true },
        eu: 'tec',
        data: '2026-11-23',
        movimentos: 'saldo',
        motivos: MOTIVOS,
        nomes: {},
        horario: {
          dia: '2026-11-23',
          tipo: 'completo',
          vendas: ['07:30', '15:00'],
          fecho: ['15:00', '15:30'],
          carga: ['15:30', '16:30'],
          agora: '2026-11-23 15:10',
        },
        rotas: [
          {
            rota: '1009',
            promotor: 'Efrem Bokre',
            cega: false,
            linhas: [linhaCompleta('10000292', 'Sabão', 0, 0)],
            contado_por: 'tec',
            contado_em: '2026-11-23 16:47:00',
            motivos: {},
            resumo: { estado: 'por_validar', artigos: 1, alertas: 0, diferencas: 0, falta_kz: 0, sobra_kz: 0 },
            historico: [],
            fora_horario: [
              {
                documento: 'FR FR0126S77745N/14',
                data: '2026-11-23',
                hora: '16:42',
                rota: '1009',
                total: 741379.5,
                inicio: '07:30',
                fim: '15:00',
                motivo: 'depois',
                autorizada: false,
              },
              {
                documento: 'FR FR0126S77745N/13',
                data: '2026-11-23',
                hora: '16:18',
                rota: '1009',
                total: 1231557,
                inicio: '07:30',
                fim: '15:00',
                motivo: 'depois',
                autorizada: true,
                autorizacao: { por: 'coord', motivo: 'Entrega combinada', ate: '16:30' },
              },
            ],
          },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#conciliacao-carrinha');
  await expect(page.locator('.hor-fase.agora')).toContainText('Contagem e PO');
  await expect(page.locator('.hor-fase.passou')).toContainText('Vendas');
  const cartao = page.locator('.cc-rota', { hasText: '1009' });
  await expect(cartao.locator('.note').first()).toContainText('1 factura(s) fora do horário');
  await cartao.getByRole('button', { name: 'Ver' }).click();
  const fh = cartao.locator('.cc-fora-horario');
  await expect(fh).toContainText('1 factura(s) fora do horário de vendas, sem autorização');
  await expect(fh).toContainText('FR FR0126S77745N/14 · 16:42');
  await expect(fh.locator('.cc-fh-aut')).toContainText('autorizada por coord: Entrega combinada');
  await expect(page.getByRole('button', { name: 'Autorizar venda fora do horário' })).toBeVisible();
  expect(erros).toEqual([]);
});
