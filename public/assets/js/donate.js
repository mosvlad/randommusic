/**
 * Последний донат на главной.
 *
 * Сервер уже отрисовал последний донат в разметке. Здесь мы лишь
 * подтягиваем новые, пока вкладка открыта: редким опросом (раз в минуту),
 * только когда вкладка видима, и с ETag — при отсутствии новых ответ 304.
 */

const CURRENCY = { RUB: '₽', USD: '$', EUR: '€', UAH: '₴', BYN: 'Br', KZT: '₸' };

/** Тот же формат, что в templates/home.php: '1 500 ₽', '$10'. */
function money(amount, currency) {
  const num = Number(amount) || 0;
  const digits = (Number.isInteger(num) ? String(num) : num.toFixed(2))
    .replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const sym = CURRENCY[currency] || currency;
  return currency === 'USD' || currency === 'EUR' ? `${sym}${digits}` : `${digits} ${sym}`;
}

export function initDonate({ base = '', last = null } = {}) {
  const box = document.querySelector('#donate-last');
  const who = document.querySelector('#donate-who');
  const amount = document.querySelector('#donate-amount');
  const message = document.querySelector('#donate-message');
  if (!box || !who || !amount || !message) return;

  let currentId = last && last.id ? last.id : 0;
  let etag = null;
  let timer = null;

  const render = (d) => {
    if (!d || !d.id || d.id === currentId) return;
    currentId = d.id;

    who.textContent = d.username || 'Аноним';
    amount.textContent = money(d.amount, d.currency);

    if (d.message) {
      message.textContent = d.message;
      message.hidden = false;
    } else {
      message.textContent = '';
      message.hidden = true;
    }

    box.hidden = false;
    box.classList.remove('donate__last--new');
    void box.offsetWidth;              // перезапустить анимацию подсветки
    box.classList.add('donate__last--new');
  };

  const poll = async () => {
    try {
      const res = await fetch(`${base}/api/v1/donation/last`, {
        headers: etag ? { 'If-None-Match': etag } : {},
      });
      if (res.status === 304) return;
      etag = res.headers.get('ETag') || etag;
      const data = await res.json();
      render(data.donation);
    } catch {
      /* сеть моргнула — попробуем в следующий раз */
    }
  };

  const start = () => { if (!timer) timer = setInterval(poll, 60000); };
  const stop = () => { clearInterval(timer); timer = null; };

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stop();
    } else {
      start();
      poll();
    }
  });

  if (!document.hidden) start();
}
