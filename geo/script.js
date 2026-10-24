"use strict";
let selectedCategory = null;
let selectedStatus = "Ativo";
let jsonData = [];
let coords = [];
let newMarkers = [];
let categoryValue = null;
let newSequenceCreated = null;
let optimizedRoute = false;

function initMap(Lats, Lngs, coords, coordsToOptimize) {
  var map = new google.maps.Map(document.getElementById("map"), {
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

  let dist = -1;
  if (coords) {
    dist = coords.findIndex((coord) => {
      return coord["Tipo de estabelecimento"] === "DISTRIBUIDOR";
    });
    console.log("Distribuidor index:", dist);
  }

  var oms = new OverlappingMarkerSpiderfier(map, {
    keepSpiderfied: true,
    circleSpiralSwitchover: 12,
    nearbyDistance: 5,
  });

  if (Lats && Lngs && Lats.length && Lngs.length) {
    map.fitBounds({
      west: Lngs.reduce((a, b) => Math.min(a, b)),
      east: Lngs.reduce((a, b) => Math.max(a, b)),
      north: Lats.reduce((a, b) => Math.max(a, b)),
      south: Lats.reduce((a, b) => Math.min(a, b)),
    });
  }

  var directionsService = new google.maps.DirectionsService();
  var directionsRenderer = new google.maps.DirectionsRenderer({
    map: map,
    suppressMarkers: false,
  });

  var navigationSymbol = {
    path: google.maps.SymbolPath.FORWARD_OPEN_ARROW,
    scale: 3,
    strokeColor: "#ffffffbd",
  };

  function normalizePoint(p, decimals = 6) {
    return {
      lat: Number(parseFloat(p.lat).toFixed(decimals)),
      lng: Number(parseFloat(p.lng).toFixed(decimals)),
    };
  }

  function isSameLocation(a, b, tol = 0.000001) {
    return Math.abs(a.lat - b.lat) <= tol && Math.abs(a.lng - b.lng) <= tol;
  }

  function splitWaypointsIntoSegments(waypoints, maxPointsPerRequest) {
    var segments = [];
    if (!Array.isArray(waypoints) || waypoints.length === 0) return segments;
    var step = Math.max(1, maxPointsPerRequest - 1);
    for (var i = 0; i < waypoints.length; i += step) {
      var segment = waypoints.slice(
        i,
        Math.min(i + maxPointsPerRequest, waypoints.length),
      );
      if (segment.length > 1) segments.push(segment);
    }
    return segments;
  }

  let Marker = [];
  let latLngOrigin = null;
  let origin = null;
  let latOr = false;
  let markersListener = false;

  if (coords) {
    for (var i = 0; i < coords.length; ++i) {
      const rawLat = parseFloat(coords[i].Latitude);
      const rawLng = parseFloat(coords[i].Longitude);
      const normalizedPos = normalizePoint({ lat: rawLat, lng: rawLng }, 6);

      const svgMarker = {
        path: "M12,2C8.13,2,5,5.13,5,9c0,5.25,7,13,7,13s7-7.75,7-13C19,5.13,15.87,2,12,2z M12,11.5c-1.38,0-2.5-1.12-2.5-2.5s1.12-2.5,2.5-2.5s2.5,1.12,2.5,2.5S13.38,11.5,12,11.5z",
        fillColor: "#1A365D",
        fillOpacity: 1,
        strokeWeight: 1,
        strokeColor: "#ffffff",
        rotation: 0,
        scale: 2,
        anchor: new google.maps.Point(12, 22),
        labelOrigin: new google.maps.Point(12, -10)
      };

      var marker = new google.maps.Marker({
        position: { lat: normalizedPos.lat, lng: normalizedPos.lng },
        map: map,
        title: coords[i].Nome,
        icon: svgMarker,
        label: {
          text: coords[i].Nome,
          color: "#ffffff",
          className: "marker-label",
        },
      });

      oms.addMarker(marker);

      marker._coord = normalizedPos;

      marker.CODIGO = coords[i].Código;
      marker.NOME = coords[i].Nome;
      marker.VENDEDOR = coords[i].Vendedor;
      marker.DIA = coords[i].Dia;
      marker.LATITUDE = coords[i].Latitude;
      marker.LONGITUDE = coords[i].Longitude;

      Marker.push(marker);
    }
    
    // Configurar o clique globalmente no OMS para não quebrar o "espalhar"
    oms.addListener("click", function (marker) {
      const detailsCard = document.getElementById("markerDetailsCard");
      if(detailsCard) {
        detailsCard.classList.remove("empty");
        detailsCard.innerHTML = `
          <div class="detail-row">
            <span class="detail-label">Código do Cliente</span>
            <span class="detail-value">${marker.CODIGO}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Cliente</span>
            <span class="detail-value">${marker.NOME}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Distribuidor / Rota</span>
            <span class="detail-value">${marker.VENDEDOR}</span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Dia de Visita</span>
            <span class="detail-value">${marker.DIA}</span>
          </div>
        `;
      }
      map.panTo(marker.getPosition());
      map.setZoom(16);
    });

    // Pesquisa no mapa
    const searchInput = document.getElementById("searchInput");
    const searchResults = document.getElementById("searchResults");
    
    if (searchInput && searchResults) {
      // Remover listener antigo para não duplicar ao carregar novo CSV
      const newSearchInput = searchInput.cloneNode(true);
      searchInput.parentNode.replaceChild(newSearchInput, searchInput);
      
      newSearchInput.addEventListener("input", function (e) {
        const term = e.target.value.toLowerCase().trim();
        searchResults.innerHTML = "";
        
        if (!term) {
          searchResults.style.display = "none";
          return;
        }
        
        const results = Marker.filter(m => 
          (m.NOME && m.NOME.toLowerCase().includes(term)) || 
          (m.CODIGO && m.CODIGO.toString().toLowerCase().includes(term)) ||
          (m.VENDEDOR && m.VENDEDOR.toLowerCase().includes(term))
        ).slice(0, 10);
        
        if (results.length > 0) {
          searchResults.style.display = "flex";
          results.forEach(m => {
            const div = document.createElement("div");
            div.className = "search-item";
            div.textContent = `${m.CODIGO} - ${m.NOME}`;
            div.onclick = function() {
              map.panTo(m.getPosition());
              map.setZoom(18);
              
              const detailsCard = document.getElementById("markerDetailsCard");
              if(detailsCard) {
                detailsCard.classList.remove("empty");
                detailsCard.innerHTML = `
                  <div class="detail-row">
                    <span class="detail-label">Código do Cliente</span>
                    <span class="detail-value">${m.CODIGO}</span>
                  </div>
                  <div class="detail-row">
                    <span class="detail-label">Cliente</span>
                    <span class="detail-value">${m.NOME}</span>
                  </div>
                  <div class="detail-row">
                    <span class="detail-label">Distribuidor / Rota</span>
                    <span class="detail-value">${m.VENDEDOR}</span>
                  </div>
                  <div class="detail-row">
                    <span class="detail-label">Dia de Visita</span>
                    <span class="detail-value">${m.DIA}</span>
                  </div>
                `;
              }
              
              searchResults.style.display = "none";
              newSearchInput.value = "";
            };
            searchResults.appendChild(div);
          });
        } else {
          searchResults.style.display = "none";
        }
      });
    }
  }

  document
    .getElementById("startRouting")
    .addEventListener("click", startRouting);

  function startRouting() {
    optimizedRoute = true;
    let normalizedPoints = coordsToOptimize.map((p) => normalizePoint(p, 6));

    let orderedPoints = tspLocal(normalizedPoints, dist);

    let kmTotal = totalDistance(orderedPoints);
    console.log("Distância total percorrida:", kmTotal.toFixed(2), "km");

    //let lastClientToDist = tspLocal(orderedPoints[15], dist)

    //let kmTotalDC = totalDistance(lastClientToDist)

    document.getElementById("totalDistance").textContent =
      "Distância percorrida do distribuidor ao último cliente: " +
      kmTotal.toFixed(2) +
      "km";

    orderedPoints = orderedPoints.map((p) => normalizePoint(p, 6));

    console.log("Ordem dos pontos (TSP):", orderedPoints);

    let segments = splitWaypointsIntoSegments(orderedPoints, 22);

    console.log("Segments:", segments);

    if (segments.length === 0) {
      console.warn("Nenhum segmento criado — rota pequena demais?");
      return;
    }

    let segOffset = [];
    let running = 0;

    for (let si = 0; si < segments.length; si++) {
      segOffset[si] = running;
      running += segments[si].length - 1;
    }

    // Opcional: limpa labels antigos
    //Marker.forEach((m) => m.setLabel({ text: "", className: ".marker-label" }));

    // Marca INÍCIO e FIM (se existirem)
    const startPoint = orderedPoints[0];
    const endPoint = orderedPoints[orderedPoints.length - 1];

    const startMarker = Marker.find((m) =>
      isSameLocation(m._coord, startPoint),
    );

    if (startMarker)
      startMarker.setLabel({
        text: "««INÍCIO»»",
        color: "##181f2e",
        fontSize: "13px",
        fontWeight: "900",
      });

    const endMarker = Marker.find((m) => isSameLocation(m._coord, endPoint));
    if (endMarker) endMarker.setLabel({ text: "««FIM»»" });

    // percorre segmentos sequencialmente (usa for para capturar índice corretamente)
    for (let s = 0; s < segments.length; s++) {
      // captura segment em variáveis locais
      let segment = segments[s];

      // monta waypoints (exclui origem do segmento)
      let waypts = [];
      for (let i = 1; i < segment.length; i++) {
        waypts.push({
          location: segment[i],
          stopover: true,
        });
      }

      (function (seg, segIndex) {
        directionsService.route(
          {
            origin: seg[0],
            destination: seg[seg.length - 1],
            waypoints: waypts,
            optimizeWaypoints: true,
            travelMode: google.maps.TravelMode.DRIVING,
          },
          function (result, status) {
            if (status === google.maps.DirectionsStatus.OK || status === "OK") {
              let my_route = result.routes[0];

              // desenha polyline
              var route = new google.maps.Polyline({
                path: result.routes[0].overview_path,
                map: map,
                strokeColor: "#2B6CB0",
                strokeOpacity: 1,
                strokeWeight: 6,
                icons: [
                  { icon: navigationSymbol, offset: "1%", repeat: "100px" },
                ],
              });

              for (let i = 0; i < seg.length - 1; i++) {
                // waypointIndex é a ordem local (posição na lista de waypoints enviada)
                let waypointIndexLocal = my_route.waypoint_order[i];

                // globalIndex: offset acumulado do segmento + waypointIndexLocal + 1 (para começar em 1)
                let globalIndex = segOffset[segIndex] + waypointIndexLocal + 1;

                // Protecção: se globalIndex ultrapassar, continua
                if (globalIndex <= 0 || globalIndex >= orderedPoints.length) {
                  // se for inválido, pula
                  console.warn("Global index inválido:", globalIndex);
                  continue;
                }

                let targetPoint = orderedPoints[globalIndex];
                if (!targetPoint) {
                  console.warn(
                    "Target point não encontrado para globalIndex:",
                    globalIndex,
                  );
                  continue;
                }

                let waypointMarker = Marker.find((m) =>
                  isSameLocation(m._coord, targetPoint),
                );

                if (waypointMarker) {
                  const currentText = waypointMarker.getLabel().text;

                  function addDia(dia) {
                    newMarkers.push({
                      "CODIGO ": waypointMarker.CODIGO,
                      "NOME DO CLIENTE": waypointMarker.NOME,
                      "DISTRIBUIDOR/ROTA": waypointMarker.VENDEDOR,
                      LATITUDE: waypointMarker.LATITUDE,
                      LONGITUDE: waypointMarker.LONGITUDE,
                      "DIA DE VISITA": dia,
                      SEQUÊNCIA: globalIndex,
                    });
                  }
                  let dia = "";
                  switch (selectedCategory) {
                    case "2ª FEIRA - A":
                      for (let i = 1; i <= 3; ++i) {
                        switch (i) {
                          case 1:
                            dia = "2ª FEIRA";

                            break;
                          case 2:
                            dia = "5ª FEIRA";
                            break;

                          case 3:
                            dia = "SÁBADO";

                            break;
                        }
                        addDia(dia);
                      }
                      break;

                    case "3ª FEIRA - B":
                      for (let i = 1; i <= 2; ++i) {
                        switch (i) {
                          case 1:
                            dia = "3ª FEIRA";

                            break;
                          case 2:
                            dia = "6ª FEIRA";
                            break;
                        }
                        addDia(dia);
                      }

                      break;

                    case "4ª FEIRA - C":
                      dia = "4ª FEIRA";
                      addDia(dia);
                      break;

                    case "2ª FEIRA":
                      dia = "2ª FEIRA";
                      addDia(dia);
                      break;

                    case "2ª FEIRA":
                      dia = "2ª FEIRA";
                      addDia(dia);
                      break;

                    case "3ª FEIRA":
                      dia = "3ª FEIRA";
                      addDia(dia);
                      break;

                    case "4ª FEIRA":
                      dia = "4ª FEIRA";
                      addDia(dia);
                      break;

                    case "5ª FEIRA":
                      dia = "5ª FEIRA";
                      addDia(dia);
                      break;

                    case "6ª FEIRA":
                      dia = "6ª FEIRA";
                      addDia(dia);
                      break;

                    case "SÁBADO":
                      dia = "SÁBADO";
                      addDia(dia);
                      break;

                    case "DOMINGO":
                      dia = "DOMINGO";
                      addDia(dia);
                      break;

                      break;
                  }

                  waypointMarker.setLabel({
                    text: "" + globalIndex, //+ " - " + currentText
                    color: "#ffffff",
                    className: "sequence-label",
                  });
                } else {
                  console.warn(
                    "Marcador não encontrado para ponto:",
                    targetPoint,
                    "globalIndex:",
                    globalIndex,
                  );
                }
              }

              console.log(newMarkers);

              newSequenceCreated = Papa.unparse(newMarkers, {
                delimiter: ",",
              });
            } else {
              console.error("Directions request failed with status: " + status);
            }
          },
        );
      })(segment, s);
    } // fim for segments
    // fim startRouting
  }
}

// Os listeners de selectCategory e selectStatus são definidos no DOMContentLoaded
document.addEventListener("DOMContentLoaded", () => {
  const selectCategory = document.getElementById("selectCategory");
  
  if (selectCategory) {
    // Definir a categoria com base no valor que está selecionado por padrão
    selectedCategory = selectCategory.value;
    console.log("Categoria padrão inicializada:", selectedCategory);

    // Atualizar selectedCategory sempre que o utilizador muda a opção
    selectCategory.addEventListener("change", () => {
      selectedCategory = selectCategory.value;
      console.log("Nova categoria selecionada:", selectedCategory);
      
      // Opcionalmente atualizar painéis da interface se necessário
      const textCategory = document.getElementById("textCategory");
      if(textCategory) {
        textCategory.textContent = "Categoria: " + selectedCategory;
      }
    });
  }

  const selectStatus = document.getElementById("selectStatus");
  if (selectStatus) {
    selectedStatus = selectStatus.value;
    console.log("Estado padrão inicializado:", selectedStatus);

    selectStatus.addEventListener("change", () => {
      selectedStatus = selectStatus.value;
      console.log("Novo estado selecionado:", selectedStatus);
    });
  }
});

function loadButtonClicked() {
  if (!selectedCategory || selectedCategory === "") {
    window.alert(
      "Por favor selecione uma categoria antes de carregar o ficheiro.",
    );
    return;
  }
}

function handleLoadClick() {
  const selectCat = document.getElementById("selectCategory");
  if (selectCat) {
    selectedCategory = selectCat.value;
  }

  if (!selectedCategory || selectedCategory === "") {
    window.alert(
      "Por favor selecione uma categoria antes de carregar o ficheiro.",
    );
    return;
  }

  const selectStat = document.getElementById("selectStatus");
  if (selectStat) {
    selectedStatus = selectStat.value;
  }

  const csvInput = document.getElementById("csvFile");

  csvInput.value = "";

  csvInput.onclick = () => {
    console.clear();
    console.log("A aguardar novo ficheiro...");
  };

  csvInput.onchange = (event) => handleFileSelect(event, selectedCategory);

  csvInput.click();
}

function handleFileSelect(event, categoria) {
  let newCategoria = categoria;
  switch (categoria) {
    case "2ª FEIRA - A":
      newCategoria = "2ª FEIRA";
      break;
    case "3ª FEIRA - B":
      newCategoria = "3ª FEIRA";
      break;
    case "4ª FEIRA - C":
      newCategoria = "4ª FEIRA";
      break;
  }
  const file = event.target.files[0];
  jsonData = [];

  if (!file) return;

  const selectStat = document.getElementById("selectStatus");
  const currentStatus = selectStat ? selectStat.value : selectedStatus;

  console.log("Categoria aplicada:", newCategoria);
  console.log("Estado aplicado:", currentStatus);
  console.log("Novo ficheiro:", file.name);

  document.getElementById("csvDocName").textContent = "Documento: " + file.name;

  const maxRows = 200;

  function filterRow(row) {
    const rowStatus = (row.Estado !== undefined && row.Estado !== null) ? String(row.Estado).trim() :
                      (row.Status !== undefined && row.Status !== null) ? String(row.Status).trim() : "";
    const statusMatch = currentStatus === "Todos" || (rowStatus.toLowerCase() === currentStatus.toLowerCase());

    return (
      (row.Dia === newCategoria || row.Dia === "DOMINGO") &&
      (row["Tipo de estabelecimento"] === "DISTRIBUIDOR" ||
        row["Tipo de estabelecimento"] !== "DISTRIBUIDOR") &&
      row.Nome !== "Cliente Eventual" &&
      statusMatch &&
      row.Latitude &&
      row.Latitude !== " "
    );
  }

  let count = 0;

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
      document.getElementById("textClients").textContent =
        "Total de clientes carregados: " + (count - 1);
      console.log(jsonData);

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

      const coordsToOptimize = jsonData.map((c) => ({
        lat: Number(parseFloat(c.Latitude).toFixed(6)),
        lng: Number(parseFloat(c.Longitude).toFixed(6)),
      }));

      console.log(coordsToOptimize);

      initMap(lats, lngs, jsonData, coordsToOptimize);
    },
  });
}

