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
  const colors = { present: '#10b981', absent: '#ef4444' };
  const names = { present: 'উপস্থিত', absent: 'অনুপস্থিত' };
  const charts = [];
  const bn = (value) => String(value).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[d]);
  const sum = (list) => (list || []).reduce((total, n) => total + Number(n || 0), 0);
  const pct = (present, absent) => {
    const total = Number(present) + Number(absent);
    return total > 0 ? Math.round((Number(present) / total) * 1000) / 10 : 0;
  };

  const gradient = (ctx, color) => {
    const area = ctx.chart.chartArea;
    if (!area) return color + '33';
    const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, color + '55');
    g.addColorStop(1, color + '05');
    return g;
  };

  const tooltip = {
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
    padding: 12,
    cornerRadius: 10,
    boxPadding: 6,
    usePointStyle: true,
    titleFont: { weight: '700' },
    callbacks: {
      label: (item) => ` ${item.dataset.label}: ${bn(item.parsed.y)} জন`,
      footer: (items) => {
        const i = items[0].dataIndex;
        const src = items[0].chart.$source;
        return `উপস্থিতির হার: ${bn(pct(src.present[i], src.absent[i]))}%`;
      },
    },
  };

  const baseOptions = (stacked) => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    animation: { duration: 900, easing: 'easeOutQuart' },
    scales: {
      x: { stacked, grid: { display: false }, border: { display: false } },
      y: {
        stacked,
        beginAtZero: true,
        border: { display: false },
        grid: { borderDash: [4, 4] },
        ticks: { precision: 0, maxTicksLimit: 6, callback: (v) => bn(v) },
      },
    },
    plugins: {
      legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16 } },
      tooltip,
    },
  });

  const addSummary = (canvas, items) => {
    const box = canvas.closest('.card-body');
    if (!box) return;
    const row = document.createElement('div');
    row.className = 'dash-chart-summary';
    row.innerHTML = items
      .map((item) => `<div class="dash-chip" style="--chip:${item.color}"><span>${item.label}</span><strong>${item.value}</strong></div>`)
      .join('');
    box.insertBefore(row, box.firstChild);
  };

  const trendCanvas = document.getElementById('attendanceTrendChart');
  if (trendCanvas && data.trend) {
    const t = data.trend;
    const rates = t.labels.map((_, i) => pct(t.present[i], t.absent[i]));
    const withData = rates.map((r, i) => ({ r, i })).filter((x) => Number(t.present[x.i]) + Number(t.absent[x.i]) > 0);
    const avg = withData.length ? Math.round((withData.reduce((n, x) => n + x.r, 0) / withData.length) * 10) / 10 : 0;
    const best = withData.length ? withData.reduce((a, b) => (b.r > a.r ? b : a)) : null;
    addSummary(trendCanvas, [
      { label: 'গড় উপস্থিতি', value: `${bn(avg)}%`, color: colors.present },
      { label: 'সর্বোচ্চ দিন', value: best ? `${t.labels[best.i]} · ${bn(best.r)}%` : '—', color: '#3b82f6' },
      { label: 'মোট অনুপস্থিত', value: `${bn(sum(t.absent))} জন`, color: colors.absent },
    ]);
    const chart = new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: t.labels,
        datasets: ['present', 'absent'].map((key) => ({
          label: names[key],
          // Days with no attendance taken (holidays) become gaps instead of a drop to zero.
          data: t[key].map((n, i) => (Number(t.present[i]) + Number(t.absent[i]) > 0 ? n : null)),
          spanGaps: true,
          borderColor: colors[key],
          backgroundColor: (ctx) => gradient(ctx, colors[key]),
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 7,
          pointBackgroundColor: '#fff',
          pointBorderColor: colors[key],
          pointBorderWidth: 2,
        })),
      },
      options: baseOptions(false),
    });
    chart.$source = t;
    charts.push(chart);
  }

  const classCanvas = document.getElementById('classAttendanceChart');
  if (classCanvas && data.classes) {
    const c = data.classes;
    const totalPresent = sum(c.present);
    const totalAbsent = sum(c.absent);
    addSummary(classCanvas, [
      { label: 'আজ উপস্থিত', value: `${bn(totalPresent)} জন`, color: colors.present },
      { label: 'আজ অনুপস্থিত', value: `${bn(totalAbsent)} জন`, color: colors.absent },
      { label: 'হার', value: `${bn(pct(totalPresent, totalAbsent))}%`, color: '#3b82f6' },
    ]);
    const chart = new Chart(classCanvas, {
      type: 'bar',
      data: {
        labels: c.labels,
        datasets: ['present', 'absent'].map((key) => ({
          label: names[key],
          data: c[key],
          backgroundColor: colors[key],
          borderRadius: 6,
          borderSkipped: false,
          maxBarThickness: 34,
        })),
      },
      options: baseOptions(true),
    });
    chart.$source = c;
    charts.push(chart);
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
