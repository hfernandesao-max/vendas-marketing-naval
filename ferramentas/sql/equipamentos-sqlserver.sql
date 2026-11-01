-- Portal RTM - Modulo de Gestao de Activos (v2: documentos e assinatura electronica)
-- SQL Server 2016+ | Executar uma vez, numa base de dados sem as tabelas ATV_*
SET XACT_ABORT ON;
GO

/* ===================== Tabelas de referencia ===================== */
CREATE TABLE dbo.ATV_TIPO (
    Codigo     CHAR(3)       NOT NULL CONSTRAINT PK_ATV_TIPO PRIMARY KEY,
    Descricao  NVARCHAR(60)  NOT NULL
);
GO

CREATE TABLE dbo.ATV_COLABORADOR (
    IdColaborador      INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ATV_COLABORADOR PRIMARY KEY,
    Nome               NVARCHAR(120)  NOT NULL,
    Funcao             NVARCHAR(80)   NULL,
    Telemovel          VARCHAR(20)    NULL,   -- fase 2: recebe o codigo de uso unico (OTP) por SMS
    Email              VARCHAR(120)   NULL,
    UtilizadorPortal   NVARCHAR(120)  NULL,   -- preenchido se tiver conta no portal
    RotaActual         VARCHAR(10)    NULL,
    IdDocConvencao     BIGINT         NULL,   -- Convencao de Prova Electronica assinada
    Activo             BIT            NOT NULL CONSTRAINT DF_ATV_COLAB_ACTIVO DEFAULT (1),
    CriadoEm           DATETIME2(0)   NOT NULL CONSTRAINT DF_ATV_COLAB_CRIADO DEFAULT (SYSDATETIME())
);
GO

/* ===================== Activos ===================== */
CREATE TABLE dbo.ATV_ACTIVO (
    IdActivo             VARCHAR(12)    NOT NULL CONSTRAINT PK_ATV_ACTIVO PRIMARY KEY,  -- NAV-SMT-0001
    Tipo                 CHAR(3)        NOT NULL CONSTRAINT FK_ATV_ACTIVO_TIPO REFERENCES dbo.ATV_TIPO(Codigo),
    Marca                NVARCHAR(60)   NULL,
    Modelo               NVARCHAR(60)   NULL,
    NumSerie             VARCHAR(40)    NULL,
    Imei1                CHAR(15)       NULL,
    Imei2                CHAR(15)       NULL,
    MacBluetooth         CHAR(17)       NULL,   -- AA:BB:CC:DD:EE:FF
    NumTelefone          VARCHAR(20)    NULL,
    MdmDeviceId          VARCHAR(30)    NULL,   -- ID interno do MDM Plus
    DataAquisicao        DATE           NULL,
    ValorAquisicao       DECIMAL(18,2)  NULL,
    Fornecedor           NVARCHAR(120)  NULL,
    FimGarantia          DATE           NULL,
    Estado               VARCHAR(20)    NOT NULL CONSTRAINT DF_ATV_ACTIVO_ESTADO DEFAULT ('EM_STOCK'),
    RotaActual           VARCHAR(10)    NULL,
    IdColaboradorActual  INT            NULL CONSTRAINT FK_ATV_ACTIVO_COLAB REFERENCES dbo.ATV_COLABORADOR(IdColaborador),
    Localizacao          NVARCHAR(120)  NULL,
    EstadoMdm            VARCHAR(20)    NULL,   -- SINCRONIZADO / PENDENTE / ERRO
    UltimoInventario     DATETIME2(0)   NULL,
    CriadoEm             DATETIME2(0)   NOT NULL CONSTRAINT DF_ATV_ACTIVO_CRIADO DEFAULT (SYSDATETIME()),
    CriadoPor            NVARCHAR(120)  NOT NULL,
    CONSTRAINT CK_ATV_ACTIVO_ID     CHECK (IdActivo LIKE '[A-Z][A-Z][A-Z]-[A-Z][A-Z][A-Z]-[0-9][0-9][0-9][0-9]'),
    CONSTRAINT CK_ATV_ACTIVO_TIPOID CHECK (SUBSTRING(IdActivo, 5, 3) = Tipo),
    CONSTRAINT CK_ATV_ACTIVO_ESTADO CHECK (Estado IN ('EM_STOCK','ATRIBUIDO','EM_REPARACAO','PERDIDO_ROUBADO','ABATIDO'))
);
GO

