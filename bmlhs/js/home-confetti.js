(function () {
  'use strict';

  var COLORS = ['#ff4848', '#ffb400', '#7d3bc0', '#19b1ff', '#45e8a3'];
  var MAX_TOTAL_MS = 4900;
  var STEPS = 10;

  function rand(min, max) {
    return min + Math.random() * (max - min);
  }

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function styleShape(el) {
    var kind = Math.random();
    var scale = rand(0.8, 1.4);
    var w;
    var h;
    var radius = '1px';
    if (kind < 0.5) {
      w = 10 * scale;
      h = 6 * scale;
    } else if (kind < 0.75) {
      w = h = rand(6, 9) * scale;
      radius = '50%';
    } else {
      w = 4 * scale;
      h = rand(16, 22) * scale;
      radius = '2px';
    }
    el.style.width = w.toFixed(1) + 'px';
    el.style.height = h.toFixed(1) + 'px';
    el.style.borderRadius = radius;
  }

  function frame(x, y, f, spin, opacity) {
    return {
      transform: 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) rotateX(' +
        (spin.x * f).toFixed(0) + 'deg) rotateY(' + (spin.y * f).toFixed(0) +
        'deg) rotateZ(' + (spin.z * f).toFixed(0) + 'deg)',
      opacity: opacity,
      offset: f
    };
  }

  function fadeAt(f) {
    return f < 0.75 ? 1 : Math.max(0, 1 - (f - 0.75) / 0.25);
  }

  // Fountain from a bottom corner: ballistic arc with horizontal drag.
  function cornerKeyframes(side, w, h, dur, spin) {
    var dir = side === 'left' ? 1 : -1;
    var x0 = side === 'left' ? 0 : w;
    var y0 = h;
    var peak = h * rand(0.5, 0.95);
    var tp = dur * rand(0.3, 0.4);
    var g = (2 * peak) / (tp * tp);
    var vy = -g * tp;
    var reach = w * rand(0.2, 0.65) * dir;
    var k = 2.2;
    var norm = 1 - Math.exp(-k);
    var frames = [];
    for (var i = 0; i <= STEPS; i++) {
      var f = i / STEPS;
      var t = f * dur;
      var x = x0 + reach * (1 - Math.exp(-k * f)) / norm;
      var y = y0 + vy * t + 0.5 * g * t * t;
      frames.push(frame(x, y, f, spin, fadeAt(f)));
    }
    return frames;
  }

  // Slow fall from above with a sideways sway.
  function topKeyframes(w, h, spin) {
    var x0 = rand(0, w);
    var sway = rand(20, 60);
    var phase = rand(0, Math.PI * 2);
    var freq = rand(1.5, 3);
    var drift = rand(-60, 60);
    var frames = [];
    for (var i = 0; i <= STEPS; i++) {
      var f = i / STEPS;
      var x = x0 + drift * f + sway * Math.sin(phase + f * freq * Math.PI);
      var y = -30 + (h + 60) * (0.55 * f + 0.45 * f * f);
      frames.push(frame(x, y, f, spin, fadeAt(f)));
    }
    return frames;
  }

  function run() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }
    if (!document.body || !Element.prototype.animate) {
      return;
    }

    var w = window.innerWidth;
    var h = window.innerHeight;
    var total = w < 600 ? 60 : Math.round(rand(120, 150));
    var topCount = Math.round(total * 0.25);

    var overlay = document.createElement('div');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;pointer-events:none;' +
      'overflow:hidden;perspective:800px;contain:strict;';

    var fragment = document.createDocumentFragment();
    var pieces = [];

    for (var i = 0; i < total; i++) {
      var el = document.createElement('div');
      el.style.cssText = 'position:absolute;left:0;top:0;opacity:0;will-change:transform,opacity;' +
        'backface-visibility:visible;background:' + pick(COLORS) + ';';
      styleShape(el);

      var isTop = i >= total - topCount;
      var dur = rand(3, 4.5) * 1000;
      var delay = rand(0, 0.4) * 1000;
      dur = Math.min(dur, MAX_TOTAL_MS - delay);
      var spin = {
        x: rand(-1080, 1080),
        y: rand(-1080, 1080),
        z: rand(-720, 720)
      };
      var frames = isTop
        ? topKeyframes(w, h, spin)
        : cornerKeyframes(i % 2 === 0 ? 'left' : 'right', w, h, dur / 1000, spin);

      pieces.push({ el: el, frames: frames, dur: dur, delay: delay });
      fragment.appendChild(el);
    }

    overlay.appendChild(fragment);
    document.body.appendChild(overlay);

    var animations = pieces.map(function (p) {
      return p.el.animate(p.frames, {
        duration: p.dur,
        delay: p.delay,
        easing: 'linear',
        fill: 'both'
      });
    });

    function cleanup() {
      pieces.forEach(function (p) {
        p.el.style.willChange = 'auto';
      });
      overlay.remove();
    }

    Promise.all(animations.map(function (a) { return a.finished; }))
      .then(cleanup, cleanup);
  }

  if (document.readyState === 'complete') {
    run();
  } else {
    window.addEventListener('load', run, { once: true });
  }
})();
