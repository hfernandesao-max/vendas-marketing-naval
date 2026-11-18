@echo off
setlocal

set "PHP=C:\tools\php85\php.exe"
if not exist "%PHP%" set "PHP=php"
set "ACTUALIZADOR=%~dp0actualizar.php"

if not exist "%ACTUALIZADOR%" (
    echo FALHA: nao foi encontrado o actualizador: "%ACTUALIZADOR%"
    exit /b 1
)

"%PHP%" -v >nul 2>nul
if errorlevel 1 (
    echo FALHA: PHP nao encontrado. Ajuste o caminho em actualizar-manual.bat ou adicione PHP ao PATH.
    exit /b 1
)

echo.
echo === Estado actual e versao publicada ===
"%PHP%" "%ACTUALIZADOR%" estado
if errorlevel 1 exit /b 1

echo.
echo === Pre-verificacao: nao instala nem altera os ficheiros activos ===
"%PHP%" "%ACTUALIZADOR%" verificar
if errorlevel 1 (
    echo FALHA: a pre-verificacao encontrou um problema. A instalacao foi cancelada.
    exit /b 1
)

echo.
echo A instalacao respeita a aprovacao configurada no servidor.
echo Se a aprovacao for obrigatoria, aprove primeiro a versao na pagina Actualizacoes.
choice /C SN /N /M "Continuar com a actualizacao agora? [S/N] "
if errorlevel 2 (
    echo Actualizacao cancelada pelo utilizador.
    exit /b 0
)

echo.
echo === A aplicar a versao aprovada mais recente ===
"%PHP%" "%ACTUALIZADOR%" aplicar
if errorlevel 1 (
    echo FALHA: a actualizacao nao terminou. Consulte a mensagem acima e os registos do servidor.
    exit /b 1
)

echo.
echo === Estado final ===
"%PHP%" "%ACTUALIZADOR%" estado
if errorlevel 1 exit /b 1

echo.
echo Processo concluido. Confirme a versao instalada no estado acima.
exit /b 0
