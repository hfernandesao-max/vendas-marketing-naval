// Idioma da página de entrada e da página «Mudar a palavra-passe» (PT/EN/TI). A escolha fica no navegador (a mesma chave do portal, vm-lang) e só serve
// para ler esta página: depois de entrar, o portal abre no idioma definido na ficha da conta.
// Os textos estão em português no HTML (marcados com data-t, data-t-ph e data-t-aria); as mensagens do servidor
// traduzem-se pelos modelos abaixo, com as partes variáveis ({n}).
(function () {
  var IDIOMAS = { pt: 'pt-PT', en: 'en', ti: 'ti' };
  var D = {
    en: {
      'Instalar a app · Portal RTM':
        'Install the app · Portal RTM',
      'Código expirado':
        'Code expired',
      'Este código QR expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.':
        'This QR code has expired or has already been used. In the portal, open «Mobile app» and scan a new code.',
      'O código expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.':
        'The code has expired or has already been used. In the portal, open «Mobile app» and scan a new code.',
      'A app só se instala pelo código QR pessoal do portal: assim fica registado quem a instalou e em que equipamento.':
        'The app can only be installed with the personal QR code from the portal: this records who installed it and on which device.',
      'Instalar a app':
        'Install the app',
      'Abra este endereço no Safari: só o Safari consegue instalar a app no iPhone.':
        'Open this address in Safari: only Safari can install the app on the iPhone.',
      'Toque em Partilhar':
        'Tap Share',
      'na barra do Safari (se não aparecer, toque primeiro em ⋯).':
        'in the Safari bar (if it does not appear, tap ⋯ first).',
      'Escolha «Adicionar ao ecrã principal».':
        'Choose «Add to Home Screen».',
      'Deixe ligado «Abrir como app web» e toque em «Adicionar».':
        'Leave «Open as Web App» on and tap «Add».',
      'Abra a app pelo ícone no ecrã principal: fica activada neste iPhone e pede o Face ID.':
        'Open the app from its icon on the Home Screen: it is activated on this iPhone and asks for Face ID.',
      'Toque em «Instalar a app». Depois, abra-a pelo ícone: fica activada neste telemóvel e pede a biometria.':
        'Tap «Install the app». Then open it from its icon: it is activated on this phone and asks for your biometrics.',
      'Abra o menu ⋮ do navegador.':
        'Open the browser\'s ⋮ menu.',
      'Escolha «Instalar aplicação» ou «Adicionar ao ecrã principal».':
        'Choose «Install app» or «Add to Home screen».',
      'Abra a app pelo ícone no ecrã principal.':
        'Open the app from its icon on the Home screen.',
      'App instalada. Abra-a pelo ícone no ecrã principal para a activar.':
        'App installed. Open it from its icon on the Home screen to activate it.',
      'Abra esta página no telemóvel: leia o código QR do portal com a câmara do telemóvel.':
        'Open this page on your phone: scan the portal\'s QR code with the phone camera.',
      'Código válido até':
        'Code valid until',
      'uso único':
        'single use',
      'Activar a app · Portal RTM':
        'Activate the app · Portal RTM',
      'A activar a app…':
        'Activating the app…',
      'A app activa-se ao abri-la pelo ícone no ecrã principal, não no navegador.':
        'The app is activated by opening it from its icon on the Home screen, not in the browser.',
      'Agora não':
        'Not now',
      'Este telemóvel não tem Face ID, impressão digital nem código de bloqueio activos. Nas próximas entradas use a palavra-passe e o código de verificação.':
        'This phone has no Face ID, fingerprint or screen lock code enabled. Next time, sign in with your password and verification code.',
      'Continuar':
        'Continue',
      'Não foi possível activar a app':
        'The app could not be activated',
      'Abra a app pelo ícone':
        'Open the app from its icon',
      'App activada':
        'App activated',
      'Proteja a entrada com o Face ID: nas próximas vezes basta olhar para o telemóvel.':
        'Protect sign-in with Face ID: next time, just look at your phone.',
      'Proteja a entrada com a impressão digital: nas próximas vezes basta tocar no leitor.':
        'Protect sign-in with your fingerprint: next time, just touch the sensor.',
      'Activar o Face ID':
        'Turn on Face ID',
      'Activar a impressão digital':
        'Turn on fingerprint',
      'A biometria não foi activada.':
        'Biometrics were not turned on.',
      'A biometria foi cancelada.':
        'Biometrics were cancelled.',
      'Sem resposta do servidor.':
        'No response from the server.',
      'Instalar pelo código QR · Portal RTM':
        'Install with the QR code · Portal RTM',
      'Instale a app pelo código QR':
        'Install the app with the QR code',
      'A app só funciona nos equipamentos registados. Para a instalar neste telemóvel:':
        'The app only works on registered devices. To install it on this phone:',
      'Num computador, entre no portal e abra o seu nome › «App no telemóvel».':
        'On a computer, sign in to the portal and open your name › «Mobile app».',
      'Leia o código QR com a câmara deste telemóvel e siga os passos.':
        'Scan the QR code with this phone\'s camera and follow the steps.',
      'Se já tinha a app noutro telemóvel, esse deixa de funcionar: cada pessoa tem um equipamento activo.':
        'If you already had the app on another phone, that one stops working: each person has one active device.',
      'Terminar sessão':
        'Sign out',
      'ou entre com a palavra-passe':
        'or sign in with your password',
      'Entrar com Face ID':
        'Sign in with Face ID',
      'Entrar com a impressão digital':
        'Sign in with fingerprint',
      'Esta app não foi instalada pelo código QR do portal. Apague-a e, no portal (num computador), abra «App no telemóvel» e leia o código QR com a câmara.':
        'This app was not installed with the portal\'s QR code. Delete it and, in the portal (on a computer), open «Mobile app» and scan the QR code with the camera.',
      'Esta app foi desactivada ou activada noutro equipamento. No portal, abra «App no telemóvel» e leia o código QR.':
        'This app has been deactivated or activated on another device. In the portal, open «Mobile app» and scan the QR code.',
      'A biometria ainda não está activada neste equipamento. Entre com a palavra-passe.':
        'Biometrics are not yet turned on for this device. Sign in with your password.',
      'Não foi possível confirmar a biometria. Tente de novo ou entre com a palavra-passe.':
        'Biometrics could not be confirmed. Try again or sign in with your password.',
      'Recuperar o acesso · GeoMobilidade Naval':
        'Recover access · GeoMobilidade Naval',
      'Recuperar o acesso':
        'Recover access',
      'Se os dados corresponderem a uma conta activa, enviámos para o correio electrónico dessa conta uma ligação para escolher uma nova palavra-passe. A ligação é válida durante 30 minutos e só pode ser usada uma vez.':
        'If the details match an active account, we have sent a link to that account’s email to choose a new password. The link is valid for 30 minutes and can only be used once.',
      'Não recebeu? Confirme a pasta de lixo electrónico ou peça ajuda ao super administrador da plataforma.':
        'Did not receive it? Check your junk mail folder or ask the platform super administrator for help.',
      'Palavra-passe alterada e conta desbloqueada. As sessões que estavam abertas foram terminadas. Entre com a nova palavra-passe.':
        'Password changed and account unlocked. Any open sessions have been ended. Sign in with your new password.',
      'Iniciar sessão':
        'Sign in',
      'A ligação expirou ou já foi usada. Peça uma nova.':
        'The link has expired or has already been used. Request a new one.',
      'Pedir uma nova ligação':
        'Request a new link',
      'Escolha a nova palavra-passe: pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. A conta fica desbloqueada e as sessões abertas terminam.':
        'Choose your new password: at least 12 characters (it can be a phrase), different from the account name and not a common one. The account is unlocked and open sessions are ended.',
      'Código da aplicação de autenticação':
        'Authenticator app code',
      'Guardar e desbloquear':
        'Save and unlock',
      'Esqueceu-se da palavra-passe ou a conta ficou bloqueada? Indique o seu utilizador ou correio electrónico: enviamos para o correio da conta uma ligação para escolher uma nova palavra-passe e desbloquear a conta.':
        'Forgot your password or is your account locked? Enter your username or email: we will send a link to the account’s email to choose a new password and unlock the account.',
      'Enviar a ligação':
        'Send the link',
      'Voltar à página de entrada':
        'Back to the sign-in page',
      'Esqueceu-se da palavra-passe ou tem a conta bloqueada?':
        'Forgot your password or is your account locked?',
      'Código inválido. Por segurança, a ligação foi anulada: peça uma nova.':
        'Invalid code. For security, the link has been cancelled: request a new one.',
      'Código de verificação inválido. Resta 1 tentativa.':
        'Invalid verification code. 1 attempt left.',
      'Código de verificação inválido. Restam {n} tentativas.':
        'Invalid verification code. {n} attempts left.',
      'Mudar a palavra-passe · GeoMobilidade Naval':
        'Change password · GeoMobilidade Naval',
      'Mudar a palavra-passe':
        'Change password',
      'Pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. As outras sessões abertas com esta conta terminam.':
        'At least 12 characters (it can be a phrase), different from the account name and not a common one. Other open sessions with this account are ended.',
      'Palavra-passe actual':
        'Current password',
      'Guardar':
        'Save',
      'Palavra-passe alterada.':
        'Password changed.',
      'As contas ainda estão na lista antiga: a palavra-passe muda-se depois de activar a gestão de contas no portal.':
        'The accounts are still on the old list: the password can be changed after account management is activated in the portal.',
      'A página expirou. Tente de novo.':
        'The page has expired. Please try again.',
      'A palavra-passe actual está incorrecta.':
        'The current password is incorrect.',
      'As duas palavras-passe novas não são iguais.':
        'The two new passwords do not match.',
      'A palavra-passe nova tem de ser diferente da actual.':
        'The new password must be different from the current one.',
      'Entrou com uma palavra-passe temporária. Escolha agora a sua palavra-passe: pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum.':
        'You signed in with a temporary password. Choose your own password now: at least 12 characters (it can be a phrase), different from the account name and not a common one.',
      'A página de entrada expirou. Tente de novo.':
        'The sign-in page has expired. Please try again.',
      'Demasiadas tentativas falhadas a partir deste endereço. Aguarde 15 minutos e tente de novo.':
        'Too many failed attempts from this address. Wait 15 minutes and try again.',
      'Código inválido. Confirme que a hora do telemóvel está certa. Resta 1 tentativa.':
        'Invalid code. Check that your phone\'s time is correct. 1 attempt left.',
      'Código inválido. Confirme que a hora do telemóvel está certa. Restam {n} tentativas.':
        'Invalid code. Check that your phone\'s time is correct. {n} attempts left.',
      'A palavra-passe temporária expirou (válida {n} horas). Peça uma nova ao super administrador.':
        'The temporary password has expired (valid for {n} hours). Ask the super administrator for a new one.',
      'A palavra-passe tem de ter pelo menos {n} caracteres (pode usar uma frase).':
        'The password must have at least {n} characters (you can use a phrase).',
      'A palavra-passe é demasiado longa (máximo 64 caracteres).':
        'The password is too long (maximum 64 characters).',
      'Esta palavra-passe é muito comum ou apareceu em fugas de dados. Escolha outra.':
        'This password is very common or has appeared in data breaches. Choose another one.',
      'A palavra-passe não pode ser o nome da conta, do email ou da empresa com números ou símbolos.':
        'The password cannot be the account, email or company name with numbers or symbols.',
      'A palavra-passe é demasiado previsível.':
        'The password is too predictable.',
      'O seu perfil exige a verificação em dois passos. Leia o código QR com uma aplicação de autenticação (Microsoft Authenticator, Google Authenticator…) e insira o código de 6 dígitos que aparece.':
        'Your profile requires two-step verification. Scan the QR code with an authenticator app (Microsoft Authenticator, Google Authenticator…) and enter the 6-digit code it shows.',
      'Ou escreva a chave:':
        'Or type the key:',
      'Código da aplicação':
        'App code',
      'Iniciar sessão · GeoMobilidade Naval': 'Sign in · GeoMobilidade Naval',
      'Módulo operacional': 'Operations module',
      Idioma: 'Language',
      Tema: 'Theme',
      'Tema do sistema': 'System theme',
      'Tema claro': 'Light theme',
      'Tema escuro': 'Dark theme',
      'Nova palavra-passe': 'New password',
      'Confirmar a nova palavra-passe': 'Confirm the new password',
      'Código de verificação': 'Verification code',
      'Código de 6 dígitos': '6-digit code',
      'Abra a aplicação de autenticação no telemóvel e insira o código de GeoMobilidade Naval.':
        'Open the authenticator app on your phone and enter the GeoMobilidade Naval code.',
      'Utilizador ou correio electrónico': 'Username or email',
      'Palavra-passe': 'Password',
      'Insira a sua palavra-passe': 'Enter your password',
      'Mostrar palavra-passe': 'Show password',
      'Ocultar palavra-passe': 'Hide password',
      Entrar: 'Sign in',
      'Voltar à página inicial': 'Back to the home page',
      'Todos os direitos reservados.': 'All rights reserved.',
      'Por favor, preencha todos os campos.': 'Please fill in all fields.',
      'A conta está bloqueada após {n} tentativas falhadas. Para a desbloquear já, use «Esqueceu-se da palavra-passe ou tem a conta bloqueada?» ou peça ao super administrador.':
        'The account is locked after {n} failed attempts. To unlock it now, use «Forgot your password or is your account locked?» or ask the super administrator.',
      'Esta conta não tem correio electrónico associado. Peça ao administrador da plataforma para o associar.':
        'This account has no email linked. Ask the platform administrator to link one.',
      'Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.':
        'This account is deactivated or has expired. Contact the platform administrator.',
      'Utilizador ou palavra-passe incorrectos. Resta 1 tentativa antes de a conta ser bloqueada.':
        'Incorrect username or password. 1 attempt left before the account is locked.',
      'Utilizador ou palavra-passe incorrectos. Restam {n} tentativas antes de a conta ser bloqueada.':
        'Incorrect username or password. {n} attempts left before the account is locked.',
      'Código inválido. Resta 1 tentativa antes de a conta ser bloqueada.': 'Invalid code. 1 attempt left before the account is locked.',
      'Código inválido. Restam {n} tentativas antes de a conta ser bloqueada.': 'Invalid code. {n} attempts left before the account is locked.',
      'As duas palavras-passe não são iguais.': 'The two passwords do not match.',
      'Escolha uma palavra-passe diferente da temporária.': 'Choose a password different from the temporary one.',
    },
    ti: {
      'Instalar a app · Portal RTM':
        'ኣፕ ምጽዓን · Portal RTM',
      'Código expirado':
        'ኮድ ግዜኡ ሓሊፉ',
      'Este código QR expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.':
        'እዚ QR ኮድ ግዜኡ ሓሊፉ ወይ ተጠቒሙሉ እዩ። ኣብ ፖርታል «ኣፕ ኣብ ሞባይል» ከፊትኩም ሓድሽ ኮድ ኣንብቡ።',
      'O código expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.':
        'እቲ ኮድ ግዜኡ ሓሊፉ ወይ ተጠቒሙሉ እዩ። ኣብ ፖርታል «ኣፕ ኣብ ሞባይል» ከፊትኩም ሓድሽ ኮድ ኣንብቡ።',
      'A app só se instala pelo código QR pessoal do portal: assim fica registado quem a instalou e em que equipamento.':
        'እቲ ኣፕ ብውልቃዊ QR ኮድ ናይቲ ፖርታል ጥራይ ይጽዓን፦ ከምኡ መን ከም ዝጸዓኖን ኣብ ኣየናይ መሳርሒን ይምዝገብ።',
      'Instalar a app':
        'ኣፕ ጽዓን',
      'Abra este endereço no Safari: só o Safari consegue instalar a app no iPhone.':
        'ነዚ ኣድራሻ ብ ሳፋሪ ክፈትዎ፦ ኣብ ኣይፎን ነቲ ኣፕ ዝጽዕን ሳፋሪ ጥራይ እዩ።',
      'Toque em Partilhar':
        'ኣካፍል ጠውቑ',
      'na barra do Safari (se não aparecer, toque primeiro em ⋯).':
        'ኣብ መስመር ሳፋሪ (እንተዘይተራእዩ፣ መጀመርያ ⋯ ጠውቑ)።',
      'Escolha «Adicionar ao ecrã principal».':
        '«ናብ ቀንዲ ስክሪን ወስኽ» ምረጹ።',
      'Deixe ligado «Abrir como app web» e toque em «Adicionar».':
        '«ከም መርበብ ኣፕ ክፈት» ብርሁ ግደፍዎ እሞ «ወስኽ» ጠውቑ።',
      'Abra a app pelo ícone no ecrã principal: fica activada neste iPhone e pede o Face ID.':
        'ነቲ ኣፕ ብምልክቱ ኣብ ቀንዲ ስክሪን ክፈትዎ፦ ኣብዚ ኣይፎን ይነጥፍ እሞ ገጽ ይሓትት።',
      'Toque em «Instalar a app». Depois, abra-a pelo ícone: fica activada neste telemóvel e pede a biometria.':
        '«ኣፕ ጽዓን» ጠውቑ። ድሕሪኡ ብምልክቱ ክፈትዎ፦ ኣብዚ ሞባይል ይነጥፍ እሞ ባዮሜትሪ ይሓትት።',
      'Abra o menu ⋮ do navegador.':
        'ዝርዝር ⋮ ናይቲ መርበብ ክፈቱ።',
      'Escolha «Instalar aplicação» ou «Adicionar ao ecrã principal».':
        '«ኣፕ ጽዓን» ወይ «ናብ ቀንዲ ስክሪን ወስኽ» ምረጹ።',
      'Abra a app pelo ícone no ecrã principal.':
        'ነቲ ኣፕ ብምልክቱ ኣብ ቀንዲ ስክሪን ክፈትዎ።',
      'App instalada. Abra-a pelo ícone no ecrã principal para a activar.':
        'ኣፕ ተጻዒኑ። ንምንጣፉ ብምልክቱ ኣብ ቀንዲ ስክሪን ክፈትዎ።',
      'Abra esta página no telemóvel: leia o código QR do portal com a câmara do telemóvel.':
        'ነዚ ገጽ ብሞባይል ክፈትዎ፦ QR ኮድ ናይቲ ፖርታል ብካሜራ ሞባይል ኣንብቡ።',
      'Código válido até':
        'ኮድ ክሳብ ዝሰርሕ',
      'uso único':
        'ሓንሳብ ጥራይ',
      'Activar a app · Portal RTM':
        'ኣፕ ምንጣፍ · Portal RTM',
      'A activar a app…':
        'ኣፕ ይነጥፍ ኣሎ…',
      'A app activa-se ao abri-la pelo ícone no ecrã principal, não no navegador.':
        'እቲ ኣፕ ብምልክቱ ኣብ ቀንዲ ስክሪን ብምኽፋት ይነጥፍ፣ ኣብ መርበብ ኣይኮነን።',
      'Agora não':
        'ሕጂ ኣይኮነን',
      'Este telemóvel não tem Face ID, impressão digital nem código de bloqueio activos. Nas próximas entradas use a palavra-passe e o código de verificação.':
        'እዚ ሞባይል ንጡፍ ገጽ፣ ኣሻራ ኣጻብዕ ወይ ናይ መዕጸዊ ኮድ የብሉን። ኣብ ዝቕጽል ብቓል ምሕላፍን ናይ ምርግጋጽ ኮድን እተዉ።',
      'Continuar':
        'ቀጽል',
      'Não foi possível activar a app':
        'ኣፕ ክነጥፍ ኣይከኣለን',
      'Abra a app pelo ícone':
        'ነቲ ኣፕ ብምልክቱ ክፈትዎ',
      'App activada':
        'ኣፕ ነጢፉ',
      'Proteja a entrada com o Face ID: nas próximas vezes basta olhar para o telemóvel.':
        'ምእታው ብገጽ ሓልዉ፦ ኣብ ዝቕጽል ናብ ሞባይል ምጥማት ጥራይ ይኣክል።',
      'Proteja a entrada com a impressão digital: nas próximas vezes basta tocar no leitor.':
        'ምእታው ብኣሻራ ኣጻብዕ ሓልዉ፦ ኣብ ዝቕጽል ነቲ መንበቢ ምትንካፍ ጥራይ ይኣክል።',
      'Activar o Face ID':
        'ገጽ ኣንጥፍ',
      'Activar a impressão digital':
        'ኣሻራ ኣጻብዕ ኣንጥፍ',
      'A biometria não foi activada.':
        'ባዮሜትሪ ኣይነጠፈን።',
      'A biometria foi cancelada.':
        'ባዮሜትሪ ተሰሪዙ።',
      'Sem resposta do servidor.':
        'ካብቲ ሰርቨር መልሲ የለን።',
      'Instalar pelo código QR · Portal RTM':
        'ብQR ኮድ ጽዓን · Portal RTM',
      'Instale a app pelo código QR':
        'ነቲ ኣፕ ብQR ኮድ ጽዓንዎ',
      'A app só funciona nos equipamentos registados. Para a instalar neste telemóvel:':
        'እቲ ኣፕ ኣብ ዝተመዝገቡ መሳርሒታት ጥራይ ይሰርሕ። ኣብዚ ሞባይል ንምጽዓኑ፦',
      'Num computador, entre no portal e abra o seu nome › «App no telemóvel».':
        'ብኮምፒተር ናብቲ ፖርታል እተዉ እሞ ስምኩም › «ኣፕ ኣብ ሞባይል» ክፈቱ።',
      'Leia o código QR com a câmara deste telemóvel e siga os passos.':
        'ነቲ QR ኮድ ብካሜራ ናይዚ ሞባይል ኣንብቡ እሞ ስጉምትታት ተኸተሉ።',
      'Se já tinha a app noutro telemóvel, esse deixa de funcionar: cada pessoa tem um equipamento activo.':
        'ኣብ ካልእ ሞባይል ኣፕ እንተነይርኩም፣ እቲ ምስራሕ የቋርጽ፦ ነፍሲ ወከፍ ሰብ ሓደ ንጡፍ መሳርሒ ኣለዎ።',
      'Terminar sessão':
        'ውዕል ዛዝም',
      'ou entre com a palavra-passe':
        'ወይ ብቓል ምሕላፍ እተዉ',
      'Entrar com Face ID':
        'ብገጽ እቶ',
      'Entrar com a impressão digital':
        'ብኣሻራ ኣጻብዕ እቶ',
      'Esta app não foi instalada pelo código QR do portal. Apague-a e, no portal (num computador), abra «App no telemóvel» e leia o código QR com a câmara.':
        'እዚ ኣፕ ብQR ኮድ ናይቲ ፖርታል ኣይተጻዕነን። ሰርዝዎ እሞ ኣብ ፖርታል (ብኮምፒተር) «ኣፕ ኣብ ሞባይል» ከፊትኩም ነቲ QR ኮድ ብካሜራ ኣንብቡ።',
      'Esta app foi desactivada ou activada noutro equipamento. No portal, abra «App no telemóvel» e leia o código QR.':
        'እዚ ኣፕ ተቛሪጹ ወይ ኣብ ካልእ መሳርሒ ነጢፉ። ኣብ ፖርታል «ኣፕ ኣብ ሞባይል» ከፊትኩም ነቲ QR ኮድ ኣንብቡ።',
      'A biometria ainda não está activada neste equipamento. Entre com a palavra-passe.':
        'ባዮሜትሪ ገና ኣብዚ መሳርሒ ኣይነጠፈን። ብቓል ምሕላፍ እተዉ።',
      'Não foi possível confirmar a biometria. Tente de novo ou entre com a palavra-passe.':
        'ባዮሜትሪ ክረጋገጽ ኣይከኣለን። ደጊምኩም ፈትኑ ወይ ብቓል ምሕላፍ እተዉ።',
      'Recuperar o acesso · GeoMobilidade Naval':
        'ኣእታውነት ምምላስ · GeoMobilidade Naval',
      'Recuperar o acesso':
        'ኣእታውነት ምምላስ',
      'Se os dados corresponderem a uma conta activa, enviámos para o correio electrónico dessa conta uma ligação para escolher uma nova palavra-passe. A ligação é válida durante 30 minutos e só pode ser usada uma vez.':
        'እቲ ሓበሬታ ምስ ንጡፍ ኣካውንት እንተ ተሰማሚዑ፡ ሓድሽ መሕለፊ ቃል ንምምራጽ መላግቦ ናብ ኢመይል ናይቲ ኣካውንት ሰዲድና ኣለና። እቲ መላግቦ ን30 ደቓይቕ ቅቡል እዩ፡ ሓንሳእ ጥራይ ይጥቀመሉ።',
      'Não recebeu? Confirme a pasta de lixo electrónico ou peça ajuda ao super administrador da plataforma.':
        'ኣይበጽሓኩምን? ናይ ጐሓፍ ኢመይል ፎልደር ርኣዩ ወይ ካብ ሱፐር ኣመሓዳሪ ሓገዝ ሕተቱ።',
      'Palavra-passe alterada e conta desbloqueada. As sessões que estavam abertas foram terminadas. Entre com a nova palavra-passe.':
        'መሕለፊ ቃል ተቐይሩ ኣካውንት ተኸፊቱ። ክፉታት ሰሽናት ተዛዚሞም። ብሓድሽ መሕለፊ ቃል እቶ።',
      'Iniciar sessão':
        'እቶ',
      'A ligação expirou ou já foi usada. Peça uma nova.':
        'እቲ መላግቦ ግዜኡ ኣኺሉ ወይ ድሮ ተጠቒምሉ። ሓድሽ ሕተቱ።',
      'Pedir uma nova ligação':
        'ሓድሽ መላግቦ ሕተት',
      'Escolha a nova palavra-passe: pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. A conta fica desbloqueada e as sessões abertas terminam.':
        'ሓድሽ መሕለፊ ቃል ምረጹ፦ እንተወሓደ 12 ፊደላት (ሓረግ ክኸውን ይኽእል)፡ ካብ ስም ኣካውንት ዝተፈልየን ልሙድ ዘይኮነን። ኣካውንት ይኽፈት፡ ክፉታት ሰሽናት ይዛዘሙ።',
      'Código da aplicação de autenticação':
        'ኮድ ናይ መረጋገጺ ኣፕ',
      'Guardar e desbloquear':
        'ዓቅብን ክፈትን',
      'Esqueceu-se da palavra-passe ou a conta ficou bloqueada? Indique o seu utilizador ou correio electrónico: enviamos para o correio da conta uma ligação para escolher uma nova palavra-passe e desbloquear a conta.':
        'መሕለፊ ቃል ረሲዕኩም ወይ ኣካውንት ተዓጽዩ? ተጠቃሚ ወይ ኢመይል ኣእትዉ፦ ሓድሽ መሕለፊ ቃል ንምምራጽን ኣካውንት ንምኽፋትን መላግቦ ናብ ኢመይል ናይቲ ኣካውንት ንሰድድ።',
      'Enviar a ligação':
        'መላግቦ ስደድ',
      'Voltar à página de entrada':
        'ናብ ገጽ መእተዊ ተመለስ',
      'Esqueceu-se da palavra-passe ou tem a conta bloqueada?':
        'መሕለፊ ቃል ረሲዕኩም ወይ ኣካውንት ተዓጽዩ?',
      'Código inválido. Por segurança, a ligação foi anulada: peça uma nova.':
        'ዘይቅቡል ኮድ። ንድሕነት እቲ መላግቦ ተሰሪዙ፦ ሓድሽ ሕተቱ።',
      'Código de verificação inválido. Resta 1 tentativa.':
        'ዘይቅቡል መረጋገጺ ኮድ። 1 ፈተነ ተሪፉ።',
      'Código de verificação inválido. Restam {n} tentativas.':
        'ዘይቅቡል መረጋገጺ ኮድ። {n} ፈተነታት ተሪፎም።',
      'Mudar a palavra-passe · GeoMobilidade Naval':
        'መሕለፊ ቃል ምቕያር · GeoMobilidade Naval',
      'Mudar a palavra-passe':
        'መሕለፊ ቃል ቀይር',
      'Pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. As outras sessões abertas com esta conta terminam.':
        'እንተወሓደ 12 ፊደላት (ሓረግ ክኸውን ይኽእል)፡ ካብ ስም ኣካውንት ዝተፈልየን ልሙድ ዘይኮነን። ካልኦት ብዚ ኣካውንት ዝተኸፍቱ ክፍለ ግዜታት ይዕጸዉ።',
      'Palavra-passe actual':
        'ናይ ሕጂ መሕለፊ ቃል',
      'Guardar':
        'ዓቅብ',
      'Palavra-passe alterada.':
        'መሕለፊ ቃል ተቐይሩ።',
      'As contas ainda estão na lista antiga: a palavra-passe muda-se depois de activar a gestão de contas no portal.':
        'ኣካውንታት ገና ኣብቲ ናይ ቀደም ዝርዝር ኣለዉ፦ መሕለፊ ቃል ድሕሪ ምሕደራ ኣካውንታት ኣብ ፖርታል ምንቕሳቑ ይቕየር።',
      'A página expirou. Tente de novo.':
        'ገጽ ግዜኡ ኣኺሉ። እንደገና ፈትኑ።',
      'A palavra-passe actual está incorrecta.':
        'ናይ ሕጂ መሕለፊ ቃል ጌጋ እዩ።',
      'As duas palavras-passe novas não são iguais.':
        'እተን ክልተ ሓደስቲ መሕለፊ ቃላት ኣይሰማምዓን።',
      'A palavra-passe nova tem de ser diferente da actual.':
        'ሓድሽ መሕለፊ ቃል ካብቲ ናይ ሕጂ ዝተፈልየ ክኸውን ኣለዎ።',
      'Entrou com uma palavra-passe temporária. Escolha agora a sua palavra-passe: pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum.':
        'ብግዝያዊ መሕለፊ ቃል ኣቲኹም። ሕጂ ናትኩም መሕለፊ ቃል ምረጹ፦ እንተወሓደ 12 ፊደላት (ሓረግ ክኸውን ይኽእል)፡ ካብ ስም ኣካውንት ዝተፈልየን ልሙድ ዘይኮነን።',
      'A página de entrada expirou. Tente de novo.':
        'ገጽ ምእታው ግዜኡ ኣኺሉ። እንደገና ፈትኑ።',
      'Demasiadas tentativas falhadas a partir deste endereço. Aguarde 15 minutos e tente de novo.':
        'ካብዚ ኣድራሻ ብዙሓት ዘይተዓወቱ ፈተነታት። 15 ደቓይቕ ተጸበዩ እሞ እንደገና ፈትኑ።',
      'Código inválido. Confirme que a hora do telemóvel está certa. Resta 1 tentativa.':
        'ዘይቅቡል ኮድ። ሰዓት ሞባይልኩም ልክዕ ምዃኑ ኣረጋግጹ። 1 ፈተነ ተሪፉ።',
      'Código inválido. Confirme que a hora do telemóvel está certa. Restam {n} tentativas.':
        'ዘይቅቡል ኮድ። ሰዓት ሞባይልኩም ልክዕ ምዃኑ ኣረጋግጹ። {n} ፈተነታት ተሪፎም።',
      'A palavra-passe temporária expirou (válida {n} horas). Peça uma nova ao super administrador.':
        'ግዝያዊ መሕለፊ ቃል ግዜኡ ኣኺሉ (ን{n} ሰዓታት ቅቡል)። ሓድሽ ካብ ሱፐር ኣመሓዳሪ ሕተቱ።',
      'A palavra-passe tem de ter pelo menos {n} caracteres (pode usar uma frase).':
        'መሕለፊ ቃል እንተወሓደ {n} ፊደላት ክህልዎ ኣለዎ (ሓረግ ክትጥቀሙ ትኽእሉ)።',
      'A palavra-passe é demasiado longa (máximo 64 caracteres).':
        'መሕለፊ ቃል ኣዝዩ ነዊሕ እዩ (ዝለዓለ 64 ፊደላት)።',
      'Esta palavra-passe é muito comum ou apareceu em fugas de dados. Escolha outra.':
        'እዚ መሕለፊ ቃል ኣዝዩ ልሙድ እዩ ወይ ኣብ ምስራቕ ሓበሬታ ተራእዩ። ካልእ ምረጹ።',
      'A palavra-passe não pode ser o nome da conta, do email ou da empresa com números ou símbolos.':
        'መሕለፊ ቃል ስም ኣካውንት፡ ኢመይል ወይ ትካል ምስ ቁጽርታት ወይ ምልክታት ክኸውን ኣይክእልን።',
      'A palavra-passe é demasiado previsível.':
        'መሕለፊ ቃል ኣዝዩ ዝግመት እዩ።',
      'O seu perfil exige a verificação em dois passos. Leia o código QR com uma aplicação de autenticação (Microsoft Authenticator, Google Authenticator…) e insira o código de 6 dígitos que aparece.':
        'ፕሮፋይልኩም ክልተ ደረጃ ምርግጋጽ የድልዮ። ኮድ QR ብመተግበሪ ምርግጋጽ (Microsoft Authenticator፡ Google Authenticator…) ኣንብቡ እሞ ዝረአ ኮድ 6 ኣሃዛት ኣእትዉ።',
      'Ou escreva a chave:':
        'ወይ መፍትሕ ጽሓፉ፦',
      'Código da aplicação':
        'ኮድ መተግበሪ',
      'Iniciar sessão · GeoMobilidade Naval': 'ምእታው · GeoMobilidade Naval',
      'Módulo operacional': 'ስራሓዊ ክፍሊ',
      Idioma: 'ቋንቋ',
      Tema: 'ቅዲ ገጽ',
      'Tema do sistema': 'ቅዲ ስርዓት',
      'Tema claro': 'ብሩህ ቅዲ',
      'Tema escuro': 'ጸልማት ቅዲ',
      'Nova palavra-passe': 'ሓድሽ መሕለፊ ቃል',
      'Confirmar a nova palavra-passe': 'ሓድሽ መሕለፊ ቃል ኣረጋግጹ',
      'Código de verificação': 'ኮድ ምርግጋጽ',
      'Código de 6 dígitos': 'ኮድ 6 ኣሃዛት',
      'Abra a aplicação de autenticação no telemóvel e insira o código de GeoMobilidade Naval.': 'ኣብ ሞባይልኩም መተግበሪ ምርግጋጽ ክፈቱ፡ ኮድ GeoMobilidade Naval ድማ ኣእትዉ።',
      'Utilizador ou correio electrónico': 'ተጠቃሚ ወይ ኢመይል',
      'Palavra-passe': 'መሕለፊ ቃል',
      'Insira a sua palavra-passe': 'መሕለፊ ቃልኩም ኣእትዉ',
      'Mostrar palavra-passe': 'መሕለፊ ቃል ኣርኢ',
      'Ocultar palavra-passe': 'መሕለፊ ቃል ሕባእ',
      Entrar: 'እቶ',
      'Voltar à página inicial': 'ናብ መበገሲ ገጽ ተመለስ',
      'Todos os direitos reservados.': 'ኩሉ መሰላት ዝተሓለወ እዩ።',
      'Por favor, preencha todos os campos.': 'በጃኹም ኩሉ ዓውድታት ምልኡ።',
      'A conta está bloqueada após {n} tentativas falhadas. Para a desbloquear já, use «Esqueceu-se da palavra-passe ou tem a conta bloqueada?» ou peça ao super administrador.':
        'ድሕሪ {n} ዘይተዓወቱ ፈተነታት እቲ ኣካውንት ተዓጽዩ። ሕጂ ንምኽፋቱ «መሕለፊ ቃል ረሲዕኩም ወይ ኣካውንት ተዓጽዩ?» ተጠቐሙ ወይ ሱፐር ኣመሓዳሪ ሕተቱ።',
      'Esta conta não tem correio electrónico associado. Peça ao administrador da plataforma para o associar.': 'እዚ ኣካውንት ኢመይል የብሉን። ኣመሓዳሪ መድረኽ ክውስኸሉ ሕተቱ።',
      'Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.': 'እዚ ኣካውንት ጠፊኡ ወይ ግዜኡ ኣኺሉ። ንኣመሓዳሪ መድረኽ ተወከሱ።',
      'Utilizador ou palavra-passe incorrectos. Resta 1 tentativa antes de a conta ser bloqueada.': 'ተጠቃሚ ወይ መሕለፊ ቃል ጌጋ እዩ። እቲ ኣካውንት ቅድሚ ምዕጻዉ 1 ፈተነ ተሪፉ።',
      'Utilizador ou palavra-passe incorrectos. Restam {n} tentativas antes de a conta ser bloqueada.':
        'ተጠቃሚ ወይ መሕለፊ ቃል ጌጋ እዩ። እቲ ኣካውንት ቅድሚ ምዕጻዉ {n} ፈተነታት ተሪፎም።',
      'Código inválido. Resta 1 tentativa antes de a conta ser bloqueada.': 'ዘይቅቡል ኮድ። እቲ ኣካውንት ቅድሚ ምዕጻዉ 1 ፈተነ ተሪፉ።',
      'Código inválido. Restam {n} tentativas antes de a conta ser bloqueada.': 'ዘይቅቡል ኮድ። እቲ ኣካውንት ቅድሚ ምዕጻዉ {n} ፈተነታት ተሪፎም።',
      'As duas palavras-passe não são iguais.': 'እተን ክልተ መሕለፊ ቃላት ኣይሰማምዓን።',
      'Escolha uma palavra-passe diferente da temporária.': 'ካብቲ ግዝያዊ ዝተፈልየ መሕለፊ ቃል ምረጹ።',
    },
  };
  var lang = 'pt';
  var titulo0 = document.title;
  try {
    var v = localStorage.getItem('vm-lang');
    if (v in IDIOMAS) lang = v;
  } catch (e) {}
  var esc = function (x) {
    return x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  };
  // Tradução: entrada exacta ou modelo com {n}
  function t(s) {
    var d = D[lang];
    if (!d || !s) return s;
    if (d[s] != null) return d[s];
    for (var k in d) {
      if (k.indexOf('{n}') < 0) continue;
      var m = s.match(new RegExp('^' + esc(k).replace('\\{n\\}', '(\\d+)') + '$'));
      if (m) return d[k].replace('{n}', m[1]);
    }
    return s;
  }
  // Guarda o texto original (português) para poder mudar de língua mais do que uma vez
  function orig(el, attr) {
    var k = attr === 'placeholder' ? 'ptPh' : attr === 'aria-label' ? 'ptAria' : 'ptTxt';
    if (el.dataset[k] == null) el.dataset[k] = attr ? el.getAttribute(attr) || '' : el.textContent.trim();
    return el.dataset[k];
  }
  function aplicar() {
    document.documentElement.lang = IDIOMAS[lang];
    document.title = t(titulo0);
    document.querySelectorAll('[data-t]').forEach(function (el) {
      el.textContent = t(orig(el));
    });
    document.querySelectorAll('[data-t-ph]').forEach(function (el) {
      el.setAttribute('placeholder', t(orig(el, 'placeholder')));
    });
    document.querySelectorAll('[data-t-aria]').forEach(function (el) {
      el.setAttribute('aria-label', t(orig(el, 'aria-label')));
    });
    document.querySelectorAll('#idioma button').forEach(function (b) {
      var on = b.getAttribute('data-l') === lang;
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    // Tema: Sistema / Claro / Escuro (estado em tema-arranque.js, partilhado com o portal)
    var tm = window.vmTema ? window.vmTema.escolha() : 'sistema';
    document.querySelectorAll('#tema button').forEach(function (b) {
      var on = b.getAttribute('data-tm') === tm;
      var nome = t(NOMES_TEMA[b.getAttribute('data-tm')]);
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', nome);
      b.title = nome;
    });
  }
  var NOMES_TEMA = { sistema: 'Tema do sistema', claro: 'Tema claro', escuro: 'Tema escuro' };
  document.querySelectorAll('#tema button').forEach(function (b) {
    b.addEventListener('click', function () {
      if (window.vmTema) window.vmTema.definir(b.getAttribute('data-tm'));
      aplicar();
    });
  });
  document.querySelectorAll('#idioma button').forEach(function (b) {
    b.addEventListener('click', function () {
      lang = b.getAttribute('data-l');
      try {
        localStorage.setItem('vm-lang', lang);
      } catch (e) {}
      aplicar();
    });
  });
  window.loginT = t; // usado pelo botão de mostrar/ocultar a palavra-passe
  aplicar();
})();
