/**
 * Плеер.
 *
 * Отличия от v1:
 *  - метаданные приходят вместе с треком, клиент больше не докачивает mp3
 *    ради ID3-тегов (в v1 этим занимался id3.js);
 *  - следующий трек загружается заранее, переключение без паузы;
 *  - громкость выравнивается по замеренной EBU R128 — в случайной подборке
 *    соседние треки отличаются на десяток децибел;
 *  - все URL относительные: переезд на другой домен не требует правки кода.
 *
 * Предзагрузка сделана двумя деками, а не «прогревом кэша».
 *
 * Первая версия v2 грузила следующий трек в скрытый элемент, надеясь, что
 * при переключении основной возьмёт файл из кэша браузера. Не берёт:
 * медиаэлементы ходят диапазонными запросами и переиспользуют дисковый кэш
 * ненадёжно. В логах сервера это выглядело как два ответа 206 на один файл
 * одному клиенту, нередко оба — полный размер. Трафик сайта удваивался
 * на каждом треке.
 *
 * Теперь элементов два и они меняются ролями: тот, что уже скачал трек,
 * сам становится играющим. Файл загружается ровно один раз.
 */

const LS = {
  volume: 'rm.volume',
};

/** Целевая громкость, LUFS. Компромисс между тишиной и перегрузом. */
const TARGET_LUFS = -14;

const fmtTime = (s) => {
  if (!Number.isFinite(s) || s < 0) s = 0;
  const m = Math.floor(s / 60);
  const r = Math.floor(s % 60);
  return `${m}:${String(r).padStart(2, '0')}`;
};

/** Адреса из разметки абсолютные, из API — относительные. Приводим к одному виду. */
const abs = (url) => {
  try {
    return new URL(url, location.href).href;
  } catch {
    return url;
  }
};

export class Player {
  constructor(root, { initial = null, onTrack = null, base = '' } = {}) {
    this.root = root;
    this.onTrack = onTrack;
    // Приложение может быть смонтировано в подкаталоге (обкатка на /v2),
    // поэтому все адреса API строятся от базы, а не от текущего пути
    this.base = base;

    // Две деки: играющая и та, что загружает следующий трек
    this.decks = [root.querySelector('#audio'), root.querySelector('#audio-b')].filter(Boolean);
    this.deck = 0;

    this.elTitle = root.querySelector('#np-title');
    this.elArtist = root.querySelector('#np-artist');
    this.elMeta = root.querySelector('#np-meta');
    this.elCur = root.querySelector('#time-current');
    this.elDur = root.querySelector('#time-total');

    this.btnPlay = root.querySelector('#btn-play');
    this.btnPrev = root.querySelector('#btn-prev');
    this.btnRandom = root.querySelector('#btn-random');
    this.volInput = root.querySelector('#volume');

    this.progress = root.querySelector('#progress');
    this.buffer = root.querySelector('#progress-buffer');

    this.historyList = document.querySelector('#history-list');
    this.shareLink = document.querySelector('#share-link');

    this.current = null;
    this.next = null;
    this.history = [];
    this.pos = -1;
    this.recent = [];
    this.reported = false;

    this.ctx = null;
    this.gain = null;
    this.sources = new WeakMap();

    this.#restoreVolume();
    this.#bind();

    if (initial) {
      this.#activate(initial, { autoplay: false, pushHistory: true, swap: false });
      this.#prefetch();
    } else {
      this.random();
    }
  }

  /** Играющая дека. */
  get audio() {
    return this.decks[this.deck];
  }

  /** Дека, в которую загружается следующий трек. */
  get standby() {
    return this.decks.length > 1 ? this.decks[1 - this.deck] : null;
  }

  // --- Публичное ---------------------------------------------------------

  async random() {
    // Трек уже скачан второй декой — просто меняем их ролями.
    // Ни одного нового запроса к серверу за файлом.
    if (this.next && this.#standbyHolds(this.next)) {
      const t = this.next;
      this.next = null;
      this.#activate(t, { autoplay: true, pushHistory: true, swap: true });
      this.#prefetch();
      return;
    }

    this.root.classList.add('is-loading');
    try {
      const track = this.next || await this.#requestTrack();
      this.next = null;
      if (!track) throw new Error('нет трека');
      this.#activate(track, { autoplay: true, pushHistory: true, swap: false });
      this.#prefetch();
    } catch (err) {
      this.#status('Не удалось получить трек. Попробуйте ещё раз.');
      console.error('[player]', err);
    } finally {
      this.root.classList.remove('is-loading');
    }
  }

