/**
 * Чат.
 *
 * Главная задача этого модуля — перестать быть 94% всего трафика сайта.
 * В v1 каждая вкладка безусловно дёргала messages.json раз в две секунды,
 * даже свёрнутая и даже когда в чате неделю тишина: 1.22 млн запросов
 * за 25 дней.
 *
 * Здесь три меры:
 *   1. вкладка не на экране — опрос полностью останавливается;
 *   2. в чате тихо — интервал растёт 2 → 5 → 15 → 30 с, любое событие
 *      возвращает его к двум секундам;
 *   3. несколько вкладок одного посетителя опрашивают сервер один раз:
 *      лидер выбирается через BroadcastChannel и раздаёт остальным.
 */

const LS = { name: 'rm.name' };

const INTERVALS = [2000, 5000, 15000, 30000];
const QUIET_STEPS = [0, 5, 15, 40]; // сколько пустых опросов до перехода на следующий интервал

const ERRORS = {
  empty: 'Имя и сообщение не должны быть пустыми',
  too_long: 'Слишком длинное сообщение',
  name_too_long: 'Слишком длинное имя',
  rate_limited: 'Слишком часто. Подождите немного',
  banned: 'Отправка сообщений недоступна',
  bad_token: 'Форма устарела, обновите страницу',
  too_fast: 'Не так быстро',
  bot: 'Не похоже на человека',
  spam: 'Сообщение выглядит как спам',
  internal: 'Ошибка сервера, попробуйте позже',
};

/** Устойчивый цвет ника: стена одинаково серого текста читается плохо. */
function nameColor(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
  return `hsl(${h % 360} 45% 38%)`;
}

