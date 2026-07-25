/**
 * Точка входа. Нативные ES-модули, без сборки и без jQuery:
 * v1 тянул jQuery 3.1 с googleapis, html5media с api.html5media.info,
 * id3.js и reCAPTCHA — четыре внешних узла в критическом пути отрисовки.
 */

import { Player } from './player.js';
import { Chat } from './chat.js';

const LS = { theme: 'rm.theme' };

const boot = JSON.parse(document.getElementById('boot')?.textContent || '{}');

/* --- Тема: тумблер был свёрстан ещё в v1, но закомментирован ------------- */

function initTheme() {
  const btn = document.querySelector('#theme-toggle');
  const apply = (theme) => {
    document.documentElement.dataset.theme = theme;
    if (btn) btn.textContent = theme === 'day' ? 'Ночь' : 'День';
  };

  let theme = 'night';
  try {
    theme = localStorage.getItem(LS.theme) || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'day' : 'night');
  } catch {}
  apply(theme);

  btn?.addEventListener('click', () => {
    theme = document.documentElement.dataset.theme === 'day' ? 'night' : 'day';
    apply(theme);
    try {
      localStorage.setItem(LS.theme, theme);
    } catch {}
  });
}

/* --- История прослушанного: спойлер, как в v1 ---------------------------- */

function initHistoryToggle() {
  const btn = document.querySelector('#history-toggle');
  const box = document.querySelector('#history');
  if (!btn || !box) return;

  btn.addEventListener('click', () => {
    const open = box.dataset.open === '1';
    box.dataset.open = open ? '0' : '1';
    btn.textContent = open ? 'Показать историю' : 'Скрыть историю';
    btn.setAttribute('aria-expanded', String(!open));
  });
}

/* --- Горячие клавиши ----------------------------------------------------- */

function initHotkeys(player, chat) {
  document.addEventListener('keydown', (e) => {
    const el = document.activeElement;
    const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);

    if (e.key === '/' && !typing) {
      e.preventDefault();
      chat.inputContent.focus();
      return;
    }

    if (typing || e.ctrlKey || e.metaKey || e.altKey) return;

    switch (e.key) {
      case ' ':
        e.preventDefault();
        player.toggle();
        break;
      case 'ArrowRight':
        e.preventDefault();
        player.random();
        break;
      case 'ArrowLeft':
        e.preventDefault();
        player.previous();
        break;
    }
  });
}

/* --- Запуск -------------------------------------------------------------- */

initTheme();
initHistoryToggle();

const playerRoot = document.querySelector('#player');
const chatRoot = document.querySelector('#chat');

let player = null;
let chat = null;

if (playerRoot) {
  player = new Player(playerRoot, { initial: boot.initial || null, base: boot.base || '' });
}

if (chatRoot) {
  chat = new Chat(chatRoot, {
    lastId: boot.lastId || 0,
    token: boot.token || '',
    online: boot.online || 0,
    base: boot.base || '',
    getTrackId: () => (player ? player.trackId : null),
  });
}

if (player && chat) initHotkeys(player, chat);

/* --- PWA: сайт слушают фоном с телефона, ему полезно ставиться на экран --- */

if ('serviceWorker' in navigator && (boot.base || '') === '') {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}

// Отдаём наружу для отладки из консоли
window.rm = { player, chat };
