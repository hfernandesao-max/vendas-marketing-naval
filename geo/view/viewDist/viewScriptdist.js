let selectedCategory = null;
let jsonData = [];
let coords = [];
let newMarkers = [];
let categoryValue = null;
let newSequenceCreated = null;
let optimizedRoute = false;
let drawing = [];
let drawingManager;
let polygon = false;
let count = 0;
let map = null;
let allPolygons = [];
let polygonLabels = new Map();
let polygonLabelMarkers = new Map();

// Cache para armazenar cores por distribuidor
const distribuidorCores = new Map();
const COR_DISTRIBUIDOR = '#FF0000'; // Vermelho para estabelecimentos do tipo DISTRIBUIDOR

// Mapeamento de grupos por distribuidor para alteração de cores
let distribuidorMarkers = new Map(); // Armazena todos os marcadores por distribuidor

function gerarCorHex() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0');
}

// Função para obter ou gerar cor para um distribuidor
function getCorParaDistribuidor(distribuidor, tipoEstabelecimento = '') {
  // Se o tipo de estabelecimento for DISTRIBUIDOR, retorna vermelho
  if (tipoEstabelecimento && tipoEstabelecimento.toUpperCase().trim() === 'DISTRIBUIDOR') {
    return COR_DISTRIBUIDOR;
  }

  // Se não tem distribuidor, retorna cor aleatória
  if (!distribuidor || distribuidor === 'undefined' || distribuidor === 'null') {
    return gerarCorHex();
  }

  // Normalizar o nome do distribuidor
  const distribuidorNormalizado = String(distribuidor).trim();

  // Gerar ou recuperar cor para o distribuidor
  if (!distribuidorCores.has(distribuidorNormalizado)) {
    distribuidorCores.set(distribuidorNormalizado, gerarCorHex());
  }

  return distribuidorCores.get(distribuidorNormalizado);
}

// Função para alterar cor de um grupo de distribuidor
function alterarCorGrupo(distribuidor, novaCor) {
  if (!distribuidor || !novaCor) return;

  const distribuidorNormalizado = String(distribuidor).trim();

  // Atualizar a cor no cache
  distribuidorCores.set(distribuidorNormalizado, novaCor);

  // Atualizar todos os marcadores deste distribuidor
  if (distribuidorMarkers.has(distribuidorNormalizado)) {
    const marcadores = distribuidorMarkers.get(distribuidorNormalizado);

    marcadores.forEach(marker => {
      if (!marker.IS_DISTRIBUIDOR) {
        // Atualizar ícone do marcador
        marker.setIcon({
          path: google.maps.SymbolPath.CIRCLE,
          scale: 10,
          fillColor: novaCor,
          fillOpacity: 1,
          strokeWeight: 1,
          strokeColor: '#000000'
        });

        // Atualizar cor armazenada no marcador
        marker.COR = novaCor;
      }
    });
  }

  // Atualizar legenda
  if (map) {
    criarLegenda(map, distribuidorCores);
  }

  console.log(`Cor do grupo ${distribuidor} alterada para ${novaCor}`);
}

// Função para verificar se é estabelecimento do tipo DISTRIBUIDOR
function isDistribuidor(tipoEstabelecimento = '') {
  if (!tipoEstabelecimento || typeof tipoEstabelecimento !== 'string') {
    return false;
  }
  return tipoEstabelecimento.toUpperCase().trim() === 'DISTRIBUIDOR';
}

// Função para salvar zonas no cache (localStorage)
function salvarPolygonsNoCache() {
  const polygonData = allPolygons.map(polygon => {
    const paths = polygon.getPath().getArray().map(latLng => ({
      lat: latLng.lat(),
      lng: latLng.lng()
    }));

    const polygonId = polygon._id || Date.now() + Math.random();
    const label = polygonLabels.get(polygonId) || '';

    return {
      id: polygonId,
      paths: paths,
      fillColor: polygon.fillColor,
      strokeColor: polygon.strokeColor,
      strokeWeight: polygon.strokeWeight,
      label: label
    };
  });

  localStorage.setItem('zonasCache', JSON.stringify(polygonData));
  console.log(`Guardadas ${polygonData.length} zonas na cache`);
}

// Função para criar um marcador de etiqueta para o polígono
function criarLabelParaPolygon(polygon, texto = '', posicaoPersonalizada = null) {
  if (!map || !polygon) return null;

  let posicao;
  if (posicaoPersonalizada) {
    posicao = posicaoPersonalizada;
  } else {
    const bounds = new google.maps.LatLngBounds();
    polygon.getPath().forEach(latLng => {
      bounds.extend(latLng);
    });
    posicao = bounds.getCenter();
  }

  const polygonId = polygon._id;
  if (polygonLabelMarkers.has(polygonId)) {
    const oldLabel = polygonLabelMarkers.get(polygonId);
    oldLabel.setMap(null);
  }

  const labelMarker = new google.maps.Marker({
    position: posicao,
    map: map,
    icon: {
      path: 'M 0,0',
      scale: 0
    },
    label: {
      text: texto || `Zona ${allPolygons.indexOf(polygon) + 1}`,
      color: '#FFFFFF',
      fontSize: '14px',
      fontWeight: 'bold',
      className: 'polygon-label-with-outline'
    },
    zIndex: 1000,
    draggable: true
  });

  polygonLabelMarkers.set(polygonId, labelMarker);

  labelMarker.addListener('click', function (event) {
    event.stop();
    editarNomePolygon(polygon);
  });

  labelMarker.addListener('dragend', function () {
    salvarPolygonsNoCache();
  });

  return labelMarker;
}

// Função para editar nome do polígono
function editarNomePolygon(polygon) {
  const polygonId = polygon._id;
  const labelMarker = polygonLabelMarkers.get(polygonId);

  if (labelMarker) {
    const currentLabel = polygonLabels.get(polygonId) || `Zona ${allPolygons.indexOf(polygon) + 1}`;

    const editDiv = document.createElement('div');
    editDiv.id = 'polygon-edit-input';
    editDiv.style.cssText = `
      position: absolute;
      left: 50%;
      top: 50%;
      z-index: 1001;
      background: white;
      padding: 5px;
      border: 2px solid #4285F4;
      border-radius: 3px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.3);
    `;

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentLabel;
    input.style.cssText = `
      width: 150px;
      padding: 5px;
      border: 1px solid #ccc;
      border-radius: 2px;
      font-size: 14px;
    `;

    editDiv.appendChild(input);
    document.getElementById('map').appendChild(editDiv);

    input.focus();
    input.select();

    const salvarNome = function () {
      const novoNome = input.value.trim();
      if (novoNome) {
        labelMarker.setLabel({
          text: novoNome,
          color: '#FFFFFF',
          fontSize: '14px',
          fontWeight: 'bold',
          className: 'polygon-label-with-outline'
        });
        polygonLabels.set(polygonId, novoNome);
        salvarPolygonsNoCache();

        if (map) {
          criarLegenda(map, distribuidorCores);
        }
      }

      editDiv.remove();
    };

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        salvarNome();
      }
      if (e.key === 'Escape') {
        editDiv.remove();
      }
    });

    input.addEventListener('blur', function () {
      setTimeout(() => {
        if (editDiv.parentNode) {
          salvarNome();
        }
      }, 100);
    });
  }
}

