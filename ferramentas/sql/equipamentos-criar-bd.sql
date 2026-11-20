-- Portal RTM - Equipamentos: criar a base de dados própria do módulo e a conta do portal (passo 1 de 2)
-- SQL Server 2016+ | Executar UMA vez, como administrador do SQL Server (sysadmin), no SSMS em «Modo SQLCMD»
-- (menu Consulta › Modo SQLCMD) ou na linha de comandos:
--     sqlcmd -S <servidor> -E -I -f 65001 -v SENHA="<palavra-passe forte>" -i equipamentos-criar-bd.sql
-- Passo 2: no servidor do portal, php ferramentas/configurar-equipamentos.php (cria as tabelas ATV_* e liga o portal).
--
-- Recomendado: no SQL Server do OLAP (10.30.10.14), ao lado da base de dados do OLAP. Base de dados SEPARADA da do
-- Pinguim BO e da do OLAP (o portal só lê essas duas; nunca escreve lá). A conta do portal só tem
-- acesso a esta base de dados: ler, escrever e executar (db_datareader, db_datawriter, EXECUTE) e alterar o esquema
-- nas actualizações (db_ddladmin; as migrações de ferramentas/sql/migracoes). Sem sysadmin nem acesso a outras bases.
-- Palavra-passe: pelo menos 16 caracteres, gerada e guardada no cofre da Informática; vai para o .env do servidor do
-- portal (ATV_DB_PASS) e não é escrita em mais lado nenhum.
:setvar BD GN_EQUIPAMENTOS
:setvar CONTA portal_equipamentos
:on error exit

SET NOCOUNT ON;
IF DB_ID(N'$(BD)') IS NULL
BEGIN
    CREATE DATABASE [$(BD)];
    ALTER DATABASE [$(BD)] SET RECOVERY FULL;   -- recuperação ao minuto com as cópias do registo de transacções
    PRINT 'Base de dados $(BD) criada.';
END
ELSE PRINT 'Base de dados $(BD) já existe: não alterada.';
GO

IF SUSER_ID(N'$(CONTA)') IS NULL
BEGIN
    CREATE LOGIN [$(CONTA)] WITH PASSWORD = N'$(SENHA)', DEFAULT_DATABASE = [$(BD)], CHECK_POLICY = ON, CHECK_EXPIRATION = OFF;
    PRINT 'Conta $(CONTA) criada.';
END
ELSE PRINT 'Conta $(CONTA) já existe: palavra-passe não alterada.';
GO

USE [$(BD)];
GO
IF USER_ID(N'$(CONTA)') IS NULL CREATE USER [$(CONTA)] FOR LOGIN [$(CONTA)];
ALTER ROLE db_datareader ADD MEMBER [$(CONTA)];
ALTER ROLE db_datawriter ADD MEMBER [$(CONTA)];
ALTER ROLE db_ddladmin ADD MEMBER [$(CONTA)];
GRANT EXECUTE TO [$(CONTA)];
PRINT 'Permissões da conta $(CONTA) na base de dados $(BD): leitura, escrita, execução e esquema.';
GO
