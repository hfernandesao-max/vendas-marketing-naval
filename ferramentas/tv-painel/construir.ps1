# Constrói a app «Naval Painel» (TV da sala) e, com -Tv, instala-a e liga-a por adb na rede.
#
#   powershell -File ferramentas\tv-painel\construir.ps1                          só constrói
#   powershell -File ferramentas\tv-painel\construir.ps1 -Tv 192.168.41.103:5555  constrói, instala e liga o serviço
#
# Precisa do OpenJDK 17 e do Android SDK (build-tools 34, platforms;android-34) em -Ferramentas (por omissão
# %LOCALAPPDATA%\tv-sala\build: jdk\, sdk\) e do adb em %LOCALAPPDATA%\tv-sala\platform-tools. A chave de assinatura é
# criada na primeira vez nessa pasta (fora do repositório) e reutilizada: as actualizações instalam-se por cima.
param(
    [string]$Tv = '',
    [string]$Ferramentas = "$env:LOCALAPPDATA\tv-sala\build"
)
$ErrorActionPreference = 'Stop'
$fonte = $PSScriptRoot
$jdk = (Get-ChildItem "$Ferramentas\jdk" -Directory | Select-Object -First 1).FullName
$bt = "$Ferramentas\sdk\build-tools\34.0.0"
$androidJar = "$Ferramentas\sdk\platforms\android-34\android.jar"
$saida = "$Ferramentas\saida"
$env:JAVA_HOME = $jdk
$env:Path = "$jdk\bin;$env:Path"
if (Test-Path $saida) { Remove-Item -Recurse -Force $saida }
New-Item -ItemType Directory -Force "$saida\res", "$saida\classes", "$saida\dex" | Out-Null

& "$bt\aapt2.exe" compile --dir "$fonte\res" -o "$saida\res.zip"
if ($LASTEXITCODE) { throw 'aapt2 compile' }
& "$bt\aapt2.exe" link --debug-mode -o "$saida\base.apk" -I $androidJar --manifest "$fonte\AndroidManifest.xml" "$saida\res.zip"
if ($LASTEXITCODE) { throw 'aapt2 link' }
$java = Get-ChildItem "$fonte\src" -Recurse -Filter *.java | ForEach-Object { $_.FullName }
& javac --release 11 -classpath $androidJar -d "$saida\classes" $java
if ($LASTEXITCODE) { throw 'javac' }
$classes = Get-ChildItem "$saida\classes" -Recurse -Filter *.class | ForEach-Object { $_.FullName }
& "$bt\d8.bat" --min-api 26 --lib $androidJar --output "$saida\dex" $classes
if ($LASTEXITCODE) { throw 'd8' }
Copy-Item "$saida\base.apk" "$saida\sem-assinatura.apk"
Push-Location "$saida\dex"
& jar uf "$saida\sem-assinatura.apk" classes.dex
Pop-Location
& "$bt\zipalign.exe" -f 4 "$saida\sem-assinatura.apk" "$saida\alinhada.apk"
if ($LASTEXITCODE) { throw 'zipalign' }

$chave = "$Ferramentas\naval-painel.jks"
$senhaF = "$Ferramentas\naval-painel.senha"
if (-not (Test-Path $chave)) {
    $senha = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
    Set-Content -Path $senhaF -Value $senha -Encoding ascii -NoNewline
    & keytool -genkeypair -keystore $chave -storepass $senha -keypass $senha -alias painel -keyalg RSA -keysize 2048 -validity 10000 -dname 'CN=Naval Painel, O=Grupo Naval, C=AO' | Out-Null
}
$senha = Get-Content $senhaF -Raw
& "$bt\apksigner.bat" sign --ks $chave --ks-pass "pass:$senha" --out "$saida\naval-painel.apk" "$saida\alinhada.apk"
if ($LASTEXITCODE) { throw 'apksigner' }
Write-Output "APK: $saida\naval-painel.apk"

if ($Tv -ne '') {
    $adb = "$env:LOCALAPPDATA\tv-sala\platform-tools\adb.exe"
    & $adb connect $Tv | Out-Null
    & $adb -s $Tv install -r "$saida\naval-painel.apk"
    if ($LASTEXITCODE) { throw 'adb install' }
    $servico = 'ao.naval.painel/ao.naval.painel.PainelServico'
    $actuais = (& $adb -s $Tv shell settings get secure enabled_accessibility_services).Trim()
    if ($actuais -eq 'null' -or $actuais -eq '') { $novos = $servico }
    elseif ($actuais -notlike "*$servico*") { $novos = "$actuais`:$servico" }
    else { $novos = $actuais }
    & $adb -s $Tv shell settings put secure enabled_accessibility_services $novos
    & $adb -s $Tv shell settings put secure accessibility_enabled 1
    & $adb -s $Tv shell appops set ao.naval.painel AUTO_START allow
    # arranque: a app que o modo hotel da TCL abre ao ligar (o modo hotel tem de estar ligado: menu 6405 no Contraste)
    & $adb -s $Tv shell pm grant ao.naval.painel android.permission.WRITE_SECURE_SETTINGS
    # permissões permanentes (a TCL repõe as appops a cada arranque, mas não as permissões dadas com pm grant)
    & $adb -s $Tv shell pm grant ao.naval.painel android.permission.PACKAGE_USAGE_STATS
    & $adb -s $Tv shell pm grant ao.naval.painel android.permission.SYSTEM_ALERT_WINDOW
    & $adb -s $Tv shell settings put system tcl_app_hotel_mode_define_app ao.naval.painel/ao.naval.painel.Arranque
    Write-Output "Instalada e ligada na TV $Tv"
}
