// Segurança: escapa texto antes de o pôr em HTML (os nomes de clientes, rotas e zonas vêm de ficheiros CSV/JSON)
function escHtml(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
// Texto como argumento JavaScript dentro de um atributo onclick="…"
function escJsAttr(v) {
  return escHtml(JSON.stringify(String(v == null ? '' : v)));
}
// Identificador (número) ou texto como argumento JavaScript num onclick="…", sem mudar o tipo
function escJsVal(v) {
  return typeof v === 'number' && isFinite(v) ? String(v) : escJsAttr(v);
}
// Célula de CSV segura: entre aspas, aspas duplicadas e sem fórmulas (=, +, -, @ no início abrem fórmulas
// no Excel: «CSV injection», OWASP). Os números (incluindo negativos, como as latitudes) ficam como estão.
function csvCampo(v) {
  let s = String(v == null ? '' : v);
  if (/^[=+\-@\t\r]/.test(s) && !/^-?\d+([.,]\d+)?$/.test(s)) s = "'" + s;
  return '"' + s.replace(/"/g, '""') + '"';
}
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
let map = null; // Variável global para o mapa
let allPolygons = []; // Array para armazenar todos os zonas
let polygonLabels = new Map(); // Mapa para armazenar etiquetas dos polígonos
let polygonLabelMarkers = new Map(); // Mapa para armazenar os marcadores de etiqueta

// Cache para armazenar cores por vendedor
const vendedorCores = new Map();

function gerarCorHex() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0');
}

// Função para obter ou gerar cor para um vendedor
function getCorParaVendedor(vendedor) {
  if (!vendedor) return gerarCorHex();

  if (!vendedorCores.has(vendedor)) {
    vendedorCores.set(vendedor, gerarCorHex());
  }
  return vendedorCores.get(vendedor);
}

// Função para salvar zonas no cache (localStorage)
function salvarPolygonsNoCache() {
  const polygonData = allPolygons.map(polygon => {
    const paths = polygon.getPath().getArray().map(latLng => ({
      lat: latLng.lat(),
      lng: latLng.lng()
    }));

    // Obter etiqueta do polígono
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

  // Calcular o centro do polígono se não houver posição personalizada
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

  // Remover etiqueta anterior se existir
  const polygonId = polygon._id;
  if (polygonLabelMarkers.has(polygonId)) {
    const oldLabel = polygonLabelMarkers.get(polygonId);
    oldLabel.setMap(null);
  }

  // Criar marcador de etiqueta com MELHOR VISIBILIDADE
  const labelMarker = new google.maps.Marker({
    position: posicao,
    map: map,
    icon: {
      path: 'M 0,0', // Marcador invisível
      scale: 0
    },
    label: {
      text: texto || `Zona ${allPolygons.indexOf(polygon) + 1}`,
      color: '#FFFFFF', // Texto branco para contraste
      fontSize: '14px',
      fontWeight: 'bold',
      className: 'polygon-label-with-outline' // Nova classe para contorno
    },
    zIndex: 1000,
    draggable: true
  });

  // Armazenar a referência
  polygonLabelMarkers.set(polygonId, labelMarker);

  // Adicionar evento de clique para editar a etiqueta
  labelMarker.addListener('click', function (event) {
    // Impedir que o evento se propague para o mapa
    event.stop();
    editarNomePolygon(polygon);
  });

  // Adicionar evento de arrastar para reposicionar a etiqueta
  labelMarker.addListener('dragend', function () {
    salvarPolygonsNoCache();
  });

  return labelMarker;
}

// Função para editar nome do polígono - CORRIGIDA
function editarNomePolygon(polygon) {
  const polygonId = polygon._id;
  const labelMarker = polygonLabelMarkers.get(polygonId);

  if (labelMarker) {
    // Criar um input flutuante para edição
    const currentLabel = polygonLabels.get(polygonId) || `Zona ${allPolygons.indexOf(polygon) + 1}`;

    // Obter posição da etiqueta CORRETAMENTE
    //const posicao = labelMarker.getPosition();

    // Converter para pixels do mapa usando OverlayView
    /* const overlay = new google.maps.OverlayView();
     overlay.onAdd = function() {};
     overlay.draw = function() {};
     overlay.onRemove = function() {};*/

    // Adicionar ao mapa temporariamente para usar a projeção
    // overlay.setMap(map);

    // Obter a projeção
    /*const projection = overlay.getProjection();
    if (!projection) {
      console.error('Projecção não disponível');
      overlay.setMap(null);
      return;
    }*/

    /*const pixelPos = projection.fromLatLngToDivPixel(posicao);
    overlay.setMap(null);*/

    // Criar elemento de edição
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

    // Focar no input
    input.focus();
    input.select();

    // Função para salvar
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

        // Atualizar legenda
        if (map) {
          criarLegenda(map, vendedorCores);
        }
      }

      // Remover o input
      editDiv.remove();
    };

    // Eventos do input
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

  // Adicionar ID único ao polígono
  polygon._id = data.id || Date.now() + Math.random();

  allPolygons.push(polygon);

  // Criar etiqueta para o polígono
  if (data.label) {
    polygonLabels.set(polygon._id, data.label);
    criarLabelParaPolygon(polygon, data.label);
  } else {
    criarLabelParaPolygon(polygon);
  }

  // Adicionar evento de clique no polígono para editar nome
  polygon.addListener('click', function (event) {
    // Se Ctrl+clique, editar nome
    if (event.domEvent.ctrlKey) {
      editarNomePolygon(polygon);
    }
  });

  // Adicionar listeners para edição e remoção
  polygon.addListener('mouseup', function () {
    salvarPolygonsNoCache();
    // Atualizar posição da etiqueta quando o polígono é editado
    atualizarLabelsPolygons();
  });

  polygon.addListener('rightclick', function () {
    // Remover polígono e sua etiqueta
    this.setMap(null);
    const index = allPolygons.indexOf(this);
    if (index > -1) {
      allPolygons.splice(index, 1);
    }

    // Remover etiqueta
    const polygonId = this._id;
    if (polygonLabelMarkers.has(polygonId)) {
      polygonLabelMarkers.get(polygonId).setMap(null);
      polygonLabelMarkers.delete(polygonId);
    }

    // Remover etiqueta do cache
    polygonLabels.delete(this._id);
    salvarPolygonsNoCache();

    // Atualizar legenda
    if (map) {
      criarLegenda(map, vendedorCores);
    }
  });

  return polygon;
}

// Função para atualizar todas as etiquetas dos polígonos
function atualizarLabelsPolygons() {
  // Recalcular posições das etiquetas
  allPolygons.forEach(polygon => {
    const polygonId = polygon._id;
    const label = polygonLabels.get(polygonId) || `Zona ${allPolygons.indexOf(polygon) + 1}`;

    // Calcular novo centro
    const bounds = new google.maps.LatLngBounds();
    polygon.getPath().forEach(latLng => {
      bounds.extend(latLng);
    });
    const novoCentro = bounds.getCenter();

    // Atualizar ou criar etiqueta
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

      // Limpar zonas existentes
      allPolygons.forEach(polygon => polygon.setMap(null));
      allPolygons = [];
      polygonLabels.clear();
      polygonLabelMarkers.clear();

      // Criar novos zonas
      polygonData.forEach(data => {
        criarPolygonDoCache(data);
      });

      salvarPolygonsNoCache();
      alert(`Importadas ${polygonData.length} zonas com sucesso!`);

      // Atualizar legenda com botão de análise
      if (map) {
        criarLegenda(map, vendedorCores);
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
  const zonaDetalhes = new Map(); // Para armazenar clientes por zona

  window.markers.forEach(marker => {
    const posicao = marker.getPosition();
    let zonaEncontrada = null;

    // Verificar se o marcador está dentro de algum zona
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

      // Adicionar aos detalhes da zona
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

  // Mostrar resumo por zona
  console.log('Resumo por zona:');
  zonaDetalhes.forEach((clientes, zonaNome) => {
    console.log(`${zonaNome}: ${clientes.length} clientes`);
  });

  // Retornar os dados para uso externo
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

  // Criar CSV com todos os marcadores e status
  const todosClientes = [...resultado.dentro, ...resultado.fora];

  if (todosClientes.length === 0) {
    alert('Nenhum marcador para exportar.');
    return;
  }

  // Criar cabeçalhos do CSV
  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude', 'Status', 'Zona'];

  // Criar linhas do CSV
  const linhasCSV = todosClientes.map(marcador => {
    return [
      csvCampo(marcador.Codigo),
      csvCampo(marcador.Nome), // Escapar aspas
      csvCampo(marcador.Vendedor),
      csvCampo(marcador.Dia),
      marcador.Latitude,
      marcador.Longitude,
      csvCampo(marcador.Status),
      csvCampo(marcador.Zona)
    ].join(',');
  });

  // Combinar headers e linhas
  const csvContent = [headers.join(','), ...linhasCSV].join('\n');

  // Criar e fazer download do arquivo
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

  // Criar CSV com marcadores dentro
  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude', 'Zona'];

  const linhasCSV = resultado.dentro.map(marcador => {
    return [
      csvCampo(marcador.Codigo),
      csvCampo(marcador.Nome),
      csvCampo(marcador.Vendedor),
      csvCampo(marcador.Dia),
      marcador.Latitude,
      marcador.Longitude,
      csvCampo(marcador.Zona)
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

  // Criar CSV com marcadores fora
  const headers = ['Codigo', 'Nome', 'Vendedor', 'Dia', 'Latitude', 'Longitude'];

  const linhasCSV = resultado.fora.map(marcador => {
    return [
      csvCampo(marcador.Codigo),
      csvCampo(marcador.Nome),
      csvCampo(marcador.Vendedor),
      csvCampo(marcador.Dia),
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

  // Criar CSV com resumo por zona
  const headers = ['Zona', 'Total de clientes', 'Vendedores'];

  const linhasCSV = [];
  resultado.zonaDetalhes.forEach((clientes, zonaNome) => {
    // Contar vendedores únicos
    const vendedores = [...new Set(clientes.map(c => c.Vendedor))].join('; ');
    linhasCSV.push([
      csvCampo(zonaNome),
      clientes.length,
      csvCampo(vendedores)
    ].join(','));
  });

  // Adicionar linha de total
  linhasCSV.push([
    'TOTAL',
    resultado.totalDentro,
    csvCampo([...new Set(resultado.dentro.map(c => c.Vendedor))].join('; '))
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
  // Verificar se o mapa já existe
  if (map) {
    // Se o mapa já existe, apenas ajustar os bounds e adicionar novos dados
    if (Lats && Lngs && Lats.length && Lngs.length) {
      const bounds = new google.maps.LatLngBounds();

      Lats.forEach((lat, index) => {
        bounds.extend(new google.maps.LatLng(lat, Lngs[index]));
      });

      map.fitBounds(bounds);
    }

    // Limpar marcadores antigos
    if (window.markers) {
      window.markers.forEach(marker => marker.setMap(null));
    }
    window.markers = [];

    // Adicionar novos marcadores
    if (coords && coords.length > 0) {
      adicionarClientesAoMapa(coords, map);
    }

    return; // Não recriar o mapa
  }

  // Criar novo mapa apenas na primeira vez
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

  // Ajustar bounds se coordenadas foram fornecidas
  if (Lats && Lngs && Lats.length && Lngs.length) {
    const bounds = new google.maps.LatLngBounds();

    Lats.forEach((lat, index) => {
      bounds.extend(new google.maps.LatLng(lat, Lngs[index]));
    });

    map.fitBounds(bounds);
  }

  // Inicializar array global de marcadores
  window.markers = [];

  // Adicionar marcadores se coordenadas foram fornecidas
  if (coords && coords.length > 0) {
    adicionarClientesAoMapa(coords, map);
  }

  // Configurar drawingManager
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

  // Adicionar listener para zonas completados
  google.maps.event.addListener(drawingManager, 'polygoncomplete', function (polygon) {
    const novaCor = gerarCorHex();
    polygon.setOptions({
      fillColor: novaCor,
      strokeColor: novaCor,
      fillOpacity: 0.3,
      strokeOpacity: 0.8
    });

    // Adicionar ID único ao polígono
    polygon._id = Date.now() + Math.random();

    // Adicionar ao array de zonas
    allPolygons.push(polygon);

    // Pedir nome para o polígono
    const zonaNumero = allPolygons.length;
    const nomePadrao = `Zona ${zonaNumero}`;

    // Criar prompt customizado
    criarPromptNomeZona(polygon, nomePadrao);

    // Salvar no cache
    salvarPolygonsNoCache();

    // Adicionar listeners para edição e remoção
    polygon.addListener('click', function (event) {
      // Se Ctrl+clique, editar nome
      if (event.domEvent.ctrlKey) {
        editarNomePolygon(polygon);
      }
    });

    polygon.addListener('mouseup', function () {
      salvarPolygonsNoCache();
      atualizarLabelsPolygons();
    });

    polygon.addListener('rightclick', function () {
      // Remover polígono e sua etiqueta
      this.setMap(null);
      const index = allPolygons.indexOf(this);
      if (index > -1) {
        allPolygons.splice(index, 1);
      }

      // Remover etiqueta
      const polygonId = this._id;
      if (polygonLabelMarkers.has(polygonId)) {
        polygonLabelMarkers.get(polygonId).setMap(null);
        polygonLabelMarkers.delete(polygonId);
      }

      // Remover etiqueta do cache
      polygonLabels.delete(this._id);
      salvarPolygonsNoCache();

      // Atualizar legenda
      if (map) {
        criarLegenda(map, vendedorCores);
      }
    });

    // Atualizar legenda com botão de análise
    criarLegenda(map, vendedorCores);
  });

  drawingManager.setMap(map);

  // Carregar zonas do cache
  carregarPolygonsDoCache();
}

// Função para criar prompt customizado para nome da zona
function criarPromptNomeZona(polygon, nomePadrao) {
  // Criar overlay para o prompt
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

  // nosemgrep: insecure-innerhtml, insecure-document-method -- dados escapados com escHtml/escJsAttr (revisto em 27-10-2026)
  overlay.innerHTML = `
    <h3 style="margin: 0 0 15px 0; color: #ccc;">Nome da zona</h3>
    <input type="text" id="zona-nome-input" 
           value="${escHtml(nomePadrao)}" 
           style="width: 100%; padding: 10px; margin-bottom: 15px; 
                  border: 2px solid #4285F4; border-radius: 4px; 
                  font-size: 16px; box-sizing: border-box;">
    <div style="display: flex; gap: 10px; justify-content: flex-end;">
      <button id="zona-nome-cancel" 
              style="padding: 8px 16px; background: #222121ff; 
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

  // Focar no input
  input.focus();
  input.select();

  // Eventos
  btnOk.addEventListener('click', function () {
    const nome = input.value.trim() || nomePadrao;
    polygonLabels.set(polygon._id, nome);
    criarLabelParaPolygon(polygon, nome);
    overlay.remove();
    salvarPolygonsNoCache();

    // Atualizar legenda
    if (map) {
      criarLegenda(map, vendedorCores);
    }
  });

  btnCancel.addEventListener('click', function () {
    // Usar nome padrão
    polygonLabels.set(polygon._id, nomePadrao);
    criarLabelParaPolygon(polygon, nomePadrao);
    overlay.remove();
    salvarPolygonsNoCache();

    // Atualizar legenda
    if (map) {
      criarLegenda(map, vendedorCores);
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

// Função para adicionar marcadores ao mapa (reutilizável)
function adicionarClientesAoMapa(coords, map) {
  const gruposPorVendedor = {};

  // Primeiro, agrupar coordenadas por vendedor
  coords.forEach((coord, index) => {
    const vendedor = coord.Vendedor || 'Sem Vendedor';
    if (!gruposPorVendedor[vendedor]) {
      gruposPorVendedor[vendedor] = [];
    }
    gruposPorVendedor[vendedor].push({
      ...coord,
      originalIndex: index
    });
  });

  // Para cada grupo de vendedor, criar marcadores com a mesma cor
  Object.keys(gruposPorVendedor).forEach(vendedor => {
    const grupo = gruposPorVendedor[vendedor];
    const corDoGrupo = getCorParaVendedor(vendedor);

    grupo.forEach(item => {
      const rawLat = parseFloat(item.Latitude);
      const rawLng = parseFloat(item.Longitude);

      var marker = new google.maps.Marker({
        position: { lat: rawLat, lng: rawLng },
        map: map,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 10,
          fillColor: corDoGrupo,
          fillOpacity: 1,
          strokeWeight: 1,
          strokeColor: '#000000'
        },
        title: `${item.Nome} - ${vendedor}`,
      });

      marker.CODIGO = item.Código;
      marker.NOME = item.Nome;
      marker.VENDEDOR = item.Vendedor;
      marker.DIA = item.Dia;
      marker.LATITUDE = item.Latitude;
      marker.LONGITUDE = item.Longitude;
      marker.COR = corDoGrupo;

      var infowindow = new google.maps.InfoWindow();

      google.maps.event.addListener(
        marker,
        "mouseover",
        (function (marker, infowindow) {
          return function () {
            var contentString =
              "<div>" +
              `<p style="color:#000000"><strong>CÓDIGO DO CLIENTE: </strong>${escHtml(marker.CODIGO)}</p>` +
              `<p style="color:#000000"><strong>CLIENTE: </strong>${escHtml(marker.NOME)}</p>` +
              `<p style="color:#000000"><strong>VENDEDOR: </strong>${escHtml(marker.VENDEDOR)}</p>` +
              `<p style="color:#000000"><strong>DIA DE VISITA: </strong>${escHtml(marker.DIA)}</p>` +
              `<p style="color:#000000"><strong>GRUPO: </strong><span style="color:${escHtml(marker.COR)}">● ${escHtml(marker.VENDEDOR)}</span></p>` +
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
    });
  });

  // Atualizar legenda
  criarLegenda(map, vendedorCores);
}

// Função para criar legenda de cores
function criarLegenda(map, vendedorCores) {
  // Remover legenda existente
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
    width: 320px;
  `;

  let legendHTML = '<h4 style="margin: 0 0 10px 0; font-size: 14px;color:#000000;">Clientes por rota</h4>';

  vendedorCores.forEach((cor, vendedor) => {
    legendHTML += `
      <div style="margin: 5px 0; display: flex; align-items: center;color:#000000;">
        <span style="display: inline-block; width: 12px; height: 12px; 
                     background-color: ${escHtml(cor)}; border: 1px solid #000; 
                     border-radius: 50%; margin-right: 8px;color:#000000;"></span>
        <span style="color:#000000;">${escHtml(vendedor || 'Sem Vendedor')}</span>
      </div>
    `;
  });

  // Adicionar seção de zonas
  legendHTML += `
    <hr style="margin: 10px 0;">
    <h4 style="margin: 10px 0; font-size: 14px;color:#000000;">Zonas criadas</h4>
    <div style="font-size: 11px; color: #000000; margin-bottom: 5px;">
      Dica: com a tecla Ctrl premida, clique no nome da zona para o editar
    </div>
    <div id="zonas-list" style="margin-bottom: 10px; max-height: 150px; overflow-y: auto;">
  `;

  // Listar zonas existentes
  allPolygons.forEach((polygon, index) => {
    const zonaNome = polygonLabels.get(polygon._id) || `Zona ${index + 1}`;
    const cor = polygon.fillColor || '#000000';
    legendHTML += `
      <div style="margin: 5px 0; padding: 5px; border: 1px solid #eee; border-radius: 3px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">
          <div style="display: flex; align-items: center;">
            <span style="display: inline-block; width: 12px; height: 12px; 
                         background-color: ${escHtml(cor)}; border: 1px solid #000; 
                         border-radius: 2px; margin-right: 8px;"></span>
            <span style="font-weight: bold; color:#000000;">${escHtml(zonaNome)}</span>
          </div>
          <div>
            <button onclick="editarNomePolygonById(${escJsVal(polygon._id)})" 
                    title="Editar nome"
                    style="padding: 2px 6px; font-size: 10px; background: #4285F4; color: white; border: none; border-radius: 2px; cursor: pointer; margin-right: 3px;">
              ✏️
            </button>
            <button onclick="removerZona(${escJsVal(polygon._id)})" 
                    title="Remover zona"
                    style="padding: 2px 6px; font-size: 10px; background: #EA4335; color: white; border: none; border-radius: 2px; cursor: pointer;">
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
    <div style="font-weight: bold; margin-bottom: 5px;">Análise de zonas</div>
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

  // nosemgrep: insecure-innerhtml, insecure-document-method -- dados escapados com escHtml/escJsAttr (revisto em 27-10-2026)
  legendDiv.innerHTML = legendHTML;

  // Adicionar ao mapa
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

    // Remover etiqueta
    if (polygonLabelMarkers.has(polygonId)) {
      polygonLabelMarkers.get(polygonId).setMap(null);
      polygonLabelMarkers.delete(polygonId);
    }

    // Remover etiqueta do cache
    polygonLabels.delete(polygonId);
    salvarPolygonsNoCache();

    // Atualizar legenda
    if (map) {
      criarLegenda(map, vendedorCores);
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

    // Atualizar a legenda
    if (map) {
      criarLegenda(map, vendedorCores);
    }
  }
}

// Função para carregar CSV (ajustada para não reinicializar o mapa)
function loadCSV(file) {
  if (!file) return;

  const selectStat = document.getElementById("selectStatus");
  const selectedStatus = selectStat ? selectStat.value : "Ativo";

  console.log("Estado aplicado:", selectedStatus);
  console.log("Novo ficheiro:", file.name);
  document.getElementById("csvDocName").textContent = "Documento: " + file.name;

  const maxRows = 10000;

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

  // Limpar dados anteriores
  //jsonData = [];
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
      document.getElementById('textClients').textContent = 'Total de clientes carregados: ' + count;
      console.log("Dados processados:", jsonData);

      const lats = jsonData.map((coords) => parseFloat(coords.Latitude));
      const lngs = jsonData.map((coords) => parseFloat(coords.Longitude));

      coords = jsonData.map((c) => ({
        Latitude: c.Latitude,
        Longitude: c.Longitude,
        Nome: c.Nome,
        Código: c.Código,
        Vendedor: c.Vendedor,
        Dia: c.Dia,
        Estado: c.Estado || c.Status,
      }));

      console.log("Coordenadas para optimização:", coords);

      // Usar initMap que agora verifica se o mapa já existe
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

// Funções para limpar dados quando necessário
function limparMapa() {
  vendedorCores.clear();
  jsonData = [];
  coords = [];
  count = 0;

  if (window.markers) {
    window.markers.forEach(marker => marker.setMap(null));
    window.markers = [];
  }

  const legendDiv = document.getElementById('legend');
  if (legendDiv) {
    legendDiv.remove();
  }

  limparPolygonsCache();
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

// Inicializar quando a página carregar

// Adicionar listener para o botão de download CSV
const btnDownloadCSV = document.getElementById("downloadCSV");
if (btnDownloadCSV) {
  btnDownloadCSV.addEventListener("click", () => {
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
}

// Adicionar CSS para as etiquetas com contorno
const existingStyle = document.querySelector('style[data-polygon-labels]');
if (existingStyle) {
  existingStyle.remove();
}

const style = document.createElement('style');
style.setAttribute('data-polygon-labels', 'true');
style.textContent = `
  /* Contorno para as etiquetas dos polígonos */
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
  
  /* Efeito de texto com contorno duplo */
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
  
  /* Animação para input de edição */
  #polygon-edit-input {
    animation: fadeIn 0.2s;
  }
  
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }
  
  /* Overlay para nome da zona */
  #zona-nome-overlay {
    animation: slideIn 0.3s;
    border: 2px solid #4285F4;
  }
  
  @keyframes slideIn {
    from { opacity: 0; transform: translate(-50%, -60%); }
    to { opacity: 1; transform: translate(-50%, -50%); }
  }
  
  /* Botões na legenda */
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
`;
document.head.appendChild(style);