# Installed browser acceptance

Run this after rebuilding assets and verifying the feature installation/upgrade and enabled cleanup task. Record the actual revision/diff, browser, editor, database and user role. The automated suites do not complete this checklist.

## Repeatable core flows

1. Open an existing Article, change a protected value and wait for **Saved**. Leave without native saving, reopen, select **Review draft**, compare and **Restore Draft**. Check that the item itself has not changed until native saving succeeds.
2. Restore a draft, intentionally clear a protected value, and confirm recovery retains the clearing. Confirm **Continue Without Restoring** leaves native values and the draft unchanged.
3. Confirm **Discard Draft** removes only the selected private draft. A different tab's work must remain recoverable.
4. Create new unsaved work, wait for acknowledgement and reload that same history entry. Confirm explicit recovery. Opening a fresh New form must not silently adopt old work.
5. Exercise Save/Apply, Save & Close, Save & New/Copy, Contact Save-to-Menu and Associations wherever actually offered. Only successful submitted revisions retire; later work and another tab remain recoverable.
6. Cause native validation failure. Then cancel a submit event using an extension/listener, continue editing, and confirm automatic protection resumes. Native saving must remain independent of draft transport.
7. Check None, CodeMirror and TinyMCE, including late initialization, editor replacement/disposal and unchanged submit synchronization. Check ordinary textarea/select/radio/checkbox/calendar/fancy-select values as applicable.
8. Change or remove a protected control's name, form association or kind, and introduce a duplicate ID. Confirm it is unavailable rather than preserved under the admitted key. Attempt recovery after such a change; an unrelated control must remain unchanged.
9. Interrupt network responses, restore connectivity and use **Retry Draft Save**. Confirm acknowledgement, later-edit ordering and useful status. Change canonical content in another session and confirm explicit stale recovery.
10. Exercise relevant roles/edit-own/category access, checkout conflict, session change, scope change, modals and browser back/forward reload. Confirm another user cannot discover or restore the draft.
11. Test first edit after empty-binding expiry and physical purge, admission expiry/eviction, and native success after empty purge. Local values must survive rejection; terminal instances must not resurrect.
12. Check selected safe dynamic parameters and omitted/sensitive widgets. Check Scheduler backlog/continuation and native user-row contention under representative installed load.

## Context record

Use shared flows for identical behavior and focused checks for each distinct model/task/identity/widget integration. Mark N/A explicitly where creation, copy or associations are unavailable.

| Context | New/existing | Selected controls/recovery | Native actions/failures | Role/scope/widget differences | Result |
| --- | --- | --- | --- | --- | --- |
| Article | | | | | Pending |
| Category | | | | | Pending |
| Banner | | | | | Pending |
| Banner Client | | | | | Pending |
| Contact | | | | | Pending |
| Field | | | | | Pending |
| Field Group | | | | | Pending |
| Guided Tour | | | | | Pending |
| Guided Tour Step | | | | | Pending |
| Smart Search Filter | | | | | Pending |
| Menu Item | | | | | Pending |
| Menu Type | | | | | Pending |
| Content Language | | | | | Pending |
| Language Override | | | | | Pending |
| Module | | | | | Pending |
| Newsfeed | | | | | Pending |
| Redirect Link | | | | | Pending |
| Tag | | | | | Pending |
| Template Style | Existing only | | | | Pending |
| User Group | | | | | Pending |
| Access Level | | | | | Pending |
| User Note | | | | | Pending |
| Workflow | | | | | Pending |
| Workflow Stage | | | | | Pending |
| Workflow Transition | | | | | Pending |
