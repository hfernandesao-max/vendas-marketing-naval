# Sala de resultados: sincronização automática do Winrest ao entrar

Objectivo: quando um comercial entra na sala do LCD de 75" com o Blackview no bolso, o telemóvel passa para a rede
Wi-Fi da sala e o Winrest vem para a frente e sincroniza as vendas, sem tocar no ecrã e sem nada ligado ao LCD.

## 1. Arquitectura (sem USB no LCD)

```
 Sala do LCD 75"                         Blackview (Android, Device Owner = ManageEngine MDM)
 ┌──────────────────────────┐            ┌───────────────────────────────────────────────────────┐
 │ Ponto de acesso Wi-Fi    │  Wi-Fi     │ Perfil Wi-Fi do MDM (SSID da sala, ligação automática)│
 │ só da sala (SSID próprio,│◀──────────▶│ Naval Sala (app companheira, gerida pelo MDM)         │
 │ potência baixa, 5 GHz)   │            │  1. vê a rede e o ponto de acesso da sala + o sinal   │
 └──────────────────────────┘            │  2. histerese: 2 leituras fortes ≥ −67 dBm = entrou   │
 ┌──────────────────────────┐            │  3. abre o Winrest em primeiro plano                  │
 │ LCD 75": painel da sala  │            │  4. avisa o portal (presença; quiosque opcional)      │
 │ do portal (v1/sala)      │            └───────────────┬───────────────────────────────────────┘
 └──────────────────────────┘                            │ HTTPS POST v1/sala/presenca
                                  ┌──────────────────────▼──────────────────────┐
                                  │ Portal RTM: regista quem está na sala;      │  API REST (OAuth)
                                  │ modo «kiosk»: associa o perfil de quiosque  │──────────────────▶ ManageEngine
                                  │ do Winrest e retira-o ao fim de 3 min       │                    MDM Plus
                                  └─────────────────────────────────────────────┘
```

**Detecção sem hardware na TV:** um ponto de acesso Wi-Fi **dedicado à sala**, com SSID próprio (ex.: `NAVAL-SALA`),
em 5 GHz e com a potência reduzida, de forma a que o sinal forte fique dentro da sala. A app só conta como «dentro»
quando está ligada a essa rede, a um dos BSSID desse ponto de acesso, com sinal ≥ −67 dBm em 2 leituras seguidas (até
90 s). Isto evita disparos no corredor. Se a sala usar a rede geral da empresa, a detecção fica imprecisa: o SSID
dedicado é a parte que mais pesa na fiabilidade.

**Histerese e espera** (configuráveis no MDM):

| Regra | Valor por omissão | Para quê |
|---|---|---|
| Entrada | 2 leituras ≥ −67 dBm em 90 s | não dispara ao passar à porta |
| Saída | 5 min sem a rede da sala | uma falha de sinal não conta como saída |
| Espera entre disparos | 30 min | entrar e sair durante a reunião não reabre o Winrest |

## 2. A sequência pedida e o que o Android permite

Confirmado na documentação oficial do Android e do ManageEngine. Os pontos 1 e 2 **não podem** ser feitos por uma
app, nem pelo Device Owner, da forma pedida; abaixo está a forma oficial de chegar ao mesmo resultado.

| Passo pedido | Como fica | Porquê |
|---|---|---|
| 1. Desligar os dados móveis | **Não se desligam.** Com o Wi-Fi da sala validado, o Android passa todo o tráfego para o Wi-Fi e os dados móveis ficam parados. | O Android não tem API para uma app (nem para o Device Owner) desligar os dados móveis; a função do telefone que o faz é reservada às apps do sistema e da operadora. O ManageEngine documenta a opção contrária (manter os dados sempre ligados). **Condição:** a rede da sala tem de ter Internet (ou chegar ao servidor do Winrest); numa rede sem Internet o Android continua a usar os dados móveis. |
| 2. Fechar as outras apps | **Modo «kiosk»:** o portal pede ao MDM o quiosque de uma só app no Winrest durante 3 min e retira-o sozinho. **Modo «app»:** o Winrest vem para a frente e as outras ficam em segundo plano. | Nenhuma app pode fechar outras. Só o Device Owner (o ManageEngine) pode trancar o telefone numa app (quiosque / Lock Task). |
| 3. Wi-Fi ligado e na rede da sala | **Sim.** Perfil Wi-Fi do MDM com ligação automática e Wi-Fi sempre ligado; a entrada **só é detectada já ligado à rede da sala**, por isso este passo está confirmado antes do 4. | O Android liga-se sozinho a uma rede do perfil do MDM quando ela aparece. |
| 4. Winrest em primeiro plano | **Sim**, pela excepção oficial do Android para apps com «sobrepor a outras apps» (concedida pelo MDM). | Desde o Android 10 uma app em segundo plano só abre outra com uma excepção; no Android 15 essa excepção exige uma janela de sobreposição visível (a app mostra um ponto de 1 px durante 3 s). |