// Função para editar cor do polígono
function editarCorPolygon(polygon) {
  const polygonId = polygon._id;
  const currentColor = polygon.fillColor || '#0F0';

  // Criar overlay para seleção de cor
  const overlay = document.createElement('div');
  overlay.id = 'polygon-color-overlay';
  overlay.style.cssText = `
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 1001;
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    min-width: 250px;
  `;

  // Cores pré-definidas
  const colors = [
    '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF',
    '#FF6600', '#6600FF', '#00FF66', '#FF0066', '#66FF00', '#0066FF',
    '#990000', '#009900', '#000099', '#999900', '#990099', '#009999'
  ];

  let colorGrid = '<div style="margin-bottom: 15px; display: grid; grid-template-columns: repeat(6, 30px); gap: 5px;">';
  colors.forEach(color => {
    colorGrid += `
      <div style="width: 30px; height: 30px; background: ${color}; 
                  border: 2px solid ${color === currentColor ? '#000' : '#ccc'}; 
                  border-radius: 4px; cursor: pointer;"
           onclick="aplicarCorPolygon(${polygonId}, '${color}')">
      </div>
    `;
  });
  colorGrid += '</div>';

  // Input para cor personalizada
  overlay.innerHTML = `
    <h3 style="margin: 0 0 15px 0; color: #333;">Alterar cor da zona</h3>
    <div style="margin-bottom: 15px;">
      <label style="display: block; margin-bottom: 5px; font-weight: bold;">Cor actual:</label>
      <div style="display: flex; align-items: center; margin-bottom: 15px;">
        <div style="width: 30px; height: 30px; background: ${currentColor}; 
                    border: 2px solid #000; border-radius: 4px; margin-right: 10px;"></div>
        <span>${currentColor}</span>
      </div>
    </div>
    
    <div style="margin-bottom: 15px;">
      <label style="display: block; margin-bottom: 5px; font-weight: bold;">Cores predefinidas:</label>
      ${colorGrid}
    </div>
    
    <div style="margin-bottom: 15px;">
      <label style="display: block; margin-bottom: 5px; font-weight: bold;">Cor personalizada:</label>
      <input type="color" id="polygon-color-picker" 
             value="${currentColor}" 
             style="width: 100%; height: 40px; margin-bottom: 10px;">
      <input type="text" id="polygon-color-hex" 
             value="${currentColor}" 
             placeholder="#RRGGBB"
             style="width: 100%; padding: 8px; border: 2px solid #4285F4; 
                    border-radius: 4px; box-sizing: border-box;">
    </div>
    
    <div style="display: flex; gap: 10px; justify-content: flex-end;">
      <button id="polygon-color-cancel" 
              style="padding: 8px 16px; background: #f0f0f0; 
                     border: none; border-radius: 4px; cursor: pointer;">
        Cancelar
      </button>
      <button id="polygon-color-ok" 
              style="padding: 8px 16px; background: #4285F4; 
                     color: white; border: none; border-radius: 4px; cursor: pointer;">
        Aplicar
      </button>
    </div>
  `;

  document.getElementById('map').appendChild(overlay);

  const colorPicker = document.getElementById('polygon-color-picker');
  const colorHexInput = document.getElementById('polygon-color-hex');
  const btnOk = document.getElementById('polygon-color-ok');
  const btnCancel = document.getElementById('polygon-color-cancel');

  // Sincronizar inputs
  colorPicker.addEventListener('input', function () {
    colorHexInput.value = this.value;
  });

  colorHexInput.addEventListener('input', function () {
    if (this.value.match(/^#[0-9A-F]{6}$/i)) {
      colorPicker.value = this.value;
    }
  });

  // Aplicar cor
  btnOk.addEventListener('click', function () {
    const novaCor = colorHexInput.value;
    if (novaCor.match(/^#[0-9A-F]{6}$/i)) {
      aplicarCorPolygon(polygonId, novaCor);
      overlay.remove();
    } else {
      alert('Por favor, insira uma cor hexadecimal válida (#RRGGBB)');
    }
  });

  // Cancelar
  btnCancel.addEventListener('click', function () {
    overlay.remove();
  });

  // Fechar com ESC
  document.addEventListener('keydown', function fecharComESC(e) {
    if (e.key === 'Escape') {
      overlay.remove();
      document.removeEventListener('keydown', fecharComESC);
    }
  });
}

// Função para aplicar cor ao polígono
function aplicarCorPolygon(polygonId, novaCor) {
  const polygon = allPolygons.find(p => p._id === polygonId);
  if (polygon) {
    polygon.setOptions({
      fillColor: novaCor,
      strokeColor: novaCor
    });

    salvarPolygonsNoCache();

    // Atualizar legenda
    if (map) {
      criarLegenda(map, distribuidorCores);
    }

    console.log(`Cor da zona ${polygonLabels.get(polygonId)} alterada para ${novaCor}`);
  }
}

// Função para carregar zonas do cache
function carregarPolygonsDoCache() {
  if (!map) return;

  const cacheData = localStorage.getItem('zonasCache');
  if (!cacheData) return;

  try {
    const polygonData = JSON.parse(cacheData);

    polygonData.forEach(data => {
      criarPolygonDoCache(data);
    });

    console.log(`Carregadas ${polygonData.length} zonas da cache`);
  } catch (error) {
    console.error('Erro ao carregar zonas da cache:', error);
  }
}

// Função para criar um zona a partir dos dados do cache
function criarPolygonDoCache(data) {
  const polygon = new google.maps.Polygon({
    paths: data.paths.map(p => new google.maps.LatLng(p.lat, p.lng)),
    fillColor: data.fillColor,
    strokeColor: data.strokeColor,
    strokeWeight: data.strokeWeight || 5,
    fillOpacity: 0.3,
    strokeOpacity: 0.8,
    editable: true,
    map: map
  });

  polygon._id = data.id || Date.now() + Math.random();

  allPolygons.push(polygon);

  if (data.label) {
    polygonLabels.set(polygon._id, data.label);
    criarLabelParaPolygon(polygon, data.label);
  } else {
    criarLabelParaPolygon(polygon);
  }

  // Adicionar evento de clique com Ctrl para editar nome
  polygon.addListener('click', function (event) {
    if (event.domEvent.ctrlKey) {
      editarNomePolygon(polygon);
    }
    if (event.domEvent.shiftKey) {
      editarCorPolygon(polygon);
    }
  });

  // Adicionar evento de clique com botão direito para menu de contexto
  polygon.addListener('rightclick', function (event) {
    mostrarMenuContextoPolygon(event, polygon);
  });

  polygon.addListener('mouseup', function () {
    salvarPolygonsNoCache();
    atualizarLabelsPolygons();
  });

  return polygon;
}

// Função para mostrar menu de contexto do polígono
function mostrarMenuContextoPolygon(event, polygon) {
  // Remover menu existente
  const menuExistente = document.getElementById('polygon-context-menu');
  if (menuExistente) {
    menuExistente.remove();
  }

  const menu = document.createElement('div');
  menu.id = 'polygon-context-menu';
  menu.style.cssText = `
    position: absolute;
    left: ${event.pixel.x}px;
    top: ${event.pixel.y}px;
    z-index: 1001;
    background: white;
    padding: 10px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    min-width: 180px;
  `;

  const polygonId = polygon._id;
  const zonaNome = polygonLabels.get(polygonId) || `Zona ${allPolygons.indexOf(polygon) + 1}`;

  menu.innerHTML = `
    <div style="padding: 8px 16px; font-weight: bold; border-bottom: 1px solid #eee;">
      ${zonaNome}
    </div>
    <div style="cursor: pointer; padding: 8px 16px; hover:background: #f0f0f0;"
         onclick="editarNomePolygon(polygons[${allPolygons.indexOf(polygon)}]); document.getElementById('polygon-context-menu').remove();">
      ✏️ Renomear zona
    </div>
    <div style="cursor: pointer; padding: 8px 16px; hover:background: #f0f0f0;"
         onclick="editarCorPolygon(polygons[${allPolygons.indexOf(polygon)}]); document.getElementById('polygon-context-menu').remove();">
      🎨 Alterar cor
    </div>
    <div style="cursor: pointer; padding: 8px 16px; hover:background: #f0f0f0; color: #d32f2f; border-top: 1px solid #eee;"
         onclick="removerZona('${polygonId}'); document.getElementById('polygon-context-menu').remove();">
      🗑️ Remover zona
    </div>
  `;

  document.getElementById('map').appendChild(menu);

  // Fechar menu ao clicar fora
  setTimeout(() => {
    const fecharMenu = function (e) {
      if (!menu.contains(e.target)) {
        menu.remove();
        document.removeEventListener('click', fecharMenu);
      }
    };
    document.addEventListener('click', fecharMenu);
  }, 100);
}

// Função para atualizar todas as etiquetas dos polígonos
function atualizarLabelsPolygons() {
  allPolygons.forEach(polygon => {
    const polygonId = polygon._id;
    const label = polygonLabels.get(polygonId) || `Zona ${allPolygons.indexOf(polygon) + 1}`;

    const bounds = new google.maps.LatLngBounds();
    polygon.getPath().forEach(latLng => {
      bounds.extend(latLng);
    });
    const novoCentro = bounds.getCenter();

    criarLabelParaPolygon(polygon, label, novoCentro);
  });
}

// Função para importar zonas de um arquivo JSON
function importarPolygonsDoArquivo(file) {
  if (!file) return;

  const reader = new FileReader();

  reader.onload = function (e) {
    try {
      const polygonData = JSON.parse(e.target.result);

      allPolygons.forEach(polygon => polygon.setMap(null));
      allPolygons = [];
      polygonLabels.clear();
      polygonLabelMarkers.clear();

      polygonData.forEach(data => {
        criarPolygonDoCache(data);
      });

      salvarPolygonsNoCache();
      alert(`Importadas ${polygonData.length} zonas com sucesso!`);

      if (map) {
        criarLegenda(map, distribuidorCores);
      }

    } catch (error) {
      console.error('Erro ao importar zonas:', error);
      alert('Erro ao importar o ficheiro. Verifique o formato do JSON.');
    }
  };

  reader.onerror = function () {
    alert('Erro ao ler o ficheiro.');
  };

  reader.readAsText(file);
}

// Função para identificar marcadores dentro/fora dos zonas
function analisarClientesNosPolygons() {
  if (!window.markers || window.markers.length === 0) {
    alert('Nenhum marcador encontrado no mapa.');
    return;
  }

  if (allPolygons.length === 0) {
    alert('Nenhuma zona desenhada no mapa.');
    return;
  }

  const marcadoresDentro = [];
  const marcadoresFora = [];
  const zonaDetalhes = new Map();

  window.markers.forEach(marker => {
    const posicao = marker.getPosition();
    let zonaEncontrada = null;

    for (const polygon of allPolygons) {
      if (google.maps.geometry.poly.containsLocation(posicao, polygon)) {
        zonaEncontrada = polygon;
        break;
      }
    }

    const dadosMarcador = {
      Codigo: marker.CODIGO || '',
      Nome: marker.NOME || '',
      Vendedor: marker.VENDEDOR || '',
      Dia: marker.DIA || '',
      Latitude: marker.LATITUDE || '',
      Longitude: marker.LONGITUDE || '',
      Status: zonaEncontrada ? 'Dentro' : 'Fora',
      Zona: zonaEncontrada ? polygonLabels.get(zonaEncontrada._id) || `Zona ${allPolygons.indexOf(zonaEncontrada) + 1}` : ''
    };

    if (zonaEncontrada) {
      marcadoresDentro.push(dadosMarcador);

      const zonaNome = polygonLabels.get(zonaEncontrada._id) || `Zona ${allPolygons.indexOf(zonaEncontrada) + 1}`;
      if (!zonaDetalhes.has(zonaNome)) {
        zonaDetalhes.set(zonaNome, []);
      }
      zonaDetalhes.get(zonaNome).push(dadosMarcador);
    } else {
      marcadoresFora.push(dadosMarcador);
    }
  });

  console.log('Clientes dentro das zonas:', marcadoresDentro.length);
  console.log('Clientes fora das zonas:', marcadoresFora.length);

  console.log('Resumo por zona:');
  zonaDetalhes.forEach((clientes, zonaNome) => {
    console.log(`${zonaNome}: ${clientes.length} clientes`);
  });

  return {
    dentro: marcadoresDentro,
    fora: marcadoresFora,
    totalDentro: marcadoresDentro.length,
    totalFora: marcadoresFora.length,
    zonaDetalhes: zonaDetalhes
  };
}

// Função para exportar CSV com marcadores dentro/fora dos zonas
function exportarClientesPolygonCSV() {
  const resultado = analisarClientesNosPolygons();

  if (!resultado) return;

  const todosClientes = [...resultado.dentro, ...resultado.fora];

  if (todosClientes.length === 0) {
    alert('Nenhum marcador para exportar.');
    return;
  }

  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude', 'Status', 'Zona'];

  const linhasCSV = todosClientes.map(marcador => {
    return [
      marcador.Codigo,
      `"${marcador.Nome.replace(/"/g, '""')}"`,
      `"${marcador.Vendedor.replace(/"/g, '""')}"`,
      `"${marcador.Dia.replace(/"/g, '""')}"`,
      marcador.Latitude,
      marcador.Longitude,
      marcador.Status,
      `"${marcador.Zona}"`
    ].join(',');
  });

  const csvContent = [headers.join(','), ...linhasCSV].join('\n');

  const BOM = "\uFEFF";
  const csvComBOM = BOM + csvContent;

  const blob = new Blob([csvComBOM], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `clientes_zona_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);

  alert(`Exportados ${todosClientes.length} marcadores.\nDentro: ${resultado.totalDentro}\nFora: ${resultado.totalFora}`);
}

// Função para exportar apenas marcadores dentro dos zonas
function exportarClientesDentroPolygonCSV() {
  const resultado = analisarClientesNosPolygons();

  if (!resultado || resultado.dentro.length === 0) {
    alert('Nenhum marcador dentro das zonas para exportar.');
    return;
  }

  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude', 'Zona'];

  const linhasCSV = resultado.dentro.map(marcador => {
    return [
      marcador.Codigo,
      `"${marcador.Nome.replace(/"/g, '""')}"`,
      `"${marcador.Vendedor.replace(/"/g, '""')}"`,
      `"${marcador.Dia.replace(/"/g, '""')}"`,
      marcador.Latitude,
      marcador.Longitude,
      `"${marcador.Zona}"`
    ].join(',');
  });

  const csvContent = [headers.join(','), ...linhasCSV].join('\n');
  const BOM = "\uFEFF";
  const csvComBOM = BOM + csvContent;

  const blob = new Blob([csvComBOM], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `clientes_dentro_zona_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);

  alert(`Exportados ${resultado.dentro.length} marcadores dentro das zonas.`);
}

// Função para exportar apenas marcadores fora dos zonas
function exportarClientesForaPolygonCSV() {
  const resultado = analisarClientesNosPolygons();

  if (!resultado || resultado.fora.length === 0) {
    alert('Nenhum marcador fora das zonas para exportar.');
    return;
  }

  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude'];

  const linhasCSV = resultado.fora.map(marcador => {
    return [
      marcador.Codigo,
      `"${marcador.Nome.replace(/"/g, '""')}"`,
      `"${marcador.Vendedor.replace(/"/g, '""')}"`,
      `"${marcador.Dia.replace(/"/g, '""')}"`,
      marcador.Latitude,
      marcador.Longitude
    ].join(',');
  });

  const csvContent = [headers.join(','), ...linhasCSV].join('\n');
  const BOM = "\uFEFF";
  const csvComBOM = BOM + csvContent;

  const blob = new Blob([csvComBOM], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `clientes_fora_zona_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);

  alert(`Exportados ${resultado.fora.length} marcadores fora das zonas.`);
}

// Função para exportar resumo por zona
function exportarResumoZonasCSV() {
  const resultado = analisarClientesNosPolygons();

  if (!resultado || resultado.zonaDetalhes.size === 0) {
    alert('Nenhuma zona com clientes para exportar.');
    return;
  }

  const headers = ['Zona', 'Total de clientes', 'Vendedores'];

  const linhasCSV = [];
  resultado.zonaDetalhes.forEach((clientes, zonaNome) => {
    const vendedores = [...new Set(clientes.map(c => c.Vendedor))].join('; ');
    linhasCSV.push([
      `"${zonaNome}"`,
      clientes.length,
      `"${vendedores}"`
    ].join(','));
  });

  linhasCSV.push([
    'TOTAL',
    resultado.totalDentro,
    `"${[...new Set(resultado.dentro.map(c => c.Vendedor))].join('; ')}"`
  ].join(','));

  const csvContent = [headers.join(','), ...linhasCSV].join('\n');
  const BOM = "\uFEFF";
  const csvComBOM = BOM + csvContent;

  const blob = new Blob([csvComBOM], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `resumo_zonas_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);

  alert(`Exportado resumo de ${resultado.zonaDetalhes.size} zonas.`);
}

