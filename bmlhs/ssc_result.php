<?php
declare(strict_types=1);

$sscRows = sscExamResults(publicDatabase());
$sscYears = [];
$sscGpa5 = [];
$sscGpa4 = [];
$sscGpa3 = [];
$sscGpa2 = [];
$sscGpa1 = [];
$sscFail = [];
$sscAppeared = [];
$sscPassRate = [];
foreach ($sscRows as $row) {
    $gpa5 = (int) $row['gpa5'];
    $gpa4 = (int) $row['gpa4'];
    $gpa3 = (int) $row['gpa3'];
    $gpa2 = (int) $row['gpa2'];
    $gpa1 = (int) $row['gpa1'];
    $fail = (int) $row['fail_count'];
    $passed = $gpa5 + $gpa4 + $gpa3 + $gpa2 + $gpa1;
    $appeared = $passed + $fail;

    $sscYears[] = (int) $row['exam_year'];
    $sscGpa5[] = $gpa5;
    $sscGpa4[] = $gpa4;
    $sscGpa3[] = $gpa3;
    $sscGpa2[] = $gpa2;
    $sscGpa1[] = $gpa1;
    $sscFail[] = $fail;
    $sscAppeared[] = $appeared;
    $sscPassRate[] = $appeared > 0 ? round(($passed / $appeared) * 100, 1) : 0;
}
?>
<div id="chart" class="school-ssc-chart" role="img" aria-label="বিগত বছরগুলোর এস.এস.সি পরীক্ষার ফলাফল ও পাশের হার"></div>
<?php if ($sscRows !== []): ?>
<script>
// ApexCharts (and its stylesheet) are only fetched once the chart scrolls into view.
(function () {
var chartEl = document.getElementById('chart');
if (!chartEl) {
return;
}

var BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
var passRates = <?= json_encode($sscPassRate, JSON_UNESCAPED_UNICODE) ?>;
var appeared = <?= json_encode($sscAppeared, JSON_UNESCAPED_UNICODE) ?>;
var axisMax = Math.max.apply(null, appeared.concat([0]));
axisMax = Math.ceil((axisMax * 1.18) / 10) * 10;

function toBanglaDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
        return BN_DIGITS[digit];
    });
}

function formatRate(rate) {
    var number = Number(rate) || 0;
    return number % 1 === 0 ? number.toFixed(0) : number.toFixed(1);
}

function placeEndLabels() {
    if (!chartEl) {
        return;
    }
    var old = chartEl.querySelector('.school-ssc-end-labels');
    if (old) {
        old.parentNode.removeChild(old);
    }
    var series = chartEl.querySelectorAll('.apexcharts-bar-series .apexcharts-series');
    if (!series.length) {
        return;
    }
    var barSets = [];
    [].forEach.call(series, function (group) {
        barSets.push(group.querySelectorAll('path.apexcharts-bar-area, rect'));
    });
    var count = barSets[0] ? barSets[0].length : 0;
    if (!count) {
        return;
    }
    var rootBox = chartEl.getBoundingClientRect();
    var layer = document.createElement('div');
    layer.className = 'school-ssc-end-labels';
    for (var index = 0; index < count; index++) {
        var right = 0;
        var top = 0;
        var height = 0;
        barSets.forEach(function (bars) {
            var el = bars[index];
            if (!el || typeof el.getBoundingClientRect !== 'function') {
                return;
            }
            var box = el.getBoundingClientRect();
            if (box.right > right) {
                right = box.right;
            }
            top = box.top;
            height = box.height;
        });
        if (!right) {
            continue;
        }
        var label = document.createElement('div');
        label.className = 'school-ssc-end-label';
        label.style.left = (right - rootBox.left + 8) + 'px';
        label.style.top = (top - rootBox.top + (height / 2)) + 'px';
        label.textContent = '= ' + toBanglaDigits(appeared[index] || 0) + ', পাশ: ' + toBanglaDigits(formatRate(passRates[index])) + '%';
        layer.appendChild(label);
    }
    chartEl.appendChild(layer);
}