**Recomendação:** começar no modo **«app»** (rápido, local, sem depender da API). Passar ao «kiosk» só se for mesmo
preciso garantir que nada mais corre durante a sincronização: o quiosque chega pelo MDM quando o telemóvel contacta o
servidor (normalmente em segundos, às vezes mais) e enquanto dura o comercial fica preso no Winrest.

## 3. Configuração no ManageEngine MDM Plus (Blackview em Device Owner)

1. **Rede da sala** · Device Mgmt › Profiles › Android › **Wi-Fi**: SSID da sala, segurança WPA2/WPA3, palavra-passe,
   **ligação automática**. Associar ao grupo dos comerciais.
2. **Wi-Fi sempre ligado** · no mesmo perfil, **Restrictions**: não permitir desligar o Wi-Fi nem alterar as redes
   (as opções de rede da lista do tenant; confirmar os nomes exactos na consola).
3. **App «Naval Sala»** · App Repository › adicionar a app (APK interno ou app privada no Managed Google Play) ›
   distribuir ao grupo, **instalação silenciosa**.
4. **Permissões sem perguntas** · na configuração gerida da app, **Custom App Permissions**: conceder Localização
   (incluindo em segundo plano) e Notificações. A permissão **«sobrepor a outras apps»** é especial: confirmar na
   consola se o tenant a concede por política; se não, a Informática liga-a uma vez em cada telemóvel na entrega
   (Definições › Apps › Naval Sala › Sobrepor a outras apps). É o único passo manual, feito uma só vez.
5. **Bateria / DuraSpeed** · configuração da app **Self Service (ME MDM)** › **Battery optimization**: colocar a
   «Naval Sala» (e o Winrest) na lista de excepções. O **DuraSpeed** (Blackview/MediaTek) não tem política no Android
   Enterprise: desligá-lo na entrega (Definições › DuraSpeed) e, se o tenant tiver OEMConfig para Blackview, impô-lo
   por aí. A app corre como serviço em primeiro plano, que o Android não encerra por poupança de energia.
6. **Configuração gerida da «Naval Sala»** (App Repository › Naval Sala › App Configuration):

   | Chave | Valor |
   |---|---|
   | `ssid_sala` | `Foodtec AP` |
   | `bssids_sala` | BSSID do(s) ponto(s) de acesso da sala, separados por vírgulas |
   | `rssi_entrada` / `leituras_entrada` | −67 / 2 (afinar no local) |
   | `saida_minutos` / `espera_minutos` | 5 / 30 |
   | `winrest_pacote` | `[INSERIR_PACKAGE_WINREST]` |
   | `modo` | `app` (ou `kiosk`) |
   | `rota` | a rota do telemóvel (ex.: `1001`), por equipamento ou com a variável do nome do equipamento, se a consola a oferecer. Sem ela, o LCD não consegue ligar a entrada à sincronização |
   | `dispositivo_mdm` | só no modo kiosk: ID do equipamento no MDM (variável do equipamento, se a consola a oferecer, ou por equipamento) |
   | `portal_url` / `portal_chave` | endereço do portal / o valor de `SALA_APP_CHAVE` |

7. **Só no modo kiosk** · Profiles › Android › **Kiosk** › **Single App** › Winrest. **Não associar a ninguém**: é o
   portal que o associa a um equipamento durante 3 min. Guardar o **ID do perfil**.

## 4. Configuração no portal (`.env` do servidor)

```
SALA_APP_CHAVE=<64 caracteres aleatórios>     # a mesma da configuração gerida da app
SALA_MODO=app                                  # ou kiosk
# só no modo kiosk:
SALA_MDM_PERFIL_KIOSK=<ID do perfil de quiosque do Winrest>
SALA_MDM_CAMINHO_PERFIS=/devices/{id}/profiles # CONFIRMAR na documentação da API do tenant (secção «Associate profiles to devices»)
SALA_KIOSK_MINUTOS=3
```

