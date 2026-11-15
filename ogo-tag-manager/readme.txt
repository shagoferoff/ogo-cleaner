=== OGO Tag Manager ===
Contributors: OGO
Requires PHP: 7.4
Stable tag: 3.7.0

Tag auditing, reversible staged relationship migration, slug change batches and CSV redirect plans.

== Changelog ==

= 3.7.0 =
* Completed slug preview, collision checks, durable batches, sequential execution, guarded undo and per-batch Redirection CSV.
* Added direct 301 / target HTTP checks for applied slug batches.
* Added AJAX queue decisions, stable UI state restoration and lazy target suggestions.
* Added explicit canonical target selection for bulk queue preparation.
* Added sequential re-verification of selected or all staged migrations, including previous OK records.
* Preserved 3.6.0 option names and existing staged migration records.
* Added shared mutation locking across supported admin actions and the new workflow.
* See README-UA.txt for installation, workflow, scope and validation limitations.