CREATE UNIQUE INDEX UX_ATV_ACTIVO_SERIE ON dbo.ATV_ACTIVO(NumSerie)     WHERE NumSerie     IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_IMEI1 ON dbo.ATV_ACTIVO(Imei1)        WHERE Imei1        IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_IMEI2 ON dbo.ATV_ACTIVO(Imei2)        WHERE Imei2        IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_MAC   ON dbo.ATV_ACTIVO(MacBluetooth) WHERE MacBluetooth IS NOT NULL;
GO

CREATE TABLE dbo.ATV_ASSOCIACAO (
    IdAssociacao  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ATV_ASSOCIACAO PRIMARY KEY,
    IdSmartphone  VARCHAR(12)   NOT NULL CONSTRAINT FK_ATV_ASSOC_SMT REFERENCES dbo.ATV_ACTIVO(IdActivo),
    IdImpressora  VARCHAR(12)   NOT NULL CONSTRAINT FK_ATV_ASSOC_IMP REFERENCES dbo.ATV_ACTIVO(IdActivo),
    DataInicio    DATETIME2(0)  NOT NULL CONSTRAINT DF_ATV_ASSOC_INICIO DEFAULT (SYSDATETIME()),
    DataFim       DATETIME2(0)  NULL,   -- NULL = em vigor
    RegistadoPor  NVARCHAR(120) NOT NULL,
    CONSTRAINT CK_ATV_ASSOC_SMT   CHECK (SUBSTRING(IdSmartphone, 5, 3) = 'SMT'),
    CONSTRAINT CK_ATV_ASSOC_IMP   CHECK (SUBSTRING(IdImpressora, 5, 3) = 'IMP'),
    CONSTRAINT CK_ATV_ASSOC_DATAS CHECK (DataFim IS NULL OR DataFim >= DataInicio)
);
GO

-- No maximo uma associacao em vigor por equipamento
CREATE UNIQUE INDEX UX_ATV_ASSOC_SMT_VIGOR ON dbo.ATV_ASSOCIACAO(IdSmartphone) WHERE DataFim IS NULL;
CREATE UNIQUE INDEX UX_ATV_ASSOC_IMP_VIGOR ON dbo.ATV_ASSOCIACAO(IdImpressora) WHERE DataFim IS NULL;
GO

