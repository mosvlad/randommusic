/**
 * Яндекс Метрика.
 *
 * Вынесена в отдельный модуль вместо инлайнового сниппета из интерфейса
 * Метрики: инлайн потребовал бы 'unsafe-inline' в script-src, то есть
 * ослабления CSP на всём сайте ради счётчика. Здесь внешним остаётся
 * только сам tag.js.
 *
 * Загружается лениво и только если в .env задан METRIKA_ID.
 */

/** Официальный загрузчик, слово в слово из документации Метрики. */
function loadTag() {
  (function (m, e, t, r, i, k, a) {
    m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
    m[i].l = 1 * new Date();
    k = e.createElement(t);
    a = e.getElementsByTagName(t)[0];
    k.async = 1;
    k.src = r;
    a.parentNode.insertBefore(k, a);
  })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
}

export function initMetrika(id) {
  const counter = Number(id);
  if (!counter) return null;

  loadTag();

  window.ym(counter, 'init', {
    clickmap: true,
    trackLinks: true,
    accurateTrackBounce: true,
    // Вебвизор пишет сессии целиком, включая набранный в чате текст.
    // Для сайта с анонимным общением это лишнее — включайте осознанно.
    webvisor: false,
    defer: false,
    ecommerce: false,
  });

  /**
   * Достижение цели. В v1 такое пытались делать через
   * gtag('ButtonNext', 'Next') — вызов был до объявления функции и с
   * неверной сигнатурой, поэтому не работал никогда.
   */
  const goal = (name, params) => {
    try {
      window.ym(counter, 'reachGoal', name, params);
    } catch {}
  };

  return { counter, goal };
}
