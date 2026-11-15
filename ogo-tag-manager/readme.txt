=== OGO Tag Manager ===
Contributors: OGO
Requires PHP: 7.4
Stable tag: 3.11.0

Tag auditing, reversible staged relationship migration, slug change batches and CSV redirect plans.

== Changelog ==

= 3.11.0 =
* Added persistent per-pair Skip and Restore actions without creating or deleting tags.
* Occupied Cyrillic slug suggestions now prepare confirmed merges into the existing tag, including multiple sources sharing one target.
* Large merges run in chunks of at most 100 link writes with durable intent, progress, stop and resume after reload or interrupted verification.
* Replaced the full active-rule table with Pending Redirects and a dedicated pending-only CSV.
* Added selected Redirection group verification: active exact rule, direct HTTP 301, Redirection response marker and target HTTP 200.
* Signed probe headers bypass OGO fallback during verification; successful external rules are hidden from pending and future ordinary exports.
* Preserved internal fallback and migration history; a disabled or changed external rule returns to pending.
* Normalized apostrophes and HTML entities in transliteration suggestions.


= 3.10.0 =
* Added durable CSV batches per guided merge or slug-change action.
* The current batch exports only new active source rules, excluding older redirects, failed writes and undone changes.
* Added batch row counts, history and reload recovery; source cleanup retains batch exports.
* Added explicit selected-row export for legacy changes and a separately labelled full backup CSV.
* Exporting does not mark rules as imported; each batch has a stable file identity.


= 3.9.0 =
* Added bulk deletion of completed merge sources to the main guided page.
* Automatically selects eligible sources, including merges completed in earlier versions.
* Rechecks article relationships, source/target changes, permissions, SEO and active redirects before each deletion.
* Added signed per-source preview tokens, durable deletion intentions, retry recovery and progress/stop controls.
* Keeps migration records and redirects after deleting source terms; article content and target terms are preserved.
* Added PHP and browser coverage for deletion, cancellation, stale previews, partial errors and retained HTTP 301 rules.


= 3.8.1 =
* Added a second CSV download button beside the active redirects list.
* Clarified that pair discovery uses existing tags only and never creates new tags.


= 3.8.0 =
* New default Ukrainian guided page for singular/plural tag merging and bulk Cyrillic slug transliteration.
* One-at-a-time writes, previews, collision checks, progress, stop and per-row results.
* Built-in 301 redirects with dynamic target IDs and a combined CSV export; no Redirection import required for new operations.
* Source tags remain for manual deletion; existing v3.7 migration, queue, undo and slug history remain available.
* Guards for failed persistence and incomplete relationship rollback.
* See README-UA.txt for upgrade steps, limits and verification scope.


= 3.7.0 =
* Completed slug preview, collision checks, durable batches, sequential execution, guarded undo and per-batch Redirection CSV.
* Added direct 301 / target HTTP checks for applied slug batches.
* Added AJAX queue decisions, stable UI state restoration and lazy target suggestions.
* Added explicit canonical target selection for bulk queue preparation.
* Added sequential re-verification of selected or all staged migrations, including previous OK records.
* Preserved 3.6.0 option names and existing staged migration records.
* Added shared mutation locking across supported admin actions and the new workflow.
* See README-UA.txt for installation, workflow, scope and validation limitations.
