(function () {
  var canvas = document.getElementById('homeClassSectionChart');
  var dataEl = document.getElementById('homeClassSectionChartData');
  var legendEl = document.getElementById('homeClassSectionLegend');
  if (!canvas || !dataEl || typeof Chart === 'undefined') {
    return;
  }

  var items;
  try {
    items = JSON.parse(dataEl.textContent || '[]');
  } catch (error) {
    return;
  }
  if (!Array.isArray(items) || items.length === 0) {
    return;
  }

  var BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  var COLORS = [
    '#1a73e8', '#34a853', '#fbbc04', '#ea4335', '#12b5cb',
    '#9334e6', '#ff6d01', '#188038', '#1967d2', '#c5221f',
    '#00897b', '#5c6bc0', '#ec407a', '#7cb342', '#fb8c00',
    '#26a69a', '#8e24aa', '#c0ca33', '#3949ab', '#00acc1'
  ];

  function toBanglaDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return BN_DIGITS[digit];
    });
  }

  function formatPercent(value, total) {
    if (!total) {
      return '০';
    }
    var pct = (value / total) * 100;
    var rounded = Math.round(pct * 10) / 10;
    return toBanglaDigits(rounded % 1 === 0 ? String(rounded.toFixed(0)) : String(rounded.toFixed(1)));
  }

  var labels = items.map(function (item) {
    return String(item.label || 'শ্রেণী');
  });
  var values = items.map(function (item) {
    return Number(item.value) || 0;
  });
  var colors = values.map(function (_value, index) {
    return COLORS[index % COLORS.length];
  });
  var total = values.reduce(function (sum, value) {
    return sum + value;
  }, 0);

  var chart = new Chart(canvas, {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{
        data: values,
        backgroundColor: colors,
        borderColor: '#ffffff',
        borderWidth: 2,
        hoverOffset: 8,
        hoverBorderColor: '#ffffff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: {
        padding: 4
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          backgroundColor: '#12382e',
          titleColor: '#ffffff',
          bodyColor: '#ffffff',
          titleFont: {
            family: '"Noto Sans Bengali", sans-serif',
            size: 13,
            weight: '700'
          },
          bodyFont: {
            family: '"Noto Sans Bengali", sans-serif',
            size: 12
          },
          padding: 10,
          cornerRadius: 8,
          displayColors: true,
          boxPadding: 4,
          callbacks: {
            title: function (entries) {
              return entries[0] ? entries[0].label : '';
            },
            label: function (context) {
              var value = Number(context.parsed) || 0;
              return toBanglaDigits(value) + ' জন (' + formatPercent(value, total) + '%)';
            }
          }
        }
      }
    }
  });

  if (!legendEl) {
    return;
  }

  legendEl.innerHTML = items.map(function (item, index) {
    var label = labels[index];
    var value = values[index];
    var color = colors[index];
    return '<li><button type="button" data-index="' + index + '" aria-pressed="true">' +
      '<span class="dot" style="background:' + color + ';color:' + color + '"></span>' +
      '<span class="name">' + label.replace(/[&<>"]/g, function (char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[char];
      }) + '</span>' +
      '<span class="count">' + toBanglaDigits(value) + '</span>' +
      '</button></li>';
  }).join('');

  legendEl.addEventListener('click', function (event) {
    var button = event.target.closest('button[data-index]');
    if (!button) {
      return;
    }
    var index = Number(button.getAttribute('data-index'));
    var visible = chart.getDataVisibility(index);
    chart.toggleDataVisibility(index);
    chart.update();
    button.classList.toggle('is-off', visible);
    button.setAttribute('aria-pressed', visible ? 'false' : 'true');
  });
})();
