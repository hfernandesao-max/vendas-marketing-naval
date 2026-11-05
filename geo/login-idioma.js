// Idioma da página de entrada e da página «Mudar a palavra-passe» (PT/EN/TI). A escolha fica no navegador (a mesma chave do portal, vm-lang) e só serve
// para ler esta página: depois de entrar, o portal abre no idioma definido na ficha da conta.
// Os textos estão em português no HTML (marcados com data-t, data-t-ph e data-t-aria); as mensagens do servidor
// traduzem-se pelos modelos abaixo, com as partes variáveis ({n}).
(function () {
  var IDIOMAS = { pt: 'pt-PT', en: 'en', ti: 'ti' };
  var D = {
    en: {
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
