// Área de assinatura manuscrita (iPhone, Android e computador), sem dependências: Pointer Events (W3C), ecrãs de
// alta densidade e sem deslocar a página enquanto se assina. Adaptada de app/assinatura.js do pacote «Equipamentos».
// Envia-se: PNG (evidência), JPEG (para o PDF) e os traços (pontos com tempo e pressão, evidência adicional).

export class AreaAssinatura {
  constructor(canvas, opcoes = {}) {
    this.canvas = canvas;
    this.cor = opcoes.cor || '#1C2340';
    this.espessura = opcoes.espessura || 2.5;
    this.aoMudar = opcoes.aoMudar || (() => {});
    this._tracos = [];
    this._actual = null;
    this.ctx = canvas.getContext('2d');
    canvas.style.touchAction = 'none'; // sem deslocar nem ampliar ao desenhar (iOS 13+)
    canvas.style.userSelect = 'none';
    this._ajustar = this._ajustar.bind(this);
    this._ajustar();
    window.addEventListener('resize', this._ajustar);
    canvas.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      canvas.setPointerCapture(e.pointerId);
      this._actual = [];
      this._tracos.push(this._actual);
      this._ponto(e);
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!this._actual) return;
      const evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      (evs.length ? evs : [e]).forEach((x) => this._ponto(x));
      e.preventDefault();
    });
    const fim = (e) => {
      if (!this._actual) return;
      this._actual = null;
      e.preventDefault();
      this.aoMudar();
    };
    canvas.addEventListener('pointerup', fim);
    canvas.addEventListener('pointercancel', fim);
    canvas.addEventListener('pointerleave', fim);
  }

  _ajustar() {
    const r = this.canvas.getBoundingClientRect();
    if (!r.width) return;
    const dpr = Math.max(window.devicePixelRatio || 1, 1);
    this.canvas.width = Math.round(r.width * dpr);
    this.canvas.height = Math.round(r.height * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this._redesenhar();
  }

  _ponto(e) {
    const r = this.canvas.getBoundingClientRect();
    const p = {
      x: +(e.clientX - r.left).toFixed(1),
      y: +(e.clientY - r.top).toFixed(1),
      t: Math.round(e.timeStamp),
      p: e.pressure ? +e.pressure.toFixed(2) : 0.5,
    };
    const tr = this._actual,
      ant = tr[tr.length - 1];
    tr.push(p);
    this._segmento(ant || p, p);
  }

  _segmento(a, b) {
    const c = this.ctx;
    c.strokeStyle = this.cor;
    c.lineWidth = this.espessura * (0.6 + 0.8 * (b.p || 0.5));
    c.lineCap = 'round';
    c.lineJoin = 'round';
    c.beginPath();
    c.moveTo(a.x, a.y);
    c.lineTo(b.x, b.y);
    c.stroke();
  }

  _redesenhar() {
    const r = this.canvas.getBoundingClientRect();
    this.ctx.clearRect(0, 0, r.width, r.height);
    this._tracos.forEach((tr) => tr.forEach((p, j) => this._segmento(tr[j - 1] || p, p)));
  }

  limpar() {
    this._tracos = [];
    this._redesenhar();
    this.aoMudar();
  }

  /** Menos de 10 pontos não é uma assinatura (um toque acidental não conta). */
  vazia() {
    return this._tracos.reduce((n, tr) => n + tr.length, 0) < 10;
  }

  /** Imagem com fundo branco (legível no PDF): 'image/png' ou 'image/jpeg'. */
  imagem(tipo = 'image/png') {
    const o = document.createElement('canvas');
    o.width = this.canvas.width;
    o.height = this.canvas.height;
    const c = o.getContext('2d');
    c.fillStyle = '#FFFFFF';
    c.fillRect(0, 0, o.width, o.height);
    c.drawImage(this.canvas, 0, 0);
    return o.toDataURL(tipo, 0.9);
  }

  /** Repõe traços guardados (a página pode voltar a ser desenhada enquanto se assina). */
  carregar(tracos) {
    this._tracos = Array.isArray(tracos) ? JSON.parse(JSON.stringify(tracos)) : [];
    this._redesenhar();
  }

  tracos() {
    return JSON.parse(JSON.stringify(this._tracos));
  }

  destruir() {
    window.removeEventListener('resize', this._ajustar);
  }
}
