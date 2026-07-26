/**
 * Плеер.
 *
 * Отличия от v1:
 *  - метаданные приходят вместе с треком, клиент больше не докачивает mp3
 *    ради ID3-тегов (в v1 этим занимался id3.js);
 *  - следующий трек известен заранее: метаданные лежат наготове, поэтому
 *    нажатие RANDOM не ждёт обращения к серверу;
 *  - громкость выравнивается по замеренной EBU R128 — в случайной подборке
 *    соседние треки отличаются на десяток децибел;
 *  - все URL относительные: переезд на другой домен не требует правки кода.
 *
 * Почему аудио НЕ загружается заранее.
 *
 * Сначала следующий трек грузился в скрытый элемент — в расчёте на кэш
 * браузера. Логи показали два ответа 206 на один файл одному клиенту,
 * нередко оба полного размера: медиаэлементы ходят диапазонными запросами
 * и переиспользуют дисковый кэш ненадёжно.
 *
 * Тогда сделали две деки, меняющиеся ролями, чтобы играл ровно тот элемент,
 * который скачал файл. Логи снова показали дубли:
 *
 *     14:52:44  148.mp3  8648565   загрузка во вторую деку
 *     14:54:11  148.mp3  8517493   тот же файл заново, когда заиграл
 *
 * Причина глубже реализации: браузер выбрасывает буфер простаивающего
 * элемента и качает файл повторно, когда доходит до воспроизведения.
 * Предзагружать аудио попросту нечем — сохранить скачанное нельзя.
 *
 * Поэтому заранее запрашиваются только метаданные следующего трека
 * (пара сотен байт). Нажатие RANDOM не ждёт сервер, название появляется
 * мгновенно, а сам файл скачивается один раз — когда его слушают.
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

    this.audio = root.querySelector('#audio');

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
    this.source = null;

    this.#restoreVolume();
    this.#bind();

    if (initial) {
      this.#activate(initial, { autoplay: false, pushHistory: true });
      this.#prefetch();
    } else {
      this.random();
    }
  }

  // --- Публичное ---------------------------------------------------------

  async random() {
    // Метаданные следующего трека уже лежат наготове — ждать сервер не нужно
    if (this.next) {
      const t = this.next;
      this.next = null;
      this.#activate(t, { autoplay: true, pushHistory: true });
      this.#prefetch();
      return;
    }

    this.root.classList.add('is-loading');
    try {
      const track = await this.#requestTrack();
      if (!track) throw new Error('нет трека');
      this.#activate(track, { autoplay: true, pushHistory: true });
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
      this.#activate(this.history[this.pos], { autoplay: true, pushHistory: false });
    } else {
      this.random();
    }
  }

  playAt(index) {
    if (index < 0 || index >= this.history.length) return;
    this.pos = index;
    this.#activate(this.history[index], { autoplay: true, pushHistory: false });
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

    this.audio.addEventListener('ended', () => {
      this.#report('played');
      this.random();
    });

    this.audio.addEventListener('play', () => {
      this.#resumeContext();
      this.#renderPlayState();
    });

    this.audio.addEventListener('pause', () => this.#renderPlayState());
    this.audio.addEventListener('timeupdate', () => this.#renderProgress());
    this.audio.addEventListener('progress', () => this.#renderBuffer());

    this.audio.addEventListener('loadedmetadata', () => {
      this.elDur.textContent = fmtTime(this.audio.duration);
      this.#syncPositionState();
    });

    this.audio.addEventListener('error', () => {
      // Битый или удалённый файл не должен останавливать эфир
      if (!this.audio.getAttribute('src')) return;
      this.#status('Трек не открылся, беру следующий');
      setTimeout(() => this.random(), 600);
    });

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

  #activate(track, { autoplay, pushHistory }) {
    if (!track || !track.url) return;

    this.#report('skipped');

    // Если элемент уже указывает на этот файл, не трогаем src: повторное
    // присваивание обрывает текущую загрузку и начинает её заново. Именно
    // так на старте страницы первый трек скачивался дважды — адрес стоял
    // в разметке, и конструктор присваивал его повторно.
    if (abs(this.audio.getAttribute('src') || '') !== abs(track.url)) {
      // Присваивание нового адреса само обрывает загрузку предыдущего
      // трека — незачем тянуть байты того, что уже не слушают
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
   * Заранее узнаём следующий трек — только метаданные, без файла.
   * Благодаря этому нажатие RANDOM не ждёт обращения к серверу.
   */
  async #prefetch() {
    try {
      this.next = await this.#requestTrack();
    } catch {
      this.next = null;
    }
  }

  // --- Звук ---------------------------------------------------------------

  #setVolume(v) {
    this.audio.volume = v;
    this.audio.muted = v === 0;
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
   * без звука. Источник для элемента создаётся один раз — повторно
   * его создать нельзя.
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
      if (!this.source) {
        this.source = this.ctx.createMediaElementSource(this.audio);
        this.source.connect(this.gain);
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
