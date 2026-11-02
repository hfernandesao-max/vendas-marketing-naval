-- Módulo Equipamentos: o mesmo modelo de ferramentas/sql/equipamentos-sqlserver.sql em SQLite, só para os testes
-- automáticos (tabelas, restrições, índices únicos parciais, gatilhos de imutabilidade e vistas). Carga inicial
-- reduzida: 3 smartphones e 3 impressoras das rotas 1001 a 1003.
PRAGMA foreign_keys = ON;

CREATE TABLE ATV_TIPO (Codigo CHAR(3) NOT NULL PRIMARY KEY, Descricao TEXT NOT NULL);

CREATE TABLE ATV_COLABORADOR (
    IdColaborador INTEGER PRIMARY KEY AUTOINCREMENT,
    Nome TEXT NOT NULL, Funcao TEXT NULL, Telemovel TEXT NULL, Email TEXT NULL, UtilizadorPortal TEXT NULL,
    RotaActual TEXT NULL, IdDocConvencao INTEGER NULL REFERENCES ATV_DOCUMENTO(IdDocumento),
    Activo INTEGER NOT NULL DEFAULT 1, CriadoEm TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ATV_ACTIVO (
    IdActivo TEXT NOT NULL PRIMARY KEY, Tipo CHAR(3) NOT NULL REFERENCES ATV_TIPO(Codigo),
    Marca TEXT NULL, Modelo TEXT NULL, NumSerie TEXT NULL, Imei1 TEXT NULL, Imei2 TEXT NULL, MacBluetooth TEXT NULL,
    NumTelefone TEXT NULL, MdmDeviceId TEXT NULL, DataAquisicao TEXT NULL, ValorAquisicao NUMERIC NULL, Fornecedor TEXT NULL,
    FimGarantia TEXT NULL, Estado TEXT NOT NULL DEFAULT 'EM_STOCK', RotaActual TEXT NULL,
    IdColaboradorActual INTEGER NULL REFERENCES ATV_COLABORADOR(IdColaborador), Localizacao TEXT NULL, EstadoMdm TEXT NULL,
    UltimoInventario TEXT NULL, CriadoEm TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, CriadoPor TEXT NOT NULL,
    CHECK (IdActivo GLOB '[A-Z][A-Z][A-Z]-[A-Z][A-Z][A-Z]-[0-9][0-9][0-9][0-9]'),
    CHECK (substr(IdActivo, 5, 3) = Tipo),
    CHECK (Estado IN ('EM_STOCK','ATRIBUIDO','EM_REPARACAO','PERDIDO_ROUBADO','ABATIDO'))
);
CREATE UNIQUE INDEX UX_ATV_ACTIVO_SERIE ON ATV_ACTIVO(NumSerie) WHERE NumSerie IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_IMEI1 ON ATV_ACTIVO(Imei1) WHERE Imei1 IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_IMEI2 ON ATV_ACTIVO(Imei2) WHERE Imei2 IS NOT NULL;
CREATE UNIQUE INDEX UX_ATV_ACTIVO_MAC ON ATV_ACTIVO(MacBluetooth) WHERE MacBluetooth IS NOT NULL;

CREATE TABLE ATV_ASSOCIACAO (
    IdAssociacao INTEGER PRIMARY KEY AUTOINCREMENT,
    IdSmartphone TEXT NOT NULL REFERENCES ATV_ACTIVO(IdActivo), IdImpressora TEXT NOT NULL REFERENCES ATV_ACTIVO(IdActivo),
    DataInicio TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, DataFim TEXT NULL, RegistadoPor TEXT NOT NULL,
    CHECK (substr(IdSmartphone, 5, 3) = 'SMT'), CHECK (substr(IdImpressora, 5, 3) = 'IMP'),
    CHECK (DataFim IS NULL OR DataFim >= DataInicio)
);
CREATE UNIQUE INDEX UX_ATV_ASSOC_SMT_VIGOR ON ATV_ASSOCIACAO(IdSmartphone) WHERE DataFim IS NULL;
CREATE UNIQUE INDEX UX_ATV_ASSOC_IMP_VIGOR ON ATV_ASSOCIACAO(IdImpressora) WHERE DataFim IS NULL;

CREATE TABLE ATV_DOCUMENTO (
    IdDocumento INTEGER PRIMARY KEY AUTOINCREMENT, NumDocumento TEXT NOT NULL UNIQUE, TipoDocumento TEXT NOT NULL,
    Estado TEXT NOT NULL DEFAULT 'A_ASSINAR', IdColaborador INTEGER NULL REFERENCES ATV_COLABORADOR(IdColaborador),
    DataEmissao TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, EmitidoPor TEXT NOT NULL, HashOriginal TEXT NOT NULL,
    FicheiroPdf TEXT NOT NULL, HashFinal TEXT NULL, DataConclusao TEXT NULL, MotivoAnulacao TEXT NULL,
    CHECK (TipoDocumento IN ('TERMO_ENTREGA','AUTO_DEVOLUCAO','AUTO_SUBSTITUICAO','PARTICIPACAO_PERDA','AUTO_ABATE','CONVENCAO_PROVA')),
    CHECK (Estado IN ('A_ASSINAR','ASSINADO','ANULADO')),
    CHECK (Estado <> 'ASSINADO' OR (HashFinal IS NOT NULL AND DataConclusao IS NOT NULL)),
    CHECK (Estado <> 'ANULADO' OR MotivoAnulacao IS NOT NULL)
);

CREATE TABLE ATV_DOCUMENTO_ACTIVO (
    IdDocumento INTEGER NOT NULL REFERENCES ATV_DOCUMENTO(IdDocumento), IdActivo TEXT NOT NULL REFERENCES ATV_ACTIVO(IdActivo),
    Papel TEXT NOT NULL, Acessorios TEXT NULL, EstadoFisico TEXT NULL,
    PRIMARY KEY (IdDocumento, IdActivo), CHECK (Papel IN ('ENTREGUE','DEVOLVIDO','ABRANGIDO'))
);

CREATE TABLE ATV_ASSINATURA (
    IdAssinatura INTEGER PRIMARY KEY AUTOINCREMENT, IdDocumento INTEGER NOT NULL REFERENCES ATV_DOCUMENTO(IdDocumento),
    Ordem INTEGER NOT NULL, NomeSignatario TEXT NOT NULL, Qualidade TEXT NOT NULL,
    IdColaborador INTEGER NULL REFERENCES ATV_COLABORADOR(IdColaborador), UtilizadorPortal TEXT NULL,
    MetodoAutenticacao TEXT NOT NULL, UtilizadorTestemunha TEXT NULL, TelemovelOtp TEXT NULL,
    DataHora TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, EnderecoIp TEXT NOT NULL, Navegador TEXT NULL,
    Latitude NUMERIC NULL, Longitude NUMERIC NULL, HashDocumento TEXT NOT NULL, ImagemAssinatura TEXT NOT NULL,
    UNIQUE (IdDocumento, Ordem),
    CHECK (MetodoAutenticacao IN ('CONTA_PORTAL','PRESENCIAL','OTP_SMS','CERTIFICADO_QUALIFICADO')),
    CHECK (MetodoAutenticacao <> 'PRESENCIAL' OR (UtilizadorTestemunha IS NOT NULL AND IdColaborador IS NOT NULL)),
    CHECK (MetodoAutenticacao <> 'OTP_SMS' OR TelemovelOtp IS NOT NULL)
);

CREATE TABLE ATV_EVENTO (
    IdEvento INTEGER PRIMARY KEY AUTOINCREMENT, IdActivo TEXT NOT NULL REFERENCES ATV_ACTIVO(IdActivo),
    TipoEvento TEXT NOT NULL, DataHora TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, Utilizador TEXT NOT NULL,
    EstadoAntes TEXT NULL, EstadoDepois TEXT NULL, RotaAntes TEXT NULL, RotaDepois TEXT NULL,
    IdColaboradorAntes INTEGER NULL REFERENCES ATV_COLABORADOR(IdColaborador),
    IdColaboradorDepois INTEGER NULL REFERENCES ATV_COLABORADOR(IdColaborador),
    IdActivoRelacionado TEXT NULL REFERENCES ATV_ACTIVO(IdActivo), IdDocumento INTEGER NULL REFERENCES ATV_DOCUMENTO(IdDocumento),
    PedidoMdm TEXT NULL, Latitude NUMERIC NULL, Longitude NUMERIC NULL, Observacoes TEXT NULL, Anexo TEXT NULL,
    CHECK (TipoEvento IN ('ENTRADA','ETIQUETAGEM','ATRIBUICAO','DEVOLUCAO','ASSOCIACAO','DESASSOCIACAO',
        'REPARACAO_ENVIO','REPARACAO_REGRESSO','SUBSTITUICAO','PERDA_ROUBO','RECUPERACAO',
        'REPOSICAO_MDM','LEITURA_QR','INVENTARIO','ABATE','DOCUMENTO_ASSINADO'))
);
CREATE INDEX IX_ATV_EVENTO_ACTIVO ON ATV_EVENTO(IdActivo, DataHora DESC);

CREATE TRIGGER TR_ATV_EVENTO_UPD BEFORE UPDATE ON ATV_EVENTO BEGIN SELECT RAISE(ABORT, 'ATV_EVENTO e um registo de auditoria: nao e permitido alterar nem apagar eventos.'); END;
CREATE TRIGGER TR_ATV_EVENTO_DEL BEFORE DELETE ON ATV_EVENTO BEGIN SELECT RAISE(ABORT, 'ATV_EVENTO e um registo de auditoria: nao e permitido alterar nem apagar eventos.'); END;
CREATE TRIGGER TR_ATV_ASS_UPD BEFORE UPDATE ON ATV_ASSINATURA BEGIN SELECT RAISE(ABORT, 'ATV_ASSINATURA e um registo de evidencias: nao e permitido alterar nem apagar assinaturas.'); END;
CREATE TRIGGER TR_ATV_ASS_DEL BEFORE DELETE ON ATV_ASSINATURA BEGIN SELECT RAISE(ABORT, 'ATV_ASSINATURA e um registo de evidencias: nao e permitido alterar nem apagar assinaturas.'); END;
CREATE TRIGGER TR_ATV_DOC_DEL BEFORE DELETE ON ATV_DOCUMENTO BEGIN SELECT RAISE(ABORT, 'Os documentos nao podem ser apagados; um documento por assinar pode ser anulado.'); END;
CREATE TRIGGER TR_ATV_DOC_FECHADO BEFORE UPDATE ON ATV_DOCUMENTO WHEN OLD.Estado IN ('ASSINADO','ANULADO')
BEGIN SELECT RAISE(ABORT, 'Documento assinado ou anulado: o conteudo e definitivo.'); END;
CREATE TRIGGER TR_ATV_DOC_BLOQUEADO BEFORE UPDATE ON ATV_DOCUMENTO
WHEN NEW.HashOriginal <> OLD.HashOriginal OR NEW.FicheiroPdf <> OLD.FicheiroPdf OR NEW.NumDocumento <> OLD.NumDocumento OR NEW.TipoDocumento <> OLD.TipoDocumento
BEGIN SELECT RAISE(ABORT, 'O documento esta bloqueado desde a emissao: so o estado e o fecho podem ser actualizados.'); END;
CREATE TRIGGER TR_ATV_ASS_HASH BEFORE INSERT ON ATV_ASSINATURA
WHEN EXISTS (SELECT 1 FROM ATV_DOCUMENTO d WHERE d.IdDocumento = NEW.IdDocumento AND (d.Estado <> 'A_ASSINAR' OR NEW.HashDocumento <> d.HashOriginal))
BEGIN SELECT RAISE(ABORT, 'Assinatura recusada: o documento nao esta por assinar ou foi alterado depois da emissao.'); END;

CREATE VIEW VW_ATV_FICHA AS
SELECT a.*, c.Nome AS ResponsavelActual, COALESCE(s.IdImpressora, i.IdSmartphone) AS IdAssociado,
       COALESCE(s.DataInicio, i.DataInicio) AS AssociadoDesde
FROM ATV_ACTIVO a
LEFT JOIN ATV_COLABORADOR c ON c.IdColaborador = a.IdColaboradorActual
LEFT JOIN ATV_ASSOCIACAO s ON s.IdSmartphone = a.IdActivo AND s.DataFim IS NULL
LEFT JOIN ATV_ASSOCIACAO i ON i.IdImpressora = a.IdActivo AND i.DataFim IS NULL;

CREATE VIEW VW_ATV_SEM_TERMO AS
SELECT a.IdActivo, a.RotaActual, a.IdColaboradorActual FROM ATV_ACTIVO a
WHERE a.Estado = 'ATRIBUIDO' AND NOT EXISTS (
    SELECT 1 FROM ATV_DOCUMENTO_ACTIVO da JOIN ATV_DOCUMENTO d ON d.IdDocumento = da.IdDocumento
    WHERE da.IdActivo = a.IdActivo AND da.Papel = 'ENTREGUE' AND d.Estado = 'ASSINADO'
      AND d.TipoDocumento IN ('TERMO_ENTREGA','AUTO_SUBSTITUICAO') AND d.IdColaborador = a.IdColaboradorActual);

-- Carga inicial de teste
CREATE TABLE ATV_MIGRACAO (Versao INTEGER NOT NULL PRIMARY KEY, Nome TEXT NOT NULL, AplicadaEm TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, Hash TEXT NULL);
INSERT INTO ATV_MIGRACAO (Versao, Nome) VALUES (1, 'equipamentos-sqlserver.sql');
INSERT INTO ATV_TIPO VALUES ('SMT', 'Smartphone'), ('IMP', 'Impressora móvel');
INSERT INTO ATV_COLABORADOR (Nome, Funcao, Email, RotaActual) VALUES
    ('Andrea Santos', 'Comercial', '1001@naval.co.ao', '1001'),
    ('Cristina Sachuma', 'Comercial', '1002@naval.co.ao', '1002'),
    ('Joceil Prata', 'Comercial', '1003@naval.co.ao', '1003');
INSERT INTO ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, NumSerie, Imei1, Imei2, MdmDeviceId, Estado, RotaActual, IdColaboradorActual, CriadoPor) VALUES
    ('NAV-SMT-0001','SMT','Blackview','BV6200 Pro','BV6200ProNEU0031328','353326512163561','353326512163579','236708000000175195','ATRIBUIDO','1001',1,'carga inicial'),
    ('NAV-SMT-0002','SMT','Blackview','BV6200 Pro','BV6200ProNEU0031371','353326512164429','353326512164437',NULL,'ATRIBUIDO','1002',2,'carga inicial'),
    ('NAV-SMT-0003','SMT','Blackview','BV6200 Pro','BV6200ProNEU0031353','353326512164064','353326512164072',NULL,'ATRIBUIDO','1003',3,'carga inicial');
