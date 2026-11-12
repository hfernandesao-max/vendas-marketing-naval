// Arranque: ecrã de carregamento, sessão, dados e primeiro desenho.
// Velocidade: os pedidos do arranque (sessão, dados de base, cadastros, vendas, dicionário do idioma e módulo da
// primeira página) partem todos em paralelo; o portal desenha-se quando chegam todos. As páginas e os idiomas
// carregam-se a pedido (paginas/modulos.js, i18n/i18n.js); as linhas de artigos, quando uma página precisa delas.
import { route } from './app.js';
import { LIGACOES, avaliarFontes, fetchVendas, recalcularDias } from './fontes.js';
import { n0 } from './formatos.js';
import { IDIOMAS, LANG, carregarIdioma, mudarIdioma, t } from './i18n/i18n.js';
import { aplicarPreferencias } from './barra-lateral.js';
import { montarBarraSup } from './barra-superior.js';
import { definirFavoritos } from './favoritos.js';
import { definirSessao, paginasVisiveis, podeVer, SESSAO } from './perfil.js';
import { aplicarPreset, carregarFiltro, st } from './periodo.js';
import { $, DB, el } from './util.js';
import { iniciarSync } from './smartphones.js';
import { MODULO_DA_PAGINA, carregarPagina } from './paginas/modulos.js';
import { apiFetch } from './api.js';
import { haPorGravar } from './componentes/rascunho.js';

const LD = {
  passos: [
    ['base', 'Rotas, carteira e plano de cargas'],
    ['cad', 'Cadastros de clientes'],
    ['vendas', 'Vendas do Pinguim BO'],
    ['pronto', 'A montar os relatórios'],
  ],
  estado: {},
  det: {},
  // Desenha a lista (de novo quando muda o idioma), mantendo o estado de cada passo
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
    const antes = this.estado;
    this.estado = {};
    if (!Object.keys(antes).length)
      ['base', 'cad', 'vendas'].forEach((k) => this.marcar(k, 'activo')); // em paralelo
    else this.passos.forEach(([k]) => antes[k] && this.marcar(k, antes[k], this.det[k]));
  },
  marcar(id, est, det) {
    const li = $('#ld-' + id);
    if (!li) return;
    li.className = est;
    li.querySelector('.st').textContent = est === 'feito' ? '✓' : est === 'aviso' ? '!' : '';
    if (det != null) {
      li.querySelector('.det').textContent = det;
      this.det[id] = det;
    }
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
  // Velocidade: o ecrã de carregamento sai logo que os dados chegam (antes esperava 0,35 s + 0,6 s)
  fechar() {
    requestAnimationFrame(() => {
      $('#loader').classList.add('fora');
      setTimeout(() => {
        $('#loader').hidden = true;
      }, 260);
    });
  },
};
LD.iniciar();
// Segurança: se algo falhar sem resposta, o ecrã de carregamento sai ao fim de 45 s
const ldLimite = setTimeout(() => LD.fechar(), 45000);

// Sessão (perfil, rotas e permissões); 401 = sessão terminada, volta ao login
const lerSessao = () =>
  apiFetch('sessao')
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
      let d = null;
      try {
        d = JSON.parse(tx);
      } catch (e) {
        d = null;
      }
      definirSessao(d && d.permissoes ? d : null);
      // aberto como app (ícone no ecrã principal) sem ter sido instalado pelo código QR: não funciona
      const comoApp = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
      if (d && d.permissoes && comoApp && !d.app) {
        location.replace('../app/so-pelo-qr.php');
        throw new Error('sessao');
      }
      if (d && d.permissoes && comoApp && d.app_biometria === false) {
        location.replace('../app/activar.php'); // falta activar o Face ID neste telemóvel
        throw new Error('sessao');
      }
      // app no telemóvel: uma versão nova instalada no servidor aplica-se sozinha quando a app volta ao ecrã
      if (d && comoApp && d.versao) {
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState !== 'visible') return;
          apiFetch('sessao')
            .then((r) => (r.ok ? r.json() : null))
            .then((s) => {
              if (s && s.versao && s.versao !== d.versao && !document.querySelector('dialog[open]') && !haPorGravar()) location.reload();
            })
            .catch(() => {});
        });
      }
      if (d && d.preferencias) {
        aplicarPreferencias(d.preferencias);
        definirFavoritos(d.preferencias.favoritos);
      }
      return d && d.permissoes ? idiomaDaConta(d) : null;
    })
    .catch((e) => {
      if (e.message === 'sessao') throw e;
    });