/* ===================== Documentos e assinaturas ===================== */
CREATE TABLE dbo.ATV_DOCUMENTO (
    IdDocumento      BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ATV_DOCUMENTO PRIMARY KEY,
    NumDocumento     VARCHAR(20)    NOT NULL CONSTRAINT UQ_ATV_DOC_NUM UNIQUE,   -- TER-2026-000001
    TipoDocumento    VARCHAR(20)    NOT NULL,
    Estado           VARCHAR(12)    NOT NULL CONSTRAINT DF_ATV_DOC_ESTADO DEFAULT ('A_ASSINAR'),
    IdColaborador    INT            NULL CONSTRAINT FK_ATV_DOC_COLAB REFERENCES dbo.ATV_COLABORADOR(IdColaborador),
    DataEmissao      DATETIME2(0)   NOT NULL CONSTRAINT DF_ATV_DOC_EMISSAO DEFAULT (SYSDATETIME()),
    EmitidoPor       NVARCHAR(120)  NOT NULL,
    HashOriginal     CHAR(64)       NOT NULL,   -- SHA-256 do PDF bloqueado, antes das assinaturas
    FicheiroPdf      NVARCHAR(400)  NOT NULL,   -- PDF/A-3 no armazenamento do portal
    HashFinal        CHAR(64)       NULL,       -- SHA-256 do PDF selado (PAdES + selo temporal)
    DataConclusao    DATETIME2(0)   NULL,
    MotivoAnulacao   NVARCHAR(400)  NULL,
    CONSTRAINT CK_ATV_DOC_TIPO   CHECK (TipoDocumento IN ('TERMO_ENTREGA','AUTO_DEVOLUCAO','AUTO_SUBSTITUICAO',
                                                          'PARTICIPACAO_PERDA','AUTO_ABATE','CONVENCAO_PROVA')),
    CONSTRAINT CK_ATV_DOC_ESTADO CHECK (Estado IN ('A_ASSINAR','ASSINADO','ANULADO')),
    CONSTRAINT CK_ATV_DOC_FECHO  CHECK (Estado <> 'ASSINADO' OR (HashFinal IS NOT NULL AND DataConclusao IS NOT NULL)),
    CONSTRAINT CK_ATV_DOC_ANUL   CHECK (Estado <> 'ANULADO' OR MotivoAnulacao IS NOT NULL)
);
GO

ALTER TABLE dbo.ATV_COLABORADOR
    ADD CONSTRAINT FK_ATV_COLAB_CONVENCAO FOREIGN KEY (IdDocConvencao) REFERENCES dbo.ATV_DOCUMENTO(IdDocumento);
GO

-- Activos abrangidos por cada documento (um termo pode cobrir smartphone + impressora)
CREATE TABLE dbo.ATV_DOCUMENTO_ACTIVO (
    IdDocumento  BIGINT       NOT NULL CONSTRAINT FK_ATV_DOCACT_DOC REFERENCES dbo.ATV_DOCUMENTO(IdDocumento),
    IdActivo     VARCHAR(12)  NOT NULL CONSTRAINT FK_ATV_DOCACT_ACT REFERENCES dbo.ATV_ACTIVO(IdActivo),
    Papel        VARCHAR(12)  NOT NULL,   -- ENTREGUE / DEVOLVIDO / ABRANGIDO
    Acessorios   NVARCHAR(200) NULL,
    EstadoFisico NVARCHAR(200) NULL,
    CONSTRAINT PK_ATV_DOCUMENTO_ACTIVO PRIMARY KEY (IdDocumento, IdActivo),
    CONSTRAINT CK_ATV_DOCACT_PAPEL CHECK (Papel IN ('ENTREGUE','DEVOLVIDO','ABRANGIDO'))
);
GO