  /** Назад по истории прослушанного в этой сессии. */
  previous() {
    if (this.pos > 0) {
      this.pos -= 1;
      this.#activate(this.history[this.pos], { autoplay: true, pushHistory: false, swap: false });
    } else {
      this.random();
    }
  }

  playAt(index) {
    if (index < 0 || index >= this.history.length) return;
    this.pos = index;
    this.#activate(this.history[index], { autoplay: true, pushHistory: false, swap: false });
  }

  toggle() {
    if (this.audio.paused) {
      this.#resumeContext();
      this.audio.play().catch(() => {});
    } else {
      this.audio.pause();
    }
  }

  get trackId() {
    return this.current ? this.current.id : null;
  }

  // --- Внутреннее --------------------------------------------------------

  #bind() {
    // Через optional chaining: разметка и скрипт могут разъехаться
    // по версиям в кэше, и это не повод ронять всю страницу
    this.btnRandom?.addEventListener('click', () => this.random());
    this.btnPrev?.addEventListener('click', () => this.previous());
    this.btnPlay?.addEventListener('click', () => this.toggle());

    // Слушаем обе деки, но реагируем только на события играющей:
    // вторая в это время молча качает следующий трек
    for (const el of this.decks) {
      const mine = (e) => e.target === this.audio;

      el.addEventListener('ended', (e) => {
        if (!mine(e)) return;
        this.#report('played');
        this.random();
      });

      el.addEventListener('play', (e) => {
        if (!mine(e)) return;
        this.#resumeContext();
        this.#renderPlayState();
      });

      el.addEventListener('pause', (e) => mine(e) && this.#renderPlayState());
      el.addEventListener('timeupdate', (e) => mine(e) && this.#renderProgress());
      el.addEventListener('progress', (e) => mine(e) && this.#renderBuffer());

      el.addEventListener('loadedmetadata', (e) => {
        if (!mine(e)) return;
        this.elDur.textContent = fmtTime(this.audio.duration);
        this.#syncPositionState();
      });

      el.addEventListener('error', (e) => {
        // Сбой второй деки не должен останавливать эфир: просто
        // забываем прогретый трек и возьмём следующий обычным путём
        if (!mine(e)) {
          this.next = null;
          return;
        }
        this.#status('Трек не открылся, беру следующий');
        setTimeout(() => this.random(), 600);
      });
    }

    this.volInput?.addEventListener('input', () => {
      const v = Number(this.volInput.value) / 100;
      this.#setVolume(v);
      try {
        localStorage.setItem(LS.volume, String(v));
      } catch {}
    });

    this.#bindScrub();
  }

  #bindScrub() {
    if (!this.progress) return;

    const seekTo = (clientX) => {
      const rect = this.progress.getBoundingClientRect();
      const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
      if (Number.isFinite(this.audio.duration)) {
        this.audio.currentTime = ratio * this.audio.duration;
        this.#renderProgress();
      }
    };

    let scrubbing = false;

    this.progress.addEventListener('pointerdown', (e) => {
      scrubbing = true;
      this.progress.classList.add('is-scrubbing');
      this.progress.setPointerCapture(e.pointerId);
      seekTo(e.clientX);
    });

    this.progress.addEventListener('pointermove', (e) => {
      if (scrubbing) seekTo(e.clientX);
    });

    const stop = (e) => {
      if (!scrubbing) return;
      scrubbing = false;
      this.progress.classList.remove('is-scrubbing');
      try {
        this.progress.releasePointerCapture(e.pointerId);
      } catch {}
    };

    this.progress.addEventListener('pointerup', stop);
    this.progress.addEventListener('pointercancel', stop);

    // Перемотка с клавиатуры — полоса фокусируема
    this.progress.addEventListener('keydown', (e) => {
      if (!Number.isFinite(this.audio.duration)) return;
      const step = e.shiftKey ? 30 : 5;
      if (e.key === 'ArrowRight') {
        this.audio.currentTime = Math.min(this.audio.duration, this.audio.currentTime + step);
        e.preventDefault();
      } else if (e.key === 'ArrowLeft') {
        this.audio.currentTime = Math.max(0, this.audio.currentTime - step);
        e.preventDefault();
      }
    });
  }

  /** Держит ли вторая дека именно этот трек. */
  #standbyHolds(track) {
    const s = this.standby;
    return !!s && !!s.getAttribute('src') && abs(s.src) === abs(track.url);
  }

