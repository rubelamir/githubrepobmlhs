(() => {
  'use strict';

  const dataElement = document.getElementById('dashboardChartData');
  if (!dataElement || typeof Chart === 'undefined') return;

  let data;
  try {
    data = JSON.parse(dataElement.textContent || '{}');
  } catch {
    return;
  }

  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const colors = { present: '#198754', absent: '#dc3545', late: '#f0ad00' };
  const names = { present: 'উপস্থিত', absent: 'অনুপস্থিত', late: 'দেরিতে' };
  const charts = [];

  const datasets = (source, type) =>
    ['present', 'absent', 'late'].map((key) => ({
      label: names[key],
      data: source[key],
      borderColor: colors[key],
      backgroundColor: colors[key],
      borderWidth: type === 'bar' ? 0 : 2,
      tension: 0.3,
      pointRadius: 3,
      fill: false,
      borderRadius: type === 'bar' ? 4 : 0,
    }));

  const baseOptions = (stacked) => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    scales: {
      x: { stacked, grid: { display: false } },
      y: { stacked, beginAtZero: true, ticks: { precision: 0 } },
    },
    plugins: { legend: { position: 'bottom' } },
  });

  const trendCanvas = document.getElementById('attendanceTrendChart');
  if (trendCanvas && data.trend) {
    charts.push(
      new Chart(trendCanvas, {
        type: 'line',
        data: { labels: data.trend.labels, datasets: datasets(data.trend, 'line') },
        options: baseOptions(false),
      })
    );
  }

  const classCanvas = document.getElementById('classAttendanceChart');
  if (classCanvas && data.classes) {
    charts.push(
      new Chart(classCanvas, {
        type: 'bar',
        data: { labels: data.classes.labels, datasets: datasets(data.classes, 'bar') },
        options: baseOptions(true),
      })
    );
  }

  // Chart text and grid colours follow the light/dark theme, including live theme switches.
  const applyTheme = () => {
    const text = css('--bs-body-color') || '#212529';
    const border = css('--bs-border-color') || 'rgba(0, 0, 0, 0.1)';
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    charts.forEach((chart) => {
      Object.values(chart.options.scales).forEach((scale) => {
        scale.ticks = { ...scale.ticks, color: text };
        scale.grid = { ...scale.grid, color: border };
      });
      chart.options.plugins.legend.labels = { color: text };
      chart.update();
    });
  };

  applyTheme();
  new MutationObserver(applyTheme).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-bs-theme'],
  });
})();
