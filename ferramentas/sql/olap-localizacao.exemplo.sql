-- Localização registada pela aplicação de vendas e pelo registo de clientes, lida do servidor OLAP (só SELECT).
-- Copiar para ferramentas/sql/olap-localizacao.sql e ajustar os nomes das tabelas e colunas ao OLAP real.
-- Parâmetros: :de e :ate (AAAA-MM-DD HH:MM:SS, o dia pedido). Colunas obrigatórias: DATA_HORA, ROTA, LATITUDE,
-- LONGITUDE; facultativas: DOCUMENTO (n.º do documento de venda), CLIENTE (código do cliente no Pinguim BO),
-- PRECISAO (metros) e TIPO (venda | cliente | ponto).
SELECT
    CONVERT(VARCHAR(19), L.DATA_HORA, 120) AS DATA_HORA,
    L.ROTA,
    CAST(L.LATITUDE AS FLOAT) AS LATITUDE,
    CAST(L.LONGITUDE AS FLOAT) AS LONGITUDE,
    L.DOCUMENTO,
    L.CLIENTE,
    CAST(L.PRECISAO AS FLOAT) AS PRECISAO,
    L.TIPO
FROM dbo.LOCALIZACAO L
WHERE L.DATA_HORA >= :de
  AND L.DATA_HORA < :ate
ORDER BY L.ROTA, L.DATA_HORA