  /**
   * @param {boolean} swap трек уже загружен второй декой — меняем их ролями
   *                       вместо повторной загрузки того же файла
   */
  #activate(track, { autoplay, pushHistory, swap }) {
    if (!track || !track.url) return;

    this.#report('skipped');

    if (swap && this.standby) {
      const leaving = this.audio;
      leaving.pause();
      this.deck = 1 - this.deck;

      // Освобождаем прежнюю деку: иначе браузер держит буфер каждого
      // прослушанного трека до конца сессии
      leaving.removeAttribute('src');
      leaving.load();
    } else {
      this.audio.src = track.url;
      this.audio.load();
    }

    this.current = track;
    this.reported = false;

    this.elTitle.textContent = track.title || 'Без названия';
    this.elArtist.textContent = track.artist || '';
    this.elMeta.textContent = [
      track.album || null,
      track.year || null,
      track.bitrate ? `${track.bitrate} kbps` : null,
    ].filter(Boolean).join(' · ');

    this.elDur.textContent = fmtTime(track.duration || 0);
    this.elCur.textContent = '0:00';
    this.#setProgress(0);
    if (this.buffer) this.buffer.style.width = '0%';

    document.title = track.artist
      ? `${track.artist} — ${track.title} · Random music`
      : `${track.title} · Random music`;

    if (this.shareLink) this.shareLink.href = `${this.base}/t/${track.id}`;

    this.#applyGain(track.loudness);
    this.#mediaSession(track);

    this.recent.push(track.id);
    if (this.recent.length > 30) this.recent.shift();

    if (pushHistory) {
      this.history.push(track);
      if (this.history.length > 60) this.history.shift();
      this.pos = this.history.length - 1;
    }
    this.#renderHistory();

    if (this.onTrack) this.onTrack(track);