O modo kiosk precisa de um token do MDM com escrita: voltar a correr `php ferramentas/configurar-mdm.php` com os escopos
`MDMOnDemand.MDMInventory.READ,MDMOnDemand.MDMDeviceMgmt.READ,MDMOnDemand.MDMDeviceMgmt.UPDATE,MDMOnDemand.MDMDeviceMgmt.CREATE`.
O quiosque retira-se na rotina de 5 em 5 minutos (dura entre 3 e 8 min) ou logo à saída da sala.

## 5. Código

- `ferramentas/android-sala/`: projecto Android (Kotlin, minSdk 29, targetSdk 35).
  - `Presenca.kt`: máquina de estados pura (entrada, saída, espera), com testes em `app/src/test`.
  - `SalaServico.kt`: serviço em primeiro plano que acompanha o Wi-Fi (`NetworkCallback` com
    `FLAG_INCLUDE_LOCATION_INFO`, para ver SSID e BSSID) e lê o sinal a cada 30 s.
  - `Accoes.kt`: abrir o Winrest (com a janela de 1 px no Android 15) e avisar o portal.
  - `DiagnosticoActivity`: ecrã para a Informática ver a configuração recebida, as permissões e o estado.
- Portal: `POST v1/sala/presenca` (`nucleo/api/v1/sala-presenca.php`, `nucleo/servicos/sala-presenca.php`): regista a
  presença (o painel do LCD pode mostrar quem já sincronizou) e, no modo kiosk, associa e retira o quiosque.

Compilar: abrir `ferramentas/android-sala` no Android Studio (ou `./gradlew assembleRelease` com o Android SDK) e
assinar com a chave da empresa; os testes da máquina de estados correm com `./gradlew test`.

## 5b. O LCD e o aviso: a sincronização confirmada pelo Pinguim BO

- **A app avisa o portal** ao entrar na sala (`POST v1/sala/presenca`), com:
  - a rota;
  - a rede que as apps estão a usar nesse momento: `wifi` (Wi-Fi com Internet confirmada pelo Android), `wifi_sem_internet`, `dados_moveis` ou `sem_rede`;
  - se os dados móveis estão ligados.
- **O Pinguim BO prova a sincronização:** a tabela `SYNC_PHSE_HISTORY` regista cada sincronização de cada telemóvel (loja = rota), com a hora. A entrada só conta como «sincronizou» quando há uma sincronização **depois** da hora de entrada. O LCD não depende do que o telemóvel diz.
- **Ecrã «Chegadas e sincronização»** no painel da sala interna (Gestão › Painel da sala comercial › Ecrãs). Mostra cada rota que entrou hoje, com:
  - a hora de entrada e a rede;
  - ✔ «sincronizou às 15:06 (1 min)», ⏳ «a aguardar» (menos de 5 minutos) ou ✖ «sem sincronizar», com a hora da última sincronização.
- **Aviso** «Entrou na sala e não sincronizou» a quem acompanha a rota (notificação `sala_sem_sincronizar`), uma vez por entrada, 5 minutos depois da entrada sem sincronização. `SALA_AVISO_SINC=0` desliga.
- **O ecrã de diagnóstico da app** mostra a rede que as apps estão a usar e a rota configurada. Se aparecer «DADOS MÓVEIS» ou «Wi-Fi SEM Internet» com o telemóvel na sala, é essa a razão de o Winrest não sincronizar.

## 6. Plano de teste no local

1. Instalar o ponto de acesso da sala, medir o sinal à porta e ao fundo da sala (app de diagnóstico Wi-Fi) e
   escolher `rssi_entrada` entre os dois valores.
2. Num Blackview de teste: abrir «Naval Sala» (ecrã de diagnóstico) e confirmar «Configuração completa», «Sobrepor:
   sim», «Isento da bateria: sim».
3. Ecrã desligado, telemóvel no bolso: sair da sala 10 min, entrar e esperar 1 min → o Winrest fica em primeiro plano;
   o diagnóstico mostra «Na sala: sim».
4. Sair 2 min e voltar → não reabre (dentro da espera). Sair 6 min → «Na sala: não».
5. Repetir com o telemóvel reiniciado (o serviço arranca sozinho) e com 3 telemóveis ao mesmo tempo.
6. Modo kiosk (se for usado): confirmar o caminho da API, ligar `SALA_MODO=kiosk`, testar com um equipamento e
   verificar que o quiosque sai ao fim do prazo.
