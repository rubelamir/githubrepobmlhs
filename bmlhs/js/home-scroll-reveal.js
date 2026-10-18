(function () {
  var sections = document.querySelectorAll('.school-reveal[data-reveal]');
  if (!sections.length) {
    return;
  }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var pieces = {
    messages: '.school-section-head, .school-message-card',
    history: '.school-section-head, p, .school-text-more, .school-about-timeline-list li',
    cards: '.school-section-head, .school-card, .school-about-campus-shot',
    events: '.school-events-head, .school-event-featured, .school-event-compact',
    links: '.school-aside-heading, li'
  };

  function prepare(section) {
    var kind = section.getAttribute('data-reveal') || '';
    var selector = pieces[kind];
    if (!selector) {
      return;
    }
    var nodes = section.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i += 1) {
      nodes[i].style.setProperty('--i', String(i));
    }
  }

  function reveal(section) {
    if (section.classList.contains('is-in')) {
      return;
    }
    section.classList.add('is-in');
    section.classList.remove('is-waiting');
  }

  Array.prototype.forEach.call(sections, prepare);

  if (reduceMotion) {
    Array.prototype.forEach.call(sections, reveal);
    return;
  }

  Array.prototype.forEach.call(sections, function (section) {
    section.classList.add('is-waiting');
  });

  function observe() {
    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(sections, reveal);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) {
          return;
        }
        observer.unobserve(entry.target);
        reveal(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });

    Array.prototype.forEach.call(sections, function (section) {
      observer.observe(section);
    });
  }

  function whenPreloaderDone(fn) {
    var ran = false;
    function run() {
      if (ran) {
        return;
      }
      ran = true;
      fn();
    }

    if (!document.documentElement.classList.contains('school-loading')) {
      run();
      return;
    }

    var mo = new MutationObserver(function () {
      if (!document.documentElement.classList.contains('school-loading')) {
        mo.disconnect();
        window.setTimeout(run, 80);
      }
    });
    mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    window.setTimeout(function () {
      mo.disconnect();
      run();
    }, 5200);
  }

  whenPreloaderDone(observe);
})();
