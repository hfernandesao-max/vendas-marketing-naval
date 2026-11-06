// «App no telemóvel» (menu da conta): código QR pessoal para instalar a app no telemóvel, equipamento activo e
// desactivação. A app só se instala por este código (uso único, 10 minutos): fica registado quem a instalou e em que
// equipamento; activar noutro telemóvel desactiva o anterior. Servidor: nucleo/api/v1/app-telemovel.php.
import { apiJson } from './api.js';
import { botao, janela, msgErro } from './componentes/janelas.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { SESSAO } from './perfil.js';
import { carregarScript, postJson } from './servidor.js';
import { el, toast } from './util.js';

const quando = (s) => (s ? dl(s) + ' ' + String(s).slice(11, 16) : '—');
const equipamento = (x) => x.modelo + ' · ' + x.so;

/** Cor do item do menu da conta: vermelho com a app activa, verde sem ela. */
function marcarMenu(tem) {
  if (SESSAO) SESSAO.app_instalada = tem;
  const b = document.getElementById('conta-app');
  if (b) b.classList.toggle('instalada', tem);
}

export function abrirAppTelemovel() {
  apiJson('app-telemovel').then((d) => {
    if (!d || !d.ok) return toast(t((d && d.erro) || 'Não foi possível ler os dados.'), 5000);
    marcarMenu(!!d.meu);
    janela(
      t('App no telemóvel'),
      t('A app «{a}» instala-se só por este código QR pessoal: fica registado quem a instalou e em que equipamento.', { a: d.nome }),
      (b, ft, fechar) => {
        const erro = msgErro();
        const estado = el('div', 'app-estado');
        if (d.meu) {
          estado.append(
            el('b', null, t('Equipamento activo')),
            el('span', null, equipamento(d.meu) + ' · ' + t('activado em {d}', { d: quando(d.meu.activado) })),
            el(
              'span',
              null,
              t('Última utilização: {d}', { d: quando(d.meu.ultimo_uso) }) + ' · ' + (d.meu.biometria ? t('com biometria') : t('sem biometria')),
            ),
          );
        } else estado.append(el('span', null, t('Ainda não tem a app activada em nenhum telemóvel.')));
        const zona = el('div', 'app-qr');
        const passos = el('ol', 'app-passos');
        [
          'Abra a câmara do telemóvel e aponte para o código.',
          'Toque na ligação que aparece e siga os passos (iPhone: Partilhar › Adicionar ao ecrã principal; Android: Instalar).',
          'Abra a app pelo ícone: fica activada e pede o Face ID ou a impressão digital.',
        ].forEach((x) => passos.append(el('li', null, t(x))));
        const aviso = el('p', 'app-nota', t('Cada pessoa tem um equipamento activo: activar a app noutro telemóvel desactiva o anterior.'));
        b.append(estado, zona, passos, aviso, erro);
        const gerar = botao(t(d.meu ? 'Gerar código para outro telemóvel' : 'Gerar código QR'), '', () => {
          gerar.disabled = true;
          Promise.all([postJson('app-telemovel', { accao: 'codigo' }), carregarScript('qrcode.js', 'qrcode')]).then(([r]) => {
            gerar.disabled = false;
            if (!r || !r.ok) {
              erro.textContent = t((r && r.erro) || 'Sem resposta do servidor.');
              erro.hidden = false;
              return;
            }
            const q = window.qrcode(0, 'M');
            q.addData(r.url);
            q.make();
            zona.replaceChildren();
            const img = el('div', 'app-qr-img');
            img.innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true }); // nosemgrep: insecure-innerhtml -- SVG gerado pela biblioteca de QR a partir do endereço
            img.setAttribute('role', 'img');
            img.setAttribute('aria-label', t('Código QR para instalar a app'));
            const prazo = el('p', 'app-prazo');
            const ate = Date.parse(r.ate.replace(' ', 'T'));
            const tick = () => {
              const s = Math.max(0, Math.round((ate - Date.now()) / 1000));
              prazo.textContent = s
                ? t('Válido durante {m}:{s} · uso único', { m: Math.floor(s / 60), s: String(s % 60).padStart(2, '0') })
                : t('Código expirado: gere um novo.');
              if (!s) {
                img.classList.add('expirado');
                clearInterval(iv);
              }
            };
            const iv = setInterval(tick, 1000);
            tick();
            zona.append(img, prazo);
            gerar.textContent = t('Gerar um código novo');
          });
        });
        ft.append(botao(t('Fechar'), 'ghost', fechar));
        if (d.meu)
          ft.append(
            botao(t('Desactivar este equipamento'), 'ghost perigo', () => {
              postJson('app-telemovel', { accao: 'desactivar', id: d.meu.id }).then((r) => {
                if (r && r.ok) {
                  toast(t('Equipamento desactivado.'), 4000);
                  marcarMenu(false);
                  fechar();
                } else {
                  erro.textContent = t((r && r.erro) || 'Sem resposta do servidor.');
                  erro.hidden = false;
                }
              });
            }),
          );
        ft.append(gerar);
      },
    );
  });
}