CREATE TABLE dbo.ATV_ASSINATURA (
    IdAssinatura      BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ATV_ASSINATURA PRIMARY KEY,
    IdDocumento       BIGINT         NOT NULL CONSTRAINT FK_ATV_ASS_DOC REFERENCES dbo.ATV_DOCUMENTO(IdDocumento),
    Ordem             TINYINT        NOT NULL,
    NomeSignatario    NVARCHAR(120)  NOT NULL,
    Qualidade         NVARCHAR(80)   NOT NULL,   -- ex.: Comercial, Responsavel da Logistica
    IdColaborador     INT            NULL CONSTRAINT FK_ATV_ASS_COLAB REFERENCES dbo.ATV_COLABORADOR(IdColaborador),
    UtilizadorPortal  NVARCHAR(120)  NULL,
    MetodoAutenticacao VARCHAR(25)   NOT NULL,   -- fase 1: CONTA_PORTAL ou PRESENCIAL; fase 2: OTP_SMS
    UtilizadorTestemunha NVARCHAR(120) NULL,  -- fase 1: utilizador do portal que presencia a assinatura
    TelemovelOtp      VARCHAR(20)    NULL,     -- fase 2: OTP por SMS
    DataHora          DATETIME2(3)   NOT NULL CONSTRAINT DF_ATV_ASS_DATA DEFAULT (SYSDATETIME()),
    EnderecoIp        VARCHAR(45)    NOT NULL,
    Navegador         NVARCHAR(300)  NULL,
    Latitude          DECIMAL(9,6)   NULL,
    Longitude         DECIMAL(9,6)   NULL,
    HashDocumento     CHAR(64)       NOT NULL,   -- tem de ser igual a ATV_DOCUMENTO.HashOriginal
    ImagemAssinatura  NVARCHAR(400)  NOT NULL,   -- assinatura manuscrita desenhada no ecra
    CONSTRAINT UQ_ATV_ASS_ORDEM UNIQUE (IdDocumento, Ordem),
    CONSTRAINT CK_ATV_ASS_METODO CHECK (MetodoAutenticacao IN ('CONTA_PORTAL','PRESENCIAL','OTP_SMS','CERTIFICADO_QUALIFICADO')),
    CONSTRAINT CK_ATV_ASS_PRES   CHECK (MetodoAutenticacao <> 'PRESENCIAL' OR (UtilizadorTestemunha IS NOT NULL AND IdColaborador IS NOT NULL)),
    CONSTRAINT CK_ATV_ASS_OTP    CHECK (MetodoAutenticacao <> 'OTP_SMS' OR TelemovelOtp IS NOT NULL)
);
GO

/* ===================== Historico ===================== */
CREATE TABLE dbo.ATV_EVENTO (
    IdEvento             BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ATV_EVENTO PRIMARY KEY,
    IdActivo             VARCHAR(12)    NOT NULL CONSTRAINT FK_ATV_EVENTO_ACTIVO REFERENCES dbo.ATV_ACTIVO(IdActivo),
    TipoEvento           VARCHAR(30)    NOT NULL,
    DataHora             DATETIME2(0)   NOT NULL CONSTRAINT DF_ATV_EVENTO_DATA DEFAULT (SYSDATETIME()),
    Utilizador           NVARCHAR(120)  NOT NULL,
    EstadoAntes          VARCHAR(20)    NULL,
    EstadoDepois         VARCHAR(20)    NULL,
    RotaAntes            VARCHAR(10)    NULL,
    RotaDepois           VARCHAR(10)    NULL,
    IdColaboradorAntes   INT            NULL CONSTRAINT FK_ATV_EVENTO_COLAB_A REFERENCES dbo.ATV_COLABORADOR(IdColaborador),
    IdColaboradorDepois  INT            NULL CONSTRAINT FK_ATV_EVENTO_COLAB_D REFERENCES dbo.ATV_COLABORADOR(IdColaborador),
    IdActivoRelacionado  VARCHAR(12)    NULL CONSTRAINT FK_ATV_EVENTO_REL REFERENCES dbo.ATV_ACTIVO(IdActivo),
    IdDocumento          BIGINT         NULL CONSTRAINT FK_ATV_EVENTO_DOC REFERENCES dbo.ATV_DOCUMENTO(IdDocumento),
    PedidoMdm            NVARCHAR(400)  NULL,   -- accao enviada a API do MDM e resposta
    Latitude             DECIMAL(9,6)   NULL,
    Longitude            DECIMAL(9,6)   NULL,
    Observacoes          NVARCHAR(1000) NULL,
    Anexo                NVARCHAR(400)  NULL,
    CONSTRAINT CK_ATV_EVENTO_TIPO CHECK (TipoEvento IN (
        'ENTRADA','ETIQUETAGEM','ATRIBUICAO','DEVOLUCAO','ASSOCIACAO','DESASSOCIACAO',
        'REPARACAO_ENVIO','REPARACAO_REGRESSO','SUBSTITUICAO','PERDA_ROUBO','RECUPERACAO',
        'REPOSICAO_MDM','LEITURA_QR','INVENTARIO','ABATE','DOCUMENTO_ASSINADO'))
);
GO

