# Capacidade do Portal RTM: 50 pessoas já, 1000 no futuro

Objectivo: **50 pessoas a trabalhar ao mesmo tempo** (Novembro de 2026) e um caminho claro para **cerca de 1000**,
seguindo as práticas das grandes tecnológicas (Google SRE, Microsoft Azure Well-Architected, AWS Well-Architected):
medir antes de mudar, objectivos de serviço (SLO) explícitos, infra-estrutura como código, observabilidade e
crescimento horizontal por fases.

## 1. Objectivos de serviço (SLO)

| Indicador                               | Objectivo                |
| --------------------------------------- | ------------------------ |
| Disponibilidade mensal                  | 99,5% (≤ 3,6 h por mês)  |
| Tempo de resposta da API (p95)          | < 1 s (p99 < 2 s)        |
| Abrir uma função do portal              | < 3 s na rede da empresa |
| Erros do servidor (5xx)                 | < 0,5% dos pedidos       |
| Perda de dados próprios do portal (RPO) | ≤ 24 h (cópia diária)    |

## 2. Medição (teste de carga, 07-11-2026)

Ferramenta: `ferramentas/carga.mjs` — cada pessoa simulada entra, abre o Início (os mesmos pedidos que o navegador
faz, em paralelo) e navega com tempo de leitura entre páginas. Ambiente de ensaio do portal (Pinguim BO simulado),
2 processadores, PHP com OPcache.

| Cenário                                                   | Pedidos/s | p50  | p95 (tempo estável)                               | Erros 5xx |
| --------------------------------------------------------- | --------- | ---- | ------------------------------------------------- | --------- |
| 50 pessoas, 8 s de leitura entre páginas (uso real)       | 11        | 3 ms | 5–30 ms                                           | 0         |
| 300 pessoas, 2,4 s entre páginas (carga de ~1000 pessoas) | 150       | 2 ms | ~550 ms durante a entrada de todas ao mesmo tempo | 0         |

Conclusões:

- **A aplicação aguenta 50 pessoas com folga grande** e a carga equivalente a 1000 sem erros, num servidor modesto.
  O pico vem da entrada de muitas pessoas no mesmo minuto (verificação da palavra-passe, de propósito lenta), não do
  uso normal.
- **O que decide na produção é o servidor e o SQL Server do Pinguim BO**: as consultas às vendas são partilhadas por
  todas as pessoas (cache de 2 minutos para hoje e 30 minutos para períodos fechados, com uma só consulta de cada vez
  — os outros pedidos esperam pelo resultado), mas a primeira consulta de cada período vai à base de dados.
- Repetir a medição no servidor real antes do arranque com as 50 pessoas (secção 5).

## 3. O que foi ajustado nesta versão

| Área            | Ajuste                                                                                                                                                                                                                 |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sessões         | Guardadas na pasta privada do portal (e não na pasta temporária do Windows) e limpas só depois de 60 min: o valor do PHP (24 min) terminava sessões activas.                                                           |
| Entrada         | Limite por IP de 20 para 100 falhas em 15 min: dezenas de pessoas saem pelo mesmo IP (empresa, rede móvel). Cada conta mantém o seu limite.                                                                            |
| Apache          | `ferramentas/apache/portal-rtm.conf` (aplicado pelo actualizador, com validação e reposição): 600 fios de execução, ligações persistentes, HTTP/2 quando o módulo existe, recomendações da Apache Lounge para Windows. |
| PHP             | `ferramentas/requisitos.json` (secção `ini`, aplicada pelo actualizador com cópia): OPcache ligado (256 MB), cache de caminhos, 512 MB de memória.                                                                     |
| Observabilidade | Pedidos lentos (> 1,5 s, `API_LENTO_MS`) e erros 5xx em `registos/desempenho-AAAA-MM.jsonl`; `GET api/index.php/v1/saude` para a monitorização.                                                                        |
| Velocidade      | Token da API e módulos com versão dentro da página, pedidos em paralelo (menos idas e voltas ao servidor).                                                                                                             |

