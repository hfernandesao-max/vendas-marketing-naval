# Conformidade: normas ISO e o que o portal faz sozinho

O portal segue as normas internacionais de software e de gestão e guarda a prova de cada controlo automático em
Administração › Conformidade (administrador da plataforma e auditoria; o super administrador confirma a revisão de
acessos). A rotina corre todas as noites a partir de `CONFORMIDADE_HORA` (02:00), na tarefa de 5 em 5 minutos.

## Controlos automáticos

| Controlo                  | Norma                               | O que faz                                                                                                                                          |
| ------------------------- | ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Registos invioláveis      | ISO/IEC 27001 8.15                  | Cada linha dos registos leva o SHA-256 da anterior; a verificação diária encontra alterações, linhas apagadas e cortes; selo diário; cópia externa |
| Contas sem uso            | ISO/IEC 27001 5.18                  | Contas sem entrada há 90 dias desligam-se sozinhas (nunca o SADM); as que vão desligar em 14 dias aparecem na página                               |
| Revisão de acessos        | ISO/IEC 27001 5.18                  | No primeiro dia de cada trimestre, a lista de acessos vai ao SADM, que confirma na página                                                          |
| Cópia diária cifrada      | ISO/IEC 27001 8.13, ISO 22301       | Dados privados em AES-256-GCM, 14 dias, cópia para fora do servidor                                                                                |
| Teste de recuperação      | ISO 22301, ISO/IEC 27001 8.13       | Cada cópia é decifrada e lida de novo: SHA-256 de cada ficheiro, JSON legíveis, cadeia dos registos dentro da cópia                                |
| Retenção                  | ISO/IEC 27701, ISO 15489, Lei 22/11 | Prazo por tipo de dado; o que passa do prazo é apagado e contado                                                                                   |
| Dados de teste protegidos | ISO/IEC 27001 8.33                  | No ensaio, NIF, telefones, e-mails e moradas mascarados; o ensaio apaga-se quando a versão chega à produção                                        |
| Componentes e licenças    | ISO/IEC 5230 (OpenChain)            | Inventário das bibliotecas (`reports/vendor/componentes.json`), licenças permitidas, SBOM CycloneDX e proveniência em cada versão                  |
| Suporte e incidentes      | ISO/IEC 20000-1, ISO/IEC 27035      | Pedidos com prioridade e prazo, histórico, causa e lições nos incidentes graves, satisfação; incidente de segurança avisa o SADM de imediato       |

## Configuração no servidor (uma vez)

`COPIAS_CHAVE` (chave das cópias), `COPIAS_PASTA_EXTERNA` e `REGISTOS_COPIA_PASTA` (pasta fora do servidor). Sem
elas, a página Conformidade mostra o que falta.

## Sistema de gestão (informação documentada obrigatória da ISO/IEC 27001)

Guardada no código, com versão, e mostrada em Administração › Conformidade:

| Documento                                                         | Cláusula                     | Ficheiro                                                                 |
| ----------------------------------------------------------------- | ---------------------------- | ------------------------------------------------------------------------ |
| Âmbito do SGSI                                                    | 4.3                          | `nucleo/conformidade/sgsi.json` (`ambito`)                               |
| Manual do SGSI: política e 8 procedimentos (SGSI-MAN-01, PT e EN) | 5.2, anexo A                 | `nucleo/conformidade/manual-sgsi.html`                                   |
| Objectivos de segurança, medidos pelo portal                      | 6.2, 9.1                     | `sgsi.json` (`objectivos`)                                               |
| Declaração de aplicabilidade: 93 controlos da ISO/IEC 27002:2022  | 6.1.3 d)                     | `sgsi.json` (`controlos`)                                                |
| Registo de riscos e plano de tratamento (P × I, aceitável até 6)  | 6.1.2, 6.1.3, 8.2, 8.3       | `sgsi.json` (`riscos`)                                                   |
| Registo das actividades de tratamento (RoPA)                      | ISO/IEC 27701, Lei n.º 22/11 | `sgsi.json` (`ropa`)                                                     |
| Programa de auditoria interna e revisão pela direcção             | 9.2, 9.3                     | `sgsi.json` (`auditoria`)                                                |
| Não conformidades e acções correctivas                            | 10.2                         | Suporte, tipo «Não conformidade» (causa e acção correctiva obrigatórias) |

Cada documento tem um resumo SHA-256 do conteúdo; o super administrador aprova cada versão e a aprovação fica com o
resumo, quem e quando (`dados-privados/conformidade/aprovacoes.json`). Se o conteúdo mudar numa versão nova, volta a
«por aprovar». Para alterar: editar `sgsi.json` (bilingue) e, no manual, a página e a versão do cabeçalho.

Os pedidos de suporte fechados há mais de `RETENCAO_SUPORTE_DIAS` (3 anos) são apagados; a numeração não recua.

## Certificação

Ordem proposta: ISO/IEC 27001 com a 27701; ISO 22301 e ISO/IEC 20000-1; ISO 9001; ISO/IEC 42001. Os documentos do
sistema de gestão (política, declaração de aplicabilidade, análise de riscos, procedimentos) seguem o modelo de
procedimentos do grupo, em PT e EN.
