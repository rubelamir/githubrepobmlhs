(function () {
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var bnMap = '০১২৩৪৫৬৭৮৯';

  function toBn(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return bnMap[digit] || digit;
    });
  }

  function animateGlance() {
    var nodes = document.querySelectorAll('.school-glance-value[data-count]');
    if (!nodes.length) {
      return;
    }

    function run(el) {
      var target = parseInt(el.getAttribute('data-count') || '0', 10);
      if (!isFinite(target) || target < 0) {
        return;
      }
      if (reduceMotion || target === 0) {
        el.textContent = toBn(target);
        return;
      }
      var start = null;
      var duration = Math.min(900, 420 + target * 4);
      function frame(ts) {
        if (start === null) {
          start = ts;
        }
        var progress = Math.min(1, (ts - start) / duration);
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = toBn(Math.round(target * eased));
        if (progress < 1) {
          window.requestAnimationFrame(frame);
        }
      }
      el.textContent = toBn(0);
      window.requestAnimationFrame(frame);
    }

    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(nodes, run);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) {
          return;
        }
        observer.unobserve(entry.target);
        run(entry.target);
      });
    }, { threshold: 0.4 });

    Array.prototype.forEach.call(nodes, function (node) {
      observer.observe(node);
    });
  }

  function restartCarouselProgress(carousel) {
    var active = carousel.querySelector('.carousel-indicators [data-bs-target].active, .carousel-indicators button.active');
    if (!active) {
      return;
    }
    active.classList.remove('is-filling');
    void active.offsetWidth;
    if (!reduceMotion) {
      active.classList.add('is-filling');
    }
  }

  function bindCarouselProgress() {
    var carousel = document.getElementById('carouselExampleCaptions');
    if (!carousel) {
      return;
    }
    carousel.classList.add('has-progress-dots');
    restartCarouselProgress(carousel);
    carousel.addEventListener('slide.bs.carousel', function () {
      var filling = carousel.querySelector('.carousel-indicators .is-filling');
      if (filling) {
        filling.classList.remove('is-filling');
      }
    });
    carousel.addEventListener('slid.bs.carousel', function () {
      restartCarouselProgress(carousel);
    });
  }

  function bindStudentSuggest() {
    var forms = document.querySelectorAll('#homeStudentSearch, #resultStudentSearch');
    Array.prototype.forEach.call(forms, function (form) {
      var input = form.querySelector('input[type="search"], input[name="q"]');
      if (!input) {
        return;
      }
      var list = form.querySelector('.school-suggest');
      if (!list) {
        list = document.createElement('div');
        list.className = 'school-suggest';
        list.hidden = true;
        list.setAttribute('role', 'listbox');
        var field = input.closest('.school-home-search-field') || form;
        field.style.position = field.style.position || 'relative';
        field.appendChild(list);
      }

      var timer = 0;
      var activeIndex = -1;
      var items = [];
      var emptyText = 'কোন তথ্য খুঁজে পাওয়া যায় নাই';

      function hideList() {
        list.hidden = true;
        list.innerHTML = '';
        activeIndex = -1;
        items = [];
        input.setAttribute('aria-expanded', 'false');
      }

      function showMessage(text) {
        var message = form.querySelector('[id$="StudentSearchMessage"]');
        var card = document.getElementById('student-search');
        if (message) {
          message.textContent = text;
          message.hidden = false;
        }
        if (card) {
          card.classList.add('has-message');
        }
        input.setAttribute('aria-invalid', 'true');
      }

      function hideMessage() {
        var message = form.querySelector('[id$="StudentSearchMessage"]');
        var card = document.getElementById('student-search');
        if (message) {
          message.textContent = '';
          message.hidden = true;
        }
        if (card) {
          card.classList.remove('has-message');
        }
        input.removeAttribute('aria-invalid');
      }

      function render(payload) {
        items = payload.items || [];
        if (!items.length) {
          hideList();
          return;
        }
        var html = items.map(function (item, index) {
          var meta = [item.class, item.section, item.roll ? 'রোল ' + item.roll : ''].filter(Boolean).join(' · ');
          return '<button type="button" class="school-suggest-item" role="option" data-index="' + index + '" data-url="' + item.url + '">'
            + '<strong>' + item.name + '</strong>'
            + (meta ? '<span>' + meta + '</span>' : '')
            + '</button>';
        }).join('');
        html += '<button type="button" class="school-suggest-all" data-all="1">সব ফলাফল দেখুন</button>';
        list.innerHTML = html;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
      }

      function fetchSuggest() {
        var query = (input.value || '').trim();
        if (query.length < 2) {
          hideList();
          return;
        }
        fetch('student_search?suggest=1&q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if ((input.value || '').trim() !== query) {
              return;
            }
            render(data || { items: [] });
          })
          .catch(function () {
            hideList();
          });
      }

      input.addEventListener('input', function () {
        hideMessage();
        window.clearTimeout(timer);
        timer = window.setTimeout(fetchSuggest, 180);
      });

      input.addEventListener('keydown', function (event) {
        var buttons = list.querySelectorAll('button');
        if (list.hidden || !buttons.length) {
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          activeIndex = Math.min(buttons.length - 1, activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          activeIndex = Math.max(0, activeIndex - 1);
        } else if (event.key === 'Escape') {
          hideList();
          return;
        } else if (event.key === 'Enter' && activeIndex >= 0) {
          event.preventDefault();
          buttons[activeIndex].click();
          return;
        } else {
          return;
        }
        Array.prototype.forEach.call(buttons, function (btn, i) {
          btn.classList.toggle('is-active', i === activeIndex);
        });
      });

      list.addEventListener('mousedown', function (event) {
        event.preventDefault();
      });

      list.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) {
          return;
        }
        if (button.getAttribute('data-all') === '1') {
          form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          return;
        }
        var url = button.getAttribute('data-url');
        if (url) {
          window.location.href = url;
        }
      });

      document.addEventListener('click', function (event) {
        if (!form.contains(event.target)) {
          hideList();
        }
      });

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var query = (input.value || '').trim();
        var button = form.querySelector('button[type="submit"]');
        hideList();
        if (query === '') {
          showMessage(emptyText);
          input.focus();
          return;
        }
        hideMessage();
        if (button) {
          button.disabled = true;
        }
        fetch('student_search?check=1&q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data && data.found) {
              window.location.href = 'student_search?q=' + encodeURIComponent(query);
              return;
            }
            showMessage(emptyText);
          })
          .catch(function () {
            showMessage(emptyText);
          })
          .then(function () {
            if (button) {
              button.disabled = false;
            }
          });
      });
    });

    if (/(?:^|[?&])search=empty(?:&|$)/.test(window.location.search)) {
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, window.location.pathname + '#student-search');
      }
      var homeInput = document.getElementById('homeStudentSearchInput');
      if (homeInput) {
        homeInput.focus();
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      animateGlance();
      bindCarouselProgress();
      bindStudentSuggest();
    });
  } else {
    animateGlance();
    bindCarouselProgress();
    bindStudentSuggest();
  }
})();
