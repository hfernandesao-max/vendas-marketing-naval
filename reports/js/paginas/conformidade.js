// Administração › Conformidade: a prova dos controlos que o portal cumpre sozinho, todos os dias (ISO/IEC 27001 e
// 27002, ISO/IEC 27701, ISO 22301, ISO 15489, Lei n.º 22/11): registos invioláveis, contas sem uso, revisão
// trimestral de acessos, cópia diária cifrada com teste de recuperação e prazos de retenção. Para o administrador da
// plataforma e a auditoria; confirmar a revisão de acessos só o super administrador. Servidor: nucleo/api/v1/conformidade.php.
// Também o SGSI (ISO/IEC 27001): âmbito, objectivos medidos, declaração de aplicabilidade (93 controlos), riscos, RoPA,
// programa de auditoria e a aprovação de cada documento pelo super administrador (nucleo/servicos/sgsi.php).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, n0 } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
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
/** Texto bilingue dos dados do SGSI ({pt, en}); em tigrínia mostra o inglês. */
const L = (o) => (o && (o[LANG] || o.en || o.pt)) || '';
const EST_CTL = {
  implementado: ['Implementado', 'p-ok'],
  parcial: ['Parcial', 'p-warn'],
  planeado: ['Planeado', 'p-serio'],
  nao_aplicavel: ['Não aplicável', 'p-mut'],
};
const EST_DOC = { aprovado: ['Aprovado', 'p-ok'], alterado: ['Alterado, por aprovar', 'p-warn'], por_aprovar: ['Por aprovar', 'p-serio'] };
/** Mantém a ordem do documento (as tabelas ordenam pela primeira coluna por omissão). */
const ordem = (rows) => rows.map((r, i) => ({ ...r, ordem: i }));
const TRAT = { reduzir: 'Reduzir', aceitar: 'Aceitar', transferir: 'Transferir', evitar: 'Evitar' };
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

function aprovar(doc) {
  janela(t('Aprovar: {d}', { d: t(doc.nome) }), t('Aprova esta versão do documento. Fica registado o resumo SHA-256, quem e quando.'), (b, ft, fechar) => {
    const notas = entrada('text', '', { maxLength: 1000, placeholder: t('Ex.: aprovado em reunião da Administração') });
    const erro = msgErro();
    b.append(el('p', 'conf-hash', 'SHA-256 ' + doc.hash), campo(t('Notas'), notas), erro);
    ft.append(botao(t('Cancelar'), 'ghost', fechar));
    ft.append(
      botao(t('Aprovar'), '', () =>
        postJson('conformidade', { accao: 'aprovar_documento', doc: doc.id, notas: notas.value.trim() }).then((d) => {
          if (!d || !d.ok) {
            erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
            erro.hidden = false;
            return;
          }
          CF = d;
          fechar();
          toast(t('Documento aprovado.'), 4000);
          draw();
        }),
      ),
    );
  });
}