var options = {
series: [
{ name: 'GPA 5.00', data: <?= json_encode($sscGpa5, JSON_UNESCAPED_UNICODE) ?> },
{ name: 'GPA 4.x', data: <?= json_encode($sscGpa4, JSON_UNESCAPED_UNICODE) ?> },
{ name: 'GPA 3.x', data: <?= json_encode($sscGpa3, JSON_UNESCAPED_UNICODE) ?> },
{ name: 'GPA 2.x', data: <?= json_encode($sscGpa2, JSON_UNESCAPED_UNICODE) ?> },
{ name: 'GPA 1.x', data: <?= json_encode($sscGpa1, JSON_UNESCAPED_UNICODE) ?> },
{ name: 'Fail', data: <?= json_encode($sscFail, JSON_UNESCAPED_UNICODE) ?> }
],
chart: {
type: 'bar',
height: 560,
stacked: true,
fontFamily: '"Noto Sans Bengali", sans-serif',
toolbar: { show: false },
events: {
    mounted: placeEndLabels,
    updated: placeEndLabels,
    animationEnd: placeEndLabels
}
},
dataLabels: {
enabled: true,
formatter: function (val) {
    var count = Number(val) || 0;
    return count > 0 ? toBanglaDigits(count) : '';
},
style: {
fontSize: '11px',
fontFamily: '"Noto Sans Bengali", sans-serif',
fontWeight: 700,
colors: ['#ffffff', '#ffffff', '#ffffff', '#ffffff', '#1b3c34', '#ffffff']
},
dropShadow: { enabled: false }
},
plotOptions: {
bar: {
horizontal: true,
barHeight: '82%',
dataLabels: {
hideOverflowingLabels: true
}
}
},
colors: ['#1a4d3e', '#246b54', '#3f8f74', '#c4892a', '#e8c078', '#b42318'],
stroke: {
width: 1,
colors: ['#fff']
},
grid: {
padding: {
right: 118
}
},
xaxis: {
categories: <?= json_encode($sscYears, JSON_UNESCAPED_UNICODE) ?>,
max: axisMax,
labels: {
formatter: function (val) {
return val
}
}
},
yaxis: {
title: {
text: undefined
},
},
tooltip: {
shared: true,
intersect: false,
custom: function ({ series, dataPointIndex, w }) {
    var year = w.globals.labels[dataPointIndex];
    var rate = formatRate(passRates[dataPointIndex]);
    var total = appeared[dataPointIndex] || 0;
    var rows = w.config.series.map(function (item, index) {
        var color = (w.globals.colors && w.globals.colors[index]) || '#1a4d3e';
        var value = series[index] ? series[index][dataPointIndex] : 0;
        return '<div class="school-ssc-tip-row"><span class="school-ssc-tip-dot" style="background:' + color + '"></span><span>' + item.name + '</span><em>' + toBanglaDigits(value) + ' জন</em></div>';
    }).join('');
    var totalLine = '= ' + toBanglaDigits(total) + ' জন';
    return '<div class="school-ssc-tip">' +
        '<p class="school-ssc-tip-year">' + toBanglaDigits(year) + ' সাল</p>' +
        '<p class="school-ssc-tip-rate">পাশের হার <strong>' + toBanglaDigits(rate) + '%</strong></p>' +
        rows +
        '<p class="school-ssc-tip-total">' + totalLine + '</p>' +
        '</div>';
}
},
fill: {
opacity: 1
},
legend: {
position: 'top',
horizontalAlign: 'left',
offsetX: 40
}
};

var started = false;
function renderChart() {
if (started) {
return;
}
started = true;
var style = document.createElement('link');
style.rel = 'stylesheet';
style.href = 'vendor/apexcharts/apexcharts.css';
document.head.appendChild(style);
var script = document.createElement('script');
script.src = 'vendor/apexcharts/apexcharts.min.js';
script.onload = function () {
new ApexCharts(chartEl, options).render().then(placeEndLabels);
};
document.head.appendChild(script);
}

if ('IntersectionObserver' in window) {
var observer = new IntersectionObserver(function (entries) {
entries.forEach(function (entry) {
if (entry.isIntersecting) {
observer.disconnect();
renderChart();
}
});
}, { rootMargin: '250px' });
observer.observe(chartEl);
} else {
renderChart();
}
})();
</script>
<?php endif; ?>
