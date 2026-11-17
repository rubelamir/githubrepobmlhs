(function () {
  var CITY = 'Lakshmipur';
  var COUNTRY = 'Bangladesh';
  var LATITUDE = '22.9447';
  var LONGITUDE = '90.8300';
  var METHOD = '1';
  var SCHOOL = '1';
  var CACHE_KEY = 'bmlhs:prayerTimes:v6';
  var TIMEZONE = 'Asia/Dhaka';
  var TIMING_KEYS = ['Fajr', 'Sunrise', 'Dhuhr', 'Asr', 'Maghrib', 'Isha'];
  var BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  var SUNRISE_GAP_MIN = 15;
  var SUNSET_GAP_MIN = 15;
  var ZAWAL_BEFORE_MIN = 6;

  function toMinutes(raw) {
    var match = String(raw || '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) {
      return null;
    }
    return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
  }

  function fromMinutes(total) {
    var minutes = ((total % 1440) + 1440) % 1440;
    var hour = Math.floor(minutes / 60);
    var minute = minutes % 60;
    return hour + ':' + String(minute).padStart(2, '0');
  }

  function nightLength(startRaw, endRaw) {
    var start = toMinutes(startRaw);
    var end = toMinutes(endRaw);
    if (start === null || end === null) {
      return null;
    }
    return (end - start + 1440) % 1440;
  }

  /**
   * Calendar day in Lakshmipur. Tahajjud is the last third of last night
   * (yesterday's maghrib until today's fajr). Isha ends at the middle of
   * tonight (today's maghrib until tomorrow's fajr). The other windows follow
   * the same gaps as the local Karachi/Hanafi timetable.
   */
  function buildSchedule(timings, bounds) {
    var extra = bounds || {};
    var fajr = toMinutes(timings.Fajr);
    var sunrise = toMinutes(timings.Sunrise);
    var dhuhr = toMinutes(timings.Dhuhr);
    var asr = toMinutes(timings.Asr);
    var maghrib = toMinutes(timings.Maghrib);
    var isha = toMinutes(timings.Isha);
    if ([fajr, sunrise, dhuhr, asr, maghrib, isha].indexOf(null) !== -1) {
      return null;
    }

    var prevMaghrib = extra.prevMaghrib || timings.Maghrib;
    var nextFajr = extra.nextFajr || timings.Fajr;
    var morningNight = nightLength(prevMaghrib, timings.Fajr);
    var eveningNight = nightLength(timings.Maghrib, nextFajr);
    if (!morningNight || !eveningNight) {
      return null;
    }

    var tahajjudStart = toMinutes(prevMaghrib) + Math.floor(morningNight * 2 / 3);
    var ishaEnd = maghrib + Math.round(eveningNight / 2);
    var midMorning = Math.floor((sunrise + dhuhr) / 2);

    function span(start, end) {
      return { start: fromMinutes(start), end: fromMinutes(end) };
    }

    return {
      prayers: [
        Object.assign({ key: 'Tahajjud', name: 'তাহাজ্জুদ' }, span(tahajjudStart, fajr - 1)),
        Object.assign({ key: 'Fajr', name: 'ফজর' }, span(fajr, sunrise - 1)),
        Object.assign({ key: 'Ishraq', name: 'ইশরাক' }, span(sunrise + SUNRISE_GAP_MIN, midMorning)),
        Object.assign({ key: 'Duha', name: 'চাশত' }, span(midMorning + 1, dhuhr - (ZAWAL_BEFORE_MIN + 1))),
        Object.assign({ key: 'Dhuhr', name: 'যোহর' }, span(dhuhr + 1, asr - 1)),
        Object.assign({ key: 'Asr', name: 'আসর' }, span(asr, maghrib - SUNSET_GAP_MIN)),
        Object.assign({ key: 'Maghrib', name: 'মাগরিব' }, span(maghrib, isha - 1)),
        Object.assign({ key: 'Awwabin', name: 'আওয়াবিন' }, span(maghrib, isha - 1)),
        Object.assign({ key: 'Isha', name: 'এশা' }, span(isha, ishaEnd))
      ],
      forbidden: [
        Object.assign({ name: 'সূর্যোদয়' }, span(sunrise, sunrise + SUNRISE_GAP_MIN - 1)),
        Object.assign({ name: 'যাওয়াল' }, span(dhuhr - ZAWAL_BEFORE_MIN, dhuhr - 1)),
        Object.assign({ name: 'সূর্যাস্ত' }, span(maghrib - SUNSET_GAP_MIN, maghrib - 1))
      ]
    };
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
      buildSchedule: buildSchedule,
      toMinutes: toMinutes,
      fromMinutes: fromMinutes
    };
  }

  if (typeof document === 'undefined') {
    return;
  }

  var root = document.getElementById('schoolPrayerTimes');
  if (!root) {
    return;
  }

  var city = (root.getAttribute('data-city') || CITY).trim() || CITY;
  var country = (root.getAttribute('data-country') || COUNTRY).trim() || COUNTRY;
  var latitude = (root.getAttribute('data-latitude') || LATITUDE).trim() || LATITUDE;
  var longitude = (root.getAttribute('data-longitude') || LONGITUDE).trim() || LONGITUDE;
  var method = (root.getAttribute('data-method') || METHOD).trim() || METHOD;
  var school = (root.getAttribute('data-school') || SCHOOL).trim() || SCHOOL;
  var statusEl = root.querySelector('[data-prayer-status]');
  var boardEl = root.querySelector('[data-prayer-board]');
  var listEl = root.querySelector('[data-prayer-list]');
  var forbiddenListEl = root.querySelector('[data-prayer-forbidden-list]');
  var dateEl = root.querySelector('[data-prayer-date]');

  function todayKey() {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: TIMEZONE,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).format(new Date());
  }

  function addDays(isoDate, days) {
    var parts = String(isoDate).split('-');
    var date = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10) + days));
    var month = String(date.getUTCMonth() + 1).padStart(2, '0');
    var day = String(date.getUTCDate()).padStart(2, '0');
    return date.getUTCFullYear() + '-' + month + '-' + day;
  }

  function toAladhanDate(isoDate) {
    var parts = String(isoDate).split('-');
    return parts[2] + '-' + parts[1] + '-' + parts[0];
  }

  function toBanglaDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return BN_DIGITS[digit];
    });
  }

  function formatTime(raw) {
    var minutes = toMinutes(raw);
    if (minutes === null) {
      return '—';
    }
    var hour = Math.floor(minutes / 60);
    var minute = minutes % 60;
    var hour12 = hour % 12;
    if (hour12 === 0) {
      hour12 = 12;
    }
    var ampm = hour < 12 ? 'AM' : 'PM';
    return toBanglaDigits(hour12 + ':' + String(minute).padStart(2, '0')) + ' ' + ampm;
  }

  function dhakaMinutesNow() {
    var parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: TIMEZONE,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    }).formatToParts(new Date());
    var hour = 0;
    var minute = 0;
    for (var i = 0; i < parts.length; i += 1) {
      if (parts[i].type === 'hour') {
        hour = parseInt(parts[i].value, 10);
      }
      if (parts[i].type === 'minute') {
        minute = parseInt(parts[i].value, 10);
      }
    }
    return hour * 60 + minute;
  }

  function isTimeInWindow(now, startRaw, endRaw) {
    var start = toMinutes(startRaw);
    var end = toMinutes(endRaw);
    if (start === null || end === null) {
      return false;
    }
    if (start <= end) {
      return now >= start && now <= end;
    }
    return now >= start || now <= end;
  }

  function activeKeys(prayers) {
    var now = dhakaMinutesNow();
    var active = {};
    var i;
    for (i = 0; i < prayers.length; i += 1) {
      if (isTimeInWindow(now, prayers[i].start, prayers[i].end)) {
        active[prayers[i].key] = true;
      }
    }
    if (Object.keys(active).length > 0) {
      return active;
    }
    for (i = 0; i < prayers.length; i += 1) {
      var start = toMinutes(prayers[i].start);
      if (start !== null && start > now) {
        active[prayers[i].key] = true;
        return active;
      }
    }
    if (prayers[0]) {
      active[prayers[0].key] = true;
    }
    return active;
  }

  function readCache() {
    try {
      var raw = localStorage.getItem(CACHE_KEY);
      if (!raw) {
        return null;
      }
      var data = JSON.parse(raw);
      if (!data || data.date !== todayKey() || data.city !== city || data.country !== country || data.latitude !== latitude || data.longitude !== longitude || String(data.method) !== String(method) || String(data.school) !== String(school) || !data.timings) {
        return null;
      }
      for (var i = 0; i < TIMING_KEYS.length; i += 1) {
        if (toMinutes(data.timings[TIMING_KEYS[i]]) === null) {
          return null;
        }
      }
      if (toMinutes(data.prevMaghrib) === null || toMinutes(data.nextFajr) === null) {
        return null;
      }
      return data;
    } catch (error) {
      return null;
    }
  }

  function writeCache(timings, prevMaghrib, nextFajr) {
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify({
        date: todayKey(),
        city: city,
        country: country,
        latitude: latitude,
        longitude: longitude,
        method: method,
        school: school,
        timings: timings,
        prevMaghrib: prevMaghrib,
        nextFajr: nextFajr
      }));
    } catch (error) {
      /* private mode / quota */
    }
  }

  function setStatus(text, isError) {
    if (!statusEl) {
      return;
    }
    statusEl.hidden = !text;
    statusEl.textContent = text || '';
    statusEl.classList.toggle('is-loading', Boolean(text) && !isError);
    statusEl.classList.toggle('is-error', Boolean(isError));
  }

  function isFriday() {
    var weekday = new Intl.DateTimeFormat('en-US', {
      timeZone: TIMEZONE,
      weekday: 'short'
    }).format(new Date());
    return weekday === 'Fri';
  }

  function prayerDisplayName(prayer) {
    if (prayer.key === 'Dhuhr' && isFriday()) {
      return 'জুমাহ';
    }
    return prayer.name;
  }

  function renderDate() {
    if (!dateEl) {
      return;
    }
    dateEl.dateTime = todayKey();
    dateEl.textContent = new Intl.DateTimeFormat('bn-BD', {
      timeZone: TIMEZONE,
      weekday: 'long',
      day: 'numeric',
      month: 'long'
    }).format(new Date());
  }

  function appendTimeRow(list, name, startRaw, endRaw, extraClass) {
    var item = document.createElement('li');
    item.className = 'school-prayer-item' + (extraClass ? ' ' + extraClass : '');

    var label = document.createElement('span');
    label.className = 'school-prayer-name';
    label.textContent = name;

    var start = document.createElement('span');
    start.className = 'school-prayer-time';
    start.textContent = formatTime(startRaw);

    var end = document.createElement('span');
    end.className = 'school-prayer-time';
    end.textContent = formatTime(endRaw);

    item.appendChild(label);
    item.appendChild(start);
    item.appendChild(end);
    list.appendChild(item);
  }

  function render(timings, prevMaghrib, nextFajr) {
    var schedule = buildSchedule(timings, { prevMaghrib: prevMaghrib, nextFajr: nextFajr });
    if (!schedule || !listEl) {
      setStatus('সময়সূচি লোড করা যায়নি। পরে আবার চেষ্টা করুন।', true);
      return;
    }
    var active = activeKeys(schedule.prayers);
    listEl.replaceChildren();
    for (var i = 0; i < schedule.prayers.length; i += 1) {
      var prayer = schedule.prayers[i];
      appendTimeRow(
        listEl,
        prayerDisplayName(prayer),
        prayer.start,
        prayer.end,
        active[prayer.key] ? 'is-next' : ''
      );
    }
    if (forbiddenListEl) {
      forbiddenListEl.replaceChildren();
      for (var j = 0; j < schedule.forbidden.length; j += 1) {
        var blocked = schedule.forbidden[j];
        appendTimeRow(forbiddenListEl, blocked.name, blocked.start, blocked.end, 'is-forbidden');
      }
    }
    if (boardEl) {
      boardEl.hidden = false;
    }
    setStatus('');
    renderDate();
  }

  function pickTimings(payload) {
    var source = payload && payload.data && payload.data.timings ? payload.data.timings : null;
    if (!source) {
      return null;
    }
    var timings = {};
    for (var i = 0; i < TIMING_KEYS.length; i += 1) {
      var key = TIMING_KEYS[i];
      var value = String(source[key] || '').trim();
      if (toMinutes(value) === null) {
        return null;
      }
      timings[key] = value;
    }
    return timings;
  }

  function fetchDay(isoDate) {
    var url = 'https://api.aladhan.com/v1/timings/' + toAladhanDate(isoDate) + '?' + new URLSearchParams({
      latitude: latitude,
      longitude: longitude,
      method: method,
      school: school
    }).toString();
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = 0;
    if (controller) {
      timer = window.setTimeout(function () {
        controller.abort();
      }, 8000);
    }

    return fetch(url, {
      method: 'GET',
      signal: controller ? controller.signal : undefined,
      headers: { Accept: 'application/json' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('prayer-http');
      }
      return response.json();
    }).then(function (payload) {
      var timings = pickTimings(payload);
      if (!timings) {
        throw new Error('prayer-data');
      }
      return timings;
    }).finally(function () {
      if (timer) {
        window.clearTimeout(timer);
      }
    });
  }

  function fetchSchedule() {
    var today = todayKey();
    return fetchDay(today).then(function (timings) {
      return Promise.all([
        fetchDay(addDays(today, -1)).catch(function () {
          return null;
        }),
        fetchDay(addDays(today, 1)).catch(function () {
          return null;
        })
      ]).then(function (neighbours) {
        var prevMaghrib = neighbours[0] ? neighbours[0].Maghrib : timings.Maghrib;
        var nextFajr = neighbours[1] ? neighbours[1].Fajr : timings.Fajr;
        writeCache(timings, prevMaghrib, nextFajr);
        render(timings, prevMaghrib, nextFajr);
      });
    }).catch(function () {
      if (!readCache()) {
        setStatus('সময়সূচি লোড করা যায়নি। পরে আবার চেষ্টা করুন।', true);
      }
    });
  }

  var cached = readCache();
  renderDate();
  if (cached) {
    render(cached.timings, cached.prevMaghrib, cached.nextFajr);
    return;
  }

  setStatus('Loading...');
  fetchSchedule();
})();
