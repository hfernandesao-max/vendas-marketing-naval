// Espelho da apresentação: a app de gestão no ecrã do computador (moldura do modo apresentação) e a app no telemóvel
// da mesma conta mostram o mesmo ecrã ao mesmo tempo, nos dois sentidos, e no idioma escolhido no portal.
import { test, expect, devices } from '@playwright/test';
import { abrir, HOJE } from './dados.js';

const ATE = 4102444800; // 01-01-2100: a apresentação está activa durante o ensaio
const SESSAO = {
  login: 'henrique',
  nome: 'Henrique Fernandes',
  perfil: 'SADM',
  idioma: 'pt',
  csrf: 'abc',
  permissoes: { inicio: 'V', 'vendas-dia': 'V', 'modo-apresentacao': 'G', 'app-gestao': true, alertas: true },
  sessao: { inactiva_s: 3600, normal_s: 900, apresentacao_s: 3600, maxima_s: 36000 },
  apresentacao: ATE,
};
const IND = {
  ok: true,
  hora: '2026-11-14 14:35',
  hoje: { dia: '2026-11-14', total: 612000, documentos: 41, previsto: 1437381, progresso: 0.426 },
  ontem: { dia: '2026-11-13', total: 1340000, documentos: 88, habitual: 1210000, variacao: 0.107 },
  semana: { de: '2026-11-09', total: 5180000, anterior: 5420000, variacao: -0.044 },
  mes: { de: '2026-11-01', total: 5180000, anterior: 4860000, variacao: 0.066 },
  rotas: [],
  serie: [{ d: '2026-11-14', t: 612000 }],
  ultima_venda: '2026-11-14',
  alertas: { critico: 0, aviso: 0, info: 0, principais: [] },
};

// um «servidor» de espelho partilhado pelos dois lados (computador e telemóvel)
function espelho() {
  const s = { seq: 1, origem: '', estado: null };
  return async (ctx) => {
    await ctx.route('**/v1/sessao**', (r) => r.fulfill({ json: SESSAO }));
    await ctx.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
    await ctx.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: { ok: true } }));
    await ctx.route('**/v1/espelho**', (r) => {
      if (r.request().method() === 'POST') {
        const b = r.request().postDataJSON();
        s.seq++;
        s.origem = b.cliente;
        s.estado = b.estado;
        return r.fulfill({ json: { ok: true, seq: s.seq } });
      }
      const pedido = +new URL(r.request().url()).searchParams.get('seq');
      return r.fulfill({
        json: { ok: true, activo: true, ate: ATE, seq: s.seq, ...(pedido !== s.seq && s.estado ? { origem: s.origem, estado: s.estado } : {}) },
      });
    });
  };
}
const IPHONE = { ...devices['iPhone 13'] };
delete IPHONE.defaultBrowserType;

test('espelho: o que se faz na app no computador aparece no telemóvel e vice-versa, no idioma do portal', async ({ browser }) => {
  const ligar = espelho();
  // computador e telemóvel com o navegador em inglês: a app tem de falar o idioma escolhido no portal (português)
  const pc = await browser.newContext({ locale: 'en-US', viewport: { width: 1600, height: 1000 } });
  const tel = await browser.newContext({ ...IPHONE, locale: 'en-US' });
  await ligar(pc);
  await ligar(tel);
  const p = await pc.newPage();
  const m = await tel.newPage();
  await m.goto('/gestao/index.html');
  await abrir(p, '#inicio');
  // o modo apresentação está activo: a marca aparece; escolhe-se a app de gestão
  await p.locator('.apres-barra').getByRole('button', { name: 'App de gestão' }).click();
  const app = p.frameLocator('.apres-tel iframe');
  await expect(app.locator('#ag-h1')).toHaveText('Hoje');
  // no computador, abre «Rotas»: o telemóvel segue, já em português
  await app.locator('#ag-separadores button', { hasText: 'Rotas' }).click();
  await expect(app.locator('#ag-h1')).toHaveText('Rotas');
  await expect(m.locator('#ag-h1')).toHaveText('Rotas', { timeout: 8000 });
  await expect(m.locator('.ag-espelho')).toContainText('A mostrar o mesmo ecrã');
  // no telemóvel, abre «Alertas»: o computador segue (o relógio do computador está parado no ensaio: avança-se)
  await m.locator('#ag-separadores button', { hasText: 'Alertas' }).click();
  await p.clock.setFixedTime(new Date(HOJE.getTime() + 10000));
  await expect(app.locator('#ag-h1')).toHaveText('Alertas', { timeout: 8000 });
  // muda-se o idioma no portal: a app no computador e o telemóvel mudam também
  await p.locator('.apres-barra .apres-idioma button', { hasText: 'EN' }).click();
  await p.clock.setFixedTime(new Date(HOJE.getTime() + 20000));
  await expect(app.locator('#ag-h1')).toHaveText('Alerts', { timeout: 8000 });
  await expect(m.locator('#ag-h1')).toHaveText('Alerts', { timeout: 8000 });
  await expect(p.locator('.apres-barra')).toContainText('Management app');
  // deixar de seguir, no telemóvel: o computador continua, o telemóvel fica onde está
  await m.locator('.ag-espelho button').click();
  await app.locator('#ag-separadores button', { hasText: 'Today' }).click();
  await expect(app.locator('#ag-h1')).toHaveText('Today');
  await p.waitForTimeout(2000);
  await expect(m.locator('#ag-h1')).toHaveText('Alerts');
  await pc.close();
  await tel.close();
});
