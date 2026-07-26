/**
 * Яндекс Метрика.
 *
 * Вынесена в отдельный модуль вместо инлайнового сниппета из интерфейса
 * Метрики: инлайн потребовал бы 'unsafe-inline' в script-src, то есть
 * ослабления CSP на всём сайте ради счётчика. Внешним остаётся только
 * сам tag.js.
 *
 * Загружается лениво и только если в .env задан METRIKA_ID.
 */

/**
 * Загрузчик слово в слово из кода счётчика, включая проверку на повторную
 * вставку: если tag.js уже на странице, второй раз не добавляем.
 */
function loadTag(src) {
  (function (m, e, t, r, i, k, a) {
    m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
    m[i].l = 1 * new Date();
    for (var j = 0; j < document.scripts.length; j++) {
      if (document.scripts[j].src === r) { return; }
    }
    k = e.createElement(t);
    a = e.getElementsByTagName(t)[0];
    k.async = 1;
    k.src = r;
    a.parentNode.insertBefore(k, a);
  })(window, document, 'script', src, 'ym');
}

/**
 * @param {number|string} id       номер счётчика
 * @param {boolean}       webvisor запись сессий
 */
export function initMetrika(id, webvisor = true) {
  const counter = Number(id);
  if (!counter) return null;

  loadTag(`https://mc.yandex.ru/metrika/tag.js?id=${counter}`);

  window.ym(counter, 'init', {
    ssr: true,
    // Вебвизор пишет сессии целиком, включая набираемый в чате текст.
    // Переключается через METRIKA_WEBVISOR в .env.
    webvisor,
    clickmap: true,
    ecommerce: 'dataLayer',
    referrer: document.referrer,
    url: location.href,
    accurateTrackBounce: true,
    trackLinks: true,
  });

  /**
   * Достижение цели. В v1 такое пытались делать через
   * gtag('ButtonNext', 'Next') — вызов стоял до объявления функции и с
   * неверной сигнатурой, поэтому не работал никогда.
   */
  const goal = (name, params) => {
    try {
      window.ym(counter, 'reachGoal', name, params);
    } catch {}
  };

  return { counter, goal };
}
