# Descoberta automática de potenciais clientes, geofences e radar do dia

Data: 19-11-2026. Âmbito: Luanda e Ícolo e Bengo, 11 rotas (vendedores / promotores), portfólio de **higiene e
limpeza** (detergentes, sabões e lixívias, desinfectantes, limpeza doméstica e institucional, higiene pessoal e
descartáveis) e **alimentar** (massa, farinhas, arroz, bolachas, óleo de cozinha).

Código: `nucleo/servicos/descoberta.php` (taxonomia, qualidade, ICP Score, entrada externa),
`nucleo/servicos/prospeccao.php` (fontes, deduplicação, territórios, cálculo da noite), `nucleo/servicos/radar.php`
(radar do dia), `nucleo/api/v1/geo-entrada.php` (webhook) e `nucleo/api/v1/geo-radar.php`.

## 1. Arquitectura do pipeline

```
 OpenStreetMap ─┐  (semanal, toda a área)
 Google Places ─┼─► normalização ─► qualidade ─► deduplicação ─► ICP Score ─► território da rota ─► radar do dia
 webhook ───────┘  (n8n, Make,     taxonomia,     área,          fontes,       0–100           (geofence)        lugares livres
 v1/geo/entrada     Apify…)        telefone       portfólio      telefone,                     dia de visita     recuperar +
                                                  nome           sítio, GPS                                      conquistar
                                                                 + clientes                                      ▼
                                                                                         Prospecção (mapa e lista), Radar do dia,
                                                                                         Rotas propostas (rota cheia / zona sem rota)
```

| Etapa                       | Onde                                                     | Quando                                                                                                                    |
| --------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Ingestão OpenStreetMap      | `naval_geo_ler_osm` (Overpass)                           | uma vez por semana, de madrugada; também no botão «Recalcular agora» se ainda não houver leitura                          |
| Ingestão Google Places      | `naval_geo_google_passagem` (API New, `searchNearby`)    | todas as noites, até `GEO_GOOGLE_POR_NOITE` (40) pedidos e `GEO_GOOGLE_PEDIDOS_MES` (4500) por mês                        |
| Ingestão externa            | `POST v1/geo/entrada`                                    | quando a ferramenta externa enviar (ex.: n8n de hora a hora)                                                              |
| Normalização e qualidade    | `naval_desc_classificar`, `naval_desc_qualidade`         | à entrada de cada fonte (o que não passa não fica guardado)                                                               |
| Deduplicação                | `naval_geo_potenciais`                                   | em cada cálculo                                                                                                           |
| ICP Score e território      | `naval_desc_icp`, `naval_geo_atribuir`                   | em cada cálculo                                                                                                           |
| Cálculo                     | `naval_geo_calcular`                                     | todas as noites a partir das 04:00 e 10 minutos depois de uma entrada externa (a qualquer hora)                           |
| Geofences e rotas novas     | `naval_geo_rotas`, `naval_rp_noite`                      | em cada cálculo da noite: território de cada rota, ocupação, propostas de rota nova (Terreno › Rotas propostas)           |
| Radar do dia                | `naval_radar_dia` (`GET v1/geo/radar`)                   | quando se abre o separador «Radar do dia» da Prospecção                                                                   |

Premissas assumidas: a tarefa de 5 em 5 minutos do servidor está activa (é a mesma do resumo e das cargas); o Pinguim
BO é a fonte dos clientes e das coordenadas, completada pelo GPS do OLAP (`DIM_CLIENT`) para os clientes sem
coordenadas no Pinguim BO; as 11 geofences são os territórios das rotas (invólucro dos clientes de cada rota, que
cresce sozinho), não polígonos desenhados à mão (esses ficam nas Zonas de entrega).

## 2. Taxonomia e palavras-chave

Tipo de estabelecimento (OpenStreetMap `shop`/`amenity`/`tourism`, Google Places ou categoria externa) ⇒ afinidade e
potencial de volume (0 a 30). Tabela completa em `NAVAL_DESC_TIPOS`.