/** Vigilância em produção: falhas do código, verificações de negócio e o que já foi para o GitHub. */
function vigia(pg) {
  const V = CF.vigia;
  if (!V) return;
  const neg = Object.entries(V.negocio || {});
  const negFalha = neg.filter(([, r]) => !r.ok);
  pg.append(
    tiles([
      {
        l: t('Vigilância'),
        v: V.activa ? t('Ligada') : t('Desligada'),
        s: V.ultimo ? t('última verificação: {d}', { d: dh(V.ultimo) }) : t('ainda sem verificações'),
        tone: V.activa ? null : 'warn',
      },
      { l: t('Problemas abertos'), v: n0(V.abertos), tone: V.abertos ? 'warn' : null },
      {
        l: t('Verificações de negócio'),
        v: neg.length ? n0(neg.length - negFalha.length) + ' / ' + n0(neg.length) : '—',
        tone: negFalha.length ? 'warn' : null,
      },
    ]),
  );
  if (V.github_erro || negFalha.length) {
    const ul = el('ul');
    if (V.github_erro) ul.append(el('li', null, t(V.github_erro)));
    negFalha.forEach(([, r]) => ul.append(el('li', null, r.mensagem)));
    pg.append(secBox(t('Atenção da vigilância'), '', ul));
  }
  const C = V.conferencia;
  if (C && C.rotas) {
    pg.append(
      tabela({
        id: 'conf-conferencia',
        titulo: t('Conferência com o Pinguim BO de {d}', { d: dh(C.dia) }),
        nota: C.ok
          ? t('Documentos e valores iguais em todas as rotas.')
          : t('{n} rota(s) com diferenças: o portal tem de mostrar exactamente o que está no Pinguim BO.', { n: C.diferencas.length }),
        search: false,
        rows: C.rotas,
        sort: 'rota',
        total: true,
        cols: [
          { key: 'rota', label: t('Rota'), p: 0 },
          { key: 'pinguim_docs', label: t('Documentos no Pinguim BO'), type: 'n', sum: true },
          { key: 'portal_docs', label: t('Documentos no portal'), type: 'n', sum: true },
          { key: 'pinguim_valor', label: t('Valor no Pinguim BO'), type: 'kz', sum: true, p: 0 },
          { key: 'portal_valor', label: t('Valor no portal'), type: 'kz', sum: true },
          { key: 'ok', label: t('Estado'), p: 0, render: (v) => pill(v ? t('Igual') : t('Diferente'), v ? 'p-ok' : 'p-bad') },
        ],
      }),
    );
  }
  pg.append(
    tabela({
      id: 'conf-vigia',
      titulo: t('Problemas detectados em produção'),
      nota: t('Agrupados por assinatura e sem dados pessoais. Os que estão no GitHub são corrigidos de madrugada, com teste, e seguem pelo ensaio.'),
      search: false,
      rows: V.problemas || [],
      sort: 'ultimo',
      dir: -1,
      cols: [
        { key: 'mensagem', label: t('Problema'), p: 0, wrap: true },
        { key: 'tipo', label: t('Tipo') },
        { key: 'contagem', label: t('Ocorrências'), type: 'n' },
        { key: 'ultimo', label: t('Última'), get: (r) => dh(r.ultimo) },
        { key: 'estado', label: t('Estado'), p: 0, render: (v) => pill(v === 'aberto' ? t('Aberto') : t('Resolvido'), v === 'aberto' ? 'p-warn' : 'p-mut') },
        {
          key: 'issue',
          label: t('GitHub'),
          render: (v) =>
            v
              ? Object.assign(el('a', null, '#' + v), { href: 'https://github.com/' + V.repositorio + '/issues/' + v, target: '_blank', rel: 'noopener' })
              : document.createTextNode('—'),
        },
      ],
    }),
  );
}