## 4. Servidor para as 50 pessoas (pedido ao departamento de TI)

1. **Recursos:** 4 processadores e 8 GB de memória para o Apache/PHP (mínimo), disco SSD.
2. **Antivírus:** excluir da análise em tempo real a pasta dos dados privados do portal e `C:\Apache24\logs` (cada
   pedido lê e grava ficheiros pequenos; a análise de cada um atrasa tudo).
3. **SQL Server do Pinguim BO:** a conta do portal só com leitura (`db_datareader`). Pedir ao DBA, se as consultas das
   vendas passarem de 1 s, índices em `DOCHEADER` por data e loja (o portal nunca cria nada na base do Pinguim BO).
4. **Rede:** certificado HTTPS reconhecido pelos telemóveis; porta 2051 aberta; DNS `geo.naval.co.ao`.
5. **Relógio:** sincronizado (`w32tm /resync`): os tokens e os códigos de verificação dependem da hora certa.
6. **Cópia de segurança diária** da pasta dos dados privados (contas, matriz, planos, registos).

## 5. Antes do arranque com as 50 pessoas

1. Criar 5 contas de ensaio sem dois passos, num perfil só de leitura.
2. Fora de horas, num computador da rede:
   `BASE=https://geo.naval.co.ao:2051 CONTAS=ensaio1,ensaio2,ensaio3,ensaio4,ensaio5 SENHA='…' N=50 DUR=300 node ferramentas/carga.mjs`
3. Aceitar se p95 < 1 s e 0 erros 5xx; senão, ver `registos/desempenho-*.jsonl` (rota e tempo dos pedidos lentos).
4. Apagar as contas de ensaio.

## 6. Caminho para 1000 pessoas

| Fase | Quando        | O quê                                                                                                                                                                                                                                                                                                                              | Porquê                                                                                                                         |
| ---- | ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| 1    | Agora (feito) | Cache partilhada, sessões e PHP afinados, Apache com 600 fios, HTTP/2, medição e verificação de saúde                                                                                                                                                                                                                              | 50 a 200 pessoas num servidor                                                                                                  |
| 2    | 1–3 meses     | Dados próprios do portal (contas, matriz, registos, equipamentos) de ficheiros JSON para uma **base de dados própria em SQL Server** (separada da do Pinguim BO), com transacções; cache e limites de pedidos em memória (APCu ou Redis); tarefas pesadas (alertas, previsões, resumos) calculadas em segundo plano pelo agendador | Ficheiros com bloqueio servem centenas de pessoas, não milhares; a base de dados dá transacções, índices e cópias consistentes |
| 3    | 3–6 meses     | **Dois ou mais servidores do portal** atrás de um balanceador de carga (verificação em `v1/saude`), sessões e cache partilhadas (Redis), ficheiros estáticos numa CDN, **réplica só de leitura do SQL Server** do Pinguim BO para os relatórios                                                                                    | Crescimento horizontal e tolerância a falhas: um servidor cai e o portal continua; os relatórios não pesam no ERP              |
| 4    | Contínuo      | Monitorização com alertas (Azure Monitor, Grafana ou equivalente) sobre os SLO; teste de carga em cada versão no ambiente de ensaio; ambiente de ensaio igual à produção; instalação gradual (primeiro um servidor, depois os outros)                                                                                              | Detectar degradação antes das pessoas; nenhuma versão chega à produção sem passar pela carga                                   |

Práticas já em vigor e que se mantêm: 17 verificações automáticas em cada alteração (testes de unidade, de serviço,
do navegador, do actualizador, do Apache, análise estática e de segurança), integração só com tudo verde,
actualizações com cópia de segurança e reposição automática, infra-estrutura como código (`ferramentas/apache`,
`ferramentas/requisitos.json`).
