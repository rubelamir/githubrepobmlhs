(function () {
  'use strict';

  var dataNode = document.getElementById('fp-sections');
  var classSelect = document.querySelector('[data-fp-class]');
  var sectionSelect = document.querySelector('[data-fp-section]');
  if (dataNode && classSelect && sectionSelect) {
    var map = {};
    try {
      map = JSON.parse(dataNode.textContent || '{}');
    } catch (error) {
      map = {};
    }
    var fill = function () {
      var sections = map[classSelect.value] || [];
      var selected = sectionSelect.getAttribute('data-selected') || '0';
      sectionSelect.innerHTML = '';
      if (sections.length === 0) {
        sectionSelect.innerHTML = '<option value="0">শাখা নেই</option>';
        return;
      }
      sections.forEach(function (section) {
        var option = document.createElement('option');
        option.value = String(section.id);
        option.textContent = section.name;
        if (String(section.id) === selected) {
          option.selected = true;
        }
        sectionSelect.appendChild(option);
      });
    };
    classSelect.addEventListener('change', function () {
      sectionSelect.setAttribute('data-selected', '0');
      fill();
    });
    fill();
  }

  var selectForm = document.querySelector('[data-fee-portal-select]');
  if (selectForm) {
    var total = selectForm.querySelector('[data-fp-selected]');
    var boxes = selectForm.querySelectorAll('[data-fp-amount]');
    var refresh = function () {
      var sum = 0;
      boxes.forEach(function (box) {
        if (box.checked) {
          sum += parseInt(box.getAttribute('data-fp-amount') || '0', 10);
        }
      });
      if (total) {
        var taka = Math.floor(sum / 100);
        var paisa = String(sum % 100).padStart(2, '0');
        total.textContent = '৳ ' + taka.toLocaleString('bn-BD') + '.' + paisa.replace(/\d/g, function (digit) {
          return '০১২৩৪৫৬৭৮৯'[digit];
        });
      }
    };
    boxes.forEach(function (box) {
      box.addEventListener('change', refresh);
    });
    selectForm.addEventListener('submit', function (event) {
      var any = false;
      boxes.forEach(function (box) {
        any = any || box.checked;
      });
      if (!any) {
        event.preventDefault();
        window.alert('কোন ইনভয়েস পরিশোধ করবেন তা নির্বাচন করুন।');
      }
    });
  }

  var payForm = document.querySelector('[data-fee-portal-pay]');
  if (payForm) {
    payForm.addEventListener('submit', function () {
      var button = payForm.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.textContent = 'bKash-এ নিয়ে যাওয়া হচ্ছে…';
      }
    });
  }
}());