/** O sistema de gestão (ISO/IEC 27001): documentos aprovados, objectivos, aplicabilidade, riscos, RoPA e auditoria. */
function sgsi(pg) {
  const S = CF.sgsi;
  if (!S) return;
  const pe = S.resumo_controlos.por_estado;
  const docsOk = S.documentos.filter((d) => d.estado === 'aprovado').length;
  pg.append(
    tiles([
      { l: t('Documentos do SGSI aprovados'), v: n0(docsOk) + ' / ' + n0(S.documentos.length), tone: docsOk < S.documentos.length ? 'warn' : null },
      {
        l: t('Controlos implementados'),
        v: n0(pe.implementado || 0) + ' / ' + n0(S.resumo_controlos.total - (pe.nao_aplicavel || 0)),
        s: t('parciais: {p} · planeados: {q}', { p: n0(pe.parcial || 0), q: n0(pe.planeado || 0) }),
      },
      {
        l: t('Riscos acima do aceitável'),
        v: n0(S.riscos.filter((r) => !r.aceitavel).length),
        s: t('residual até {n}', { n: S.risco_aceitavel }),
        tone: S.riscos.some((r) => !r.aceitavel) ? 'warn' : null,
      },
      { l: t('Não conformidades abertas'), v: n0(S.nao_conformidades.abertas), s: t('{n} no total', { n: n0(S.nao_conformidades.total) }) },
    ]),
  );
  pg.append(secBox(t('Âmbito do SGSI'), t('Versão {v} · {d}', { v: S.versao, d: dh(S.data) }), el('p', 'conf-ambito', L(S.ambito))));
  pg.append(
    tabela({
      id: 'conf-docs',
      titulo: t('Documentos do SGSI'),
      nota: t('Cada versão é aprovada pelo super administrador; se o conteúdo mudar, volta a «por aprovar».'),
      search: false,
      rows: ordem(S.documentos),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'nome', label: t('Documento'), p: 0, get: (r) => t(r.nome) },
        { key: 'estado', label: t('Estado'), render: (v) => pill(t((EST_DOC[v] || [v])[0]), (EST_DOC[v] || [0, 'p-mut'])[1]) },
        {
          key: 'aprovacao',
          label: t('Aprovação'),
          get: (r) => (r.aprovacao ? dh(r.aprovacao.em) + ' · ' + r.aprovacao.nome : '—'),
        },
        {
          key: 'id',
          label: '',
          p: 0,
          render: (v, r) => (CF.pode_confirmar && r.estado !== 'aprovado' ? botao(t('Aprovar'), 'ghost sm', () => aprovar(r)) : document.createTextNode('')),
        },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-objectivos',
      titulo: t('Objectivos de segurança'),
      nota: t('Medidos pelo próprio portal (ISO/IEC 27001, 6.2 e 9.1).'),
      search: false,
      rows: ordem(S.objectivos),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'texto', label: t('Objectivo'), p: 0, wrap: true, get: (r) => L(r.texto) },
        { key: 'meta', label: t('Meta'), get: (r) => r.meta + '%' },
        { key: 'valor', label: t('Resultado'), get: (r) => (r.valor == null ? '—' : r.valor + '%') },
        {
          key: 'cumpre',
          label: t('Estado'),
          render: (v) => (v == null ? pill(t('Sem dados'), 'p-mut') : pill(t(v ? 'Cumpre' : 'Não cumpre'), v ? 'p-ok' : 'p-bad')),
        },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-soa',
      titulo: t('Declaração de aplicabilidade (ISO/IEC 27002:2022)'),
      nota: t('Os 93 controlos: se se aplicam, estado, evidência e responsável.'),
      rows: ordem(S.controlos),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'id', label: t('Controlo'), p: 0 },
        { key: 'nome', label: t('Nome'), wrap: true, get: (r) => L(r.nome) },
        { key: 'tema', label: t('Tema'), get: (r) => L(S.temas[r.tema]) },
        { key: 'estado', label: t('Estado'), render: (v) => pill(t((EST_CTL[v] || [v])[0]), (EST_CTL[v] || [0, 'p-mut'])[1]) },
        { key: 'evidencias', label: t('Evidência'), wrap: true, get: (r) => r.evidencias.map((e) => L(S.evidencias[e])).join(' · ') || '—' },
        { key: 'nota', label: t('Acção ou justificação'), wrap: true, get: (r) => L(r.nota) || '—' },
        { key: 'responsavel', label: t('Responsável'), get: (r) => L(S.responsaveis[r.responsavel]) },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-riscos',
      titulo: t('Registo de riscos e plano de tratamento'),
      nota: t('Nível = probabilidade × impacto (1 a 25); aceitável até {n}.', { n: S.risco_aceitavel }),
      rows: S.riscos,
      sort: 'nivel',
      dir: -1,
      cols: [
        { key: 'id', label: t('Risco'), p: 0 },
        { key: 'ameaca', label: t('Ameaça'), wrap: true, get: (r) => L(r.ameaca) },
        { key: 'nivel', label: t('Nível'), type: 'n' },
        { key: 'tratamento', label: t('Tratamento'), get: (r) => t(TRAT[r.tratamento] || r.tratamento) },
        { key: 'controlos', label: t('Controlos'), get: (r) => r.controlos.join(', ') },
        {
          key: 'nivel_residual',
          label: t('Residual'),
          type: 'n',
          render: (v, r) => pill(String(v), r.aceitavel ? 'p-ok' : 'p-warn'),
        },
        { key: 'nota', label: t('Acção ou justificação'), wrap: true, get: (r) => L(r.nota) || '—' },
        { key: 'dono', label: t('Responsável'), get: (r) => L(S.responsaveis[r.dono]) },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-ropa',
      titulo: t('Registo das actividades de tratamento (RoPA)'),
      nota: t('Dados pessoais tratados pela plataforma (ISO/IEC 27701, Lei n.º 22/11).'),
      search: false,
      rows: ordem(S.ropa),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'tratamento', label: t('Tratamento'), p: 0, wrap: true, get: (r) => L(r.tratamento) },
        { key: 'finalidade', label: t('Finalidade'), wrap: true, get: (r) => L(r.finalidade) },
        { key: 'base_legal', label: t('Base legal'), wrap: true, get: (r) => L(r.base_legal) },
        { key: 'titulares', label: t('Titulares'), wrap: true, get: (r) => L(r.titulares) },
        { key: 'dados', label: t('Dados'), wrap: true, get: (r) => L(r.dados) },
        { key: 'destinatarios', label: t('Destinatários'), wrap: true, get: (r) => L(r.destinatarios) },
        { key: 'prazo', label: t('Prazo de conservação'), wrap: true, get: (r) => L(r.prazo) },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'conf-auditoria',
      titulo: t('Programa de auditoria interna e revisão pela direcção'),
      search: false,
      rows: ordem(S.auditoria),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'quando', label: t('Mês'), p: 0, get: (r) => r.quando.slice(5) + '-' + r.quando.slice(0, 4) },
        { key: 'ambito', label: t('Âmbito'), wrap: true, get: (r) => L(r.ambito) },
      ],
    }),
  );
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

