-- Portal RTM | GeoMobilidade: clientes com coordenadas GPS no Pinguim BO (só leitura).
-- Só é preciso se o portal não encontrar sozinho as colunas de latitude e longitude na tabela ENTITY.
-- Para usar: copiar para ferramentas/sql/geo-clientes.sql, ajustar os nomes da tabela e das colunas e guardar.
-- Colunas obrigatórias: CODIGO (o ID do cliente usado nas vendas, DOCHEADER.ENTITY_ID), NOME, LATITUDE, LONGITUDE.
-- Coluna facultativa: CRIADO (data do registo, AAAA-MM-DD).
SELECT  E.ID                                   AS CODIGO,
        E.NAME                                 AS NOME,
        CAST(E.LATITUDE  AS FLOAT)             AS LATITUDE,
        CAST(E.LONGITUDE AS FLOAT)             AS LONGITUDE,
        CONVERT(VARCHAR(10), E.CREATION_DATE, 120) AS CRIADO
FROM    ENTITY E
WHERE   E.LATITUDE  IS NOT NULL AND E.LATITUDE  <> 0
  AND   E.LONGITUDE IS NOT NULL AND E.LONGITUDE <> 0
  AND   E.DELETED = 0;
