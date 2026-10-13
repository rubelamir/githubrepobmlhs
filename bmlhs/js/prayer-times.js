(function () {
  var CITY = 'Lakshmipur';
  var COUNTRY = 'Bangladesh';
  var LATITUDE = '22.9447';
  var LONGITUDE = '90.8300';
  var METHOD = '1';
  var SCHOOL = '1';
  var CACHE_KEY = 'bmlhs:prayerTimes:v5';
  var TIMEZONE = 'Asia/Dhaka';
  var PRAYERS = [
    { key: 'Fajr', name: 'ফজর', start: 'Fajr', end: 'Sunrise' },
    { key: 'Dhuhr', name: 'যোহর', start: 'Dhuhr', end: 'Asr' },
    { key: 'Asr', name: 'আসর', start: 'Asr', end: 'SunsetForbid' },
    { key: 'Maghrib', name: 'মাগরিব', start: 'Maghrib', end: 'Isha' },
    { key: 'Isha', name: 'এশা', start: 'Isha', end: 'Midnight' }
  ];
  var TIMING_KEYS = ['Fajr', 'Sunrise', 'Dhuhr', 'Asr', 'Maghrib', 'Isha', 'Midnight'];
  var SUNRISE_FORBID_MIN = 20;
  var SUNSET_FORBID_MIN = 20;
  var BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

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

  function toBanglaDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return BN_DIGITS[digit];
    });
  }

  function parseHm(raw) {
    var match = String(raw || '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) {
      return null;
    }
    return { hour: parseInt(match[1], 10), minute: parseInt(match[2], 10) };
  }

  function shiftTime(raw, minutes) {
    var parts = parseHm(raw);
    if (!parts) {
      return '';
    }
    var total = ((parts.hour * 60 + parts.minute + minutes) % 1440 + 1440) % 1440;
    var hour = Math.floor(total / 60);
    var minute = total % 60;
    return hour + ':' + String(minute).padStart(2, '0');
  }

  function withWindows(timings) {
    var sunsetForbid = shiftTime(timings.Maghrib, -SUNSET_FORBID_MIN);
    var sunriseForbidEnd = shiftTime(timings.Sunrise, SUNRISE_FORBID_MIN);
    return {
      Fajr: timings.Fajr,
      Sunrise: timings.Sunrise,
      Dhuhr: timings.Dhuhr,
      Asr: timings.Asr,
      Maghrib: timings.Maghrib,
      Isha: timings.Isha,
      Midnight: timings.Midnight,
      SunsetForbid: sunsetForbid,
      SunriseForbidEnd: sunriseForbidEnd
    };
  }

  function formatTime(raw) {
    var parts = parseHm(raw);
    if (!parts) {
      return '—';
    }
    var hour12 = parts.hour % 12;
    if (hour12 === 0) {
      hour12 = 12;
    }
    var minute = String(parts.minute).padStart(2, '0');
    var ampm = parts.hour < 12 ? 'AM' : 'PM';
    return toBanglaDigits(hour12 + ':' + minute) + ' ' + ampm;
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

  function toMinutes(raw) {
    var parts = parseHm(raw);
    if (!parts) {
      return null;
    }
    return parts.hour * 60 + parts.minute;
  }

  function isTimeInWindow(now, startRaw, endRaw) {
    var start = toMinutes(startRaw);
    var end = toMinutes(endRaw);
    if (start === null || end === null) {
      return false;
    }
    if (start === end) {
      return false;
    }
    if (start < end) {
      return now >= start && now < end;
    }
    return now >= start || now < end;
  }

  function selectedPrayerKey(windows) {
    var now = dhakaMinutesNow();
    var i;
    for (i = 0; i < PRAYERS.length; i += 1) {
      var current = PRAYERS[i];
      if (isTimeInWindow(now, windows[current.start], windows[current.end])) {
        return current.key;
      }
    }
    for (i = 0; i < PRAYERS.length; i += 1) {
      var upcoming = PRAYERS[i];
      var start = toMinutes(windows[upcoming.start]);
      if (start !== null && start > now) {
        return upcoming.key;
      }
    }
    return PRAYERS[0].key;
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
        if (!parseHm(data.timings[TIMING_KEYS[i]])) {
          return null;
        }
      }
      return data;
    } catch (error) {
      return null;
    }
  }

  function writeCache(timings) {
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify({
        date: todayKey(),
        city: city,
        country: country,
        latitude: latitude,
        longitude: longitude,
        method: method,
        school: school,
        timings: timings
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

  function render(timings) {
    if (!listEl) {
      return;
    }
    var windows = withWindows(timings);
    var selected = selectedPrayerKey(windows);
    listEl.replaceChildren();
    for (var i = 0; i < PRAYERS.length; i += 1) {
      var prayer = PRAYERS[i];
      var item = document.createElement('li');
      item.className = 'school-prayer-item' + (prayer.key === selected ? ' is-next' : '');

      var name = document.createElement('span');
      name.className = 'school-prayer-name';
      name.textContent = prayerDisplayName(prayer);

      var start = document.createElement('span');
      start.className = 'school-prayer-time';
      start.textContent = formatTime(windows[prayer.start]);

      var end = document.createElement('span');
      end.className = 'school-prayer-time';
      end.textContent = formatTime(windows[prayer.end]);

      item.appendChild(name);
      item.appendChild(start);
      item.appendChild(end);
      listEl.appendChild(item);
    }
    renderForbidden(windows);
    if (boardEl) {
      boardEl.hidden = false;
    }
    setStatus('');
    renderDate();
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

  function renderForbidden(timings) {
    if (!forbiddenListEl) {
      return;
    }
    forbiddenListEl.replaceChildren();
    appendTimeRow(forbiddenListEl, 'সূর্যোদয়', timings.Sunrise, timings.SunriseForbidEnd, 'is-forbidden');
    appendTimeRow(forbiddenListEl, 'সূর্যাস্ত', timings.SunsetForbid, timings.Maghrib, 'is-forbidden');
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
      if (!parseHm(value)) {
        return null;
      }
      timings[key] = value;
    }
    return timings;
  }

  function fetchToday() {
    var url = 'https://api.aladhan.com/v1/timings?' + new URLSearchParams({
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
      writeCache(timings);
      render(timings);
    }).catch(function () {
      if (!readCache()) {
        setStatus('সময়সূচি লোড করা যায়নি। পরে আবার চেষ্টা করুন।', true);
      }
    }).finally(function () {
      if (timer) {
        window.clearTimeout(timer);
      }
    });
  }

  var cached = readCache();
  renderDate();
  if (cached) {
    render(cached.timings);
    return;
  }

  setStatus('Loading...');
  fetchToday();
})();