const LIG = {
  controlador: 'Controlador',
  cifra: 'Cifra da ligação',
  certificado: 'Certificado do servidor',
  intencao: 'Intenção só de leitura',
  aplicacao: 'Nome da aplicação',
  tempos: 'Tempos limite (ligação / consulta)',
};
const ODBC18 = 'Microsoft ODBC Driver 18 for SQL Server';
const LIG_AJUDA = {
  controlador: () => t('Instalar o {c} no servidor do portal.', { c: ODBC18 }),
  cifra: () => t('Retirar {k} do ficheiro de configuração: a ligação tem de ir cifrada.', { k: 'DB_ENCRYPT=0' }),
  certificado: () =>
    t(
      'Instalar no SQL Server um certificado emitido para o nome do servidor (autoridade de certificação da empresa ou pública) e reiniciar o serviço; o portal passa a validá-lo sozinho.',
    ),
  intencao: () => t('Instalar o {c} (o controlador antigo não a suporta).', { c: ODBC18 }),
};

/** Ligações às bases de dados: cada controlo da configuração de referência, com o que falta fazer no servidor. */
function ligacoes(pg) {
  const L2 = CF.ligacoes || [];
  if (!L2.length) return;
  const rows = [];
  L2.forEach((x) =>
    (x.controlos || []).forEach((c) =>
      rows.push({
        fonte: x.nome,
        id: c.id,
        controlo: t(LIG[c.id] || c.id),
        valor: c.id === 'certificado' ? (c.ok ? t('validado') : t('não validado')) : c.valor,
        norma: c.norma,
        ok: c.ok,
        accao: c.ok || !LIG_AJUDA[c.id] ? '' : LIG_AJUDA[c.id](),
      }),
    ),
  );
  if (!rows.length) return;
  const fora = rows.filter((r) => !r.ok).length;
  pg.append(
    tabela({
      id: 'conf-ligacoes',
      titulo: t('Ligações às bases de dados'),
      nota:
        fora === 1
          ? t('1 controlo fora da norma: o portal liga-se da forma mais segura que o servidor permite e volta a verificar sozinho.')
          : fora
            ? t('{n} controlos fora da norma: o portal liga-se da forma mais segura que o servidor permite e volta a verificar sozinho.', { n: n0(fora) })
            : t('Todas as ligações cumprem a configuração de referência (ISO/IEC 27001 A.8.24, A.5.15).'),
      search: false,
      rows,
      cols: [
        { key: 'fonte', label: t('Base de dados'), p: 0 },
        { key: 'controlo', label: t('Controlo') },
        { key: 'valor', label: t('Situação') },
        { key: 'norma', label: t('Norma') },
        { key: 'ok', label: t('Estado'), render: (v) => pill(v ? t('Conforme') : t('Fora da norma'), v ? 'p-ok' : 'p-serio') },
        { key: 'accao', label: t('O que falta fazer') },
      ],
    }),
  );
}