CREATE INDEX IX_ATV_EVENTO_ACTIVO ON dbo.ATV_EVENTO(IdActivo, DataHora DESC);
CREATE INDEX IX_ATV_DOCACT_ACTIVO ON dbo.ATV_DOCUMENTO_ACTIVO(IdActivo);
GO

/* ===================== Imutabilidade (registo de auditoria) ===================== */
CREATE TRIGGER dbo.TR_ATV_EVENTO_IMUTAVEL ON dbo.ATV_EVENTO
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50001, N'ATV_EVENTO e um registo de auditoria: nao e permitido alterar nem apagar eventos.', 1;
END;
GO

CREATE TRIGGER dbo.TR_ATV_ASSINATURA_IMUTAVEL ON dbo.ATV_ASSINATURA
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50002, N'ATV_ASSINATURA e um registo de evidencias: nao e permitido alterar nem apagar assinaturas.', 1;
END;
GO

-- Documentos: nunca se apagam; depois de ASSINADO ou ANULADO nao se alteram
CREATE TRIGGER dbo.TR_ATV_DOCUMENTO_DELETE ON dbo.ATV_DOCUMENTO
INSTEAD OF DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50003, N'Os documentos nao podem ser apagados; um documento por assinar pode ser anulado.', 1;
END;
GO

CREATE TRIGGER dbo.TR_ATV_DOCUMENTO_FECHADO ON dbo.ATV_DOCUMENTO
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM deleted WHERE Estado IN ('ASSINADO','ANULADO'))
        THROW 50004, N'Documento assinado ou anulado: o conteudo e definitivo.', 1;
    IF UPDATE(HashOriginal) OR UPDATE(FicheiroPdf) OR UPDATE(NumDocumento) OR UPDATE(TipoDocumento)
        THROW 50005, N'O documento esta bloqueado desde a emissao: so o estado e o fecho podem ser actualizados.', 1;
END;
GO

CREATE TRIGGER dbo.TR_ATV_ASSINATURA_HASH ON dbo.ATV_ASSINATURA
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM inserted i JOIN dbo.ATV_DOCUMENTO d ON d.IdDocumento = i.IdDocumento
               WHERE d.Estado <> 'A_ASSINAR' OR i.HashDocumento <> d.HashOriginal)
        THROW 50006, N'Assinatura recusada: o documento nao esta por assinar ou foi alterado depois da emissao.', 1;
END;
GO

/* ===================== Vistas ===================== */
-- Ficha: activo + responsavel + equipamento associado em vigor
CREATE VIEW dbo.VW_ATV_FICHA
AS
SELECT a.*,
       c.Nome                                   AS ResponsavelActual,
       COALESCE(s.IdImpressora, i.IdSmartphone) AS IdAssociado,
       COALESCE(s.DataInicio,   i.DataInicio)   AS AssociadoDesde
FROM dbo.ATV_ACTIVO a
LEFT JOIN dbo.ATV_COLABORADOR c ON c.IdColaborador = a.IdColaboradorActual
LEFT JOIN dbo.ATV_ASSOCIACAO s ON s.IdSmartphone = a.IdActivo AND s.DataFim IS NULL
LEFT JOIN dbo.ATV_ASSOCIACAO i ON i.IdImpressora = a.IdActivo AND i.DataFim IS NULL;
GO

-- Activos atribuidos sem Termo de Entrega assinado para o responsavel actual
CREATE VIEW dbo.VW_ATV_SEM_TERMO
AS
SELECT a.IdActivo, a.RotaActual, a.IdColaboradorActual
FROM dbo.ATV_ACTIVO a
WHERE a.Estado = 'ATRIBUIDO'
  AND NOT EXISTS (SELECT 1
                  FROM dbo.ATV_DOCUMENTO_ACTIVO da
                  JOIN dbo.ATV_DOCUMENTO d ON d.IdDocumento = da.IdDocumento
                  WHERE da.IdActivo = a.IdActivo
                    AND da.Papel = 'ENTREGUE'
                    AND d.Estado = 'ASSINADO'
                    AND d.TipoDocumento IN ('TERMO_ENTREGA','AUTO_SUBSTITUICAO')
                    AND d.IdColaborador = a.IdColaboradorActual);
