// Arranque: ecrã de carregamento, sessão, dados e primeiro desenho.
import { route } from './app.js';
import { LIGACOES, avaliarFontes, fetchVendas } from './fontes.js';
import { n0 } from './formatos.js';
import { t } from './i18n/i18n.js';
import { definirSessao } from './perfil.js';
import { aplicarPreset, carregarFiltro, st } from './periodo.js';
import { $, DB, el } from './util.js';
import { iniciarSync } from './smartphones.js';
import './paginas/inicio.js';
import './paginas/vendas.js';
import './paginas/terreno.js';
import './paginas/sincronizacao.js';
import './paginas/recebimentos.js';
import './paginas/logistica.js';
import './paginas/cargas.js';
import './paginas/clientes.js';
import './paginas/plano-cargas.js';
import './paginas/sap.js';
import './paginas/acessos.js';

const LD = {
  passos: [
    ['base', 'Rotas, carteira e plano de cargas'],
    ['cad', 'Cadastros de clientes'],
    ['vendas', 'Vendas do Pinguim BO'],
    ['pronto', 'A montar os relatórios'],
  ],
  estado: {},
  iniciar() {
    const ul = $('#ld-passos');
    ul.replaceChildren();
    this.passos.forEach(([id, txt]) => {
      const li = el('li');
      li.id = 'ld-' + id;
      li.append(el('span', 'st'), el('span', null, t(txt)), el('span', 'det'));
      ul.append(li);
    });
    $('#ld-titulo').textContent = t('Portal RTM');
    this.marcar('base', 'activo');
  },
  marcar(id, est, det) {
    const li = $('#ld-' + id);
    if (!li) return;
    li.className = est;
    li.querySelector('.st').textContent = est === 'feito' ? '✓' : est === 'aviso' ? '!' : '';
    if (det != null) li.querySelector('.det').textContent = det;
    this.estado[id] = est;
    const feitos = this.passos.filter(([k]) => ['feito', 'aviso'].includes(this.estado[k])).length;
    $('#ld-barra').style.width = Math.max(8, Math.round((feitos / this.passos.length) * 100)) + '%';
    const prox = this.passos.find(([k]) => !['feito', 'aviso'].includes(this.estado[k]));
    if (est !== 'activo' && prox && this.estado[prox[0]] !== 'activo') {
      this.marcar(prox[0], 'activo');
      return;
    }
    $('#ld-sub').textContent = prox ? t(prox[1]) + '…' : t('Pronto');
  },
  fechar() {
    setTimeout(() => {
      $('#loader').classList.add('fora');
      setTimeout(() => {
        $('#loader').hidden = true;
      }, 600);
    }, 350);
  },
};
LD.iniciar();
// Segurança: se algo falhar sem resposta, o ecrã de carregamento sai ao fim de 45 s
const ldLimite = setTimeout(() => LD.fechar(), 45000);

// Sessão (perfil, rotas e permissões); 401 = sessão terminada, volta ao login
const lerSessao = () =>
  fetch('data/sessao.php', { cache: 'no-store' })
    .then((r) => {
      if (r.status === 401) {
        try {
          sessionStorage.setItem('vm-voltar', '1'); // depois de entrar de novo, volta à página onde estava
        } catch (e) {
          /* sem armazenamento */
        }
        location.href = '../geo/login.php?redirect=' + encodeURIComponent('../reports/');
        throw new Error('sessao');
      }
      return r.text();
    })
    .then((tx) => {
      try {
        const d = JSON.parse(tx);
        definirSessao(d && d.permissoes ? d : null);
      } catch (e) {
        definirSessao(null);
      }
    })
    .catch((e) => {
      if (e.message === 'sessao') throw e;
    });
// Ficheiros de dados: pelo serviço PHP (filtrado pelo perfil); sem PHP (teste local), o .json directo
const lerDados = (id) =>
  fetch('data/dados.php?f=' + id, { cache: 'no-store' })
    .then((r) => r.text())
    .then((tx) => {
      try {
        const d = JSON.parse(tx);
        return Array.isArray(d) ? d : [];
      } catch (e) {
        return fetch('data/' + id + '.json', { cache: 'no-store' })
          .then((r) => (r.ok ? r.json() : []))
          .catch(() => []);
      }
    })
    .catch(() => []);
lerSessao()
  .then(() =>
    Promise.all(
      ['rotas', 'clientes', 'cargas'].map((id) =>
        lerDados(id).then((d) => {
          DB[id] = d;
        }),
      ),
    ),
  )
  .then(() => {
    ['rotas', 'clientes', 'cargas'].forEach((k) => {
      if (!Array.isArray(DB[k])) DB[k] = [];
    });
    LD.marcar('base', DB.rotas.length ? 'feito' : 'aviso', t('{n} rotas', { n: DB.rotas.length }));
    DB.vendas = [];
    DB.mapRota = Object.fromEntries(DB.rotas.map((x) => [x.rota, x]));
    // Cadastros (rtm-clientes): no servidor via PHP; em alternativa, o ficheiro JSON copiado para data/
    const ler = (u) =>
      fetch(u, { cache: 'no-store' })
        .then((r) => (r.ok ? r.json() : null))
        .catch(() => null);
    return ler('data/cadastros.php')
      .then((d) => (d && d.cadastros ? d : ler('data/cadastros.json')))
      .then((d) => {
        DB.cad = d && Array.isArray(d.cadastros) ? d : null;
        // A carteira mais recente (com o dia de visita) substitui a lista fixa de clientes.json
        if (DB.cad && Array.isArray(DB.cad.carteira) && DB.cad.carteira.length) DB.clientes = DB.cad.carteira;
        avaliarFontes();
        LD.marcar('cad', DB.cad ? 'feito' : 'aviso', DB.cad ? n0(DB.cad.cadastros.length) + ' ' + t('clientes') : t('sem ligação'));
      });
  })
  .then(() => {
    carregarFiltro();
    return (st.preset || !st.de || !st.ate ? aplicarPreset(st.preset || 'mes') : fetchVendas(st.de, st.ate)).then(() =>
      LD.marcar(
        'vendas',
        LIGACOES.pinguim.estado === 'ok' ? 'feito' : 'aviso',
        LIGACOES.pinguim.estado === 'ok' ? t('{n} documentos', { n: n0(DB.vendas.length) }) : t('sem ligação'),
      ),
    );
  })
  .then(() => {
    // Abre sempre, mesmo sem vendas ainda no dia de hoje: cada página mostra o seu estado vazio
    route();
    iniciarSync(); // comunicação dos smartphones (indicador discreto no menu lateral)
    LD.marcar('pronto', 'feito');
    clearTimeout(ldLimite);
    LD.fechar();
  })
  .catch((e) => {
    console.error(e);
    clearTimeout(ldLimite);
    LD.fechar();
  });