    if (autoplay) {
      this.#resumeContext();
      this.audio.play().catch(() => {
        // Браузер заблокировал автозапуск — ждём нажатия
        this.#renderPlayState();
      });
    }
    this.#renderPlayState();
  }

  async #requestTrack() {
    const params = new URLSearchParams();
    if (this.recent.length) params.set('exclude', this.recent.join(','));

    const res = await fetch(`${this.base}/api/v1/track/random?${params}`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    return res.json();
  }

  /**
   * Заранее загружаем следующий трек во вторую деку. Именно она потом
   * станет играющей, поэтому файл скачивается один раз, а не дважды.
   */
  async #prefetch() {
    const s = this.standby;
    if (!s) return;

    try {
      const track = await this.#requestTrack();
      this.next = track;
      s.src = track.url;
      s.load();
    } catch {
      this.next = null;
    }
  }

  // --- Звук ---------------------------------------------------------------

  #setVolume(v) {
    for (const el of this.decks) {
      el.volume = v;
      el.muted = v === 0;
    }
  }

  /**
   * Выравнивание громкости. Пока замера нет (фоновое задание считает
   * постепенно), громкость трека остаётся как есть.
   */
  #applyGain(loudness) {
    if (typeof loudness !== 'number' || !Number.isFinite(loudness)) {
      if (this.gain) this.gain.gain.value = 1;
      return;
    }

    if (!this.#ensureGraph()) return;

    this.gain.gain.value = Math.min(2, Math.max(0.25, Math.pow(10, (TARGET_LUFS - loudness) / 20)));
  }

  /**
   * Граф собираем только когда он действительно нужен: подключение
   * элемента к WebAudio до жеста пользователя может оставить вкладку
   * без звука. Источник создаётся по одному на деку — повторно для того
   * же элемента его создать нельзя.
   */
  #ensureGraph() {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return false;

    try {
      if (!this.ctx) {
        this.ctx = new AC();
        this.gain = this.ctx.createGain();
        this.gain.connect(this.ctx.destination);
      }
      const el = this.audio;
      if (!this.sources.has(el)) {
        const src = this.ctx.createMediaElementSource(el);
        src.connect(this.gain);
        this.sources.set(el, src);
      }
      return true;
    } catch {
      this.ctx = null;
      this.gain = null;
      return false;
    }
  }

  #resumeContext() {
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume().catch(() => {});
  }

  // --- Системные контролы --------------------------------------------------

  #mediaSession(track) {
    if (!('mediaSession' in navigator)) return;

    const origin = location.origin;
    navigator.mediaSession.metadata = new MediaMetadata({
      title: track.title || 'Без названия',
      artist: track.artist || 'Random music',
      album: track.album || 'random',
      artwork: [96, 128, 192, 256, 384, 512].map((s) => ({
        src: `${origin}${this.base}/assets/img/cover.jpg`,
        sizes: `${s}x${s}`,
        type: 'image/jpeg',
      })),
    });

    const set = (action, handler) => {
      try {
        navigator.mediaSession.setActionHandler(action, handler);
      } catch {}
    };

    set('play', () => this.toggle());
    set('pause', () => this.toggle());
    set('nexttrack', () => this.random());
    set('previoustrack', () => this.previous());
    set('seekbackward', (d) => {
      this.audio.currentTime = Math.max(0, this.audio.currentTime - (d.seekOffset || 10));
    });
    set('seekforward', (d) => {
      this.audio.currentTime = Math.min(this.audio.duration || 0, this.audio.currentTime + (d.seekOffset || 10));
    });
    set('seekto', (d) => {
      if (typeof d.seekTime === 'number') this.audio.currentTime = d.seekTime;
    });
  }

  #syncPositionState() {
    if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState) return;
    if (!Number.isFinite(this.audio.duration)) return;
    try {
      navigator.mediaSession.setPositionState({
        duration: this.audio.duration,
        playbackRate: this.audio.playbackRate,
        position: Math.min(this.audio.currentTime, this.audio.duration),
      });
    } catch {}
  }

  // --- Статистика ----------------------------------------------------------

  /** По ней потом пересчитываются веса ротации. */
  #report(event) {
    if (!this.current || this.reported) return;
    const listened = this.audio.currentTime || 0;
    if (listened < 1) return;

    this.reported = true;
    const id = this.current.id;
    const body = new URLSearchParams({ event, listened: listened.toFixed(1) });

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(`${this.base}/api/v1/track/${id}/event`, body);
      } else {
        fetch(`${this.base}/api/v1/track/${id}/event`, { method: 'POST', body, keepalive: true });
      }
    } catch {}
  }

  // --- Отрисовка -----------------------------------------------------------

  #renderPlayState() {
    const playing = !this.audio.paused && !this.audio.ended;
    if (!this.btnPlay) return;
    this.btnPlay.setAttribute('aria-label', playing ? 'Пауза' : 'Слушать');
    this.btnPlay.dataset.state = playing ? 'playing' : 'paused';
    if (this.btnPrev) this.btnPrev.disabled = this.pos <= 0 && this.history.length <= 1;
  }

  #renderProgress() {
    const d = this.audio.duration;
    if (Number.isFinite(d) && d > 0) {
      this.#setProgress((this.audio.currentTime / d) * 100);
    }
    this.elCur.textContent = fmtTime(this.audio.currentTime);
  }

  #setProgress(percent) {
    this.progress.style.setProperty('--played', `${Math.min(100, Math.max(0, percent))}%`);
    this.progress.setAttribute('aria-valuenow', String(Math.round(percent)));
  }

  #renderBuffer() {
    if (!this.buffer || !this.audio.buffered.length || !Number.isFinite(this.audio.duration)) return;
    const end = this.audio.buffered.end(this.audio.buffered.length - 1);
    this.buffer.style.width = `${Math.min(100, (end / this.audio.duration) * 100)}%`;
  }

  #renderHistory() {
    if (!this.historyList) return;

    this.historyList.textContent = '';
    this.history.forEach((t, i) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'history__item';
      if (i === this.pos) btn.setAttribute('aria-current', 'true');

      const num = document.createElement('span');
      num.className = 'history__num';
      num.textContent = String(i + 1);

      const label = document.createElement('span');
      label.textContent = t.artist ? `${t.artist} — ${t.title}` : t.title;

      btn.append(num, label);
      btn.addEventListener('click', () => this.playAt(i));
      li.append(btn);
      this.historyList.append(li);
    });
  }

  #restoreVolume() {
    let v = 0.5;
    try {
      const saved = localStorage.getItem(LS.volume);
      if (saved !== null) v = Math.min(1, Math.max(0, Number(saved)));
    } catch {}
    this.#setVolume(v);
    if (this.volInput) this.volInput.value = String(Math.round(v * 100));
  }

  #status(text) {
    const el = document.querySelector('#player-status');
    if (el) el.textContent = text;
  }
}
