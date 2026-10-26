// Período escolhido (presets, datas de início e fim). O portal abre sempre no dia de hoje (prática habitual
// dos painéis de gestão: a informação actual primeiro); a escolha só se mantém durante a sessão do separador.
import { draw } from './app.js';
import { fetchVendas } from './fontes.js';

export const st = { de: null, ate: null, preset: 'hoje' };
export let diasSap = [],
  diasTodos = [];
export const inR = (d) => d >= st.de && d <= st.ate;
// Data de hoje no fuso local (em Luanda, entre as 00:00 e a 01:00 o UTC ainda está no dia anterior)
export const hoje = () => {
  const x = new Date();
  return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
};
export const INICIO_PINGUIM = '2026-10-20'; // arranque do Pinguim BO
export const addD = (d, n) => {
  const x = new Date(d + 'T12:00:00Z');
  x.setUTCDate(x.getUTCDate() + n);
  return x.toISOString().slice(0, 10);
};
export const PRESETS = ['hoje', 'ontem', 'sete', 'mes', 'tudo'];
export function presets() {
  // Períodos calculados sempre a partir de hoje (data local): acompanham a mudança de dia
  const hj = hoje(),
    on = addD(hj, -1);
  return {
    hoje: ['Hoje', hj, hj],
    ontem: ['Ontem', on, on],
    sete: ['7 dias', addD(hj, -6), hj],
    mes: ['Mês', hj.slice(0, 8) + '01', hj],
    tudo: ['Tudo', INICIO_PINGUIM, hj],
  };
}
// O período inclui o dia de hoje (os dados ainda estão a mudar)?
export const periodoAberto = () => !!st.ate && st.ate >= hoje();
export function aplicarPreset(k) {
  if (!PRESETS.includes(k)) k = 'hoje';
  st.preset = k;
  const p = presets()[k];
  st.de = p[1];
  st.ate = p[2];
  return fetchVendas(st.de, st.ate);
}
export function setPreset(k) {
  aplicarPreset(k).then(() => {
    guardar();
    draw();
  });
}
// Guarda o período só nesta sessão do separador (ao abrir o portal de novo, volta ao dia de hoje)
export function guardar() {
  try {
    sessionStorage.setItem('vm-periodo', JSON.stringify(st.preset ? { preset: st.preset } : { de: st.de, ate: st.ate }));
  } catch (e) {}
}
export function carregarFiltro() {
  st.preset = 'hoje';
  st.de = st.ate = null;
  try {
    localStorage.removeItem('vm-filtro'); // memória antiga (entre visitas): já não se usa
    const x = JSON.parse(sessionStorage.getItem('vm-periodo') || 'null');
    if (x && x.preset && PRESETS.includes(x.preset)) {
      st.preset = x.preset;
      st.de = st.ate = null;
    } else if (x && !x.preset && x.de && x.ate) {
      st.preset = null;
      st.de = x.de;
      st.ate = x.ate;
    }
  } catch (e) {}
}

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirDiasSap(valor) {
  diasSap = valor;
}
export function definirDiasTodos(valor) {
  diasTodos = valor;
}
