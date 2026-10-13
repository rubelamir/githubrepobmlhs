(function () {
  var CACHE_NAME = 'bmlhs-national-anthem-v1';
  var META_KEY = 'bmlhs:nationalAnthem:v1';
  var TIMEZONE = 'Asia/Dhaka';
  var BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

  var root = document.getElementById('schoolNationalAnthem');
  if (!root) {
    return;
  }

  var src = (root.getAttribute('data-src') || '').trim();
  var playBtn = root.querySelector('[data-anthem-play]');
  var iconEl = root.querySelector('[data-anthem-icon]');
  var statusEl = root.querySelector('[data-anthem-status]');
  var timeEl = root.querySelector('[data-anthem-time]');
  var card = root.querySelector('.school-anthem-card');
  if (!src || !playBtn || !card) {
    return;
  }

  var audio = null;
  var objectUrl = '';
  var loading = false;
  var readySrc = '';
  var eventsBound = false;

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

  function formatClock(seconds) {
    if (!isFinite(seconds) || seconds < 0) {
      return '০:০০';
    }
    var total = Math.floor(seconds);
    var mins = Math.floor(total / 60);
    var secs = String(total % 60).padStart(2, '0');
    return toBanglaDigits(mins + ':' + secs);
  }

  function readMeta() {
    try {
      var data = JSON.parse(localStorage.getItem(META_KEY) || 'null');
      if (!data || data.date !== todayKey() || data.src !== src) {
        return null;
      }
      return data;
    } catch (error) {
      return null;
    }
  }

  function writeMeta() {
    try {
      localStorage.setItem(META_KEY, JSON.stringify({
        date: todayKey(),
        src: src,
        cached: true
      }));
    } catch (error) {
      /* private mode / quota */
    }
  }

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function setPlayingUi(playing) {
    playBtn.setAttribute('aria-pressed', playing ? 'true' : 'false');
    playBtn.setAttribute('aria-label', playing ? 'জাতীয় সংগীত থামান' : 'জাতীয় সংগীত চালান');
    if (iconEl) {
      iconEl.classList.toggle('fa-pause', playing);
      iconEl.classList.toggle('fa-play', !playing);
    }
    root.classList.toggle('is-playing', playing);
    setStatus(playing ? 'চালছে' : 'চালাতে আইকনে চাপুন');
  }

  function setTimeLabel() {
    if (!timeEl || !audio) {
      return;
    }
    timeEl.textContent = formatClock(audio.currentTime) + ' / ' + formatClock(audio.duration);
  }

  function absoluteSrc() {
    return new URL(src, window.location.href).href;
  }

  function revokeObjectUrl() {
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = '';
    }
  }

  function blobToUrl(blob) {
    revokeObjectUrl();
    objectUrl = URL.createObjectURL(blob);
    return objectUrl;
  }

  function bindAudioEvents() {
    if (!audio || eventsBound) {
      return;
    }
    eventsBound = true;
    audio.addEventListener('timeupdate', setTimeLabel);
    audio.addEventListener('loadedmetadata', setTimeLabel);
    audio.addEventListener('ended', function () {
      audio.currentTime = 0;
      setPlayingUi(false);
      setTimeLabel();
    });
    audio.addEventListener('pause', function () {
      if (!audio.ended) {
        setPlayingUi(false);
      }
    });
    audio.addEventListener('play', function () {
      setPlayingUi(true);
    });
  }

  function getAudio() {
    if (audio) {
      return audio;
    }
    audio = document.createElement('audio');
    audio.preload = 'none';
    audio.playsInline = true;
    audio.setAttribute('playsinline', '');
    audio.hidden = true;
    card.appendChild(audio);
    bindAudioEvents();
    return audio;
  }

  function getCachedResponse() {
    if (!('caches' in window)) {
      return Promise.resolve(null);
    }
    return caches.open(CACHE_NAME).then(function (cache) {
      return cache.match(absoluteSrc());
    }).catch(function () {
      return null;
    });
  }

  function putCachedResponse(response) {
    if (!('caches' in window)) {
      return Promise.resolve();
    }
    return caches.open(CACHE_NAME).then(function (cache) {
      return cache.put(absoluteSrc(), response);
    }).catch(function () {
      return undefined;
    });
  }

  function loadFromNetwork() {
    return fetch(absoluteSrc(), {
      method: 'GET',
      headers: { Accept: 'audio/mpeg' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('anthem-http');
      }
      return putCachedResponse(response.clone()).then(function () {
        writeMeta();
        return response.blob();
      });
    });
  }

  function ensureAudio() {
    var player = getAudio();
    if (readySrc && player.src) {
      return Promise.resolve();
    }
    return getCachedResponse().then(function (cached) {
      if (cached) {
        if (!readMeta()) {
          writeMeta();
        }
        return cached.blob();
      }
      return loadFromNetwork();
    }).then(function (blob) {
      player.src = blobToUrl(blob);
      readySrc = src;
    });
  }

  function playAnthem() {
    if (loading) {
      return;
    }
    if (audio && !audio.paused) {
      audio.pause();
      setPlayingUi(false);
      return;
    }
    loading = true;
    playBtn.disabled = true;
    setStatus('Loading...');
    ensureAudio().then(function () {
      return getAudio().play();
    }).then(function () {
      setPlayingUi(true);
    }).catch(function () {
      setPlayingUi(false);
      setStatus('সংগীত চালানো যায়নি। আবার চেষ্টা করুন।');
    }).finally(function () {
      loading = false;
      playBtn.disabled = false;
    });
  }

  playBtn.addEventListener('click', playAnthem);
  window.addEventListener('pagehide', revokeObjectUrl);
})();
