(function () {
  'use strict';

  var node = document.getElementById('acc-chart-data');
  if (!node || typeof Chart === 'undefined') {
    return;
  }
  var data;
  try {
    data = JSON.parse(node.textContent || '{}');
  } catch (error) {
    return;
  }

  var taka = function (paisa) {
    var sign = paisa < 0 ? '-' : '';
    var value = Math.abs(paisa);
    var whole = Math.floor(value / 100).toLocaleString('bn-BD');
    var fraction = String(value % 100).padStart(2, '0');
    return sign + '৳ ' + whole + '.' + fraction;
  };
  var scale = {
    ticks: {
      callback: function (value) {
        return taka(value);
      }
    }
  };
  var tooltip = {
    callbacks: {
      label: function (item) {
        return item.dataset.label + ': ' + taka(item.parsed.y);
      }
    }
  };

  var collection = document.getElementById('acc-chart-collection');
  if (collection) {
    new Chart(collection, {
      type: 'bar',
      data: {
        labels: data.labels,
        datasets: [{ label: 'আদায়', data: data.collection, backgroundColor: '#1a4d3e' }]
      },
      options: { responsive: true, plugins: { tooltip: tooltip }, scales: { y: scale } }
    });
  }

  var flow = document.getElementById('acc-chart-flow');
  if (flow && data.income && data.income.length) {
    new Chart(flow, {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [
          { label: 'আয়', data: data.income, borderColor: '#1a4d3e', backgroundColor: '#1a4d3e', tension: 0.2 },
          { label: 'ব্যয়', data: data.expense, borderColor: '#c4892a', backgroundColor: '#c4892a', tension: 0.2 }
        ]
      },
      options: { responsive: true, plugins: { tooltip: tooltip }, scales: { y: scale } }
    });
  }
}());
