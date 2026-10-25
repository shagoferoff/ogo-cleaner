# Наш регіон: карта заміни

Карту звірено з трьома CSV, наданими редакцією. Вибірка містить 116 опублікованих статей: 113 посилань на 14 рубрик і 3 на головну. У цій вибірці немає посилань на статті виду дата/ID.html.

| Стара рубрика | Тег | Посилань |
|---|---|---:|
| berezne | https://ogo.ua/tag/berezne/ | 8 |
| dubno | https://ogo.ua/tag/dubno/ | 13 |
| dubrovytsia | https://ogo.ua/tag/dubrovyczya/ | 8 |
| hoshcha | https://ogo.ua/tag/goshha/ | 6 |
| korets | https://ogo.ua/tag/korecz/ | 6 |
| kostopil | https://ogo.ua/tag/kostopil/ | 9 |
| mlyniv | https://ogo.ua/tag/mlyniv/ | 7 |
| ostroh | https://ogo.ua/tag/ostrog/ | 13 |
| radyvyliv | https://ogo.ua/tag/radyvyliv/ | 8 |
| rivne | https://ogo.ua/tag/rivne/ | 7 |
| sarny | https://ogo.ua/tag/sarny/ | 7 |
| volodymyrets | https://ogo.ua/tag/volodymyrecz/ | 7 |
| zarichne | https://ogo.ua/tag/zarichne/ | 7 |
| zdolbuniv | https://ogo.ua/tag/zdolbuniv/ | 7 |

Головна `/nashregion` і `/nashregion/` → `https://ogo.ua/rubrics/view/region/` («Новини Рівного»), за вказівкою редакції. Це 3 посилання у вибірці.

`#page-top` видаляється із замінюваних посилань цього проєкту. У вибірці 5 таких посилань. Інші фрагменти та параметри рубрик/головної зберігаються.

Статті `/nashregion/РРРР-ММ-ДД/ID.html` обробляються через ID WordPress: перевірка дати та ID, доступного опублікованого запису й актуального permalink. Невідомі адреси, статті з параметрами та пошкоджені посилання залишаються для ручної перевірки. Перед застосуванням результат перевіряється повторно.

База сайту: `ogo2023_ogo`. Фактичний префікс таблиць: `wp_` (повідомлено редакцією). Сам плагін використовує `$wpdb->prefix`, без жорсткого префікса.

Звіти тестування експорту описують локальну обробку наданих CSV; зміни на робочому сайті тут не виконувалися.
