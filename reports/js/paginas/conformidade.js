// Administração › Conformidade: a prova dos controlos que o portal cumpre sozinho, todos os dias (ISO/IEC 27001 e
// 27002, ISO/IEC 27701, ISO 22301, ISO 15489, Lei n.º 22/11): registos invioláveis, contas sem uso, revisão
// trimestral de acessos, cópia diária cifrada com teste de recuperação e prazos de retenção. Para o administrador da
// plataforma e a auditoria; confirmar a revisão de acessos só o super administrador. Servidor: nucleo/api/v1/conformidade.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let CF = null;
let aLer = false;
const dh = (s) => {
  const x = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
  return x ? `${x[3]}-${x[2]}-${x[1]}` + (x[4] ? ` ${x[4]}:${x[5]}` : '') : '—';
};
const mb = (b) => (b / 1048576).toFixed(1).replace('.', ',') + ' MB';

function ler(verificar) {
  if (aLer) return;
  aLer = true;
  apiJson('conformidade' + (verificar ? '?verificar=1' : '')).then((d) => {
    aLer = false;
    CF = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'conformidade') draw();
    if (verificar && d && d.verificacao)
      toast(
        t(d.verificacao.ok ? 'Registos íntegros: {n} linhas encadeadas.' : 'ATENÇÃO: a cadeia dos registos está partida.', { n: n0(d.verificacao.encadeadas) }),
        6000,
      );
  });
}

function confirmar(tri) {
  janela(
    t('Confirmar a revisão de acessos {t}', { t: tri }),
    t('Confirme que reviu quem tem acesso a quê. O que retirar, faça-o em Acessos.'),
    (b, ft, fechar) => {
      const notas = entrada('text', '', { maxLength: 1000, placeholder: t('Ex.: retirado o acesso de 2 contas de estagiários') });
      const erro = msgErro();
      b.append(campo(t('Notas'), notas), erro);
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      ft.append(
        botao(t('Confirmar'), '', () =>
          postJson('conformidade', { accao: 'confirmar_revisao', trimestre: tri, notas: notas.value.trim() }).then((d) => {
            if (!d || !d.ok) {
              erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
              erro.hidden = false;
              return;
            }
            CF = d;
            fechar();
            toast(t('Revisão de {t} confirmada.', { t: tri }), 4000);
            draw();
          }),
        ),
      );
    },
  );
}

