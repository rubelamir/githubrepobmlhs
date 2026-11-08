/* Student filter enhancements: progressive layer over server-rendered GET filter forms.
 * Mark a form with data-student-filter; mark a result table with data-sf-table. */
(function () {
  'use strict';

  var BN = '০১২৩৪৫৬৭৮৯';
  var EMPTY_SELECT = { '': true, '0': true };
  var PINNED = { session_year: true, date: true };

  function bnDigits(value) {
    return String(value).replace(/\d/g, function (d) { return BN[d]; });
  }
  function asciiDigits(value) {
    return String(value).replace(/[০-৯]/g, function (d) { return String(BN.indexOf(d)); });
  }
  function el(tag, cls, html) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function storageGet(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage unavailable */ }
  }

  function parseRolls(raw) {
    var text = asciiDigits(raw).replace(/[–—~]/g, '-').replace(/\s*-\s*/g, '-').trim();
    if (text === '') return { count: 0, values: {}, invalid: [] };
    var seen = {};
    var invalid = [];
    text.split(/[,;،\s]+/).forEach(function (part) {
      if (!part) return;
      var range = part.match(/^(\d+)-(\d+)$/);
      if (range) {
        var from = parseInt(range[1], 10);
        var to = parseInt(range[2], 10);
        if (from > to) { var t = from; from = to; to = t; }
        if (to - from > 199) to = from + 199;
        for (var n = from; n <= to; n++) seen[n] = true;
      } else if (/^\d+$/.test(part)) {
        seen[parseInt(part, 10)] = true;
      } else {
        invalid.push(part);
      }
    });
    return { count: Object.keys(seen).length, values: seen, invalid: invalid };
  }

  function fields(form) {
    return Array.prototype.filter.call(
      form.querySelectorAll('select[name], input[name]'),
      function (field) {
        var type = (field.getAttribute('type') || 'text').toLowerCase();
        return type !== 'hidden' && type !== 'submit' && type !== 'button' && !field.closest('[data-sf-ignore]');
      }
    );
  }

  function isEmpty(field) {
    var value = String(field.value || '').trim();
    return field.tagName === 'SELECT' ? !!EMPTY_SELECT[value] : value === '';
  }

  function fieldLabel(form, field) {
    if (field.dataset.sfLabel) return field.dataset.sfLabel;
    var label = field.id ? form.querySelector('label[for="' + field.id + '"]') : null;
    if (!label) {
      var box = field.closest('.sf-field') || field.parentElement;
      label = box ? box.querySelector('label') : null;
    }
    if (!label) return field.name;
    var clone = label.cloneNode(true);
    Array.prototype.forEach.call(clone.querySelectorAll('span, i'), function (n) { n.remove(); });
    return clone.textContent.replace(/\s+/g, ' ').trim();
  }

  function fieldText(field) {
    if (field.tagName === 'SELECT') {
      var option = field.options[field.selectedIndex];
      return option ? option.textContent.replace(/\s+/g, ' ').trim() : '';
    }
    return String(field.value || '').trim();
  }

  function emptyValueFor(field) {
    if (field.tagName !== 'SELECT') return '';
    for (var i = 0; i < field.options.length; i++) {
      if (EMPTY_SELECT[field.options[i].value]) return field.options[i].value;
    }
    return null;
  }

  function submitButton(form) {
    return form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
  }

  function formUrl(form, submitter) {
    var params = new URLSearchParams();
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name || field.disabled) return;
      var type = (field.type || '').toLowerCase();
      if (type === 'submit' || type === 'button' || type === 'file') return;
      if ((type === 'checkbox' || type === 'radio') && !field.checked) return;
      params.append(field.name, field.value);
    });
    if (submitter && submitter.name) params.set(submitter.name, submitter.value);
    var action = form.getAttribute('action') || location.pathname.split('/').pop();
    return action.split('?')[0] + '?' + params.toString();
  }

  function enhanceForm(form) {
    if (form.dataset.sfReady) return;
    form.dataset.sfReady = '1';
    form.classList.add('sf-form');

    var pageKey = 'sf:last:' + (form.getAttribute('action') || location.pathname).split('?')[0].split('/').pop();
    var collapseKey = 'sf:collapsed:' + pageKey;
    var url = new URLSearchParams(location.search);
    var list = fields(form);
    var applied = list.some(function (field) { return url.has(field.name); }) || form.dataset.sfApplied === '1';
    var submit = submitButton(form);
    var body = form.querySelector('.card-body') || form;

    list.forEach(function (field) {
      var box = field.closest('[class*="col-"], .flex-grow-1, .flex-shrink-0') || field.parentElement;
      if (box && box !== form && box !== body) box.classList.add('sf-field');
      if (!field.parentElement.classList.contains('sf-input-wrap')) {
        var wrap = el('span', 'sf-input-wrap');
        field.parentNode.insertBefore(wrap, field);
        wrap.appendChild(field);
      }
      if (!field.id) field.id = 'sf-' + field.name + '-' + Math.random().toString(36).slice(2, 7);
      if (box) {
        var orphan = box.querySelector('label.form-label:not([for])');
        if (orphan) orphan.setAttribute('for', field.id);
      }
    });

    if (submit) {
      submit.classList.add('sf-submit');
      if (submit.classList.contains('btn-outline-primary')) {
        submit.classList.remove('btn-outline-primary');
        submit.classList.add('btn-primary');
      }
      if (submit.tagName === 'BUTTON') {
        if (!submit.querySelector('.bi')) submit.insertAdjacentHTML('afterbegin', '<i class="bi bi-search sf-submit-icon" aria-hidden="true"></i>');
        submit.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm sf-spin" aria-hidden="true"></span>');
      }
      var submitBox = submit.closest('[class*="col-"], .flex-shrink-0');
      if (submitBox) submitBox.classList.add('sf-submit-wrap');
    }

    // Header
    var title = form.dataset.sfTitle || 'ফিল্টার / অনুসন্ধান';
    var head = el('div', 'sf-head');
    head.innerHTML =
      '<div class="sf-title"><span class="sf-title-icon"><i class="bi bi-funnel-fill" aria-hidden="true"></i></span>' +
      '<span><span class="sf-title-text">' + escapeHtml(title) + '</span>' +
      '<span class="sf-subtitle"></span></span></div>' +
      '<div class="sf-tools"></div>';
    form.insertBefore(head, form.firstChild);
    var tools = head.querySelector('.sf-tools');
    var subtitle = head.querySelector('.sf-subtitle');

    var lastBtn = null;
    var saved = storageGet(pageKey);
    if (!applied && saved && saved.url) {
      lastBtn = el('a', 'btn btn-sm btn-outline-primary sf-tool', '<i class="bi bi-clock-history" aria-hidden="true"></i><span>শেষ ফিল্টার</span>');
      lastBtn.href = saved.url;
      lastBtn.title = saved.summary || '';
      tools.appendChild(lastBtn);
    }

    var resetBtn = el('a', 'btn btn-sm btn-outline-secondary sf-tool', '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span>রিসেট</span>');
    var keep = new URLSearchParams();
    (form.dataset.sfKeep || '').split(',').forEach(function (name) {
      name = name.trim();
      if (name && url.has(name)) keep.set(name, url.get(name));
    });
    resetBtn.href = (form.getAttribute('action') || location.pathname.split('/').pop()) + (keep.toString() ? '?' + keep.toString() : '');
    resetBtn.title = 'সব ফিল্টার মুছে নতুন করে শুরু করুন';
    tools.appendChild(resetBtn);

    var toggleBtn = el('button', 'btn btn-sm btn-outline-secondary sf-tool sf-toggle', '<i class="bi bi-chevron-up" aria-hidden="true"></i>');
    toggleBtn.type = 'button';
    toggleBtn.setAttribute('aria-label', 'ফিল্টার দেখান/লুকান');
    tools.appendChild(toggleBtn);

    // Applied-filter chips
    var chips = el('div', 'sf-chips');
    chips.setAttribute('aria-label', 'প্রয়োগকৃত ফিল্টার');
    form.appendChild(chips);

    function renderChips() {
      chips.innerHTML = '';
      if (!applied) {
        if (saved && saved.summary) {
          chips.innerHTML = '<span class="sf-chips-label"><i class="bi bi-clock-history"></i> আগের ফিল্টার:</span>' +
            '<span class="sf-chip sf-chip-muted">' + escapeHtml(saved.summary) + '</span>';
          var again = el('a', 'sf-chip-action', 'আবার দেখুন <i class="bi bi-arrow-right"></i>');
          again.href = saved.url;
          chips.appendChild(again);
        } else {
          chips.innerHTML = '<span class="sf-chips-label text-secondary"><i class="bi bi-info-circle"></i> প্রয়োজনীয় ঘর পূরণ করে <strong>' +
            escapeHtml(submit ? submit.textContent.trim() : 'ফিল্টার') + '</strong> চাপুন।</span>';
        }
        return;
      }
      chips.appendChild(el('span', 'sf-chips-label', '<i class="bi bi-sliders"></i> প্রয়োগকৃত:'));
      list.forEach(function (field) {
        if (isEmpty(field) || field.dataset.sfInitial === undefined || field.dataset.sfInitial !== field.value) return;
        var chip = el('span', 'sf-chip');
        chip.innerHTML = '<span class="sf-chip-k">' + escapeHtml(fieldLabel(form, field)) + ':</span> ' +
          '<span class="sf-chip-v">' + escapeHtml(fieldText(field)) + '</span>';
        var clearTo = emptyValueFor(field);
        if (!field.required && !PINNED[field.name] && clearTo !== null) {
          var x = el('button', 'sf-chip-x', '<i class="bi bi-x"></i>');
          x.type = 'button';
          x.setAttribute('aria-label', fieldLabel(form, field) + ' ফিল্টার সরান');
          x.addEventListener('click', function () {
            field.value = clearTo;
            if (field.name === 'cls') {
              ['sections', 'subject_id'].forEach(function (name) {
                var dep = form.querySelector('[name="' + name + '"]');
                if (dep) { var v = emptyValueFor(dep); if (v !== null) dep.value = v; }
              });
            }
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submit || undefined);
            else form.submit();
          });
          chip.appendChild(x);
        }
        chips.appendChild(chip);
      });
      var resultCount = form.dataset.sfResultCount;
      if (resultCount !== undefined && resultCount !== '') {
        chips.appendChild(el('span', 'sf-result-count', '<i class="bi bi-people-fill"></i> ' + bnDigits(resultCount) + ' জন পাওয়া গেছে'));
      }
    }

    function summary() {
      return list.filter(function (field) { return !isEmpty(field); })
        .map(function (field) { return fieldLabel(form, field) + ': ' + fieldText(field); })
        .join(' · ');
    }

    function refreshState() {
      var count = 0;
      var dirty = false;
      list.forEach(function (field) {
        var filled = !isEmpty(field);
        if (filled) count++;
        field.classList.toggle('sf-filled', filled);
        var box = field.closest('.sf-field');
        if (box) box.classList.toggle('sf-field-filled', filled);
        if (field.dataset.sfInitial !== undefined && field.dataset.sfInitial !== field.value) dirty = true;
      });
      subtitle.textContent = count > 0 ? bnDigits(count) + 'টি ফিল্টার নির্বাচিত' : 'কোনো ফিল্টার নির্বাচিত নয়';
      if (submit) submit.classList.toggle('sf-dirty', applied && dirty);
    }

    list.forEach(function (field) { field.dataset.sfInitial = field.value; });

    // Clear buttons on text inputs
    list.forEach(function (field) {
      if (field.tagName !== 'INPUT' || !/^(text|search|)$/i.test(field.getAttribute('type') || '')) return;
      var wrap = field.parentElement;
      wrap.classList.add('sf-has-clear');
      var clear = el('button', 'sf-clear', '<i class="bi bi-x-circle-fill"></i>');
      clear.type = 'button';
      clear.tabIndex = -1;
      clear.setAttribute('aria-label', 'মুছুন');
      clear.addEventListener('click', function () {
        field.value = '';
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.focus();
      });
      wrap.appendChild(clear);
      var sync = function () { wrap.classList.toggle('sf-show-clear', field.value !== ''); };
      field.addEventListener('input', sync);
      sync();
    });

    // Live roll preview (mirrors studentIdCardRollFilterValues)
    var roll = form.querySelector('input[name="cls_roll"]');
    if (roll) {
      var hint = el('div', 'sf-roll-hint');
      hint.setAttribute('aria-live', 'polite');
      roll.parentElement.insertAdjacentElement('afterend', hint);
      var updateRoll = function () {
        var parsed = parseRolls(roll.value);
        hint.className = 'sf-roll-hint';
        roll.classList.remove('is-invalid');
        if (roll.value.trim() === '') { hint.textContent = ''; return; }
        if (parsed.invalid.length) {
          hint.classList.add('text-danger');
          roll.classList.add('is-invalid');
          hint.textContent = 'বোঝা যায়নি: ' + parsed.invalid.join(', ');
          return;
        }
        hint.classList.add('text-success');
        hint.innerHTML = '<i class="bi bi-check2-circle"></i> ' + bnDigits(parsed.count) + 'টি রোল নির্বাচিত';
      };
      roll.addEventListener('input', updateRoll);
      updateRoll();
    }

    // Search shortcut
    var search = form.querySelector('input[name="q"]');
    if (search) {
      search.parentElement.insertAdjacentHTML('beforeend', '<kbd class="sf-kbd" aria-hidden="true">/</kbd>');
      search.setAttribute('aria-keyshortcuts', '/');
      document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
        var target = event.target;
        if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return;
        event.preventDefault();
        if (form.classList.contains('sf-collapsed')) setCollapsed(false);
        search.focus();
        search.select();
      });
    }

    // Collapse
    function setCollapsed(collapsed, remember) {
      form.classList.toggle('sf-collapsed', collapsed);
      toggleBtn.setAttribute('aria-expanded', String(!collapsed));
      toggleBtn.title = collapsed ? 'ফিল্টার দেখান' : 'ফিল্টার লুকান';
      if (remember) storageSet(collapseKey, collapsed);
    }
    toggleBtn.addEventListener('click', function () {
      setCollapsed(!form.classList.contains('sf-collapsed'), true);
    });
    head.addEventListener('dblclick', function (event) {
      if (!event.target.closest('.sf-tools')) setCollapsed(!form.classList.contains('sf-collapsed'), true);
    });
    var rememberedCollapse = storageGet(collapseKey);
    var mobile = window.matchMedia('(max-width: 767.98px)').matches;
    setCollapsed(applied && (rememberedCollapse === true || (rememberedCollapse === null && mobile)), false);

    form.addEventListener('input', refreshState);
    form.addEventListener('change', refreshState);
    form.addEventListener('sf:refresh', refreshState);

    form.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      var submitter = event.submitter || submit;
      storageSet(pageKey, { url: formUrl(form, submitter), summary: summary(), at: Date.now() });
      form.classList.add('sf-submitting');
      form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', function () {
      form.classList.remove('sf-submitting');
      form.removeAttribute('aria-busy');
    });

    renderChips();
    refreshState();
  }

  // Busy indicator on dependent selects while academic-api lookups run.
  var pending = 0;
  function setBusy() {
    document.querySelectorAll('form.sf-form').forEach(function (form) {
      form.classList.toggle('sf-busy', pending > 0);
      if (pending === 0) form.dispatchEvent(new Event('sf:refresh'));
    });
  }
  if (typeof window.fetch === 'function') {
    var nativeFetch = window.fetch;
    window.fetch = function (input) {
      var target = typeof input === 'string' ? input : (input && input.url) || '';
      if (target.indexOf('academic-api.php') === -1) return nativeFetch.apply(this, arguments);
      pending++;
      setBusy();
      var done = function () { pending = Math.max(0, pending - 1); setBusy(); };
      var request = nativeFetch.apply(this, arguments);
      request.then(done, done);
      return request;
    };
  }

  function enhanceTable(table) {
    if (table.dataset.sfReady) return;
    var tbody = table.tBodies[0];
    if (!tbody) return;
    var rows = Array.prototype.filter.call(tbody.rows, function (row) {
      return !(row.cells.length === 1 && row.cells[0].colSpan > 1);
    });
    if (rows.length < 2) return;
    table.dataset.sfReady = '1';

    var placeholder = table.dataset.sfTable || 'তালিকায় খুঁজুন: নাম, রোল, মোবাইল…';
    var bar = el('div', 'sf-table-tools');
    bar.innerHTML =
      '<div class="sf-table-search"><i class="bi bi-search" aria-hidden="true"></i>' +
      '<input type="search" class="form-control form-control-sm" placeholder="' + escapeHtml(placeholder) + '" aria-label="তালিকায় খুঁজুন"></div>' +
      '<span class="sf-table-count"></span>';
    var anchor = table.closest('.table-responsive') || table;
    anchor.parentNode.insertBefore(bar, anchor);
    var input = bar.querySelector('input');
    var counter = bar.querySelector('.sf-table-count');
    var colspan = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells.length : 1;
    var noMatch = el('tr', 'sf-no-match');
    noMatch.innerHTML = '<td colspan="' + colspan + '" class="text-center text-secondary py-4"><i class="bi bi-search me-1"></i>এই অনুসন্ধানে কোনো মিল নেই।</td>';

    var rollCol = table.dataset.sfRollCol !== undefined && table.dataset.sfRollCol !== '' ? Number(table.dataset.sfRollCol) : null;
    var cols = (table.dataset.sfCols || '').split(',').filter(function (c) { return c.trim() !== ''; }).map(Number);
    var index = rows.map(function (row) {
      var cells = cols.length
        ? cols.map(function (c) { return row.cells[c]; }).filter(Boolean)
        : Array.prototype.slice.call(row.cells);
      // Numeric queries match the roll cell exactly so "5" does not also hit roll 15 or a mobile number.
      var rollCells = rollCol !== null && row.cells[rollCol] ? [row.cells[rollCol]] : cells;
      return {
        text: asciiDigits(cells.map(function (cell) { return cell.textContent; }).join(' ')).toLowerCase().replace(/\s+/g, ' '),
        exact: rollCells.map(function (cell) { return asciiDigits(cell.textContent).trim().toLowerCase(); })
      };
    });

    function apply() {
      var query = asciiDigits(input.value).toLowerCase().trim();
      var terms = query.split(/\s+/).filter(Boolean);
      var rollSet = null;
      if (/^[\d\s,;،\-–—~]+$/.test(query) && /[,;،\-–—~]/.test(query)) {
        rollSet = parseRolls(query).values;
      } else if (/^\d{1,3}$/.test(query)) {
        rollSet = {};
        rollSet[parseInt(query, 10)] = true;
      }
      var shown = 0;
      rows.forEach(function (row, i) {
        var match = rollSet
          ? index[i].exact.some(function (value) { return /^\d+$/.test(value) && rollSet[parseInt(value, 10)]; })
          : terms.every(function (term) { return index[i].text.indexOf(term) !== -1; });
        row.hidden = !match;
        if (match) shown++;
      });
      if (shown === 0 && terms.length) tbody.appendChild(noMatch);
      else if (noMatch.parentNode) noMatch.remove();
      counter.textContent = terms.length
        ? bnDigits(shown) + ' / ' + bnDigits(rows.length) + ' জন'
        : 'মোট ' + bnDigits(rows.length) + ' জন';
    }
    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { input.value = ''; apply(); }
      if (event.key === 'Enter') event.preventDefault();
    });
    apply();
  }

  function init() {
    document.querySelectorAll('form[data-student-filter]').forEach(enhanceForm);
    document.querySelectorAll('table[data-sf-table]').forEach(enhanceTable);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
