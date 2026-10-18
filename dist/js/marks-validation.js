(function () {
  'use strict';

  var BN_DIGITS = {
    '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4',
    '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9',
  };

  function toAsciiDigits(value) {
    return String(value == null ? '' : value).replace(/[০-৯]/g, function (digit) {
      return BN_DIGITS[digit] || digit;
    });
  }

  function sanitizeMarkValue(raw) {
    var cleaned = toAsciiDigits(raw).replace(/[^0-9.]/g, '');
    if (cleaned === '') {
      return '';
    }
    var firstDot = cleaned.indexOf('.');
    if (firstDot === -1) {
      return cleaned;
    }
    var intPart = cleaned.slice(0, firstDot);
    var fracPart = cleaned.slice(firstDot + 1).replace(/\./g, '').slice(0, 2);
    if (intPart === '') {
      intPart = '0';
    }
    return fracPart === '' ? intPart : (intPart + '.' + fracPart);
  }

  function formatMarkDisplay(value) {
    if (!isFinite(value)) {
      return '';
    }
    var text = String(Math.round(value * 100) / 100);
    if (text.indexOf('.') === -1) {
      return text;
    }
    return text.replace(/\.?0+$/, '');
  }

  function letterGrade(obtained, full) {
    if (!(full > 0)) {
      return 'N/A';
    }
    var percent = (obtained / full) * 100;
    if (percent >= 80) return 'A+';
    if (percent >= 70) return 'A';
    if (percent >= 60) return 'A-';
    if (percent >= 50) return 'B';
    if (percent >= 40) return 'C';
    if (percent >= 33) return 'D';
    return 'F';
  }

  function parseNumber(raw) {
    var value = sanitizeMarkValue(raw);
    if (value === '') {
      return null;
    }
    var number = Number(value);
    if (!isFinite(number) || number < 0) {
      return NaN;
    }
    return number;
  }

  function setInputError(input, message) {
    var field = input.closest('.mark-field');
    var error = field ? field.querySelector('.mark-error') : null;
    var text = error ? error.querySelector('.mark-error-text') : null;
    if (message) {
      input.classList.add('is-invalid');
      input.setAttribute('aria-invalid', 'true');
      if (error) {
        if (text) {
          text.textContent = message;
        }
        error.hidden = false;
      }
    } else {
      input.classList.remove('is-invalid');
      input.removeAttribute('aria-invalid');
      if (error) {
        if (text) {
          text.textContent = '';
        }
        error.hidden = true;
      }
    }
  }

  function passMark(form, component) {
    var key = component === 'MCQ' ? 'data-pass-mcq' : (component === 'CQ' ? 'data-pass-cq' : 'data-pass-practical');
    var raw = form ? form.getAttribute(key) : '';
    if (raw === null || raw === '') {
      return null;
    }
    var number = Number(raw);
    return isFinite(number) ? number : null;
  }

  function updateRowTotal(row, fullMarks) {
    if (!row) {
      return { total: null, over: false };
    }
    var form = row.closest('form');
    var inputs = row.querySelectorAll('.mark-input');
    var total = 0;
    var hasValue = false;
    var invalid = false;
    var failedPart = false;
    Array.prototype.forEach.call(inputs, function (input) {
      var number = parseNumber(input.value);
      var pass = passMark(form, input.getAttribute('data-component'));
      if (number === null) {
        if (pass !== null && hasValue) {
          failedPart = true;
        }
        return;
      }
      if (!isFinite(number)) {
        invalid = true;
        return;
      }
      hasValue = true;
      total += number;
      if (pass !== null && number < pass) {
        failedPart = true;
      }
    });
    if (hasValue) {
      Array.prototype.forEach.call(inputs, function (input) {
        var pass = passMark(form, input.getAttribute('data-component'));
        if (pass !== null && parseNumber(input.value) === null) {
          failedPart = true;
        }
      });
    }
    var gradeFull = 0;
    if (hasValue && !invalid) {
      Array.prototype.forEach.call(inputs, function (input) {
        var pass = passMark(form, input.getAttribute('data-component'));
        var max = Number(input.getAttribute('data-max') || '0');
        if (pass !== null && max > 0) {
          gradeFull += max;
        }
      });
    }
    if (!(gradeFull > 0)) {
      gradeFull = fullMarks > 0 ? fullMarks : 100;
    }
    var totalCell = row.querySelector('.mark-total');
    var gradeCell = row.querySelector('.mark-grade');
    var over = hasValue && fullMarks > 0 && total > fullMarks + 1e-9;
    if (totalCell) {
      totalCell.textContent = hasValue && !invalid ? formatMarkDisplay(total) : '—';
      totalCell.classList.toggle('is-over', over);
    }
    if (gradeCell) {
      gradeCell.textContent = hasValue && !invalid && !over
        ? (failedPart ? 'F' : letterGrade(total, gradeFull))
        : '—';
    }
    return { total: hasValue ? total : null, over: over, invalid: invalid };
  }

  function validateInput(input, fullMarks, fullLabel) {
    var sanitized = sanitizeMarkValue(input.value);
    if (input.value !== sanitized) {
      var start = input.selectionStart;
      var end = input.selectionEnd;
      var delta = input.value.length - sanitized.length;
      input.value = sanitized;
      if (typeof start === 'number' && typeof end === 'number') {
        var next = Math.max(0, start - delta);
        try {
          input.setSelectionRange(next, next);
        } catch (error) {
          // Some Android WebViews reject setSelectionRange on certain inputs.
        }
      }
    }

    var number = parseNumber(input.value);
    if (number !== null && !isFinite(number)) {
      setInputError(input, 'শুধু সংখ্যা');
      return false;
    }
    var componentMax = Number(input.getAttribute('data-max') || '0');
    if (number !== null && componentMax > 0 && number > componentMax + 1e-9) {
      setInputError(input, 'সর্বোচ্চ ' + formatMarkDisplay(componentMax));
      updateRowTotal(input.closest('tr'), fullMarks);
      return false;
    }

    var rowState = updateRowTotal(input.closest('tr'), fullMarks);
    if (rowState.over) {
      setInputError(input, 'পূর্ণমান ' + fullLabel);
      return false;
    }

    setInputError(input, '');
    return true;
  }

  function validateForm(form) {
    var fullMarks = Number(form.getAttribute('data-full-marks') || '0');
    var fullLabel = form.getAttribute('data-full-label') || String(fullMarks || '');
    var rows = form.querySelectorAll('tr[data-mark-row]');
    var invalidRows = 0;
    var firstInvalid = null;

    Array.prototype.forEach.call(rows, function (row) {
      var rowInvalid = false;
      var inputs = row.querySelectorAll('.mark-input');
      Array.prototype.forEach.call(inputs, function (input) {
        if (!validateInput(input, fullMarks, fullLabel)) {
          rowInvalid = true;
          if (!firstInvalid) {
            firstInvalid = input;
          }
        }
      });
      var rowState = updateRowTotal(row, fullMarks);
      if (rowState.over) {
        rowInvalid = true;
        Array.prototype.forEach.call(inputs, function (input) {
          if (parseNumber(input.value) !== null) {
            setInputError(input, 'পূর্ণমান ' + fullLabel);
            if (!firstInvalid) {
              firstInvalid = input;
            }
          }
        });
      }
      if (rowInvalid) {
        invalidRows += 1;
      }
    });

    var summary = form.querySelector('#resultsMarkSummary');
    if (summary) {
      if (invalidRows > 0) {
        summary.hidden = false;
        summary.textContent = '⚠ ' + invalidRows + 'টি সারিতে ভুল আছে। সংশোধন করে আবার Save করুন।';
      } else {
        summary.hidden = true;
        summary.textContent = '';
      }
    }

    return { ok: invalidRows === 0, firstInvalid: firstInvalid, invalidRows: invalidRows };
  }

  function focusNextMark(form, current) {
    var inputs = Array.prototype.slice.call(form.querySelectorAll('.mark-input'));
    var index = inputs.indexOf(current);
    if (index === -1) {
      return;
    }
    if (index < inputs.length - 1) {
      inputs[index + 1].focus();
      return;
    }
    var saveButton = form.querySelector('.results-save button[type="submit"], button.results-save[type="submit"]');
    if (saveButton) {
      saveButton.focus();
    }
  }

  function bindMarkForm(form) {
    if (!form || form.getAttribute('data-marks-validation') === '1') {
      return;
    }
    form.setAttribute('data-marks-validation', '1');
    var fullMarks = Number(form.getAttribute('data-full-marks') || '0');
    var fullLabel = form.getAttribute('data-full-label') || String(fullMarks || '');

    form.addEventListener('input', function (event) {
      var input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.classList.contains('mark-input')) {
        return;
      }
      validateInput(input, fullMarks, fullLabel);
      var summary = form.querySelector('#resultsMarkSummary');
      if (summary && !summary.hidden) {
        validateForm(form);
      }
    });

    form.addEventListener('focusin', function (event) {
      var input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.classList.contains('mark-input')) {
        return;
      }
      window.setTimeout(function () {
        try {
          input.select();
        } catch (error) {
          // Ignore selection failures in older WebViews.
        }
      }, 0);
    });

    form.addEventListener('keydown', function (event) {
      var input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.classList.contains('mark-input')) {
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        focusNextMark(form, input);
      }
    });

    form.addEventListener('submit', function (event) {
      var result = validateForm(form);
      if (result.ok) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      if (result.firstInvalid) {
        result.firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        result.firstInvalid.focus();
      }
    });

    Array.prototype.forEach.call(form.querySelectorAll('tr[data-mark-row]'), function (row) {
      updateRowTotal(row, fullMarks);
    });
  }

  function boot() {
    var form = document.getElementById('resultsMarkForm');
    if (form) {
      bindMarkForm(form);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