// Função principal de inicialização do mapa
function initMap(Lats, Lngs, coords) {
  if (map) {
    if (Lats && Lngs && Lats.length && Lngs.length) {
      const bounds = new google.maps.LatLngBounds();

      Lats.forEach((lat, index) => {
        bounds.extend(new google.maps.LatLng(lat, Lngs[index]));
      });

      map.fitBounds(bounds);
    }

    if (window.markers) {
      window.markers.forEach(marker => marker.setMap(null));
    }
    window.markers = [];
    distribuidorMarkers.clear();

    if (coords && coords.length > 0) {
      adicionarClientesAoMapa(coords, map);
    }

    return;
  }

  map = new google.maps.Map(document.getElementById("map"), {
    zoom: 7,
    center: { lat: -11.2027, lng: 17.8739 },
    mapTypeControl: true,
    mapTypeControlOptions: {
      style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
      position: google.maps.ControlPosition.LEFT_BOTTOM,
      mapTypeIds: [
        google.maps.MapTypeId.ROADMAP,
        google.maps.MapTypeId.SATELLITE,
        google.maps.MapTypeId.HYBRID,
      ],
    },
    streetViewControl: true,
    fullscreenControl: true,
  });

  if (Lats && Lngs && Lats.length && Lngs.length) {
    const bounds = new google.maps.LatLngBounds();

    Lats.forEach((lat, index) => {
      bounds.extend(new google.maps.LatLng(lat, Lngs[index]));
    });

    map.fitBounds(bounds);
  }

  window.markers = [];
  distribuidorMarkers.clear();

  if (coords && coords.length > 0) {
    adicionarClientesAoMapa(coords, map);
  }

  drawingManager = new google.maps.drawing.DrawingManager({
    drawingMode: null,
    drawingControl: false,
    drawingControlOptions: {
      position: google.maps.ControlPosition.TOP_RIGHT,
      drawingModes: [
        google.maps.drawing.OverlayType.POLYGON,
      ],
    },
    polygonOptions: {
      fillColor: '#0F0',
      strokeColor: "#0F0",
      strokeWeight: 5,
      fillOpacity: 0.3,
      strokeOpacity: 0.8,
      clickable: true,
      editable: true,
      zIndex: 1,
    },
  });

  google.maps.event.addListener(drawingManager, 'polygoncomplete', function (polygon) {
    const novaCor = gerarCorHex();
    polygon.setOptions({
      fillColor: novaCor,
      strokeColor: novaCor,
      fillOpacity: 0.3,
      strokeOpacity: 0.8
    });

    polygon._id = Date.now() + Math.random();

    allPolygons.push(polygon);

    const zonaNumero = allPolygons.length;
    const nomePadrao = `Zona ${zonaNumero}`;

    criarPromptNomeZona(polygon, nomePadrao);

    salvarPolygonsNoCache();

    polygon.addListener('click', function (event) {
      if (event.domEvent.ctrlKey) {
        editarNomePolygon(polygon);
      }
      if (event.domEvent.shiftKey) {
        editarCorPolygon(polygon);
      }
    });

    polygon.addListener('rightclick', function (event) {
      mostrarMenuContextoPolygon(event, polygon);
    });

    polygon.addListener('mouseup', function () {
      salvarPolygonsNoCache();
      atualizarLabelsPolygons();
    });

    criarLegenda(map, distribuidorCores);
  });

  drawingManager.setMap(map);

  carregarPolygonsDoCache();
}