GO

/* ===================== Carga inicial (31-10-2026) ===================== */
BEGIN TRANSACTION;

DECLARE @u NVARCHAR(120) = N'Henrique Fernandes (carga inicial)';

INSERT INTO dbo.ATV_TIPO (Codigo, Descricao) VALUES
    ('SMT', N'Smartphone'),
    ('IMP', N'Impressora móvel');

DECLARE @s TABLE (Id VARCHAR(12), Rota VARCHAR(10), Resp NVARCHAR(120), Marca NVARCHAR(60), Modelo NVARCHAR(60),
                  Serie VARCHAR(40), Imei1 CHAR(15), Imei2 CHAR(15), Mdm VARCHAR(30));
INSERT INTO @s VALUES
 ('NAV-SMT-0001','1001',N'Andrea Santos',      N'Blackview',N'BV6200 Pro','BV6200ProNEU0031328','353326512163561','353326512163579','236708000000175195'),
 ('NAV-SMT-0002','1002',N'Cristina Sachuma',   N'Blackview',N'BV6200 Pro','BV6200ProNEU0031371','353326512164429','353326512164437',NULL),
 ('NAV-SMT-0003','1003',N'Joceil Prata',       N'Blackview',N'BV6200 Pro','BV6200ProNEU0031353','353326512164064','353326512164072',NULL),
 ('NAV-SMT-0004','1004',N'Nelson Bumba',       N'Blackview',N'BV6200 Pro','BV6200ProNEU0031321','353326512163421','353326512163439',NULL),
 ('NAV-SMT-0005','1005',N'Olindo Baião',       N'Blackview',N'BV6200 Pro','BV6200ProNEU0031317','353326512163348','353326512163355',NULL),
 ('NAV-SMT-0006','1006',N'Rosa Vemba',         N'Blackview',N'BV6200 Pro','BV6200ProNEU0031312','353326512163249','353326512163256',NULL),
 ('NAV-SMT-0007','1007',N'Awet Weldemenkorios',N'Blackview',N'BV6200 Pro','BV6200ProNEU0031336','353326512163728','353326512163736',NULL),
 ('NAV-SMT-0008','1008',N'Daniel Mihreteab',   N'Blackview',N'BV6200 Pro','BV6200ProNEU0031411','353326512165228','353326512165236',NULL),
 ('NAV-SMT-0009','1009',N'Efrem Bokre',        N'Blackview',N'BV6200 Pro','BV6200ProNEU0031406','353326512165129','353326512165137',NULL),
 ('NAV-SMT-0010','1010',N'Nahom Areaya',       N'Blackview',N'BV6200 Pro','BV6200ProNEU0031360','353326512164205','353326512164213',NULL),
 ('NAV-SMT-0011','1011',N'Robel Tesfamichael', NULL,        N'FORT 1',    'FORT1NEU00015929',   '354675480818567','354675480818575',NULL);

-- Comerciais (telemovel a completar antes da primeira assinatura)
INSERT INTO dbo.ATV_COLABORADOR (Nome, Funcao, Email, RotaActual)
SELECT Resp, N'Comercial', Rota + '@naval.co.ao', Rota FROM @s;

-- Smartphones
INSERT INTO dbo.ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, NumSerie, Imei1, Imei2, MdmDeviceId,
                            Estado, RotaActual, IdColaboradorActual, CriadoPor)