function distance(a, b) {
  const R = 6371;
  const dLat = ((b.lat - a.lat) * Math.PI) / 180;
  const dLng = ((b.lng - a.lng) * Math.PI) / 180;
  const lat1 = (a.lat * Math.PI) / 180;
  const lat2 = (b.lat * Math.PI) / 180;

  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

function nearestNeighbor(points, startIndex) {
  let unvisited = points.slice();
  let route = [];
  let current = unvisited.splice(startIndex, 1)[0];

  route.push(current);

  while (unvisited.length > 0) {
    let nearestIndex = 0;
    let nearestDist = distance(current, unvisited[0]);

    for (let i = 1; i < unvisited.length; i++) {
      let d = distance(current, unvisited[i]);
      if (d < nearestDist) {
        nearestDist = d;
        nearestIndex = i;
      }
    }
    current = unvisited.splice(nearestIndex, 1)[0];
    route.push(current);
  }

  return route;
}

function twoOpt(route) {
  let improved = true;

  while (improved) {
    improved = false;

    for (let i = 1; i < route.length - 2; i++) {
      for (let j = i + 1; j < route.length - 1; j++) {
        let A = route[i - 1];
        let B = route[i];
        let C = route[j];
        let D = route[j + 1];

        let currentDist = distance(A, B) + distance(C, D);
        let newDist = distance(A, C) + distance(B, D);

        if (newDist < currentDist) {
          // inverte o segmento // Comunicaç
          let reversed = route.slice(i, j + 1).reverse();
          route.splice(i, j - i + 1, ...reversed);

          improved = true;
        }
      }
    }
  }

  return route;
}

function tspLocal(points, startIndex) {
  // pontos já normalizados quando chamados
  let routeNN = nearestNeighbor(points, startIndex);
  let optimized = twoOpt(routeNN);

  return optimized;
}

function totalDistance(route) {
  let total = 0;

  for (let i = 0; i < route.length - 1; i++) {
    total += distance(route[i], route[i + 1]);
  }

  return total; // km
}

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
      "Sem rotas por otimizar:\n1- Selecione um ficheiro\n2 - Click no ícone optimizar\n3 - Optimizar Rota",
    );
  }
});

// Funcionalidade de Pesquisa de Marcadores
document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("searchInput");
  const searchResults = document.getElementById("searchResults");

  if (searchInput && searchResults) {
    searchInput.addEventListener("input", (e) => {
      const termo = e.target.value.toLowerCase();
      searchResults.innerHTML = "";
      
      if (termo.length < 2) return;

      const resultados = Marker.filter(m => 
        (m.NOME && m.NOME.toLowerCase().includes(termo)) || 
        (m.CODIGO && m.CODIGO.toString().toLowerCase().includes(termo)) ||
        (m.VENDEDOR && m.VENDEDOR.toLowerCase().includes(termo))
      ).slice(0, 10);

      resultados.forEach(m => {
        const item = document.createElement("div");
        item.className = "search-item";
        item.innerHTML = `<strong>${m.CODIGO}</strong> - ${m.NOME}`;
        item.addEventListener("click", () => {
          google.maps.event.trigger(m, 'click');
          searchResults.innerHTML = "";
          searchInput.value = m.NOME;
        });
        searchResults.appendChild(item);
      });
    });
  }
});