// Idioma da ficha da conta: a cada nova entrada o portal abre no idioma definido para a pessoa. Durante a sessão
// pode mudar-se no topo (PT/EN/TI); a escolha vale até sair, e na entrada seguinte volta o da ficha.
function idiomaDaConta(d) {
  if (!(d.idioma in IDIOMAS) || !d.entrada) return null;
  let marca = '';
  try {
    marca = localStorage.getItem('vm-lang-entrada') || '';
  } catch (e) {}
  const esta = d.login + '|' + d.entrada;
  if (marca === esta) return null;
  try {
    localStorage.setItem('vm-lang-entrada', esta);
    localStorage.setItem('vm-lang', d.idioma);
  } catch (e) {}
  if (d.idioma === LANG) return null;
  return mudarIdioma(d.idioma).then(() => {
    document.documentElement.lang = IDIOMAS[LANG];
    LD.iniciar();
  });
}
// Ficheiros de dados: pelo serviço PHP (filtrado pelo perfil); sem PHP (teste local), o .json directo
const lerDados = (id) =>
  apiFetch('dados/' + id)
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
// Cadastros (rtm-clientes): no servidor, só pelo serviço PHP (com o âmbito do perfil). O ficheiro JSON
// directo só serve nos testes locais sem PHP (no servidor está bloqueado, e bem, pelo .htaccess: 403).
const lerCadastros = () => {
  const ler = (u, api) =>
    (api ? apiFetch(u) : fetch(u, { cache: 'no-store' }))
      .then((r) => {
        const json = (r.headers.get('content-type') || '').includes('application/json');
        if (api && json) return r.json().then((d) => ({ php: true, d: r.ok ? d : null }));
        return r.ok ? r.json().then((d) => ({ php: false, d })) : { php: false, d: null };
      })
      .catch(() => ({ php: false, d: null }));
  return ler('cadastros', true).then((x) => (x.php ? x.d : ler('data/cadastros.json').then((y) => y.d)));
};

// Página pedida no endereço (#página/…) ou a última deste separador (ao actualizar com F5): o seu módulo começa
// logo a descarregar, em paralelo com os dados
let ultima = '';
try {
  ultima = (sessionStorage.getItem('vm-ultima-pagina') || '').slice(1);
} catch (e) {
  /* sem armazenamento */
}
const pedida = (location.hash.slice(1) || ultima).split('?')[0].split('/')[0];
carregarPagina(MODULO_DA_PAGINA[pedida] ? pedida : 'inicio').catch(() => {});

carregarFiltro();
DB.cargas = [];
DB.vendas = [];
const idioma = carregarIdioma(LANG).then(() => LD.iniciar()); // dicionário do idioma guardado (se não é o português)
const sessao = lerSessao();
const base = Promise.all(
  ['rotas', 'clientes', 'cargas'].map((id) =>
    lerDados(id).then((d) => {
      DB[id] = Array.isArray(d) ? d : [];
    }),
  ),
).then(() => {
  DB.mapRota = Object.fromEntries(DB.rotas.map((x) => [x.rota, x]));
  LD.marcar('base', DB.rotas.length ? 'feito' : 'aviso', t('{n} rotas', { n: DB.rotas.length }));
});
const cadastros = lerCadastros().then((d) => {
  DB.cad = d && Array.isArray(d.cadastros) ? d : null;
  LD.marcar('cad', DB.cad ? 'feito' : 'aviso', DB.cad ? n0(DB.cad.cadastros.length) + ' ' + t('clientes') : t('sem ligação'));
});
const vendas = (st.preset || !st.de || !st.ate ? aplicarPreset(st.preset || 'mes') : fetchVendas(st.de, st.ate)).then(() =>
  LD.marcar(
    'vendas',
    LIGACOES.pinguim.estado === 'ok' ? 'feito' : 'aviso',
    LIGACOES.pinguim.estado === 'ok' ? t('{n} documentos', { n: n0(DB.vendas.length) }) : t('sem ligação'),
  ),
);
Promise.all([idioma, sessao, base, cadastros, vendas])
  .then(() => {
    // A carteira mais recente (com o dia de visita) substitui a lista fixa de clientes.json
    if (DB.cad && Array.isArray(DB.cad.carteira) && DB.cad.carteira.length) DB.clientes = DB.cad.carteira;
    recalcularDias(); // vendas e cargas chegaram por ordem qualquer
    avaliarFontes();
    // Módulo da página que vai abrir (a pedida, se o perfil a vê; senão a primeira do perfil)
    const abre = podeVer(pedida) && MODULO_DA_PAGINA[pedida] ? pedida : (paginasVisiveis()[0] || {}).id;
    return abre ? carregarPagina(abre).catch((e) => console.error(e)) : null;
  })
  .then(() => {
    // Abre sempre, mesmo sem vendas ainda no dia de hoje: cada página mostra o seu estado vazio
    montarBarraSup();
    route();
    iniciarSync(); // comunicação dos smartphones (indicador discreto no menu lateral)
    LD.marcar('pronto', 'feito');
    clearTimeout(ldLimite);
    LD.fechar();
    // Super administrador: resumo das actualizações do portal ainda por ver (módulo carregado só para esse perfil)
    if (SESSAO && SESSAO.perfil === 'SADM') import('./novidades.js').then((m) => m.mostrarNovidades()).catch(() => {});
  })
  .catch((e) => {
    console.error(e);
    clearTimeout(ldLimite);
    LD.fechar();
  });
