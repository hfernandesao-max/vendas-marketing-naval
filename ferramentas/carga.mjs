// Teste de carga do Portal RTM: N pessoas a usar o portal ao mesmo tempo — entrada, Início (os pedidos que a página faz,
// em paralelo como o navegador) e navegação com tempo de leitura entre páginas. Mede p50/p95/p99 por pedido e erros.
// Uso (num computador da rede, nunca no próprio servidor; de preferência num servidor de ensaio ou fora de horas):
//   BASE=https://geo.naval.co.ao:2051 CONTAS=conta1,conta2 SENHA='...' N=50 DUR=300 node ferramentas/carga.mjs
// As contas não podem ter os dois passos activos. Ver docs/CAPACIDADE.md.
const BASE = process.env.BASE || 'http://127.0.0.1:8810';
const N = +process.env.N || 50;
const DUR = (+process.env.DUR || 120) * 1000;
const PAUSA = +process.env.PAUSA || 8000; // tempo médio de leitura entre páginas
const CONTAS = (process.env.CONTAS || 'dir,com,fin,log,pro,rec,sup,aud,bo')
  .split(',')
  .map((x) => x.trim())
  .filter(Boolean);
const SENHA = process.env.SENHA || '';
if (!SENHA) {
  console.error('Indique a palavra-passe das contas de ensaio em SENHA.');
  process.exit(2);
}
const ymd = (d) => d.toISOString().slice(0, 10).replace(/-/g, '');
const hoje = process.env.HOJE || ymd(new Date());
const ontem = process.env.ONTEM || ymd(new Date(Date.now() - 864e5));
const mes0 = hoje.slice(0, 6) + '01';
const stats = {};
let erros = {},
  total = 0;
const reg = (k, ms, ok, st) => {
  (stats[k] ||= []).push(ms);
  total++;
  if (!ok) erros[k + ' ' + st] = (erros[k + ' ' + st] || 0) + 1;
};
const dorme = (ms) => new Promise((r) => setTimeout(r, ms));
class Pessoa {
  constructor(i) {
    this.i = i;
    this.cookies = {};
  }
  ck() {
    return Object.entries(this.cookies)
      .map(([k, v]) => k + '=' + v)
      .join('; ');
  }
  guardar(r) {
    for (const c of r.headers.getSetCookie?.() || []) {
      const [kv] = c.split(';');
      const [k, ...v] = kv.split('=');
      this.cookies[k.trim()] = v.join('=');
    }
  }
  async pedir(nome, url, opt = {}) {
    const t0 = performance.now();
    let r,
      st = 0;
    try {
      r = await fetch(BASE + url, {
        redirect: 'manual',
        ...opt,
        headers: { Cookie: this.ck(), ...(this.token && url.startsWith('/api') ? { Authorization: 'Bearer ' + this.token } : {}), ...(opt.headers || {}) },
      });
      st = r.status;
      this.guardar(r);
      const txt = await r.text();
      reg(nome, performance.now() - t0, st < 400, st);
      return { r, txt };
    } catch (e) {
      reg(nome, performance.now() - t0, false, 'rede');
      return { r: null, txt: '' };
    }
  }
  async entrar() {
    const a = await this.pedir('GET login', '/geo/login.php');
    const csrf = (a.txt.match(/name="csrf" value="([^"]+)"/) || [])[1];
    await this.pedir('POST login', '/geo/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ usuario: CONTAS[this.i % CONTAS.length], senha: SENHA, csrf }),
    });
    const c = await this.pedir('GET portal', '/reports/');
    this.token = (c.txt.match(/name="api-token" content="([^"]+)"/) || [])[1];
    this.csrf = (c.txt.match(/name="csrf-token" content="([^"]+)"/) || [])[1];
    if (!this.token) {
      const t = await this.pedir('POST token', '/api/index.php/v1/auth/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
        body: '{}',
      });
      try {
        this.token = JSON.parse(t.txt).access_token;
      } catch (e) {}
    }
    return !!this.token;
  }
  async inicio() {
    const api = (n, u) => this.pedir(n, '/api/index.php/v1/' + u);
    await api('sessao', 'sessao');
    await Promise.all([
      api('dados/rotas', 'dados/rotas'),
      api('dados/clientes', 'dados/clientes'),
      api('dados/cargas', 'dados/cargas'),
      api('cadastros', 'cadastros'),
    ]);
    await Promise.all([
      api('vendas hoje', `vendas?start_date=${hoje}&end_date=${hoje}`),
      api('vendas hoje artigos', `vendas?start_date=${hoje}&end_date=${hoje}&include_items=only`),
      api('alertas', 'alertas'),
    ]);
    await Promise.all([
      api('vendas ontem', `vendas?start_date=${ontem}&end_date=${ontem}`),
      api('painel', 'painel'),
      api('sincronizacao', 'sincronizacao'),
      api('vistas', 'vistas'),
    ]);
  }
  async pagina() {
    const api = (n, u) => this.pedir(n, '/api/index.php/v1/' + u);
    const p = [
      () => api('vendas mês', `vendas?start_date=${mes0}&end_date=${hoje}`),
      () => api('vendas mês artigos', `vendas?start_date=${mes0}&end_date=${hoje}&include_items=only`),
      () => api('alertas', 'alertas'),
      () => api('indicadores', 'indicadores'),
      () => api('painel', 'painel'),
      () => api('vendas hoje', `vendas?start_date=${hoje}&end_date=${hoje}`),
    ];
    await p[Math.floor(Math.random() * p.length)]();
  }
  async correr(fim) {
    await dorme(Math.random() * 10000); // as pessoas não entram todas no mesmo segundo
    if (!(await this.entrar())) return;
    await this.inicio();
    while (Date.now() < fim) {
      await dorme(PAUSA * (0.5 + Math.random()));
      await this.pagina();
    }
  }
}
const fim = Date.now() + DUR;
const t0 = Date.now();
await Promise.all(Array.from({ length: N }, (_, i) => new Pessoa(i).correr(fim)));
const q = (a, p) => {
  const s = a.slice().sort((x, y) => x - y);
  return Math.round(s[Math.min(s.length - 1, Math.floor(p * s.length))]);
};
const linhas = Object.entries(stats)
  .sort()
  .map(([k, a]) => [k, a.length, q(a, 0.5), q(a, 0.95), q(a, 0.99), Math.round(Math.max(...a))]);
console.log(`\n${N} pessoas · ${Math.round((Date.now() - t0) / 1000)} s · ${total} pedidos · ${(total / ((Date.now() - t0) / 1000)).toFixed(1)} pedidos/s`);
console.log('pedido | n | p50 ms | p95 ms | p99 ms | máx ms');
for (const l of linhas) console.log(l.join(' | '));
console.log('erros:', JSON.stringify(erros));