const MODO_FONTE = {
  pinguim: 'Pinguim BO (o OLAP não é usado)',
  paralelo: 'Em conferência: mostra o Pinguim BO e confere o OLAP todas as manhãs',
  olap: 'OLAP nos dias conferidos; hoje e os restantes do Pinguim BO',
};
const MEDIDA = { documentos: 'documentos', total: 'total com IVA', sem_iva: 'total sem IVA', linhas: 'linhas', quantidade: 'quantidade', bruto: 'total bruto' };

/** Fontes de dados: modo das vendas, contrato do OLAP e a conferência diária OLAP × Pinguim BO dos últimos 7 dias. */
function fontesDados(pg) {
  const f = CF.fontes;
  if (!f || !f.olap) return;
  const ok = (f.dias || []).filter((d) => d.conferido === true).length;
  pg.append(
    tiles([
      {
        l: t('Vendas: fonte do histórico'),
        v: t(f.vendas === 'olap' ? 'OLAP' : f.vendas === 'paralelo' ? 'Em conferência' : 'Pinguim BO'),
        s: t(MODO_FONTE[f.vendas] || ''),
      },
      {
        l: t('Contrato de dados (rtm v{v})', { v: f.contrato }),
        v: f.contrato_ok ? t('Completo') : t('Incompleto'),
        s: f.contrato_ok ? '' : f.contrato_erro ? t('sem resposta do OLAP') : t('{n} colunas em falta', { n: n0((f.contrato_faltam || []).length) }),
        tone: f.contrato_ok ? null : 'bad',
      },
      {
        l: t('Dias conferidos (últimos 7)'),
        v: n0(ok) + ' / ' + n0((f.dias || []).length),
        s: f.conferido_ate ? t('conferido até {d}', { d: dl(f.conferido_ate.dia) }) : t('ainda nenhum'),
        tone: ok < (f.dias || []).length ? 'warn' : null,
      },
      {
        l: t('Disjuntor do OLAP'),
        v: f.disjuntor ? t('Em pausa') : t('Fechado'),
        s: f.disjuntor ? t('até {h}', { h: String(f.disjuntor).slice(11, 16) }) : t('consultas normais'),
        tone: f.disjuntor ? 'bad' : null,
      },
    ]),
  );
  if (!f.contrato_ok && (f.contrato_faltam || []).length) {
    const ul = el('ul');
    f.contrato_faltam.forEach((x) => ul.append(el('li', null, x)));
    pg.append(secBox(t('Colunas do contrato em falta no OLAP'), t('Enquanto faltarem, as vendas vêm do Pinguim BO.'), ul));
  }
  pg.append(
    tabela({
      id: 'conf-fontes',
      titulo: t('Conferência diária OLAP × Pinguim BO'),
      nota: t(
        'Por dia e rota: documentos, totais com e sem IVA, linhas, quantidades e pagamentos por meio. Um dia só passa a ser lido do OLAP depois de conferido sem diferenças.',
      ),
      search: false,
      rows: (f.dias || []).map((d) => ({
        ...d,
        estado: d.conferido === true ? 'ok' : d.conferido === false ? 'dif' : 'por',
        resumo: (d.diferencas || [])
          .slice(0, 3)
          .map((x) => t('rota {r}: {m} Pinguim BO {a} × OLAP {b}', { r: x.rota, m: t(MEDIDA[x.medida] || x.medida), a: x.pinguim, b: x.olap }))
          .join(' · '),
      })),
      cols: [
        { key: 'dia', label: t('Dia'), p: 0, render: (v) => document.createTextNode(dl(v)) },
        { key: 'carga_fim', label: t('Carga do OLAP'), render: (v) => document.createTextNode(v ? dh(v) : '—') },
        { key: 'em', label: t('Conferido em'), render: (v) => document.createTextNode(v ? dh(v) : '—') },
        { key: 'documentos', label: t('Documentos'), type: 'n' },
        {
          key: 'estado',
          label: t('Resultado'),
          render: (v) =>
            pill(t(v === 'ok' ? 'Conferido' : v === 'dif' ? 'Com diferenças' : 'Por conferir'), v === 'ok' ? 'p-ok' : v === 'dif' ? 'p-serio' : 'p-mut'),
        },
        { key: 'resumo', label: t('Diferenças') },
      ],
    }),
  );
}

