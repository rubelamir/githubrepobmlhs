'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../../bmlhs/js/prayer-times.js'), 'utf8');
const sandbox = { module: { exports: {} }, exports: {}, console: console };
vm.runInNewContext(source, sandbox, { filename: 'prayer-times.js' });
const { buildSchedule, toMinutes } = sandbox.module.exports;

const today = {
  Fajr: '04:53',
  Sunrise: '06:11',
  Dhuhr: '11:42',
  Asr: '15:36',
  Maghrib: '17:12',
  Isha: '18:30'
};

const schedule = buildSchedule(today, { prevMaghrib: '17:12', nextFajr: '04:54' });
assert.ok(schedule, 'schedule is built');

function pair(row) {
  return [Number(toMinutes(row.start)), Number(toMinutes(row.end))];
}

function rowsOf(list) {
  const rows = [];
  for (let i = 0; i < list.length; i += 1) {
    rows.push(pair(list[i]));
  }
  return rows;
}

const prayers = {};
for (let i = 0; i < schedule.prayers.length; i += 1) {
  prayers[schedule.prayers[i].key] = pair(schedule.prayers[i]);
}
assert.deepStrictEqual(prayers, {
  Tahajjud: [59, 292],
  Fajr: [293, 370],
  Ishraq: [386, 536],
  Duha: [537, 695],
  Dhuhr: [703, 935],
  Asr: [936, 1017],
  Maghrib: [1032, 1109],
  Awwabin: [1032, 1109],
  Isha: [1110, 1383]
});

assert.deepStrictEqual(rowsOf(schedule.forbidden), [
  [371, 385],
  [696, 701],
  [1017, 1031]
]);
assert.strictEqual(schedule.forbidden.length, 3);

const names = [];
for (let i = 0; i < schedule.prayers.length; i += 1) {
  names.push(schedule.prayers[i].name);
}
assert.strictEqual(names.join(','), 'তাহাজ্জুদ,ফজর,ইশরাক,চাশত,যোহর,আসর,মাগরিব,আওয়াবিন,এশা');
assert.strictEqual(buildSchedule({ Fajr: 'bad' }), null);

console.log('PASS');
