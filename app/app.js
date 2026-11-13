// App no telemóvel: funções partilhadas pelas páginas de instalação, activação e entrada (sem dependências).
// Chaves de acesso WebAuthn: o telemóvel escolhe sozinho o método mais forte que tiver — Face ID; sem Face ID, a
// impressão digital (Touch ID ou leitor do Android); sem biometria, o código do próprio telemóvel. A biometria nunca
// sai do telemóvel: o servidor recebe só uma assinatura.
(function () {
  const b64 = (buf) =>
    btoa(String.fromCharCode(...new Uint8Array(buf)))
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/, '');
  const bin = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));
  const K = 'naval-app-equipamento';
  // dentro das páginas da app (app/) o endereço é relativo à página; na página de entrada vem do próprio ficheiro
  const BASE = new URL('chave.php', document.currentScript && document.currentScript.src ? document.currentScript.src : location.href).href;
  const A = {
    instalada: () => matchMedia('(display-mode: standalone)').matches || matchMedia('(display-mode: fullscreen)').matches || navigator.standalone === true,
    so: () =>
      /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
        ? 'ios'
        : /Android/.test(navigator.userAgent)
          ? 'android'
          : 'outro',
    equipamento: () => {
      try {
        return localStorage.getItem(K) || '';
      } catch (e) {
        return '';
      }
    },
    guardarEquipamento: (id) => {
      try {
        if (id) localStorage.setItem(K, id);
        else localStorage.removeItem(K);
      } catch (e) {
        /* sem armazenamento */
      }
    },
    /** Há Face ID, impressão digital ou código do telemóvel para as chaves de acesso? */
    temBiometria: () =>
      window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
        ? PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().catch(() => false)
        : Promise.resolve(false),
    /** Nome do método no botão: Face ID no iPhone e no iPad; biometria no Android. */
    nomeBiometria: () => (A.so() === 'ios' ? 'Face ID' : 'impressão digital'),
    pedir: (corpo) =>
      fetch(BASE, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(corpo),
      }).then(
        (r) => r.json().catch(() => ({ ok: false, erro: 'Sem resposta do servidor.' })),
        () => ({ ok: false, erro: 'Sem ligação ao servidor.' }),
      ),
    /** Cria a chave de acesso deste equipamento (pede o Face ID ou a impressão digital). */
    registar: async (pre) => {
      const o = pre ? { ok: true, opcoes: JSON.parse(JSON.stringify(pre)) } : await A.pedir({ f: 'registo-opcoes' });
      if (!o.ok) return o;
      const p = o.opcoes;
      p.challenge = bin(p.challenge);
      p.user.id = bin(p.user.id);
      p.excludeCredentials = (p.excludeCredentials || []).map((c) => ({ ...c, id: bin(c.id) }));
      let c;
      try {
        c = await navigator.credentials.create({ publicKey: p });
      } catch (e) {
        return { ok: false, cancelado: true, erro: 'A biometria não foi activada.' };
      }
      const r = await A.pedir({
        f: 'registar',
        resposta: { id: c.id, clientDataJSON: b64(c.response.clientDataJSON), attestationObject: b64(c.response.attestationObject) },
      });
      // opções já recebidas mas fora de prazo (mais de 5 minutos): repete com opções novas
      return !r.ok && pre && /expirou/.test(r.erro || '') ? A.registar() : r;
    },
    /** Entra com a chave de acesso deste equipamento. */
    entrar: async (pre) => {
      const eq = A.equipamento() || (pre && pre.equipamento) || '';
      const o = pre ? { ok: true, opcoes: JSON.parse(JSON.stringify(pre.opcoes)) } : await A.pedir({ f: 'entrada-opcoes', equipamento: eq });
      if (!o.ok) return o;
      const p = o.opcoes;
      p.challenge = bin(p.challenge);
      p.allowCredentials = p.allowCredentials.map((c) => ({ ...c, id: bin(c.id) }));
      let c;
      try {
        c = await navigator.credentials.get({ publicKey: p });
      } catch (e) {
        // NotAllowedError: cancelada ou o telemóvel não deixou pedir sem um toque (iPhone ao voltar à app)
        return { ok: false, cancelado: true, nome: (e && e.name) || '', erro: 'A biometria foi cancelada.' };
      }
      return A.pedir({
        f: 'entrar',
        equipamento: eq,
        resposta: {
          id: c.id,
          clientDataJSON: b64(c.response.clientDataJSON),
          authenticatorData: b64(c.response.authenticatorData),
          signature: b64(c.response.signature),
        },
      });
    },
  };
  window.NavalApp = A;
})();