// Função para criar prompt customizado para nome da zona
function criarPromptNomeZona(polygon, nomePadrao) {
  const overlay = document.createElement('div');
  overlay.id = 'zona-nome-overlay';
  overlay.style.cssText = `
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1001;
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    min-width: 300px;
  `;

  overlay.innerHTML = `
    <h3 style="margin: 0 0 15px 0; color: #333;">Nome da zona</h3>
    <input type="text" id="zona-nome-input" 
           value="${nomePadrao}" 
           style="width: 100%; padding: 10px; margin-bottom: 15px; 
                  border: 2px solid #4285F4; border-radius: 4px; 
                  font-size: 16px; box-sizing: border-box;">
    <div style="display: flex; gap: 10px; justify-content: flex-end;">
      <button id="zona-nome-cancel" 
              style="padding: 8px 16px; background: #f0f0f0; 
                     border: none; border-radius: 4px; cursor: pointer;">
        Cancelar
      </button>
      <button id="zona-nome-ok" 
              style="padding: 8px 16px; background: #4285F4; 
                     color: white; border: none; border-radius: 4px; cursor: pointer;">
        OK
      </button>
    </div>
  `;

  document.getElementById('map').appendChild(overlay);

  const input = document.getElementById('zona-nome-input');
  const btnOk = document.getElementById('zona-nome-ok');
  const btnCancel = document.getElementById('zona-nome-cancel');

  input.focus();
  input.select();

  btnOk.addEventListener('click', function () {
    const nome = input.value.trim() || nomePadrao;
    polygonLabels.set(polygon._id, nome);
    criarLabelParaPolygon(polygon, nome);
    overlay.remove();
    salvarPolygonsNoCache();

    if (map) {
      criarLegenda(map, distribuidorCores);
    }
  });

  btnCancel.addEventListener('click', function () {
    polygonLabels.set(polygon._id, nomePadrao);
    criarLabelParaPolygon(polygon, nomePadrao);
    overlay.remove();
    salvarPolygonsNoCache();

    if (map) {
      criarLegenda(map, distribuidorCores);
    }
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      btnOk.click();
    }
    if (e.key === 'Escape') {
      btnCancel.click();
    }
  });
}

