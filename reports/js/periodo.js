// Período escolhido (presets, datas de início e fim) e respectiva memória.
import { draw } from './app.js';
import { fetchVendas } from './fontes.js';

export const st = { de: null, ate: null, preset: 'mes' };
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
export function presets() {
  // Períodos calculados sempre a partir de hoje (data local), sem depender dos dias já carregados
  const hj = hoje(),
    ult = st.preset === 'ultimo' && st.de === st.ate ? st.de : hj;
  return { ultimo: ['Último dia', ult, ult], sete: ['7 dias', addD(hj, -6), hj], mes: ['Mês', hj.slice(0, 8) + '01', hj], tudo: ['Tudo', INICIO_PINGUIM, hj] };
}
// Aplica um período: "Último dia" procura o dia mais recente com vendas nos últimos 7 dias
export function aplicarPreset(k) {
  st.preset = k;
  if (k === 'ultimo') {
    const hj = hoje();
    return fetchVendas(addD(hj, -6), hj).then(() => {
      const d = diasSap[diasSap.length - 1] || hj;
      st.de = d;
      st.ate = d;
    });
  }
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
// Guarda o tipo de período; as datas só ficam guardadas quando são escolhidas à mão
export function guardar() {
  try {
    localStorage.setItem('vm-filtro', JSON.stringify(st.preset ? { preset: st.preset } : { de: st.de, ate: st.ate }));
  } catch (e) {}
}
export function carregarFiltro() {
  try {
    const x = JSON.parse(localStorage.getItem('vm-filtro') || 'null');
    if (x && x.preset && ['ultimo', 'sete', 'mes', 'tudo'].includes(x.preset)) {
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