/** Grupo de relatórios da página (ver organizarGrupos em navegacao.js). junto = os relatórios lêem-se seguidos. */
function grupo(pg, chave, titulo, junto) {
  const g = el('div', 'rel-grupo');
  g.dataset.chave = chave;
  g.dataset.titulo = titulo;
  if (junto) g.dataset.junto = '1';
  pg.append(g);
  return g;
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
  // quatro grupos de relatórios (separadores primários); o SGSI tem os seus documentos em separadores secundários
  const gResumo = grupo(pg, 'resumo', t('Resumo'), true);
  const gSgsi = grupo(pg, 'sgsi', t('Sistema de gestão (SGSI)'), false);
  const gVigia = grupo(pg, 'vigilancia', t('Vigilância e conferência'), true);
  const gDados = grupo(pg, 'contas-dados', t('Contas e dados'), true);
  gResumo.append(
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
  gResumo.append(acc);
  sgsi(gSgsi);
  vigia(gVigia);
  if ((e.problemas || []).length) {
    const ul = el('ul');
    e.problemas.forEach((x) => ul.append(el('li', null, x)));
    gResumo.append(secBox(t('Problemas na última verificação'), dh(e.inicio), ul));
  }
  const avisos = [];
  if (!CF.cifra) avisos.push(t('Cópias sem cifra: definir COPIAS_CHAVE no .env do servidor.'));
  if (!CF.copia_externa) avisos.push(t('Cópias só no servidor: definir COPIAS_PASTA_EXTERNA (pasta de rede ou disco externo).'));
  if (!CF.registos_externos) avisos.push(t('Registos só no servidor: definir REGISTOS_COPIA_PASTA.'));
  if (avisos.length) {
    const ul = el('ul');
    avisos.forEach((x) => ul.append(el('li', null, x)));
    gResumo.append(secBox(t('Por configurar no servidor'), t('Uma vez, pela Informática; o resto é automático.'), ul));
  }
  ligacoes(gResumo);
  fontesDados(gVigia);
  const contas = [
    ...(con.desligadas || []).map((c) => ({ ...c, estado: 'desligada' })),
    ...(con.a_desligar || []).map((c) => ({ ...c, estado: 'a_desligar' })),
  ];
  if (contas.length)
    gDados.append(
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
  gDados.append(
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
  gDados.append(
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