| Grupo                                   | Tipos (exemplos)                                                                  | Afinidade                    | Volume  |
| --------------------------------------- | --------------------------------------------------------------------------------- | ---------------------------- | ------- |
| Grosso e cash-and-carry                 | wholesale, wholesaler, cash_and_carry, warehouse                                  | higiene e limpeza + alimentar | 26–30   |
| Retalho alimentar                       | supermarket, hypermarket, grocery, convenience, general (cantina), market, kiosk  | higiene e limpeza + alimentar | 18–30   |
| Higiene e limpeza                       | chemist (drogaria), drugstore, houseware, home_goods_store, cosmetics, laundry    | higiene e limpeza            | 10–24   |
| Instituições                            | school, kindergarten, clinic, hospital, university                                | higiene e limpeza            | 14–22   |
| Restauração e alojamento                | bakery, pastry, canteen, catering, restaurant, fast_food, hotel, guest_house      | alimentar (+ limpeza)        | 10–22   |
| Alimentar especializado                 | beverages, deli, greengrocer, butcher, frozen_food, bar                           | alimentar                    | 6–12    |

Palavras no nome (sem acentos), que corrigem um tipo genérico ou vazio e dão bónus de volume (`NAVAL_DESC_PALAVRAS`):
«cash and carry», «grossista», «armazém», «distribuid…», «hipermercado», «supermercado», «minimercado», «mercearia»,
«cantina», «comércio geral», «comercial», «mercado», «bazar», «drogaria», «higiene», «limpeza», «detergente»,
«lavandaria», «utilidades», «panificadora», «padaria», «pastelaria», «catering», «refeitório», «restaurante»,
«hotel», «hospedaria», «pensão», «residencial», «colégio», «escola», «clínica», «hospital», «centro médico».

## 3. ICP Score (0 a 100)

| Critério                         | Pontos                                                                                    |
| -------------------------------- | ----------------------------------------------------------------------------------------- |
| Adequação ao portfólio           | as duas linhas 25 · uma linha 18                                                          |
| Potencial de volume              | tipo 0–30 + palavras do nome até 10                                                       |
| Contacto válido                  | telefone angolano (9 dígitos) 6 · sítio 2                                                 |
| Confiança das fontes             | 2 ou mais fontes 15 · Google 10 · fonte externa 8 · só OpenStreetMap 6                    |
| Viabilidade na zona              | cliente da rota a ≤ 300 m 15 · ≤ 800 m 9 · ≤ 1,5 km 4 · mais longe 0                      |
| Zona comercial                   | outros estabelecimentos a 300 m: 2 por cada, até 6                                        |

## 4. Deduplicação e qualidade dos dados

Recusado à entrada (com o motivo, no caso do webhook): sem coordenadas, coordenadas nulas, fora da área
(`GEO_AREA`, por omissão Luanda + Ícolo e Bengo), nome sem letras, fora do portfólio, sem nome e de pouco volume.

O mesmo estabelecimento em várias fontes junta-se num só ponto quando está a menos de 25 m com nome parecido (ou sem
nome e o mesmo tipo), ou quando tem o mesmo telefone ou o mesmo sítio até 500 m. Deixa de ser potencial quando há um
cliente a menos de 30 m (ou a menos de 80 m com nome parecido); se já tinha sido mostrado, conta como **convertido**.
Os vendedores registam o resultado da visita; «não existe / fechou» tira-o de vez. Pelas regras do Google, de cada
lugar só o identificador fica para sempre: as coordenadas apagam-se ao fim de 30 dias sem voltar a ser visto. Não se
guardam dados pessoais: só o nome, a categoria, a morada e o contacto públicos do estabelecimento.

## 5. Contrato do webhook (fontes externas)

```http
POST /api/index.php/v1/geo/entrada
Authorization: Bearer <GEO_ENTRADA_CHAVE>
Content-Type: application/json

{
  "fonte": "apify-diretorio-angola",
  "estabelecimentos": [
    {
      "id_externo": "AO-LDA-000123",
      "nome": "Armazém Kianda Comércio Geral",
      "lat": -8.8312,
      "lng": 13.2655,
      "categoria": "wholesaler",
      "telefone": "+244 923 000 000",
      "website": "https://kianda.co.ao",
      "morada": "Rua da Missão, Ingombota"
    }
  ]
}
```

Resposta `200`:

```json
{ "ok": true, "recebidos": 1, "aceites": 1, "novos": 1, "rejeitados": [] }
```

Regras: até 500 estabelecimentos e 1 MB por pedido, 120 pedidos por minuto por IP; `401` com chave errada ou em falta;
`404` se a entrada estiver desligada (sem `GEO_ENTRADA_CHAVE`); `400` sem a lista; `413` acima dos limites. Campos
aceites também em inglês (`name`, `latitude`, `longitude`, `category`, `phone`, `site`, `address`, `id`). A
`categoria` usa os tipos da taxonomia (secção 2), do Google Places ou do OpenStreetMap; uma categoria desconhecida
aceita-se se o nome tiver uma palavra-chave. Reenviar o mesmo `id_externo` da mesma `fonte` actualiza-o (não duplica)
e mantém-no vivo; 90 dias sem reenvio (`GEO_EXTERNOS_DIAS`) apagam-no. O novo cálculo corre 10 minutos depois do
último envio.

