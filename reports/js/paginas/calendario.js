// Administração › Calendário de trabalho: horário das vendas, do fecho e da carga; feriados nacionais; folgas da
// empresa (pontes) e dias de trabalho excepcionais (trabalhar num feriado), definidos aqui com motivo e registo.
// É a fonte única do portal: conciliação das carrinhas, pendentes, resumo diário, previsão, plano de cargas e avisos
// (na véspera de um feriado ou folga, às 17:30, vendedores e Logística recebem o aviso). Servidor: v1/calendario.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let C = null;
let aLer = false;
const TIPOS = { feriado: ['Feriado', 'p-warn'], folga: ['Folga da empresa', 'p-serio'], trabalho: ['Dia de trabalho excepcional', 'p-ok'] };
const DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
const diaSemana = (d) => DIAS[new Date(d + 'T12:00:00Z').getUTCDay()];

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('calendario').then((d) => {
    aLer = false;
    C = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'calendario') draw();
  });
}

function definir(dia0, tipo0) {
  janela(t('Definir um dia'), t('Folga da empresa (não se trabalha) ou dia de trabalho excepcional (trabalha-se num feriado).'), (b, ft, fechar) => {
    const d = entrada('date', dia0 || '', {});
    const tp = el('select', 'inp');
    [
      ['folga', t('Folga da empresa')],
      ['trabalho', t('Dia de trabalho excepcional')],
      ['', t('Voltar ao normal')],
    ].forEach(([v, l]) => tp.append(new Option(l, v)));
    if (tipo0 != null) tp.value = tipo0;
    const m = entrada('text', '', { maxLength: 120, placeholder: t('Ex.: ponte de Natal') });
    const erro = msgErro();
    b.append(campo(t('Dia'), d), campo(t('Tipo'), tp), campo(t('Motivo'), m), erro);
    const ok = botao(t('Gravar'), null, () => {
      ok.disabled = true;
      postJson('calendario', { accao: 'definir', dia: d.value, tipo: tp.value, motivo: m.value }).then((x) => {
        ok.disabled = false;
        if (!x || !x.ok) {
          erro.hidden = false;
          erro.textContent = tv((x && x.erro) || 'Não foi possível gravar.');
          return;
        }
        C = x;
        fechar();
        toast(t('Calendário actualizado: todo o portal passa a usá-lo.'), 4000);
        draw();
      });
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
  });
}

R.calendario = (pg) => {
  if (!C) {
    ler();
    pg.append(el('p', 'note', t('A carregar…')));
    return;
  }
  if (C.falhou) {
    pg.append(el('p', 'erro', C.falhou));
    return;
  }
  const h = C.horario;
  const prox = C.excepcoes.find((x) => x.tipo !== 'trabalho');
  pg.append(
    tiles([
      {
        l: t('Hoje'),
        v: C.hoje.tipo === 'folga' ? t('Dia de descanso') : C.hoje.tipo === 'meio' ? t('Meio dia') : t('Dia completo'),
        s: C.hoje.motivo ? t(C.hoje.motivo) : '',
      },
      { l: t('Segunda a sexta'), v: h.semana, s: t('vendas') },
      { l: t('Sábado'), v: h.sabado, s: t('vendas (meio dia)') },
      { l: t('Fecho e carga'), v: h.fecho + ' · ' + h.carga, s: t('contas, contagem e requisição · carga') },
      { l: t('Próximo dia de descanso'), v: prox ? dl(prox.dia) : '—', s: prox ? prox.feriado || prox.motivo : t('domingos') },
    ]),
  );
  if (C.gere) {
    const acc = el('div', 'cal-acc');
    acc.append(botao(t('Definir folga ou dia de trabalho'), null, () => definir()));
    pg.append(acc);
  }
  pg.append(
    tabela({
      id: 'calendario-excepcoes',
      titulo: t('Próximos 120 dias fora do normal'),
      nota: t(
        'Os domingos são sempre dia de descanso. Os feriados nacionais de Angola entram sozinhos; as folgas e os dias de trabalho excepcionais definem-se aqui.',
      ),
      rows: C.excepcoes,
      sort: 'dia',
      dir: 1,
      cols: [
        { key: 'dia', label: t('Dia'), type: 'date', p: 0 },
        { key: 'semana', label: t('Dia da semana'), get: (r) => t(diaSemana(r.dia)) },
        {
          key: 'tipo',
          label: t('Tipo'),
          render: (v) => el('span', 'pill ' + (TIPOS[v] || ['', 'p-mut'])[1], t((TIPOS[v] || [v])[0])),
          xl: (r) => t((TIPOS[r.tipo] || [r.tipo])[0]),
        },
        { key: 'motivo', label: t('Motivo'), wrap: true, get: (r) => r.feriado || tv(r.motivo) },
        {
          key: 'por',
          label: t('Definido por'),
          get: (r) => (r.por ? r.por + (r.em ? ' · ' + dl(r.em.slice(0, 10)) : '') : r.portal ? '' : t('calendário oficial')),
        },
        ...(C.gere
          ? [
              {
                key: 'acc',
                label: '',
                render: (v, r) => botao(t('Mudar'), 'ghost sm', () => definir(r.dia, r.tipo === 'feriado' ? 'trabalho' : '')),
                xl: () => '',
              },
            ]
          : []),
      ],
    }),
  );
};
