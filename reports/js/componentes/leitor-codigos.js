// Leitura de códigos pela câmara do telemóvel (QR das PO, códigos de barras dos artigos): o leitor do próprio
// navegador (BarcodeDetector, Android/Chrome) ou, onde não existe (iPhone/Safari), a biblioteca ZXing incluída no
// portal (reports/vendor/zxing.min.js, MIT), carregada só quando é precisa. Tudo no telemóvel: nenhuma imagem sai.
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

const FORMATOS = {
  qr: ['qr_code'],
  barras: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'],
};
let zx = null;
/** Carrega a ZXing uma vez (script do próprio portal). */
function carregarZXing(base) {
  if (window.ZXing) return Promise.resolve(window.ZXing);
  if (zx) return zx;
  zx = new Promise((ok, falha) => {
    const s = document.createElement('script');
    s.src = base + 'vendor/zxing.min.js';
    s.onload = () => (window.ZXing ? ok(window.ZXing) : falha(new Error('ZXing')));
    s.onerror = () => {
      zx = null;
      falha(new Error('ZXing'));
    };
    document.head.append(s);
  });
  return zx;
}

/** Leitor de um fotograma do vídeo: devolve o texto lido ou null (BarcodeDetector ou ZXing). */
async function criarLeitor(tipo, base) {
  if ('BarcodeDetector' in window) {
    try {
      const sup = window.BarcodeDetector.getSupportedFormats ? await window.BarcodeDetector.getSupportedFormats() : FORMATOS[tipo];
      const formats = FORMATOS[tipo].filter((f) => sup.includes(f));
      if (formats.length) {
        const det = new window.BarcodeDetector({ formats });
        return async (v) => {
          const r = await det.detect(v).catch(() => []);
          return r.length ? String(r[0].rawValue) : null;
        };
      }
    } catch (e) {
      /* segue para a ZXing */
    }
  }
  const Z = await carregarZXing(base);
  const F = Z.BarcodeFormat;
  const hints = new Map();
  hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, tipo === 'qr' ? [F.QR_CODE] : [F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_128, F.CODE_39, F.ITF, F.QR_CODE]);
  hints.set(Z.DecodeHintType.TRY_HARDER, true);
  const leitor = new Z.MultiFormatReader();
  leitor.setHints(hints);
  const cv = document.createElement('canvas');
  const cx = cv.getContext('2d', { willReadFrequently: true });
  return async (v) => {
    if (!v.videoWidth) return null;
    // a faixa central do vídeo (onde se aponta), reduzida: mais rápido e mais certeiro
    const w = Math.min(960, v.videoWidth);
    const h = Math.round((w * v.videoHeight) / v.videoWidth);
    cv.width = w;
    cv.height = h;
    cx.drawImage(v, 0, 0, w, h);
    try {
      const src = new Z.HTMLCanvasElementLuminanceSource(cv);
      const r = leitor.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(src)));
      return r ? String(r.getText()) : null;
    } catch (e) {
      return null; // nada lido neste fotograma
    }
  };
}

/**
 * Abre a câmara e lê até `aceitar(texto)` devolver verdadeiro (ou a pessoa cancelar). opts: {tipo: 'qr' | 'barras',
 * titulo, aceitar(texto) → bool, continuo (não fecha a cada leitura: chama aceitar e continua), base ('../reports/')}.
 * Devolve uma promessa com o texto lido (ou null se cancelou). Erro: sem câmara ou sem permissão.
 */
export function lerCodigo(opts) {
  const tipo = opts.tipo || 'barras';
  const base = opts.base || '../reports/';
  return new Promise((resolver, rejeitar) => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      rejeitar(new Error(t('Este telemóvel não dá acesso à câmara nesta app.')));
      return;
    }
    const d = el('dialog', 'ag-folha op-qr');
    const v = el('video', 'op-video');
    v.setAttribute('playsinline', '');
    v.muted = true;
    const mira = el('div', 'op-mira');
    mira.setAttribute('aria-hidden', 'true');
    const quadro = el('div', 'op-quadro');
    quadro.append(v, mira);
    const estado = el('p', 'ag-nota', opts.titulo || t('Aponte a câmara para o código.'));
    estado.setAttribute('role', 'status');
    let parar = false;
    let fluxo = null;
    let ultimo = '';
    const acabar = (texto) => {
      parar = true;
      if (fluxo) fluxo.getTracks().forEach((x) => x.stop());
      if (d.open) d.close();
      d.remove();
      resolver(texto || null);
    };
    const fechar = el('button', 'ag-botao ag-sec-b', opts.continuo ? t('Terminar a leitura') : t('Cancelar'));
    fechar.type = 'button';
    fechar.addEventListener('click', () => acabar(null));
    d.addEventListener('cancel', () => acabar(null));
    d.append(estado, quadro, fechar);
    document.body.append(d);
    d.showModal();
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } })
      .then(async (s) => {
        fluxo = s;
        v.srcObject = s;
        await v.play();
        const ler = await criarLeitor(tipo, base);
        const passo = async () => {
          if (parar) return;
          const txt = await ler(v);
          if (txt && txt !== ultimo) {
            ultimo = txt;
            const ok = opts.aceitar ? opts.aceitar(txt, estado) : true;
            if (ok && !opts.continuo) {
              if (navigator.vibrate) navigator.vibrate(60);
              acabar(txt);
              return;
            }
            if (ok && navigator.vibrate) navigator.vibrate(40);
            // contínuo: o mesmo código só volta a contar depois de 1,5 s (ler a mesma caixa duas vezes de seguida)
            setTimeout(() => (ultimo = ''), 1500);
          }
          setTimeout(passo, 200);
        };
        passo();
      })
      .catch(() => {
        parar = true;
        if (d.open) d.close();
        d.remove();
        rejeitar(new Error(t('Sem acesso à câmara: autorize a câmara para esta app nas definições do telemóvel.')));
      });
  });
}
