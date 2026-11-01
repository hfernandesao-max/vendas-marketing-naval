/**
 * Conversor de exportações «Stock de artigos» do Pinguim BO (CSV) para JSON.
 * Gera:
 *   reports/data/stock.json (alimenta as páginas «Stock por promotor / carrinha» e «Stock por armazém»)
 *
 * Uso:
 *   node scripts/converter_stock.js
 * Procura automaticamente todos os ficheiros «Stock de artigos*.csv» na raiz organizados por data.
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');

// 1. Carregar definições auxiliares (rotas e cargas para enriquecer com promotor, carrinha e motorista)
const rotasJsonPath = path.join(rootDir, 'reports/data/rotas.json');
let rotasDefs = [];
if (fs.existsSync(rotasJsonPath)) {
  try { rotasDefs = JSON.parse(fs.readFileSync(rotasJsonPath, 'utf8')); } catch (e) {}
}
const promotorByRota = Object.fromEntries(rotasDefs.map(r => [r.rota, r.promotor]));

const cargasJsonPath = path.join(rootDir, 'reports/data/cargas.json');
let cargasDefs = [];
if (fs.existsSync(cargasJsonPath)) {
  try { cargasDefs = JSON.parse(fs.readFileSync(cargasJsonPath, 'utf8')); } catch (e) {}
}

// Mapa de última carrinha/motorista por rota
const infoCarrinhaByRota = {};
cargasDefs.sort((a, b) => (a.data || '').localeCompare(b.data || '')).forEach(c => {
  if (c.rota) {
    infoCarrinhaByRota[c.rota] = {
      matricula: c.matricula || '',
      viatura: c.viatura || '',
      motorista: c.motorista || '',
      transportadora: c.transportadora || ''
    };
  }
});

// Mapa de carrinha específica por dia e rota
const carrinhaByDataRota = {};
cargasDefs.forEach(c => {
  if (c.data && c.rota) {
    carrinhaByDataRota[`${c.data}|${c.rota}`] = {
      matricula: c.matricula || '',
      viatura: c.viatura || '',
      motorista: c.motorista || '',
      transportadora: c.transportadora || ''
    };
  }
});

function parseCSV(text) {
  const rows = [];
  let currentRow = [];
  let currentVal = '';
  let insideQuotes = false;
  
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    const nextChar = text[i + 1];
    
    if (char === '"') {
      if (insideQuotes && nextChar === '"') {
        currentVal += '"';
        i++;
      } else {
        insideQuotes = !insideQuotes;
      }
    } else if (char === ';' && !insideQuotes) {
      currentRow.push(currentVal.trim());
      currentVal = '';
    } else if ((char === '\r' || char === '\n') && !insideQuotes) {
      if (char === '\r' && nextChar === '\n') {
        i++;
      }
      currentRow.push(currentVal.trim());
      currentVal = '';
      if (currentRow.length > 1 || (currentRow.length === 1 && currentRow[0] !== '')) {
        rows.push(currentRow);
      }
      currentRow = [];
    } else {
      currentVal += char;
    }
  }
  if (currentVal || currentRow.length > 0) {
    currentRow.push(currentVal.trim());
    if (currentRow.length > 1 || (currentRow.length === 1 && currentRow[0] !== '')) {
      rows.push(currentRow);
    }
  }
  return rows;
}

function classificarCategoria(nomeArtigo) {
  const txt = (nomeArtigo || '').toUpperCase();
  if (txt.includes('FRALDA')) return 'Fraldas';
  if (txt.includes('SABONETE')) return 'Sabonetes';
  if (txt.includes('SABÃO') || txt.includes('SABAO') || txt.includes('SOAP')) return 'Sabão';
  return 'Outros';
}

function identificarCentro(nomeEntidade) {
  const n = (nomeEntidade || '').toUpperCase();
  if (n.includes('HIGITEC')) return 'HIGITEC';
  if (n.includes('FOODTEC') || n.includes('REFITEC')) return 'FOODTEC';
  if (n.includes('GRUPO NAVAL') || n.includes('FORMAÇÃO') || n.includes('FORMACAO')) return 'Grupo Naval';
  return 'Geral';
}

// 2. Procurar ficheiros de stock
const files = fs.readdirSync(rootDir).filter(f => /^Stock de artigos\d+\.csv$/i.test(f));
if (!files.length) {
  console.error('Nenhum ficheiro Stock de artigos*.csv encontrado.');
  process.exit(1);
}

// Ordenar por data a partir do nome (DDMMYYYY)
const filesWithDate = files.map(f => {
  const m = f.match(/Stock de artigos(\d{2})(\d{2})(\d{4})\.csv/i);
  let isoDate = '';
  if (m) {
    isoDate = `${m[3]}-${m[2]}-${m[1]}`;
  } else {
    const stat = fs.statSync(path.join(rootDir, f));
    isoDate = new Date(stat.mtimeMs).toISOString().slice(0, 10);
  }
  return { file: f, isoDate };
});

filesWithDate.sort((a, b) => a.isoDate.localeCompare(b.isoDate));

console.log(`Encontrados ${filesWithDate.length} ficheiros de stock:`, filesWithDate.map(x => `${x.file} (${x.isoDate})`).join(', '));

const stockPorCarrinha = [];
const stockPorArmazem = [];
const linhasCarrinhas = [];
const linhasArmazens = [];
const datasDisponiveis = [];

filesWithDate.forEach(({ file, isoDate }) => {
  datasDisponiveis.push(isoDate);
  const filePath = path.join(rootDir, file);
  const content = fs.readFileSync(filePath, 'utf8');
  const rows = parseCSV(content);
  if (rows.length < 2) return;

  const header = rows[0].map(h => h.replace(/^["\uFEFF]+|["\uFEFF]+$/g, ''));
  const col = (name) => header.indexOf(name);

  const cCodArt = col('Cód. Artigo');
  const cArtigo = col('Artigo');
  const cTipoProd = col('TIPO DE PRODUTO');
  const cFamilia = col('Família');
  const cCodEnt = col('Cód. Entidade');
  const cEntidade = col('Entidade');
  const cQnt = col('Qnt.');
  const cUnidade = col('Unidade');
  const cCusto = col('Custo');
  const cTotal = col('Total');

  // Mapas temporários para agregação do dia
  const carrinhasDia = new Map();
  const armazensDia = new Map();

  for (let i = 1; i < rows.length; i++) {
    const r = rows[i];
    if (r.length < 15) continue;

    const codEnt = r[cCodEnt] || '';
    const nomeEnt = r[cEntidade] || '';
    const codArt = r[cCodArt] || '';
    const nomeArt = r[cArtigo] || '';
    const tipoProd = r[cTipoProd] || '';
    const familia = r[cFamilia] || '';
    const unidade = r[cUnidade] || 'Unidade';
    const categoria = classificarCategoria(nomeArt);

    const rawQty = (r[cQnt] || '0').replace(',', '.');
    const quantidade = parseFloat(rawQty) || 0;
    const custo = parseFloat((r[cCusto] || '0').replace(',', '.')) || 0;
    const total = parseFloat((r[cTotal] || '0').replace(',', '.')) || 0;

    // É carrinha de promotor? (Ex: 10001001 a 10001011 ou "Armazém 100X - FHR PIV")
    const isCarrinha = /^100010\d{2}$/.test(codEnt) || /^Armazém 10\d{2}/i.test(nomeEnt);

    if (isCarrinha) {
      let rota = '';
      if (/^100010\d{2}$/.test(codEnt)) {
        rota = codEnt.slice(-4);
      } else {
        const m = nomeEnt.match(/10\d{2}/);
        rota = m ? m[0] : codEnt;
      }

      if (!carrinhasDia.has(rota)) {
        const promotor = promotorByRota[rota] || `Promotor ${rota}`;
        const carrinhaInfo = carrinhaByDataRota[`${isoDate}|${rota}`] || infoCarrinhaByRota[rota] || {
          matricula: '—',
          viatura: '—',
          motorista: '—',
          transportadora: '—'
        };

        carrinhasDia.set(rota, {
          chave: `${isoDate}|${rota}`,
          data: isoDate,
          rota,
          promotor,
          matricula: carrinhaInfo.matricula,
          viatura: carrinhaInfo.viatura,
          motorista: carrinhaInfo.motorista,
          transportadora: carrinhaInfo.transportadora,
          total_caixas: 0,
          caixas_positivas: 0,
          caixas_fraldas: 0,
          caixas_sabao: 0,
          caixas_sabonete: 0,
          caixas_outros: 0,
          n_artigos: 0,
          artigos_com_stock: 0,
          artigos: []
        });
      }

      const cObj = carrinhasDia.get(rota);
      cObj.total_caixas += quantidade;
      if (quantidade > 0) {
        cObj.caixas_positivas += quantidade;
      }
      if (categoria === 'Fraldas') cObj.caixas_fraldas += quantidade;
      else if (categoria === 'Sabão') cObj.caixas_sabao += quantidade;
      else if (categoria === 'Sabonetes') cObj.caixas_sabonete += quantidade;
      else cObj.caixas_outros += quantidade;

      cObj.n_artigos++;
      if (quantidade !== 0) cObj.artigos_com_stock++;

      const itemObj = {
        codigo: codArt,
        artigo: nomeArt,
        categoria,
        tipo: tipoProd,
        familia,
        quantidade,
        unidade,
        custo,
        total
      };
      cObj.artigos.push(itemObj);

      if (quantidade !== 0) {
        linhasCarrinhas.push({
          chave: `${isoDate}|${rota}|${codArt}`,
          data: isoDate,
          rota,
          promotor: cObj.promotor,
          matricula: cObj.matricula,
          viatura: cObj.viatura,
          codigo: codArt,
          artigo: nomeArt,
          categoria,
          familia,
          quantidade,
          unidade,
          custo,
          total
        });
      }
    } else {
      // Armazém Físico / Central
      const codArmazem = codEnt || nomeEnt;
      if (!armazensDia.has(codArmazem)) {
        armazensDia.set(codArmazem, {
          chave: `${isoDate}|${codArmazem}`,
          data: isoDate,
          cod_armazem: codArmazem,
          armazem: nomeEnt,
          centro: identificarCentro(nomeEnt),
          total_quantidade: 0,
          total_valor: 0,
          n_artigos: 0,
          artigos_com_stock: 0,
          artigos: []
        });
      }

      const aObj = armazensDia.get(codArmazem);
      aObj.total_quantidade += quantidade;
      aObj.total_valor += total;
      aObj.n_artigos++;
      if (quantidade !== 0) aObj.artigos_com_stock++;

      aObj.artigos.push({
        codigo: codArt,
        artigo: nomeArt,
        categoria,
        tipo: tipoProd,
        familia,
        quantidade,
        unidade,
        custo,
        total
      });

      if (quantidade !== 0) {
        linhasArmazens.push({
          chave: `${isoDate}|${codArmazem}|${codArt}`,
          data: isoDate,
          cod_armazem: codArmazem,
          armazem: nomeEnt,
          centro: aObj.centro,
          codigo: codArt,
          artigo: nomeArt,
          categoria,
          familia,
          quantidade,
          unidade,
          custo,
          total
        });
      }
    }
  }

  carrinhasDia.forEach(c => stockPorCarrinha.push(c));
  armazensDia.forEach(a => stockPorArmazem.push(a));
});

// Ordenar
stockPorCarrinha.sort((a, b) => b.data.localeCompare(a.data) || a.rota.localeCompare(b.rota));
stockPorArmazem.sort((a, b) => b.data.localeCompare(a.data) || a.armazem.localeCompare(b.armazem));

const ultimaData = datasDisponiveis[datasDisponiveis.length - 1];

const stockPayload = {
  dados_ate: `${ultimaData} 18:00:00`,
  datas_disponiveis: datasDisponiveis,
  ultima_data: ultimaData,
  totais: {
    carrinhas_registos: stockPorCarrinha.length,
    armazens_registos: stockPorArmazem.length,
    linhas_carrinhas: linhasCarrinhas.length,
    linhas_armazens: linhasArmazens.length
  },
  carrinhas: stockPorCarrinha,
  armazens: stockPorArmazem,
  linhas_carrinhas: linhasCarrinhas,
  linhas_armazens: linhasArmazens
};

// Guardar stock.json
const stockJsonPath = path.join(rootDir, 'reports/data/stock.json');
fs.writeFileSync(stockJsonPath, JSON.stringify(stockPayload, null, 2), 'utf8');
const sizeKb = (fs.statSync(stockJsonPath).size / 1024).toFixed(1);
console.log(`[OK] Guardado: ${stockJsonPath} (${sizeKb} KB)`);
console.log(`Transformação de stock concluída com sucesso para as datas: ${datasDisponiveis.join(', ')}.`);
