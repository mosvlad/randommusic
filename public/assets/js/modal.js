/**
 * Модалка для /login, /register, /upload.
 *
 * Сервер ничего не знает о модалке: отдаёт обычные полные страницы.
 * Здесь они загружаются fetch'ем, из ответа вырезается .auth-card и
 * вставляется в <dialog>. Без JS те же ссылки просто открывают
 * настоящую страницу — работает один в один, включая токен формы,
 * антиспам и honeypot: это ровно тот же HTML, что увидел бы человек
 * без JS, только во всплывающем окне поверх главной.
 *
 * Правило «куда дели после отправки формы»: если action формы (страница,
 * где мы были) совпадает с путём финального ответа (после всех
 * редиректов) — показываем этот фрагмент на месте, с ошибкой или
 * статусом внутри (так уже умеют сами шаблоны). Если путь изменился —
 * значит сервер увёл в другое место (вход/регистрация удались и
 * увели на /upload) — перезагружаем страницу целиком, чтобы топ-бар
 * узнал о новой сессии.
 */
export function initAuthModal() {
  const dialog = document.querySelector('#auth-modal');
  if (!dialog || typeof dialog.showModal !== 'function') return;

  const modalBody = dialog.querySelector('.modal__body');
  const closeBtn = dialog.querySelector('.modal__close');

  const focusFirst = () => {
    modalBody.querySelector('input, button, select, textarea')?.focus();
  };

  const showError = () => {
    modalBody.innerHTML = '<p class="auth-card__status" data-kind="error">Не удалось загрузить форму, попробуйте ещё раз</p>';
  };

  async function swapFrom(res) {
    const html = await res.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const card = doc.querySelector('.auth-card');
    if (!card) {
      // Не похоже на страницу с формой — например, разлогинило по пути.
      // Надёжнее всего просто уйти туда взаправду.
      window.location.href = res.url;
      return;
    }
    modalBody.replaceChildren(card);
    focusFirst();
  }

  async function open(url) {
    if (!dialog.open) dialog.showModal();
    modalBody.innerHTML = '<p class="modal__loading">Загрузка…</p>';
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      await swapFrom(res);
    } catch {
      showError();
    }
  }

  async function submit(form) {
    const action = form.getAttribute('action') || window.location.href;
    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    const fd = new FormData(form);

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(action, {
        method,
        body: method === 'GET' ? undefined : fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      const samePage = new URL(action, window.location.href).pathname
        === new URL(res.url, window.location.href).pathname;

      if (samePage) {
        await swapFrom(res);
      } else {
        // Увело на другую страницу — скорее всего, вход/регистрация
        // удались. Перезагружаем, чтобы топ-бар отрисовался с сессией.
        window.location.reload();
      }
    } catch {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  // Открытие: клики по ссылкам с data-modal в любом месте страницы
  // (топ-бар), и по любым ссылкам внутри уже открытой модалки
  // (переходы «нет аккаунта» <-> «уже есть аккаунт»).
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a');
    if (!a) return;

    if (dialog.open && modalBody.contains(a)) {
      e.preventDefault();
      open(a.href);
      return;
    }

    if (a.hasAttribute('data-modal')) {
      e.preventDefault();
      open(a.href);
    }
  });

  modalBody.addEventListener('submit', (e) => {
    e.preventDefault();
    submit(e.target);
  });

  closeBtn?.addEventListener('click', () => dialog.close());

  // Клик по подложке — закрыть. Клик по самому <dialog> случается и
  // при клике вне его содержимого (подложка — часть элемента), отличаем
  // по координатам прямоугольника содержимого.
  dialog.addEventListener('click', (e) => {
    if (e.target !== dialog) return;
    const r = dialog.getBoundingClientRect();
    const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
    if (!inside) dialog.close();
  });
}
