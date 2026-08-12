/**
 * Responsive SOAP signature pad (mouse, trackpad, finger, stylus).
 */
(function (window) {
  'use strict';

  function SoapSignaturePad(canvas, options) {
    this.canvas = canvas;
    this.wrap = options.wrap || canvas.parentElement;
    this.placeholder = options.placeholder || null;
    this.strokes = [];
    this.current = null;
    this.drawing = false;
    this.dpr = 1;
    this.minInk = 8;
    this._onResize = this.fit.bind(this);
    this.fit();
    this.bind();
    window.addEventListener('resize', this._onResize);
    window.addEventListener('orientationchange', this._onResize);
  }

  SoapSignaturePad.prototype.fit = function () {
    const wrap = this.wrap || this.canvas.parentElement;
    const cssW = Math.max(1, wrap.clientWidth || this.canvas.clientWidth || 300);
    const cssH = Math.max(1, wrap.clientHeight || this.canvas.clientHeight || 160);
    this.dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2.5));
    this.canvas.width = Math.round(cssW * this.dpr);
    this.canvas.height = Math.round(cssH * this.dpr);
    this.canvas.style.width = cssW + 'px';
    this.canvas.style.height = cssH + 'px';
    this.redraw();
  };

  SoapSignaturePad.prototype.point = function (e) {
    const rect = this.canvas.getBoundingClientRect();
    const src = (e.touches && e.touches[0]) || (e.changedTouches && e.changedTouches[0]) || e;
    return {
      x: (src.clientX - rect.left) * this.dpr,
      y: (src.clientY - rect.top) * this.dpr,
    };
  };

  SoapSignaturePad.prototype.bind = function () {
    const el = this.canvas;
    el.style.touchAction = 'none';

    const start = (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      e.preventDefault();
      this.drawing = true;
      this.current = [this.point(e)];
      this.strokes.push(this.current);
      this.markDrawn();
      if (el.setPointerCapture && e.pointerId != null) {
        try { el.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      }
    };
    const move = (e) => {
      if (!this.drawing || !this.current) return;
      e.preventDefault();
      this.current.push(this.point(e));
      this.redraw();
    };
    const end = (e) => {
      if (!this.drawing) return;
      if (e) e.preventDefault();
      this.drawing = false;
      this.current = null;
      this.redraw();
      if (typeof this.onChange === 'function') this.onChange();
    };

    if (window.PointerEvent) {
      el.addEventListener('pointerdown', start, { passive: false });
      el.addEventListener('pointermove', move, { passive: false });
      el.addEventListener('pointerup', end, { passive: false });
      el.addEventListener('pointercancel', end, { passive: false });
      el.addEventListener('pointerleave', function (e) {
        if (e.pointerType === 'mouse') end(e);
      }, { passive: false });
    } else {
      el.addEventListener('mousedown', start);
      el.addEventListener('mousemove', move);
      el.addEventListener('mouseup', end);
      el.addEventListener('mouseleave', end);
      el.addEventListener('touchstart', start, { passive: false });
      el.addEventListener('touchmove', move, { passive: false });
      el.addEventListener('touchend', end, { passive: false });
      el.addEventListener('touchcancel', end, { passive: false });
    }
  };

  SoapSignaturePad.prototype.markDrawn = function () {
    if (this.wrap) this.wrap.classList.add('is-drawn');
    if (this.placeholder) this.placeholder.hidden = true;
  };

  SoapSignaturePad.prototype.redraw = function () {
    const ctx = this.canvas.getContext('2d');
    const w = this.canvas.width;
    const h = this.canvas.height;
    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = '#0f172a';
    ctx.lineWidth = Math.max(2.2, 2.4 * this.dpr);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    this.strokes.forEach((stroke) => {
      if (!stroke || stroke.length < 1) return;
      ctx.beginPath();
      ctx.moveTo(stroke[0].x, stroke[0].y);
      for (let i = 1; i < stroke.length; i++) {
        ctx.lineTo(stroke[i].x, stroke[i].y);
      }
      ctx.stroke();
    });
  };

  SoapSignaturePad.prototype.clear = function () {
    this.strokes = [];
    this.current = null;
    this.drawing = false;
    if (this.wrap) this.wrap.classList.remove('is-drawn');
    if (this.placeholder) this.placeholder.hidden = false;
    this.redraw();
    if (typeof this.onChange === 'function') this.onChange();
  };

  SoapSignaturePad.prototype.hasInk = function () {
    let points = 0;
    for (let i = 0; i < this.strokes.length; i++) {
      points += this.strokes[i] ? this.strokes[i].length : 0;
      if (points >= this.minInk) return true;
    }
    return false;
  };

  SoapSignaturePad.prototype.toDataURL = function () {
    if (!this.hasInk()) return '';
    return this.canvas.toDataURL('image/png');
  };

  window.SoapSignaturePad = SoapSignaturePad;
})(window);
