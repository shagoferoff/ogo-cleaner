# OGO Tag Manager 3.11.0

Persistent per-pair Skip/Restore, occupied-slug merge plans, resumable 100-link chunks, pending-only redirect CSV and verified Redirection handoff are implemented. Existing option keys and fallback rules are preserved.

Verification requires an enabled exact URL rule in the configured Redirection group, a direct HTTP 301 with a Redirection provider header, and HTTP 200 at the destination. Signed probes bypass the OGO fallback. Successful rows are excluded from pending and ordinary exports. Disabled or changed external rules reappear and OGO fallback returns. The plugin does not import or mutate external Redirection rules.

190 local checks passed: 36 baseline, 31 deletion, 30 export, 32 guided/chunks, 33 Redirection verification and 28 browser/HTTP checks. WordPress and Redirection APIs were simulated; actual site WordPress/MySQL, installed Redirection version and caching need deployment verification. Full Ukrainian release instructions and limits: README-UA.txt.

API references: https://redirection.me/developer/php-api/ and https://github.com/johngodley/redirection/tree/trunk/models .
