# ogo-cleaner

WordPress-плагін OGO cleaner; код у `ogo-media-cleaner/`, готові ZIP у `dist/`.

База сайту: `ogo2023_ogo`; префікс таблиць **`wp_`**. Плагін визначає префікс через `$wpdb->prefix`.

Карта проєкту «Наш регіон»: [docs/nashregion.md](docs/nashregion.md).

Функціональні перевірки (PHP CLI):

```sh
php tests/horoscope.php
php tests/garden.php
php tests/pets.php
php tests/dim.php
php tests/nashregion.php
php tests/woman.php
php tests/audit.php
php tests/editorial.php
php tests/legacy.php
node tests/audit-js.cjs
```

Карта «Жіночого клубу»: [docs/woman.md](docs/woman.md).

Ревізія старих посилань: [docs/audit.md](docs/audit.md).

Інтеграційний тест ревізії запускається окремо на чистій тестовій інсталяції
WordPress з активованим плагіном, адміністратором `audit_admin` і базою
`ogo_audit_test`; він створює тестові записи. Встановіть URL тестового сайту
`https://ogo.ua`, заблокуйте зовнішні HTTP-запити (`WP_HTTP_BLOCK_EXTERNAL`)
та вимкніть WP Cron. Не використовуйте робочу базу сайту.

```sh
OGO_TEST_WP_ROOT=/path/to/test-wordpress php tests/audit-wordpress.php
```

Погоджені заміни з ревізії: [docs/editorial.md](docs/editorial.md).

Для перевірки нового режиму замін у тій самій ізольованій базі:

```sh
OGO_TEST_WP_ROOT=/path/to/test-wordpress php tests/editorial-wordpress.php
```

Розпізнавання пошкоджених старих адрес статей: [docs/legacy.md](docs/legacy.md).
Інтеграційний тест на тій самій ізольованій тестовій інсталяції:

```sh
OGO_TEST_WP_ROOT=/path/to/test-wordpress php tests/legacy-wordpress.php
```
