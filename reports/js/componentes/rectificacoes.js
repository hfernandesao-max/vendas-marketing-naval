// Rectificações de documentos anteriores (servidor: vendas.php, naval_vendas_substituicoes): nota de crédito que anula
// uma factura de outro dia e factura nova ao mesmo cliente no mesmo dia (por exemplo, com um desconto). Não é venda
// nova nem movimento de dinheiro: fica fora das vendas e das cobranças do período e aparece aqui, com o desconto em
// positivo, para os valores se conciliarem com o Pinguim BO.
import { mkz } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { inR } from '../periodo.js';
import { DB, el } from '../util.js';

/** Substituições do período (uma por nota de crédito), já com os filtros da página. */
export const rectificacoesR = () => (DB.rectificacoes || []).filter((x) => inR(x.data));

/** Aviso com as substituições do período, ou null se não houver nenhuma. */
export function avisoRectificacoes() {
  const R = rectificacoesR();
  if (!R.length) return null;
  const p = el('p', 'note aviso-rect');
  p.setAttribute('role', 'note');
  p.append(
    el('b', null, t('Rectificações de documentos anteriores ({n})', { n: R.length }) + ': '),
    document.createTextNode(
      R.map(
        (x) =>
          t('rota {r} · {nc} substitui uma factura anterior pela {f} · desconto ao cliente {v}', {
            r: x.rota,
            nc: x.substituicao.nota,
            f: x.substituicao.factura,
            v: mkz(x.substituicao.desconto),
          }) +
          ((x.substituicao.acerto || []).length
            ? ' · ' + t('acerto do recebido na factura original: {l}', { l: x.substituicao.acerto.map((a) => t(a.meio) + ' ' + mkz(a.valor)).join(', ') })
            : ''),
      ).join('; ') +
        '. ' +
        t(
          'Sem venda nova nem dinheiro de hoje: ficam fora das vendas e das cobranças deste período; o desconto e o acerto do recebido seguem no ficheiro para o SAP, como no Pinguim BO.',
        ),
    ),
  );
  return p;
}
