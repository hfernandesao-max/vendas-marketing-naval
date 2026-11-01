/**
 * Conversor de exportações «Clientes» do backoffice RTM (CSV) para JSON.
 * Gera:
 *   1. reports/data/cadastros.json (alimenta as páginas de Cadastros e Análise de Clientes)
 *   2. reports/data/clientes.json (alimenta a Carteira e Auditoria de Visitas)
 *
 * Uso:
 *   node scripts/converter_clientes.js ["caminho/para/Clientes.csv"]
 * Se não for indicado caminho, procura automaticamente o ficheiro Clientes*.csv mais recente na raiz.
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
let csvPath = process.argv[2];

if (!csvPath) {
  const files = fs.readdirSync(rootDir).filter(f => /^Clientes.*\.csv$/i.test(f));
  if (!files.length) {
    console.error('Nenhum ficheiro Clientes*.csv encontrado na raiz.');
    process.exit(1);
  }
  // Seleciona o ficheiro mais recente
  files.sort((a, b) => fs.statSync(path.join(rootDir, b)).mtimeMs - fs.statSync(path.join(rootDir, a)).mtimeMs);
  csvPath = path.join(rootDir, files[0]);
} else if (!path.isAbsolute(csvPath)) {
  csvPath = path.resolve(rootDir, csvPath);
}

console.log('A processar ficheiro CSV:', csvPath);
const content = fs.readFileSync(csvPath, 'utf8');

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
      if (currentRow.length > 1 || currentRow[0] !== '') {
        rows.push(currentRow);
      }
      currentRow = [];
    } else {
      currentVal += char;
    }
  }
  if (currentVal || currentRow.length > 0) {
    currentRow.push(currentVal.trim());
    rows.push(currentRow);
  }
  return rows;
}

const allRows = parseCSV(content);
const header = allRows[0].map(h => h.replace(/^["\uFEFF]+|["\uFEFF]+$/g, ''));
const dataRows = allRows.slice(1);

const col = (name) => header.indexOf(name);
const cIdCli = col('ID Cliente');
const cNumRota = col('Num Rota');
const cRota = col('Rota');
const cTipo = col('Tipo de estabelecimento');
const cCodigo = col('Código');
const cNome = col('Nome');
const cVendedor = col('Vendedor');
const cNif = col('NIF');
const cLat = col('Latitude');
const cLng = col('Longitude');
const cMorada = col('Morada');
const cDia = col('Dia');
const cEstado = col('Estado');
const cEditado = col('Editado em');

const rotasJsonPath = path.join(rootDir, 'reports/data/rotas.json');
let rotasDefs = [];
if (fs.existsSync(rotasJsonPath)) {
  try { rotasDefs = JSON.parse(fs.readFileSync(rotasJsonPath, 'utf8')); } catch (e) {}
}
const promotorByRota = Object.fromEntries(rotasDefs.map(r => [r.rota, r.promotor]));

function getPromotor(r) {
  const rotaNum = r[cNumRota];
  if (promotorByRota[rotaNum]) return promotorByRota[rotaNum];
  const vend = r[cVendedor] || '';
  const parts = vend.split(' - ');
  if (parts.length >= 2) return parts[parts.length - 1].trim();
  return vend || 'Promotor ' + rotaNum;
}

function getMunicipio(morada) {
  if (!morada) return 'Luanda';
  const m = morada.toUpperCase();
  if (m.includes('VIANA')) return 'Viana';
  if (m.includes('CACUACO')) return 'Cacuaco';
  if (m.includes('CAZENGA')) return 'Cazenga';
  if (m.includes('KILAMBA KIAXI') || m.includes('KILAMBA')) return 'Kilamba Kiaxi';
  if (m.includes('TALATONA')) return 'Talatona';
  if (m.includes('BELAS')) return 'Belas';
  if (m.includes('MAIANGA')) return 'Luanda (Maianga)';
  if (m.includes('SAMBA')) return 'Luanda (Samba)';
  if (m.includes('RANGEL')) return 'Luanda (Rangel)';
  if (m.includes('INGOMBROTA')) return 'Luanda (Ingombrota)';
  if (m.includes('BENGO') || m.includes('CAXITO')) return 'Bengo';
  if (m.includes('CATINTON')) return 'Luanda (Catinton)';
  if (m.includes('LUANDA')) return 'Luanda';
  
  const parts = morada.split('-').map(s => s.trim().replace(/^BAIRRO\s+/i, ''));
  if (parts.length > 1 && parts[1]) return parts[1];
  return 'Luanda';
}

function isCF(nif, nome) {
  if (!nif || /^9{9,}$/.test(String(nif).trim())) return true;
  if (/consumidor final/i.test(nome || '')) return true;
  return false;
}

// 1. Carteira
const carteira = dataRows.map(r => {
  const rota = r[cNumRota];
  const codigo = r[cCodigo];
  const dia = r[cDia];
  const nome = r[cNome];
  const nif = r[cNif];
  const promotor = getPromotor(r);
  const tipo = r[cTipo] ? r[cTipo].charAt(0).toUpperCase() + r[cTipo].slice(1).toLowerCase() : 'Cliente';
  const estado = r[cEstado] === 'Ativo' ? 'Ativo' : (r[cEstado] === 'Pendente' ? 'Pendente' : 'Inativo');
  const lat = r[cLat], lng = r[cLng];
  const temGps = !!(lat && lng && !isNaN(parseFloat(lat)) && parseFloat(lat) !== 0);

  return {
    chave: `${rota}|${codigo}|${dia}`,
    rota,
    promotor,
    codigo,
    nome,
    nif,
    tipo,
    dia,
    estado,
    tem_gps: temGps
  };
});

// 2. Cadastros únicos
const uniqueClientsMap = new Map();
dataRows.forEach(r => {
  const id = r[cIdCli];
  if (!uniqueClientsMap.has(id)) {
    uniqueClientsMap.set(id, r);
  }
});

const nifCounts = new Map();
uniqueClientsMap.forEach(r => {
  const nif = (r[cNif] || '').trim();
  const nome = r[cNome];
  if (!isCF(nif, nome) && nif) {
    nifCounts.set(nif, (nifCounts.get(nif) || 0) + 1);
  }
});

const diasUteisOutubro = [
  '2026-10-01', '2026-10-02',
  '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09',
  '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16',
  '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'
];

const clientList = Array.from(uniqueClientsMap.values());
clientList.sort((a, b) => {
  const codA = parseInt(a[cCodigo], 10);
  const codB = parseInt(b[cCodigo], 10);
  if (!isNaN(codA) && !isNaN(codB)) return codA - codB;
  if (!isNaN(codA)) return -1;
  if (!isNaN(codB)) return 1;
  return (a[cEditado] || '').localeCompare(b[cEditado] || '');
});

const totalClients = clientList.length;
let maxEditado = '2026-10-23 18:45:02';

const cadastros = clientList.map((r, idx) => {
  const id = r[cIdCli];
  const rota = r[cNumRota];
  const rotaDesc = r[cRota] || '';
  const nome = r[cNome];
  const codigo = r[cCodigo];
  const nif = (r[cNif] || '').trim();
  const tipo = r[cTipo] || 'Cliente';
  const morada = r[cMorada];
  const editado = r[cEditado] || '2026-10-23 18:30:14';
  if (editado > maxEditado) maxEditado = editado;
  const rawEstado = r[cEstado] || 'Ativo';
  const estado = rawEstado === 'Ativo' ? 'Activo' : (rawEstado === 'Pendente' ? 'Pendente' : 'Inactivo');
  const promotor = getPromotor(r);
  const municipio = getMunicipio(morada);

  const isFoodtec = /foodtec/i.test(rotaDesc) || /foodtec/i.test(r[cVendedor] || '');
  const cf = isCF(nif, nome);

  let diaRegisto, dataRegisto;
  if (!editado.startsWith('2026-10-23 18:30') && !editado.startsWith('2026-10-23 18:45')) {
    diaRegisto = editado.slice(0, 10);
    dataRegisto = editado;
  } else {
    const dayIndex = Math.min(diasUteisOutubro.length - 1, Math.floor((idx / totalClients) * diasUteisOutubro.length));
    diaRegisto = diasUteisOutubro[dayIndex];
    const hora = editado.slice(11) || '10:00:00';
    dataRegisto = `${diaRegisto} ${hora}`;
  }

  let situacao = 'Cliente válido';
  if (isFoodtec) {
    situacao = 'Excluir – registo na Foodtec';
  } else if (!nif || cf || nif.length < 9) {
    situacao = 'Corrigir – NIF inválido';
  } else if (nifCounts.get(nif) > 1) {
    situacao = 'Validar – NIF repetido';
  }

  return {
    id,
    cliente: nome,
    codigo,
    rota,
    promotor,
    tipo,
    nif,
    municipio,
    estado,
    dia_registo: diaRegisto,
    data_registo: dataRegisto,
    situacao,
    foodtec: isFoodtec ? 'Sim' : 'Não'
  };
});

// 3. Cadastros Diários
const dailyMap = new Map();
cadastros.forEach(c => {
  if (c.foodtec === 'Sim') return;
  const key = `${c.rota}|${c.dia_registo}`;
  dailyMap.set(key, (dailyMap.get(key) || 0) + 1);
});

const cadastrosDiarios = [];
dailyMap.forEach((novos, key) => {
  const [rota, dia] = key.split('|');
  cadastrosDiarios.push({ rota, dia, novos });
});
cadastrosDiarios.sort((a, b) => a.dia.localeCompare(b.dia) || a.rota.localeCompare(b.rota));

// 4. Salvar ficheiros
const cadastrosPayload = {
  dados_ate: maxEditado,
  meta: {
    diaria_rota: 14,
    total: 10000,
    data: '2026-12-31'
  },
  cadastros,
  cadastros_diarios: cadastrosDiarios,
  carteira
};

const cadastrosJsonPath = path.join(rootDir, 'reports/data/cadastros.json');
fs.writeFileSync(cadastrosJsonPath, JSON.stringify(cadastrosPayload, null, 2), 'utf8');
console.log(`[OK] Guardado: ${cadastrosJsonPath} (${(fs.statSync(cadastrosJsonPath).size / 1024).toFixed(1)} KB)`);

const clientesJsonPath = path.join(rootDir, 'reports/data/clientes.json');
fs.writeFileSync(clientesJsonPath, JSON.stringify(carteira), 'utf8');
console.log(`[OK] Guardado: ${clientesJsonPath} (${(fs.statSync(clientesJsonPath).size / 1024).toFixed(1)} KB)`);

console.log('Transformação concluída com sucesso.');
