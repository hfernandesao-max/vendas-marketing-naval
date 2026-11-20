# Pedido ao fornecedor do Pinguim BO: API de criação de documentos

Para: equipa técnica do Pinguim BO. De: Grupo Naval — Portal RTM. Data: 20-11-2026. Versão 1.0.

## Objectivo

O Grupo Naval passa a trabalhar a logística das carrinhas (autovenda) no Portal RTM. Os documentos continuam a ser
gerados, numerados e assinados **pelo Pinguim BO**, enquanto software certificado. Pedimos uma API para o portal
**ordenar** a criação desses documentos, sem ninguém os digitar no Pinguim BO.

Hoje o portal usa só a exportação REST (XML) e consultas SQL de leitura. O portal não escreve, nem vai escrever, nas
tabelas do Pinguim BO.

## Documentos pedidos

| #  | Documento                                       | Origem → destino                                    | Prioridade |
| -- | ----------------------------------------------- | --------------------------------------------------- | ---------- |
| 1  | Guia de Remessa / transferência de stock        | armazém principal (1150, 1151) → viatura (100010NN) | alta       |
| 2  | Devolução / transferência                       | viatura (100010NN) → armazém principal              | alta       |
| 3  | Regularização de inventário da viatura          | viatura, com motivo                                 | média      |
| 4  | Consulta do estado de um pedido                 | —                                                   | alta       |

## Requisitos da API

1. **HTTPS**, JSON (ou XML, se for o padrão do Pinguim BO), na rede interna; autenticação por conta técnica própria
   (token ou certificado de cliente), só com permissão para estes documentos e estes armazéns.
2. **Idempotência**: cada pedido leva uma chave única do portal (`chave_pedido`, ex.: `PO-2026-000412/guia`). O mesmo
   pedido enviado duas vezes devolve o mesmo documento, nunca cria dois.
3. **Validações do Pinguim BO** (stock disponível, artigo activo, armazém e série válidos), com erros de negócio
   distinguíveis dos técnicos (ex.: HTTP 422 com código e texto, 5xx para falhas temporárias).
4. **Referência externa** gravada no documento e visível nas consultas SQL (o número da PO do portal), para conferência.
5. **Resposta síncrona** com o número completo do documento, a série, o identificador interno (`DOCHEADER.ID`) e o hash
   ou assinatura, ou uma resposta assíncrona com consulta de estado.
6. **Operador**: o documento fica em nome da conta técnica e guarda o utilizador do portal que deu a ordem (campo de
   observações ou utilizador externo).
7. **Ambiente de testes** com os mesmos armazéns, para validar antes da produção.

## Exemplo de pedido (proposta; aceitamos o formato do Pinguim BO)

```http
POST /api/documentos
Authorization: Bearer <token da conta técnica>
Content-Type: application/json
Idempotency-Key: PO-2026-000412/guia

{
  "tipo": "GUIA_REMESSA",
  "data": "2026-11-20",
  "armazem_origem": "1151",
  "armazem_destino": "10001008",
  "referencia_externa": "PO-2026-000412",
  "utilizador_externo": "bo.maria",
  "observacoes": "Carga da rota 1008 conferida no cais por fiel.ana às 07:20",
  "linhas": [
    { "artigo": "10000332", "quantidade": 400, "unidade": "UN" },
    { "artigo": "10000292", "quantidade": 28, "unidade": "UN" }
  ]
}
```

Resposta `201`:

```json
{ "estado": "criado", "documento": "GR 2026/1234", "id": 987654, "serie": "GR2026", "hash": "…", "criado_em": "2026-11-20T07:41:12+01:00" }
```

Resposta `422` (sem repetição automática):

```json
{ "estado": "recusado", "codigo": "STOCK_INSUFICIENTE", "mensagem": "Artigo 10000292: disponível 20, pedido 28" }
```

## Do lado do portal (já previsto)

Fila de ordens com tentativas automáticas, confirmação de cada documento por leitura (`DOCMOV`), registo de auditoria
encadeado e segregação de funções (quem confere a carga não a valida). Detalhe em `docs/LOGISTICA-PO.md`, secção 6.

## Perguntas ao fornecedor

1. Já existe uma API ou importação de documentos (ficheiro ou serviço) que possamos usar? Que documentos cobre?
2. Em que campo fica a referência externa, e como se lê no SQL?
3. Prazo e custo para os documentos 1 a 4, e ambiente de testes.
4. Limites de pedidos por minuto e janela de manutenção.