### Exemplo de fluxo n8n (orquestrador)

1. **Schedule Trigger** — de hora a hora (ou diário às 02:00).
2. **HTTP Request / Apify** — corre o actor de diretórios (ex.: Google Maps Scraper ou um diretório empresarial de
   Angola) com as pesquisas «mercearia Luanda», «armazém Viana», «cantina Cacuaco», «padaria Catete»,
   «drogaria Talatona»… e a caixa da área.
3. **Code (JavaScript)** — mapeia cada resultado para `{id_externo, nome, lat, lng, categoria, telefone, website,
   morada}`; deita fora os sem coordenadas.
4. **Split In Batches** — de 500 em 500.
5. **HTTP Request** — `POST` para `https://<portal>/api/index.php/v1/geo/entrada`, autenticação «Header Auth» com
   `Authorization: Bearer {{$env.GEO_ENTRADA_CHAVE}}`, corpo `{"fonte": "n8n-apify", "estabelecimentos": $json.lote}`.
6. **IF** `rejeitados.length > 0` — guarda os motivos numa folha para afinar as pesquisas.

O enriquecimento e a pontuação ficam no portal (uma só regra para todas as fontes); o n8n só recolhe e envia.

## 6. Geofences e visitas por dia

- **Território (geofence) de cada uma das 11 rotas**: invólucro convexo dos clientes da rota com 300 m de margem
  (`naval_geo_territorio`); um potencial cliente entra na rota que domina os 5 clientes mais próximos até 1,5 km e no
  dia em que essa rota visita a zona. Mais longe fica «fora das rotas»; muitos juntos (40 em ~2 km) fazem uma «zona sem
  rota», candidata a rota nova.
- **Capacidade**: `GEO_VISITAS_DIA` (30) × `GEO_DIAS_SEMANA` (6) visitas por semana e por vendedor; acima de 90 % durante
  14 dias seguidos, a rota está cheia e nasce uma proposta de divisão (Terreno › Rotas propostas), com o impacto em
  visitas e carga, para aprovar.
- **Radar do dia** (Prospecção › «Radar do dia»): para cada rota e dia, lugares livres = 30 − visitas do plano; até
  `GEO_RADAR_MAX` (8) sugestões intercaladas — **recuperar** clientes regulares parados perto do caminho (até 2 km; os de
  60 dias ou mais primeiro) e **conquistar** potenciais clientes do território, do dia da zona, a até
  `GEO_RADAR_DESVIO` (1 km) de uma visita do dia, pelo ICP Score menos o desvio. Quando o plano passa a capacidade, não
  há sugestões e as visitas «em dia» de menor valor ficam para a próxima (nunca as paradas ou a abrandar). Assim os
  potenciais novos entram na carga diária sem sobrecarregar nenhuma rota.

## 7. Plano de implementação

1. **Instalar a versão** (Administração › Actualizações). O OpenStreetMap passa a cobrir toda a área na próxima noite
   (ou já, com «Recalcular agora» na Prospecção).
2. **Google Places (opcional)**: criar uma chave de servidor só para a «Places API (New)», restringida ao IP do servidor,
   e pôr `GOOGLE_PLACES_API_KEY` no `.env`. Os 4500 pedidos por mês ficam dentro dos gratuitos; acompanhar o contador no
   rodapé da Prospecção.
3. **Amostra (sandbox)**: gerar a chave `GEO_ENTRADA_CHAVE`, enviar do n8n uma amostra de 20 a 50 estabelecimentos de um
   bairro conhecido e conferir no mapa (aceites, rejeitados e motivos, deduplicação com os clientes).
4. **Afinar**: ajustar as pesquisas do Apify/Scrapfly pelos motivos de rejeição; conferir com os supervisores o ICP dos
   primeiros 50 (os vendedores registam o resultado de cada visita: o funil em «Resultados» mede a conversão).
5. **Produção**: ligar o fluxo n8n de hora a hora; vigiar o custo do Apify/Scrapfly na própria ferramenta e o do Google
   no contador do portal; o registo de actividade `geo_entrada` guarda cada envio (fonte, aceites e novos).
6. **Operação**: os supervisores usam o Radar do dia de manhã; os vendedores registam o resultado; as rotas cheias e as
   zonas sem rota chegam como propostas para aprovar.