SELECT s.Id, 'SMT', s.Marca, s.Modelo, s.Serie, s.Imei1, s.Imei2, s.Mdm, 'ATRIBUIDO', s.Rota, c.IdColaborador, @u
FROM @s s JOIN dbo.ATV_COLABORADOR c ON c.RotaActual = s.Rota;

-- Impressoras: DDigital RM-T9 (SPRT SP-RMT9 BT), sem n.o de serie do fabricante;
-- identificador fisico = MAC Bluetooth (talao de autoteste).
DECLARE @p TABLE (Rota VARCHAR(10), Mac CHAR(17));
INSERT INTO @p VALUES
 ('1001','00:12:5B:04:2E:BC'), ('1002','00:12:5B:04:2E:49'), ('1003','00:12:5B:04:2D:E7'),
 ('1004','00:12:5B:04:30:E8'), ('1005','00:12:5B:04:2E:C0'), ('1006','00:12:5B:04:2F:0B'),
 ('1007','00:12:5B:04:2B:48'), ('1008','00:12:5B:04:2D:95'), ('1009','00:12:5B:04:2B:39'),
 ('1010','00:12:5B:04:2B:2C'), ('1011','00:12:5B:04:2D:94');

INSERT INTO dbo.ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, MacBluetooth, Estado, RotaActual, IdColaboradorActual, CriadoPor)
SELECT REPLACE(s.Id, '-SMT-', '-IMP-'), 'IMP', N'DDigital (SPRT)', N'DD-RMT9BT', p.Mac, 'ATRIBUIDO', s.Rota, c.IdColaborador, @u
FROM @s s
JOIN @p p ON p.Rota = s.Rota
JOIN dbo.ATV_COLABORADOR c ON c.RotaActual = s.Rota;

-- Associacoes smartphone <-> impressora
INSERT INTO dbo.ATV_ASSOCIACAO (IdSmartphone, IdImpressora, RegistadoPor)
SELECT Id, REPLACE(Id, '-SMT-', '-IMP-'), @u FROM @s;

-- Eventos de abertura do historico
INSERT INTO dbo.ATV_EVENTO (IdActivo, TipoEvento, Utilizador, EstadoDepois, RotaDepois, IdColaboradorDepois, Observacoes)
SELECT a.IdActivo, 'ATRIBUICAO', @u, 'ATRIBUIDO', a.RotaActual, a.IdColaboradorActual,
       N'Carga inicial do registo de activos (situação a 31-10-2026); Termo de Entrega a assinar no arranque do módulo'
FROM dbo.ATV_ACTIVO a;

INSERT INTO dbo.ATV_EVENTO (IdActivo, TipoEvento, Utilizador, IdActivoRelacionado, Observacoes)
SELECT IdSmartphone, 'ASSOCIACAO', @u, IdImpressora, N'Carga inicial' FROM dbo.ATV_ASSOCIACAO
UNION ALL
SELECT IdImpressora, 'ASSOCIACAO', @u, IdSmartphone, N'Carga inicial' FROM dbo.ATV_ASSOCIACAO;
-- O evento ETIQUETAGEM regista-se no portal quando cada etiqueta for colada e o QR lido.

COMMIT TRANSACTION;
GO

-- Verificacao: 22 activos, 11 colaboradores, 11 associacoes em vigor, 22 activos sem termo assinado
SELECT Tipo, COUNT(*) AS Activos FROM dbo.ATV_ACTIVO GROUP BY Tipo;
SELECT COUNT(*) AS Colaboradores FROM dbo.ATV_COLABORADOR;
SELECT IdActivo, RotaActual, ResponsavelActual, IdAssociado FROM dbo.VW_ATV_FICHA ORDER BY IdActivo;
SELECT COUNT(*) AS ActivosSemTermo FROM dbo.VW_ATV_SEM_TERMO;
SELECT COUNT(*) AS ImpressorasSemMac FROM dbo.ATV_ACTIVO WHERE Tipo = 'IMP' AND MacBluetooth IS NULL;  -- esperado: 0