function formatTime(unix) {
  const d = new Date(unix * 1000);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/** Ссылки кликабельны, но текст всегда вставляется как текст. */
function renderBody(el, text) {
  el.textContent = '';
  const re = /(https?:\/\/[^\s<>"']+)/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) el.append(document.createTextNode(text.slice(last, m.index)));
    const a = document.createElement('a');
    a.href = m[0];
    a.textContent = m[0];
    a.target = '_blank';
    a.rel = 'noopener noreferrer nofollow ugc';
    el.append(a);
    last = m.index + m[0].length;
  }
  if (last < text.length) el.append(document.createTextNode(text.slice(last)));
}

export class Chat {
  constructor(root, { lastId = 0, token = '', online = 0, base = '', getTrackId = () => null } = {}) {
    this.root = root;
    this.base = base;
    this.log = root.querySelector('#chat-log');
    this.form = root.querySelector('#chat-form');
    this.inputContent = root.querySelector('#chat-content');
    this.inputName = root.querySelector('#chat-name');
    this.inputToken = root.querySelector('#chat-token');
    this.status = root.querySelector('#chat-status');
    this.onlineEl = root.querySelector('#chat-online');
    this.moreBtn = root.querySelector('#chat-more');

    this.lastId = lastId;
    this.token = token;
    this.getTrackId = getTrackId;
    this.etag = null;
    this.quiet = 0;
    this.timer = null;
    this.stopped = false;
    this.seen = new Set();
    this.oldestId = null;

    this.#collectExisting();
    this.#restoreName();
    this.#renderOnline(online);
    this.#bind();
    this.#initChannel();
    this.#schedule(0);
  }

  // --- Опрос -------------------------------------------------------------

  get interval() {
    let i = 0;
    while (i + 1 < INTERVALS.length && this.quiet >= QUIET_STEPS[i + 1]) i++;
    return INTERVALS[i];
  }

  #schedule(delay = this.interval) {
    clearTimeout(this.timer);
    if (this.stopped || document.hidden || !this.isLeader) return;
    this.timer = setTimeout(() => this.poll(), delay);
  }

  async poll() {
    if (this.stopped || document.hidden || !this.isLeader) return;

    try {
      const headers = { Accept: 'application/json' };
      if (this.etag) headers['If-None-Match'] = this.etag;

      const res = await fetch(`${this.base}/api/v1/chat?since=${this.lastId}`, { headers });

      if (res.status === 304) {
        this.quiet++;
      } else if (res.ok) {
        const tag = res.headers.get('ETag');
        if (tag) this.etag = tag;

        const data = await res.json();
        const fresh = this.append(data.messages || []);
        this.#renderOnline(data.online);

        if (fresh > 0) {
          this.quiet = 0;
          this.#broadcast({ type: 'messages', messages: data.messages, online: data.online });
        } else {
          this.quiet++;
        }
      } else {
        this.quiet++;
      }
    } catch {
      // Сеть отвалилась — не долбим сервер, ждём дольше
      this.quiet = Math.max(this.quiet, QUIET_STEPS[2]);
    }

    this.#schedule();
  }

  /** Разбудить опрос: пришло своё сообщение или вкладка вернулась на экран. */
  wake() {
    this.quiet = 0;
    this.#schedule(0);
  }

  // --- Отрисовка ---------------------------------------------------------

  /** @returns {number} сколько сообщений реально добавилось */
  append(messages) {
    if (!messages || !messages.length) return 0;

    const atBottom = this.#isAtBottom();
    let added = 0;

    for (const m of messages) {
      if (this.seen.has(m.id)) continue;
      this.seen.add(m.id);
      // Пришло подтверждение своего сообщения — убираем заглушку,
      // иначе оно висело бы в ленте дважды до истечения таймаута
      this.#dropPending(m.id);
      this.log.append(this.#node(m));
      if (m.id > this.lastId) this.lastId = m.id;
      if (this.oldestId === null || m.id < this.oldestId) this.oldestId = m.id;
      added++;
    }

    if (!added) return 0;

    // Ленту не режем до 50, как в v1: браузер спокойно держит тысячи узлов,
    // а прокрутка вверх — единственный способ прочитать пропущенное
    const extra = this.log.children.length - 400;
    for (let i = 0; i < extra; i++) this.log.firstElementChild?.remove();

    if (atBottom) this.scrollToBottom();
    return added;
  }

  #node(m) {
    const li = document.createElement('li');
    li.className = 'msg';
    li.dataset.id = String(m.id);

    const head = document.createElement('div');
    head.className = 'msg__head';

    const name = document.createElement('span');
    name.className = 'msg__name';
    name.textContent = m.name;
    name.style.setProperty('--msg-color', nameColor(m.name));

    const time = document.createElement('time');
    time.className = 'msg__time';
    time.dateTime = new Date(m.time * 1000).toISOString();
    time.textContent = formatTime(m.time);

    head.append(name, time);

    if (m.source && m.source !== 'web' && m.source !== 'v1' && m.source !== 'web-legacy') {
      const src = document.createElement('span');
      src.className = 'msg__src';
      src.textContent = m.source;
      head.append(src);
    }

    const body = document.createElement('div');
    body.className = 'msg__body';
    renderBody(body, m.content);

    li.append(head, body);
    return li;
  }

  #collectExisting() {
    for (const li of this.log.querySelectorAll('.msg[data-id]')) {
      const id = Number(li.dataset.id);
      this.seen.add(id);
      if (this.oldestId === null || id < this.oldestId) this.oldestId = id;
      const name = li.querySelector('.msg__name');
      if (name) name.style.setProperty('--msg-color', nameColor(name.textContent || ''));
      const body = li.querySelector('.msg__body');
      if (body) renderBody(body, body.textContent || '');
    }
    this.scrollToBottom();
  }

  #isAtBottom() {
    return this.log.scrollHeight - this.log.scrollTop - this.log.clientHeight < 60;
  }

  scrollToBottom() {
    this.log.scrollTop = this.log.scrollHeight;
  }

  #renderOnline(n) {
    if (this.onlineEl && typeof n === 'number') {
      this.onlineEl.textContent = String(n);
      this.onlineEl.closest('[hidden]')?.removeAttribute('hidden');
    }
  }

  // --- Отправка ----------------------------------------------------------

  #bind() {
    this.form.addEventListener('submit', (e) => {
      e.preventDefault();
      this.send();
    });

    this.inputName.addEventListener('change', () => {
      try {
        localStorage.setItem(LS.name, this.inputName.value.trim());
      } catch {}
    });

    if (this.moreBtn) {
      this.moreBtn.addEventListener('click', () => this.loadOlder());
    }

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        clearTimeout(this.timer);
      } else {
        this.wake();
      }
    });
  }

  async send() {
    const content = this.inputContent.value.trim();
    const name = this.inputName.value.trim() || 'Anonymous';

    if (!content) return;

    const body = new URLSearchParams({
      name,
      content,
      token: this.token,
      website: this.form.querySelector('#chat-hp')?.value || '',
    });

    const trackId = this.getTrackId();
    if (trackId) body.set('track', String(trackId));

    this.#setStatus('');
    const pending = this.#pending(name, content);

    try {
      const res = await fetch(`${this.base}/api/v1/chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body,
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        pending.remove();
        this.#setStatus(ERRORS[data.error] || 'Не отправилось', 'error');
        if (data.error === 'bad_token') setTimeout(() => location.reload(), 2500);
        return;
      }

      if (data.token) this.token = data.token;
      this.inputContent.value = '';
      this.inputContent.focus();

      // Привязываем заглушку к присвоенному серверу id. Опрос мог успеть
      // притащить сообщение раньше, чем вернулся ответ на отправку, —
      // тогда убираем заглушку сразу.
      if (typeof data.id === 'number') {
        if (this.seen.has(data.id)) {
          pending.remove();
        } else {
          pending.dataset.pendingFor = String(data.id);
        }
      }

      this.wake();
    } catch {
      pending.remove();
      this.#setStatus('Нет связи с сервером', 'error');
    }
  }

  #pending(name, content) {
    const node = this.#node({ id: -Date.now(), time: Math.floor(Date.now() / 1000), name, content, source: 'web' });
    node.classList.add('msg--pending');
    node.removeAttribute('data-id');
    this.log.append(node);
    this.scrollToBottom();

    // Обычно заглушку снимает append(), когда приходит подтверждение.
    // Таймер — страховка на случай, если сообщение так и не вернулось.
    setTimeout(() => node.remove(), 15000);
    return node;
  }

  /** Убрать заглушку, подтверждённую сервером. */
  #dropPending(id) {
    this.log.querySelector(`.msg--pending[data-pending-for="${id}"]`)?.remove();
  }

  async loadOlder() {
    if (this.oldestId === null) return;

    this.moreBtn.disabled = true;
    try {
      const res = await fetch(`${this.base}/api/v1/chat/history?before=${this.oldestId}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;

      const data = await res.json();
      const messages = data.messages || [];

      if (!messages.length) {
        this.moreBtn.hidden = true;
        return;
      }

      const before = this.log.scrollHeight;
      const frag = document.createDocumentFragment();
      for (const m of messages) {
        if (this.seen.has(m.id)) continue;
        this.seen.add(m.id);
        if (this.oldestId === null || m.id < this.oldestId) this.oldestId = m.id;
        frag.append(this.#node(m));
      }
      this.log.prepend(frag);
      this.log.scrollTop = this.log.scrollHeight - before;
    } finally {
      this.moreBtn.disabled = false;
    }
  }

  #setStatus(text, kind = '') {
    if (!this.status) return;
    this.status.textContent = text;
    this.status.dataset.kind = kind;
  }

  #restoreName() {
    try {
      const saved = localStorage.getItem(LS.name);
      if (saved) this.inputName.value = saved;
    } catch {}
  }

  // --- Одна вкладка опрашивает за всех ------------------------------------

  #initChannel() {
    this.isLeader = true;
    this.tabId = Math.random().toString(36).slice(2);

    if (!('BroadcastChannel' in window)) return;

    this.channel = new BroadcastChannel('rm-chat');
    this.lastHeartbeat = Date.now();

    this.channel.addEventListener('message', (e) => {
      const msg = e.data || {};

      switch (msg.type) {
        case 'hello':
          // Лидер отзывается, новичок становится ведомым
          if (this.isLeader) this.#broadcast({ type: 'leader', tab: this.tabId });
          break;

        case 'leader':
          if (msg.tab !== this.tabId) {
            // Побеждает меньший id — иначе обе вкладки сочтут лидером себя
            if (this.isLeader && msg.tab < this.tabId) this.#demote();
            this.lastHeartbeat = Date.now();
          }
          break;

        case 'messages':
          if (!this.isLeader) {
            this.append(msg.messages || []);
            this.#renderOnline(msg.online);
            this.lastHeartbeat = Date.now();
          }
          break;
      }
    });

    this.#broadcast({ type: 'hello', tab: this.tabId });

    // Лидер объявляется, ведомые следят, что он жив
    setInterval(() => {
      if (this.isLeader) {
        this.#broadcast({ type: 'leader', tab: this.tabId });
      } else if (Date.now() - this.lastHeartbeat > 20000) {
        this.#promote();
      }
    }, 5000);

    window.addEventListener('pagehide', () => {
      this.stopped = true;
      clearTimeout(this.timer);
      this.channel?.close();
    });
  }

  #broadcast(payload) {
    try {
      this.channel?.postMessage(payload);
    } catch {}
  }

  #demote() {
    this.isLeader = false;
    clearTimeout(this.timer);
  }

  #promote() {
    this.isLeader = true;
    this.lastHeartbeat = Date.now();
    this.#broadcast({ type: 'leader', tab: this.tabId });
    this.wake();
  }
}
