# Migrações da base de dados do módulo Equipamentos

Cada alteração ao esquema depois do script inicial (`../equipamentos-sqlserver.sql`, versão 1) é um ficheiro
`NNNN-descricao.sql` nesta pasta (`0002-...`, `0003-...`), em SQL Server, com lotes separados por `GO`.

O actualizador do portal (`ferramentas/actualizar.php`) aplica as migrações pendentes **antes** de copiar o código
novo, cada uma numa transacção, e regista-a na tabela `ATV_MIGRACAO`. Regras (expandir e depois contrair):

- cada migração tem de funcionar com o código que está em produção (acrescentar colunas com valor por omissão,
  tabelas novas, índices); retirar colunas só numa versão posterior, quando o código já não as usar;
- nunca alterar uma migração já publicada: corrigir com uma nova;
- só a base de dados do módulo (ATV_*); o Pinguim BO é só de leitura;
- se a conta do módulo não puder alterar tabelas, indicar no `.env` `ATV_DB_MIGRACAO_USER` e `ATV_DB_MIGRACAO_PASS`
  (conta com `db_ddladmin` nessa base de dados); sem ela, a actualização pára antes de alterar ficheiros.