INSERT INTO ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, MacBluetooth, Estado, RotaActual, IdColaboradorActual, CriadoPor) VALUES
    ('NAV-IMP-0001','IMP','DDigital (SPRT)','DD-RMT9BT','00:12:5B:04:2E:BC','ATRIBUIDO','1001',1,'carga inicial'),
    ('NAV-IMP-0002','IMP','DDigital (SPRT)','DD-RMT9BT','00:12:5B:04:2E:49','ATRIBUIDO','1002',2,'carga inicial'),
    ('NAV-IMP-0003','IMP','DDigital (SPRT)','DD-RMT9BT','00:12:5B:04:2D:E7','ATRIBUIDO','1003',3,'carga inicial');
INSERT INTO ATV_ASSOCIACAO (IdSmartphone, IdImpressora, DataInicio, RegistadoPor) VALUES
    ('NAV-SMT-0001','NAV-IMP-0001','2026-10-31 10:00:00','carga inicial'),
    ('NAV-SMT-0002','NAV-IMP-0002','2026-10-31 10:00:00','carga inicial'),
    ('NAV-SMT-0003','NAV-IMP-0003','2026-10-31 10:00:00','carga inicial');
INSERT INTO ATV_EVENTO (IdActivo, TipoEvento, DataHora, Utilizador, EstadoDepois, RotaDepois, IdColaboradorDepois, Observacoes)
SELECT IdActivo, 'ATRIBUICAO', '2026-10-31 10:00:00', 'carga inicial', 'ATRIBUIDO', RotaActual, IdColaboradorActual, 'Carga inicial do registo de activos' FROM ATV_ACTIVO;