// Função para adicionar marcadores ao mapa
function adicionarClientesAoMapa(coords, map) {
  console.log("Coordenadas recebidas:", coords.slice(0, 3));

  const gruposPorDistribuidor = {};

  // Agrupar por distribuidor
  coords.forEach((coord, index) => {
    const distribuidor = coord.Distribuidor || 'Sem Distribuidor';
    if (!gruposPorDistribuidor[distribuidor]) {
      gruposPorDistribuidor[distribuidor] = [];
    }
    gruposPorDistribuidor[distribuidor].push({
      ...coord,
      originalIndex: index
    });
  });

  // Para cada distribuidor, criar marcadores
  Object.keys(gruposPorDistribuidor).forEach(distribuidor => {
    const grupo = gruposPorDistribuidor[distribuidor];

    grupo.forEach(item => {
      const rawLat = parseFloat(item.Latitude);
      const rawLng = parseFloat(item.Longitude);

      // Extrair tipo de estabelecimento (com fallback)
      const tipoEstabelecimento = item['Tipo de estabelecimento'] || item.tipoEstabelecimento || '';

      // Determinar se é estabelecimento DISTRIBUIDOR
      const isDistribuidorEstabelecimento = isDistribuidor(tipoEstabelecimento);

      // Obter cor apropriada
      const corDoMarcador = getCorParaDistribuidor(distribuidor, tipoEstabelecimento);

      // Configurar ícone baseado no tipo
      const iconConfig = {
        path: isDistribuidorEstabelecimento ?
          google.maps.SymbolPath.BACKWARD_CLOSED_ARROW :
          google.maps.SymbolPath.CIRCLE,
        scale: isDistribuidorEstabelecimento ? 14 : 10,
        fillColor: corDoMarcador,
        fillOpacity: 1,
        strokeWeight: isDistribuidorEstabelecimento ? 2 : 1,
        strokeColor: isDistribuidorEstabelecimento ? '#FFFFFF' : '#000000'
      };

      var marker = new google.maps.Marker({
        position: { lat: rawLat, lng: rawLng },
        map: map,
        icon: iconConfig,
        title: `${item.Nome} - ${isDistribuidorEstabelecimento ? 'DISTRIBUIDOR' : distribuidor}`,
        zIndex: isDistribuidorEstabelecimento ? 1000 : 1
      });

      // Armazenar propriedades no marcador
      marker.CODIGO = item.Código || '';
      marker.NOME = item.Nome || '';
      marker.VENDEDOR = item.Vendedor || '';
      marker.DISTRIBUIDOR = distribuidor;
      marker.TIPO_ESTABELECIMENTO = tipoEstabelecimento;
      marker.DIA = item.Dia || '';
      marker.LATITUDE = item.Latitude || '';
      marker.LONGITUDE = item.Longitude || '';
      marker.COR = corDoMarcador;
      marker.IS_DISTRIBUIDOR = isDistribuidorEstabelecimento;

      var infowindow = new google.maps.InfoWindow();

      // Adicionar evento de clique para alterar cor do grupo
      google.maps.event.addListener(marker, 'click', function (event) {
        // Se for distribuidor, não altera cor
        if (marker.IS_DISTRIBUIDOR) return;

        mostrarMenuCorDistribuidor(event, marker);
      });

      google.maps.event.addListener(
        marker,
        "mouseover",
        (function (marker, infowindow) {
          return function () {
            const distribuidorBadge = marker.IS_DISTRIBUIDOR ?
              '<span style="color:red; font-weight:bold;">[DISTRIBUIDOR]</span>' : '';

            var contentString =
              "<div>" +
              `<p style="color:#000000;"><strong>DISTRIBUIDOR: </strong>${marker.DISTRIBUIDOR}</p>` +
              `<p style="color:#000000;"><strong>CÓDIGO DO CLIENTE: </strong>${marker.CODIGO}</p>` +
              `<p style="color:#000000;"><strong>TIPO DE ESTABELECIMENTO: </strong>${marker.TIPO_ESTABELECIMENTO}</p>` +
              `<p style="color:#000000;"><strong>CLIENTE: </strong>${marker.NOME} ${distribuidorBadge}</p>` +
              `<p style="color:#000000;"><strong>VENDEDOR: </strong>${marker.VENDEDOR}</p>` +
              `<p style="color:#000000;"><strong>DIA DE VISITA: </strong>${marker.DIA}</p>` +
              `<p style="color:${marker.COR}"><strong>GRUPO: </strong><span style="color:${marker.COR}">● ${marker.DISTRIBUIDOR}</span></p>` +
              `<p><small style="color:#000000;">Clique para alterar cor do grupo</small></p>` +
              "</div>";
            infowindow.setContent(contentString);
            infowindow.open(map, marker);
          };
        })(marker, infowindow)
      );

      google.maps.event.addListener(
        marker,
        "mouseout",
        (function (infowindow) {
          return function () {
            infowindow.close();
          };
        })(infowindow)
      );

      window.markers.push(marker);

      // Adicionar ao mapa de distribuidores
      const distribuidorNormalizado = String(distribuidor).trim();
      if (!distribuidorMarkers.has(distribuidorNormalizado)) {
        distribuidorMarkers.set(distribuidorNormalizado, []);
      }
      distribuidorMarkers.get(distribuidorNormalizado).push(marker);
    });
  });

  // Atualizar legenda
  criarLegenda(map, distribuidorCores);
}

