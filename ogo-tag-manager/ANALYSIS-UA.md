# OGO Tag Manager 3.11.1

Fixed false redirect ownership conflicts caused by filtered slug lookup: WordPress WP_Term_Query applies sanitize_title to slug lookup values, and transliteration filters can turn a Cyrillic source into its Latin target. Ownership now uses a prepared read-only query against actual post_tag slugs and compares normalized raw paths. Genuine reuse by another tag still blocks the redirect, and database errors fail closed.

The reported mappings 8422 → 6385, 13161 → 8811, and 30768 → 24238 were reproduced in isolated regression fixtures with transliterated lookups. Their completed transfers recover active redirects, pending CSV rows and cleanup eligibility without moving articles again.

All prior 190 local checks and 15 new ownership regressions passed (205 total). Actual WordPress/MySQL, third-party plugin filters and production cache require deployment verification. No live-site data was changed by this release task.

Reference: https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/class-wp-term-query.php .
