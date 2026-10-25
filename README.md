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
```

Карта «Жіночого клубу»: [docs/woman.md](docs/woman.md).