R.conformidade = (pg) => {
  if (!CF) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler(false);
    return;
  }
  if (CF.falhou) {
    pg.append(el('div', 'glass empty', t(CF.falhou)));
    return;
  }
  const e = CF.estado || {};
  const reg = e.registos || {};
  const cop = e.copia || {};
  const con = e.contas || {};
  const rev = CF.revisoes.find((r) => r.trimestre === CF.revisao_actual);
  pg.append(
    tiles([
      {
        l: t('Registos de auditoria'),
        v: reg.ok === false ? t('Cadeia partida') : reg.ok ? t('Íntegros') : '—',
        s: reg.encadeadas != null ? t('{n} linhas encadeadas', { n: n0(reg.encadeadas) }) : t('ainda sem verificação'),
        tone: reg.ok === false ? 'bad' : null,
      },
      {
        l: t('Cópia dos dados'),
        v: cop.copia ? dh(e.inicio) : '—',
        s: cop.copia ? (cop.copia.cifrada ? t('cifrada') : t('sem cifra')) + ' · ' + mb(cop.copia.bytes) : t('ainda sem cópia'),
        tone: cop.copia && !cop.copia.cifrada ? 'warn' : null,
      },
      {
        l: t('Teste de recuperação'),
        v: cop.recuperacao ? (cop.recuperacao.ok ? t('Passou') : t('Falhou')) : '—',
        s: cop.recuperacao ? t('{n} ficheiros conferidos', { n: n0(cop.recuperacao.ficheiros) }) : '',
        tone: cop.recuperacao && !cop.recuperacao.ok ? 'bad' : null,
      },
      {
        l: t('Contas desligadas por falta de uso'),
        v: n0((con.desligadas || []).length),
        s: t('a desligar em 14 dias: {n}', { n: n0((con.a_desligar || []).length) }),
      },
      {
        l: t('Revisão de acessos {t}', { t: CF.revisao_actual }),
        v: rev ? (rev.confirmada ? t('Confirmada') : t('Por confirmar')) : t('Por abrir'),
        s: rev && rev.confirmada ? dh(rev.confirmada.em) + ' · ' + rev.confirmada.por : '',
        tone: rev && !rev.confirmada ? 'warn' : null,
      },
    ]),
  );
  const acc = el('section', 'glass sec conf-barra');
  acc.append(botao(t('Verificar os registos agora'), 'ghost', () => ler(true)));
  if (CF.pode_confirmar && rev && !rev.confirmada) acc.append(botao(t('Confirmar a revisão de acessos'), '', () => confirmar(rev.trimestre)));
  pg.append(acc);
  if ((e.problemas || []).length) {
    const ul = el('ul');
    e.problemas.forEach((x) => ul.append(el('li', null, x)));
    pg.append(secBox(t('Problemas na última verificação'), dh(e.inicio), ul));
  }
  const avisos = [];
  if (!CF.cifra) avisos.push(t('Cópias sem cifra: definir COPIAS_CHAVE no .env do servidor.'));
  if (!CF.copia_externa) avisos.push(t('Cópias só no servidor: definir COPIAS_PASTA_EXTERNA (pasta de rede ou disco externo).'));
  if (!CF.registos_externos) avisos.push(t('Registos só no servidor: definir REGISTOS_COPIA_PASTA.'));
  if (avisos.length) {
    const ul = el('ul');
    avisos.forEach((x) => ul.append(el('li', null, x)));
    pg.append(secBox(t('Por configurar no servidor'), t('Uma vez, pela Informática; o resto é automático.'), ul));
  }
  const contas = [
    ...(con.desligadas || []).map((c) => ({ ...c, estado: 'desligada' })),
    ...(con.a_desligar || []).map((c) => ({ ...c, estado: 'a_desligar' })),
  ];
  if (contas.length)
    pg.append(
      tabela({
        id: 'conf-contas',
        titulo: t('Contas sem uso (mais de {d} dias desligam-se sozinhas)', { d: CF.contas_dias }),
        rows: contas,
        cols: [
          { key: 'nome', label: t('Pessoa'), p: 0 },
          { key: 'perfil', label: t('Perfil') },
          { key: 'ultima_entrada', label: t('Última entrada'), render: (v) => document.createTextNode(dh(v)) },
          { key: 'dias', label: t('Dias sem uso'), type: 'n' },
          { key: 'estado', label: t('Estado'), render: (v) => pill(t(v === 'desligada' ? 'Desligada' : 'A desligar'), v === 'desligada' ? 'p-mut' : 'p-warn') },
        ],
      }),
    );
  pg.append(
    tabela({
      id: 'conf-retencao',
      titulo: t('Prazos de retenção'),
      nota: t('O que passa do prazo é apagado todas as noites, com registo (ISO/IEC 27701, Lei n.º 22/11).'),
      search: false,
      rows: CF.retencao.map((r) => ({ ...r, apagados: ((e.retencao || {}).apagados || {})[r.id] || 0 })),
      cols: [
        { key: 'descricao', label: t('Dados'), p: 0, get: (r) => t(r.descricao) },
        { key: 'dias', label: t('Prazo (dias)'), type: 'n' },
        { key: 'apagados', label: t('Apagados na última noite'), type: 'n' },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-copias',
      titulo: t('Cópias dos dados'),
      search: false,
      rows: CF.copias,
      cols: [
        { key: 'em', label: t('Data'), p: 0, render: (v) => document.createTextNode(dh(v)) },
        { key: 'bytes', label: t('Tamanho'), render: (v) => document.createTextNode(mb(v)) },
        { key: 'ficheiro', label: t('Ficheiro') },
      ],
    }),
  );
};
