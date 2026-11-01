-- Carga de teste (igual à de tests/dados/equipamentos-sqlite.sql) para o cenário do módulo Equipamentos no SQL Server.
-- Aplica-se depois do script oficial sem a «Carga inicial» (ver .github/workflows/equipamentos-sqlserver.yml).
INSERT INTO dbo.ATV_TIPO (Codigo, Descricao) VALUES ('SMT', N'Smartphone'), ('IMP', N'Impressora móvel');
INSERT INTO dbo.ATV_COLABORADOR (Nome, Funcao, Email, RotaActual) VALUES
    (N'Andrea Santos', N'Comercial', '1001@naval.co.ao', '1001'),
    (N'Cristina Sachuma', N'Comercial', '1002@naval.co.ao', '1002'),
    (N'Joceil Prata', N'Comercial', '1003@naval.co.ao', '1003');
INSERT INTO dbo.ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, NumSerie, Imei1, Imei2, MdmDeviceId, Estado, RotaActual, IdColaboradorActual, CriadoPor) VALUES
    ('NAV-SMT-0001','SMT',N'Blackview',N'BV6200 Pro','BV6200ProNEU0031328','353326512163561','353326512163579','236708000000175195','ATRIBUIDO','1001',1,N'carga inicial'),
    ('NAV-SMT-0002','SMT',N'Blackview',N'BV6200 Pro','BV6200ProNEU0031371','353326512164429','353326512164437',NULL,'ATRIBUIDO','1002',2,N'carga inicial'),
    ('NAV-SMT-0003','SMT',N'Blackview',N'BV6200 Pro','BV6200ProNEU0031353','353326512164064','353326512164072',NULL,'ATRIBUIDO','1003',3,N'carga inicial');
INSERT INTO dbo.ATV_ACTIVO (IdActivo, Tipo, Marca, Modelo, MacBluetooth, Estado, RotaActual, IdColaboradorActual, CriadoPor) VALUES
    ('NAV-IMP-0001','IMP',N'DDigital (SPRT)',N'DD-RMT9BT','00:12:5B:04:2E:BC','ATRIBUIDO','1001',1,N'carga inicial'),
    ('NAV-IMP-0002','IMP',N'DDigital (SPRT)',N'DD-RMT9BT','00:12:5B:04:2E:49','ATRIBUIDO','1002',2,N'carga inicial'),
    ('NAV-IMP-0003','IMP',N'DDigital (SPRT)',N'DD-RMT9BT','00:12:5B:04:2D:E7','ATRIBUIDO','1003',3,N'carga inicial');
INSERT INTO dbo.ATV_ASSOCIACAO (IdSmartphone, IdImpressora, DataInicio, RegistadoPor) VALUES
    ('NAV-SMT-0001','NAV-IMP-0001','2026-10-31 10:00:00',N'carga inicial'),
    ('NAV-SMT-0002','NAV-IMP-0002','2026-10-31 10:00:00',N'carga inicial'),
    ('NAV-SMT-0003','NAV-IMP-0003','2026-10-31 10:00:00',N'carga inicial');
INSERT INTO dbo.ATV_EVENTO (IdActivo, TipoEvento, DataHora, Utilizador, EstadoDepois, RotaDepois, IdColaboradorDepois, Observacoes)
SELECT IdActivo, 'ATRIBUICAO', '2026-10-31 10:00:00', N'carga inicial', 'ATRIBUIDO', RotaActual, IdColaboradorActual, N'Carga inicial do registo de activos' FROM dbo.ATV_ACTIVO;
GO
