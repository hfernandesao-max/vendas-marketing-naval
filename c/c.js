// Página pública do cartaz do cliente (código QR colado no estabelecimento): encomendar, actualizar os dados e aceitar
// mensagens por WhatsApp. Cada envio fica pendente até a equipa do Grupo Naval o validar (back-office).
(function () {
  'use strict';
  var codigo = new URLSearchParams(location.search).get('k') || '';
  var API = '../api/index.php/v1/cliente';
  var C = null;
  var sep = 'encomenda';
  var feito = {};

  function $(id) {
    return document.getElementById(id);
  }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function campo(rotulo, tipo, nome, attrs) {
    var l = el('label', null, rotulo);
    var i = el(tipo === 'textarea' ? 'textarea' : 'input');
    if (tipo !== 'textarea') i.type = tipo;
    i.name = nome;
    Object.keys(attrs || {}).forEach(function (k) {
      i.setAttribute(k, attrs[k]);
    });
    l.appendChild(i);
    return l;
  }
  function armadilha() {
    // campo escondido: as pessoas não o vêem nem o preenchem; os robôs sim
    var d = el('div', 'c-armadilha');
    d.setAttribute('aria-hidden', 'true');
    var i = el('input');
    i.name = 'site';
    i.tabIndex = -1;
    i.autocomplete = 'off';
    d.appendChild(i);
    return d;
  }
  function enviar(form, tipo, dados, botao, erro) {
    botao.disabled = true;
    erro.textContent = '';
    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'omit',
      body: JSON.stringify({ k: codigo, tipo: tipo, dados: dados, site: form.site.value }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        botao.disabled = false;
        if (!j.ok) {
          erro.textContent = j.erro || 'Não foi possível enviar. Tente novamente.';
          return;
        }
        feito[tipo] = true;
        desenhar();
      })
      .catch(function () {
        botao.disabled = false;
        erro.textContent = 'Sem ligação. Verifique a rede e tente novamente.';
      });
  }
  function confirmado(titulo, texto) {
    var c = el('div', 'c-cartao c-ok');
    c.appendChild(el('strong', null, titulo));
    c.appendChild(el('p', null, texto));
    var b = el('button', 'c-botao sec', 'Fazer outro pedido');
    b.type = 'button';
    b.addEventListener('click', function () {
      feito = {};
      desenhar();
    });
    c.appendChild(b);
    return c;
  }
  function contactos(form) {
    form.appendChild(campo('O seu nome', 'text', 'contacto_nome', { maxlength: '80', autocomplete: 'name' }));
    form.appendChild(campo('Telefone', 'tel', 'contacto_telefone', { maxlength: '20', autocomplete: 'tel', inputmode: 'tel', placeholder: '9xx xxx xxx' }));
  }

  function encomenda() {
    if (feito.encomenda)
      return confirmado('Encomenda recebida', 'A nossa equipa vai confirmar a encomenda e o preço consigo pelo telefone indicado. Obrigado!');
    var f = el('form', 'c-cartao');
    f.appendChild(el('h2', null, 'Fazer uma encomenda'));
    f.appendChild(el('p', null, 'Indique as quantidades (caixas ou unidades, como costuma comprar). O preço é confirmado pelo promotor.'));
    if (!C.artigos.length) f.appendChild(el('p', null, 'A lista de artigos ainda não está disponível: escreva a encomenda nas observações.'));
    // sugestão pelas compras habituais: primeiro o que está na altura de repor (já preenchido), depois os outros habituais,
    // os que outros clientes da zona levam e o resto da lista
    var sug = C.sugestao || { habituais: [], cruzada: [] };
    var porCodigo = {};
    C.artigos.forEach(function (a) {
      porCodigo[a.codigo] = a;
    });
    var usados = {};
    function linha(a, hab) {
      usados[a.codigo] = true;
      var l = el('div', 'c-artigo');
      var n = el('div', null, a.nome);
      if (hab)
        n.appendChild(
          el(
            'small',
            null,
            'Costuma levar ' +
              hab.quantidade +
              ' ' +
              (a.unidade || '') +
              (hab.intervalo > 1 ? ' a cada ' + hab.intervalo + ' dias' : '') +
              (hab.repor ? ' · está na altura de repor' : ''),
          ),
        );
      else if (a.unidade) n.appendChild(el('small', null, a.unidade));
      var i = el('input');
      i.type = 'number';
      i.min = '0';
      i.max = '10000';
      i.inputMode = 'numeric';
      i.placeholder = hab ? String(hab.quantidade) : '0';
      if (hab && hab.repor) i.value = String(hab.quantidade);
      i.dataset.codigo = a.codigo;
      i.setAttribute('aria-label', 'Quantidade de ' + a.nome);
      l.appendChild(n);
      l.appendChild(i);
      return l;
    }
    var habituais = sug.habituais.filter(function (h) {
      return porCodigo[h.codigo];
    });
    if (habituais.length) {
      f.appendChild(el('h2', null, 'As suas compras habituais'));
      f.appendChild(el('p', null, 'Sugestão feita a partir das suas compras. Já preenchemos o que está na altura de repor: ajuste à vontade.'));
      habituais.forEach(function (h) {
        f.appendChild(linha(porCodigo[h.codigo], h));
      });
    }
    var cruz = sug.cruzada.filter(function (c) {
      return porCodigo[c] && !usados[c];
    });
    if (cruz.length) {
      f.appendChild(el('h2', null, 'Outros clientes da sua zona também levam'));
      cruz.forEach(function (c) {
        f.appendChild(linha(porCodigo[c]));
      });
    }
    var resto = C.artigos.filter(function (a) {
      return !usados[a.codigo];
    });
    if (resto.length) {
      if (habituais.length || cruz.length) f.appendChild(el('h2', null, 'Outros artigos'));
      resto.forEach(function (a) {
        f.appendChild(linha(a));
      });
    }
    f.appendChild(campo('Observações', 'textarea', 'observacoes', { maxlength: '400', rows: '3' }));
    contactos(f);
    f.appendChild(armadilha());
    var erro = el('p', 'c-erro');
    erro.setAttribute('role', 'alert');
    var b = el('button', 'c-botao', 'Enviar encomenda');
    b.type = 'submit';
    f.appendChild(erro);
    f.appendChild(b);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var linhas = [];
      f.querySelectorAll('input[data-codigo]').forEach(function (i) {
        if (+i.value > 0) linhas.push({ codigo: i.dataset.codigo, quantidade: Math.round(+i.value) });
      });
      if (!linhas.length && C.artigos.length) {
        erro.textContent = 'Indique pelo menos um artigo e a quantidade.';
        return;
      }
      enviar(
        f,
        'encomenda',
        { linhas: linhas, observacoes: f.observacoes.value, contacto_nome: f.contacto_nome.value, contacto_telefone: f.contacto_telefone.value },
        b,
        erro,
      );
    });
    return f;
  }

  function dados() {
    if (feito.dados) return confirmado('Dados recebidos', 'Obrigado! A alteração fica registada depois de confirmada pela nossa equipa.');
    var f = el('form', 'c-cartao');
    f.appendChild(el('h2', null, 'Actualizar os dados da empresa'));
    f.appendChild(el('p', null, 'Preencha só o que mudou ou está em falta.'));
    f.appendChild(campo('Nome comercial', 'text', 'nome_comercial', { maxlength: '120' }));
    contactos(f);
    f.appendChild(campo('Correio electrónico', 'email', 'email', { maxlength: '120', autocomplete: 'email' }));
    f.appendChild(campo('Morada', 'text', 'morada', { maxlength: '200', autocomplete: 'street-address' }));
    f.appendChild(campo('Horário de funcionamento', 'text', 'horario', { maxlength: '80', placeholder: 'Ex.: segunda a sábado, 07:00–19:00' }));
    f.appendChild(campo('Observações', 'textarea', 'observacoes', { maxlength: '400', rows: '2' }));
    var pos = null;
    var gps = el('button', 'c-botao sec', 'Enviar a localização do estabelecimento');
    gps.type = 'button';
    var gpsInfo = el('p', 'c-nota');
    gps.addEventListener('click', function () {
      if (!navigator.geolocation) {
        gpsInfo.textContent = 'Este telemóvel não permite obter a localização.';
        return;
      }
      gpsInfo.textContent = 'A obter a localização…';
      navigator.geolocation.getCurrentPosition(
        function (p) {
          pos = p.coords;
          gpsInfo.textContent = 'Localização obtida (precisão de ' + Math.round(p.coords.accuracy) + ' m). Envie quando estiver no estabelecimento.';
        },
        function () {
          gpsInfo.textContent = 'Não foi possível obter a localização (autorização recusada ou sem sinal).';
        },
        { enableHighAccuracy: true, timeout: 15000 },
      );
    });
    f.appendChild(gps);
    f.appendChild(gpsInfo);
    f.appendChild(armadilha());
    var erro = el('p', 'c-erro');
    erro.setAttribute('role', 'alert');
    var b = el('button', 'c-botao', 'Enviar dados');
    b.type = 'submit';
    f.appendChild(erro);
    f.appendChild(b);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = {};
      ['nome_comercial', 'contacto_nome', 'contacto_telefone', 'email', 'morada', 'horario', 'observacoes'].forEach(function (k) {
        d[k] = f[k].value;
      });
      if (pos) {
        d.latitude = pos.latitude;
        d.longitude = pos.longitude;
      }
      enviar(f, 'dados', d, b, erro);
    });
    return f;
  }

  function consentimento() {
    if (feito.consentimento)
      return confirmado(
        'Pedido recebido',
        'Depois de confirmado, passa a receber as nossas promoções por WhatsApp. Para deixar de receber, basta responder «SAIR».',
      );
    var f = el('form', 'c-cartao');
    f.appendChild(el('h2', null, 'Receber promoções por WhatsApp'));
    f.appendChild(el('p', null, 'Promoções da semana, artigos novos e aviso da visita do promotor, no seu WhatsApp.'));
    contactos(f);
    var l = el('label', 'c-check');
    var i = el('input');
    i.type = 'checkbox';
    i.name = 'aceito';
    l.appendChild(i);
    l.appendChild(el('span', null, C.consentimento));
    f.appendChild(l);
    f.appendChild(armadilha());
    var erro = el('p', 'c-erro');
    erro.setAttribute('role', 'alert');
    var b = el('button', 'c-botao', 'Quero receber');
    b.type = 'submit';
    f.appendChild(erro);
    f.appendChild(b);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!f.aceito.checked) {
        erro.textContent = 'Para receber mensagens tem de aceitar as condições.';
        return;
      }
      enviar(f, 'consentimento', { aceito: true, contacto_nome: f.contacto_nome.value, contacto_telefone: f.contacto_telefone.value }, b, erro);
    });
    return f;
  }

  var SECCOES = { encomenda: encomenda, dados: dados, consentimento: consentimento };
  function desenhar() {
    var m = $('c-conteudo');
    m.textContent = '';
    m.appendChild(SECCOES[sep]());
    document.querySelectorAll('#c-sep button').forEach(function (b) {
      if (b.dataset.s === sep) b.setAttribute('aria-current', 'page');
      else b.removeAttribute('aria-current');
    });
  }
  document.querySelectorAll('#c-sep button').forEach(function (b) {
    b.addEventListener('click', function () {
      sep = b.dataset.s;
      desenhar();
      window.scrollTo(0, 0);
    });
  });

  fetch(API + '?k=' + encodeURIComponent(codigo), { cache: 'no-store', credentials: 'omit' })
    .then(function (r) {
      return r.json();
    })
    .then(function (j) {
      if (!j.ok) throw new Error(j.erro);
      C = j;
      $('c-cliente').textContent = j.nome || 'Cliente';
      $('c-sep').hidden = false;
      desenhar();
    })
    .catch(function (e) {
      $('c-cliente').textContent = '';
      var m = $('c-conteudo');
      m.textContent = '';
      var c = el('div', 'c-cartao');
      c.appendChild(el('h2', null, 'Código não reconhecido'));
      c.appendChild(el('p', null, (e && e.message) || 'Sem ligação. Verifique a rede e tente novamente.'));
      m.appendChild(c);
    });
})();