// Função para mostrar menu de alteração de cor do distribuidor
function mostrarMenuCorDistribuidor(event, marker) {
  // Remover menu existente
  const menuExistente = document.getElementById('distribuidor-color-menu');
  if (menuExistente) {
    menuExistente.remove();
  }

  const distribuidor = marker.DISTRIBUIDOR;
  const corAtual = marker.COR;

  const menu = document.createElement('div');
  menu.id = 'distribuidor-color-menu';
  menu.style.cssText = `
    position: absolute;
    left: 50%;
    top: 50%;
    z-index: 1001;
    background: white;
    padding: 15px;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    min-width: 200px;
  `;

  // Cores pré-definidas
  const colors = [
    '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF',
    '#FF6600', '#6600FF', '#00FF66', '#FF0066', '#66FF00', '#0066FF',
    '#990000', '#009900', '#000099', '#999900', '#990099', '#009999',
    '#FF3399', '#33FF99', '#9933FF', '#FF9933', '#33FFCC', '#CC33FF'
  ];

  let colorGrid = '<div style="margin-bottom: 10px; display: grid; grid-template-columns: repeat(6, 25px); gap: 3px;">';
  colors.forEach(color => {
    colorGrid += `
      <div style="width: 25px; height: 25px; background: ${color}; 
                  border: 2px solid ${color === corAtual ? '#000' : '#ccc'}; 
                  border-radius: 4px; cursor: pointer;"
           onclick="alterarCorGrupo('${distribuidor}', '${color}'); 
                    document.getElementById('distribuidor-color-menu').remove();">
      </div>
    `;
  });
  colorGrid += '</div>';

  menu.innerHTML = `
    <div style="margin-bottom: 10px; font-weight: bold;">
      Alterar cor do grupo:<br>
      <span style="color: ${corAtual}">${distribuidor}</span>
    </div>
    
    <div style="margin-bottom: 15px;">
      <div style="font-size: 12px; margin-bottom: 5px;">Cores pré-definidas:</div>
      ${colorGrid}
    </div>
    
    <div style="margin-bottom: 15px;">
      <div style="font-size: 12px; margin-bottom: 5px;">Cor personalizada:</div>
      <div style="display: flex; gap: 5px;">
        <input type="color" id="distribuidor-color-picker" 
               value="${corAtual}" 
               style="width: 40px; height: 40px;">
        <input type="text" id="distribuidor-color-hex" 
               value="${corAtual}" 
               placeholder="#RRGGBB"
               style="flex: 1; padding: 8px; border: 2px solid #4285F4; 
                      border-radius: 4px; box-sizing: border-box;">
      </div>
    </div>
    
    <div style="display: flex; gap: 5px; justify-content: flex-end;">
      <button id="distribuidor-color-cancel" 
              style="padding: 6px 12px; background: #f0f0f0; 
                     border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
        Cancelar
      </button>
      <button id="distribuidor-color-ok" 
              style="padding: 6px 12px; background: #4285F4; 
                     color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
        Aplicar
      </button>
    </div>
  `;

  document.getElementById('map').appendChild(menu);

  const colorPicker = document.getElementById('distribuidor-color-picker');
  const colorHexInput = document.getElementById('distribuidor-color-hex');
  const btnOk = document.getElementById('distribuidor-color-ok');
  const btnCancel = document.getElementById('distribuidor-color-cancel');

  // Sincronizar inputs
  colorPicker.addEventListener('input', function () {
    colorHexInput.value = this.value;
  });

  colorHexInput.addEventListener('input', function () {
    if (this.value.match(/^#[0-9A-F]{6}$/i)) {
      colorPicker.value = this.value;
    }
  });

  // Aplicar cor
  btnOk.addEventListener('click', function () {
    const novaCor = colorHexInput.value;
    if (novaCor.match(/^#[0-9A-F]{6}$/i)) {
      alterarCorGrupo(distribuidor, novaCor);
      menu.remove();
    } else {
      alert('Por favor, insira uma cor hexadecimal válida (#RRGGBB)');
    }
  });

  // Cancelar
  btnCancel.addEventListener('click', function () {
    menu.remove();
  });

  // Fechar com ESC
  document.addEventListener('keydown', function fecharComESC(e) {
    if (e.key === 'Escape') {
      menu.remove();
      document.removeEventListener('keydown', fecharComESC);
    }
  });

  // Fechar ao clicar fora
  setTimeout(() => {
    const fecharMenu = function (e) {
      if (!menu.contains(e.target)) {
        menu.remove();
        document.removeEventListener('click', fecharMenu);
      }
    };
    document.addEventListener('click', fecharMenu);
  }, 100);
}

// Função para criar legenda de cores
function criarLegenda(map, distribuidorCores) {
  const legendExistente = document.getElementById('legend');
  if (legendExistente) {
    legendExistente.remove();
  }

  const legendDiv = document.createElement('div');
  legendDiv.id = 'legend';
  legendDiv.style.cssText = `
    background: white;
    padding: 10px;
    margin: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    max-height: 500px;
    overflow-y: auto;
    font-size: 12px;
    width: 350px;
  `;

  let legendHTML = '<h4 style="margin: 0 0 10px 0; font-size: 14px;color:#000000;">Clientes por distribuidor</h4>';

  // Adicionar item especial para DISTRIBUIDOR
  legendHTML += `
    <div style="margin: 5px 0; display: flex; align-items: center; background: #FFF0F0; padding: 5px; border-radius: 3px;">
      <span style="display: inline-block; width: 14px; height: 14px; 
                   background-color: ${COR_DISTRIBUIDOR}; border: 2px solid #FFFFFF; 
                   border-radius: 50%; margin-right: 8px; box-shadow: 0 0 3px rgba(0,0,0,0.5);"></span>
      <span style="font-weight: bold; color: #CC0000;">DISTRIBUIDOR (Tipo Estabelecimento)</span>
    </div>
    <hr style="margin: 10px 0; border-color: #eee;">
  `;

  // Adicionar distribuidores normais
  distribuidorCores.forEach((cor, distribuidor) => {
    legendHTML += `
      <div style="margin: 5px 0; padding: 5px; border: 1px solid #eee; border-radius: 3px; 
                  display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center;">
          <span style="display: inline-block; width: 12px; height: 12px; 
                       background-color: ${cor}; border: 1px solid #000; 
                       border-radius: 50%; margin-right: 8px; cursor: pointer;"
                onclick="alterarCorGrupo('${distribuidor}', '${cor}')"
                title="Alterar cor">
          </span>
          <span style="color:#000000;">${distribuidor}</span>
        </div>
        <button onclick="alterarCorGrupo('${distribuidor}', gerarCorHex())" 
                style="padding: 2px 6px; font-size: 10px; background: #4285F4; 
                       color: white; border: none; border-radius: 2px; cursor: pointer;"
                title="Gerar nova cor">
          🔄
        </button>
      </div>
    `;
  });

  // Adicionar seção de zonas
  legendHTML += `
    <hr style="margin: 10px 0;">
    <h4 style="margin: 10px 0; font-size: 14px; color:#000000;">Zonas criadas</h4>
    <div style="font-size: 11px; color: #333333; margin-bottom: 5px;">
      Dica: Ctrl+clique para renomear, Shift+clique para alterar cor
    </div>
    <div id="zonas-list" style="margin-bottom: 10px; max-height: 150px; overflow-y: auto;">
  `;

  allPolygons.forEach((polygon, index) => {
    const zonaNome = polygonLabels.get(polygon._id) || `Zona ${index + 1}`;
    const cor = polygon.fillColor || '#000000';
    legendHTML += `
      <div style="margin: 5px 0; padding: 5px; border: 1px solid #eee; border-radius: 3px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">
          <div style="display: flex; align-items: center;">
            <span style="display: inline-block; width: 12px; height: 12px; 
                         background-color: ${cor}; border: 1px solid #000; 
                         border-radius: 2px; margin-right: 8px; cursor: pointer;"
                  onclick="aplicarCorPolygon('${polygon._id}', '${cor}')"
                  title="Alterar cor">
            </span>
            <span style="color:#000000;font-weight: bold;">${zonaNome}</span>
          </div>
          <div>
            <button onclick="editarNomePolygonById(${polygon._id})" 
                    style="padding: 2px 6px; font-size: 10px; background: #4285F4; 
                           color: white; border: none; border-radius: 2px; cursor: pointer; margin-right: 3px;"
                    title="Renomear zona">
              ✏️
            </button>
            <button onclick="removerZona(${polygon._id})" 
                    style="padding: 2px 6px; font-size: 10px; background: #EA4335; 
                           color: white; border: none; border-radius: 2px; cursor: pointer;"
                    title="Remover zona">
              🗑️
            </button>
          </div>
        </div>
        <div style="font-size: 10px; color: #888;">
          Clientes: ${analisarClientesNaZona(polygon._id)}
        </div>
      </div>
    `;
  });

  legendHTML += `</div>`;

  // Adicionar botões para análise de zonas
  legendHTML += `
    <hr style="margin: 10px 0;">
    <div style="font-weight: bold; margin-bottom: 5px; color:#000000;">Análise de zonas</div>
    <button onclick="mostrarApenasDistribuidores()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #FF4444; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Mostrar apenas distribuidores
    </button>
    <button onclick="mostrarTodosClientes()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #444444; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Mostrar todos os clientes
    </button>
    <button onclick="exportarClientesPolygonCSV()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #4285F4; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Exportar todos os clientes
    </button>
    <button onclick="exportarClientesDentroPolygonCSV()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #34A853; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Exportar clientes dentro das zonas
    </button>
    <button onclick="exportarClientesForaPolygonCSV()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #EA4335; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Exportar clientes fora das zonas
    </button>
    <button onclick="exportarResumoZonasCSV()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #9C27B0; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Exportar resumo das zonas
    </button>
    <button onclick="limparPolygonsCache()" 
            style="width: 100%; padding: 6px; margin: 3px 0; 
                   background: #ff4444; color: white; border: none; 
                   border-radius: 3px; cursor: pointer; font-size: 11px;">
      Limpar zonas
    </button>
  `;

  legendDiv.innerHTML = legendHTML;

  map.controls[google.maps.ControlPosition.RIGHT_TOP].push(legendDiv);
}

// Função para analisar clientes em uma zona específica
function analisarClientesNaZona(polygonId) {
  const polygon = allPolygons.find(p => p._id === polygonId);
  if (!polygon || !window.markers || window.markers.length === 0) return '0';

  let count = 0;
  window.markers.forEach(marker => {
    const posicao = marker.getPosition();
    if (google.maps.geometry.poly.containsLocation(posicao, polygon)) {
      count++;
    }
  });

  return count.toString();
}

// Função para editar nome do polígono por ID
function editarNomePolygonById(polygonId) {
  const polygon = allPolygons.find(p => p._id === polygonId);
  if (polygon) {
    editarNomePolygon(polygon);
  }
}

// Função para remover uma zona específica
function removerZona(polygonId) {
  const polygon = allPolygons.find(p => p._id === polygonId);
  if (polygon && confirm(`Remover a zona "${polygonLabels.get(polygonId)}"?`)) {
    polygon.setMap(null);
    const index = allPolygons.indexOf(polygon);
    if (index > -1) {
      allPolygons.splice(index, 1);
    }

    if (polygonLabelMarkers.has(polygonId)) {
      polygonLabelMarkers.get(polygonId).setMap(null);
      polygonLabelMarkers.delete(polygonId);
    }

    polygonLabels.delete(polygonId);
    salvarPolygonsNoCache();

    if (map) {
      criarLegenda(map, distribuidorCores);
    }
  }
}

// Função para limpar zonas
function limparPolygonsCache() {
  if (confirm('Tem a certeza de que pretende remover todas as zonas?')) {
    localStorage.removeItem('zonasCache');
    allPolygons.forEach(polygon => polygon.setMap(null));
    allPolygons = [];
    polygonLabels.clear();
    polygonLabelMarkers.clear();
    console.log('Zonas limpas');

    if (map) {
      criarLegenda(map, distribuidorCores);
    }
  }
}

// Funções de filtro
function mostrarApenasDistribuidores() {
  if (!window.markers || window.markers.length === 0) {
    return;
  }

  window.markers.forEach(marker => {
    if (marker.IS_DISTRIBUIDOR) {
      marker.setMap(map);
    } else {
      marker.setMap(null);
    }
  });
}

function mostrarTodosClientes() {
  if (!window.markers || window.markers.length === 0) {
    alert('Nenhum marcador encontrado.');
    return;
  }

  window.markers.forEach(marker => {
    marker.setMap(map);
  });
}

// Função para carregar CSV
function loadCSV(file) {
  if (!file) return;

  const selectStat = document.getElementById("selectStatus");
  const selectedStatus = selectStat ? selectStat.value : "Ativo";

  console.log("Estado aplicado:", selectedStatus);
  console.log("Novo ficheiro:", file.name);
  document.getElementById("csvDocName").innerHTML = "Documento: " + file.name;

  const maxRows = 20000;

  function filterRow(row) {
    const rowStatus = (row.Estado !== undefined && row.Estado !== null) ? String(row.Estado).trim() :
                      (row.Status !== undefined && row.Status !== null) ? String(row.Status).trim() : "";
    const statusMatch = selectedStatus === "Todos" || (rowStatus.toLowerCase() === selectedStatus.toLowerCase());

    return (
      row.Nome !== "Cliente Eventual" &&
      statusMatch &&
      row.Latitude &&
      row.Latitude !== " "
    );
  }

  // jsonData = [];
  //count = 0;

  Papa.parse(file, {
    header: true,
    dynamicTyping: true,
    skipEmptyLines: true,

    step: function (row) {
      if (filterRow(row.data) && count < maxRows) {
        jsonData.push(row.data);
        count++;
      }
    },

    complete: function () {
      console.log("Total carregado:", count);
      document.getElementById('textClients').innerHTML = 'Total de clientes carregados: ' + count;
      console.log("Primeiro registo:", jsonData[0]);
      console.log("Colunas disponíveis:", Object.keys(jsonData[0]));

      const lats = jsonData.map((coords) => parseFloat(coords.Latitude));
      const lngs = jsonData.map((coords) => parseFloat(coords.Longitude));

      // Extrair dados do CSV, incluindo a coluna "Tipo de estabelecimento"
      coords = jsonData.map((c) => {
        // Procurar a coluna "Tipo de estabelecimento" (com atenção ao espaços e acentos)
        const tipoEstabelecimento = c['Tipo de estabelecimento'] ||
          c['Tipo de Estabelecimento'] ||
          c['Tipo estabelecimento'] ||
          '';

        console.log("Tipo de estabelecimento encontrado:", tipoEstabelecimento);

        return {
          Distribuidor: c.Distribuidor || c.DISTRIBUIDOR || 'Sem Distribuidor',
          'Tipo de estabelecimento': tipoEstabelecimento,
          Latitude: c.Latitude,
          Longitude: c.Longitude,
          Nome: c.Nome,
          Código: c.Código,
          Vendedor: c.Vendedor,
          Dia: c.Dia,
          Estado: c.Estado || c.Status,
        };
      });

      console.log("Coordenadas processadas (primeiras 3):", coords.slice(0, 3));

      // Limpar cache anterior
      distribuidorCores.clear();
      distribuidorMarkers.clear();

      // Usar initMap
      initMap(lats, lngs, coords);
    },
  });
}

// Função para importar zonas (chamada pelo botão)
function importarPolygons() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.json';

  input.onchange = function (e) {
    const file = e.target.files[0];
    if (file) {
      importarPolygonsDoArquivo(file);
    }
  };

  input.click();
}

function exportarPolygons() {
  const cacheData = localStorage.getItem('zonasCache');
  if (cacheData) {
    const blob = new Blob([cacheData], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'zonas_export.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    alert('Zonas exportadas com sucesso!');
  } else {
    alert('Nenhuma zona para exportar.');
  }
};

// Função para lidar com o clique no botão de carregar
function handleLoadClick() {
  const csvInput = document.getElementById("csvFile");
  csvInput.value = "";

  csvInput.onclick = () => {
    console.clear();
    console.log("A aguardar novo ficheiro...");
  };

  csvInput.onchange = (event) => {
    const file = event.target.files[0];
    if (file) {
      loadCSV(file);
    }
  };

  csvInput.click();
}

function loadButtonClicked() {
  handleLoadClick();
}

// Função específica para ativar modo de desenho
function ativarModoDesenho() {
  if (drawingManager) {
    drawingManager.setOptions({
      drawingMode: google.maps.drawing.OverlayType.POLYGON,
    });
  }
}

// Função para desativar o modo de desenho
function desativarModoDesenho() {
  if (drawingManager) {
    drawingManager.setOptions({
      drawingMode: null
    });
  }
}

// Adicionar listener para o botão de download CSV
document.getElementById("downloadCSV").addEventListener("click", () => {
  if (optimizedRoute) {
    const BOM = "\uFEFF";
    const csvWithBOM = BOM + newSequenceCreated;

    const blob = new Blob([csvWithBOM], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.setAttribute("hidden", "");
    a.setAttribute("href", url);
    a.setAttribute("download", "Sequência.csv");
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  } else {
    alert(
      "Sem rotas por optimizar:\n1 - Seleccione um ficheiro\n2 - Clique no ícone Optimizar\n3 - Optimizar rota"
    );
  }
});

// Adicionar CSS para as etiquetas com contorno
const existingStyle = document.querySelector('style[data-polygon-labels]');
if (existingStyle) {
  existingStyle.remove();
}

const style = document.createElement('style');
style.setAttribute('data-polygon-labels', 'true');
style.textContent = `
  .polygon-label-with-outline {
    background-color: rgba(0, 0, 0, 0.7);
    padding: 4px 8px;
    border-radius: 4px;
    border: 2px solid white;
    white-space: nowrap;
    text-shadow: none;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    display: inline-block;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
  }
  
  .polygon-label-with-outline::before {
    content: attr(text);
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 4px 8px;
    border-radius: 4px;
    background: transparent;
    z-index: -1;
    text-shadow: 
      -1px -1px 0 #000,
      1px -1px 0 #000,
      -1px 1px 0 #000,
      1px 1px 0 #000;
  }
  
  .polygon-label-with-outline:hover {
    background-color: rgba(66, 133, 244, 0.9);
    transform: scale(1.05);
    z-index: 1002 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
  }
  
  #polygon-edit-input, #polygon-color-overlay, #distribuidor-color-menu {
    animation: fadeIn 0.2s;
  }
  
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }
  
  #zona-nome-overlay {
    animation: slideIn 0.3s;
    border: 2px solid #4285F4;
  }
  
  @keyframes slideIn {
    from { opacity: 0; transform: translate(-50%, -60%); }
    to { opacity: 1; transform: translate(-50%, -50%); }
  }
  
  button[onclick*="editarNomePolygonById"],
  button[onclick*="removerZona"] {
    transition: all 0.2s;
  }
  
  button[onclick*="editarNomePolygonById"]:hover {
    background-color: #3367D6 !important;
    transform: scale(1.1);
  }
  
  button[onclick*="removerZona"]:hover {
    background-color: #D32F2F !important;
    transform: scale(1.1);
  }
  
  #polygon-context-menu div:hover {
    background-color: #f0f0f0;
  }
`;
document.head.appendChild(style);