# Private native-form drafts

Autosave preserves selected incomplete values for explicit recovery. It does not commit, publish, change workflow, run canonical save plugins, or create content-history versions. Use the native Save/Apply actions to commit content. Recovery changes native controls; discard removes only the selected private draft. There is no server draft-version undo timeline.

## Configuration and form control

Autosave runs automatically for every supported native form; there is no per-form or global enable/disable option. Joomla's native component interval parameter sets the maximum attempt interval in seconds (default 30, minimum 1). The view converts seconds to milliseconds once. The runtime retains the short debounce and one non-resetting dirty deadline, both entering the same serialized writer. Only changed supported values create draft payloads. The shared binder compares the signalled native field with its last observed value; repeated input/change/editor notifications with identical values do not create revisions or dirty work. First editor initialization uses its native serialized value once, while edits made before availability and later replacement values retain the original baseline. The writer also rejects an untouched initial projection for both new and existing forms. Intentional clearing after preserved edits remains recoverable. Unchanged forms do not write periodic payloads. Background suspension, unavailable controls, transport failure, and revoked authority can prevent timely persistence. The original compact status uses Saving... and Saved; Saved reflects an acknowledged private snapshot, while native Save/Apply commits the item.

Native binding admission and mounting remain subject to the existing provider, form, and authorization contracts. Scheduler cleanup is independent of form mounting.

No additional checkbox or coverage disclosure is rendered in the toolbar. Existing recovery/error UI and accessible status help remain. Native submission never waits for draft or resumption transport.

## Retention and empty-render resumption

The store retains empty bindings for one hour, payloads for seven idle days with a 30-day absolute limit, and retirement tombstones for one day. Raw expired reads/preservation still fail closed. There is no unchanged-form heartbeat.

Held native edit IDs do not retain the exact original canonical revision, scope, projection, or separate new-record form identity. Therefore authorized rendering stores minimal provenance in Joomla's native session under `DraftService::RENDER_ADMISSIONS`: owner, opaque draft ID, original identity/scope/projection and revision hash, render time and deadline. Canonical values and draft payloads are excluded from this metadata. No draft payload or new signing protocol is stored there. At most 128 render entries are retained; expired entries are pruned when opening a form. Eviction removes only empty-binding resumption eligibility, not existing draft payloads.

The protected `draft.resume` request accepts only an ID and schema version. The dispatcher reads server session metadata before releasing the session lock; browser context/target/scope/fields cannot establish admission. Under the existing owner lock, the store verifies original admission, unused state, and current provider ACL/checkout, immutable scope, projection and canonical base. It renews or recreates the same empty ID only after those checks, handling logical expiry and physical purge without changing local values or guessing a new baseline. The original creation time is retained. The browser makes at most one negotiation per preservation attempt, then recaptures current values through the same writer. Negotiation is not a persistence acknowledgment. Disposal/discard/native submission cannot be undone by its late response.

Admission expires no later than the configured tombstone lifetime after the original render. Discard and successful native retirement may recreate a missing empty metadata row only from that trusted admission, and retain their rejection floor for a full tombstone lifetime. Consequently an older session admission has expired before its terminal floor can be physically purged. It cannot resurrect a discarded or successfully committed editing instance. Present expired rows can be retired by the authoritative native-success/owner-discard path; ordinary preservation cannot use them.

Lost session provenance, expired admission, prior payload/retirement, changed canonical revision/scope/schema, revoked access, and checkout conflict fail safely. Local values remain available for ordinary saving; there is no forced reload, unlimited renewal, or automatic baseline adoption.

## Component/provider contract

A component implements `AutosaveServiceInterface` and registers exact contexts through `AutosaveServiceTrait`. Its `DraftProviderInterface` describes the native prepared form and canonical item, and rereads native models/forms to recheck authority. Context, target, immutable scope, schema version, canonical revision, allowed native controls, and selected canonical values are server-established. Client locators are not authority.

Dynamic parameters require explicit safe `autosave="true"` declarations; sensitive, disabled/readonly, password/upload and unsupported complex controls are excluded. Private normalization allows incomplete selected values; native final validation/filtering remains authoritative. No general nested/subform or arbitrary third-party form support is claimed. Articles protect title, alias, article text and category only; custom-field values, tags, publishing/access settings and general metadata are excluded. The current 25 registered contexts remain implemented; template styles support existing records only, and user-account/password forms are not registered.

The concrete administrator `AutosaveComponent::getDraftService()` exposes the shared service to native views and successful controller hooks. It is not a browser canonical-save endpoint. Views pass the native session when opening bindings. The dispatcher authenticates backend access, POST/token/JSON/envelope limits, obtains trusted session admission, closes the session, then executes draft-only operations. The service uses the existing resolver/store factory; cleanup uses the store independently.

Successful native saves retain controller/model/table validation, checkout, workflow, associations, save plugins and history. Optional reconciliation retires only the submitted revision floor and compatible explicitly restored source; later revisions/other editing instances remain independent. Exact committed revisions are never guessed from a later read. Native failures do not retire work.

New-record drafts use a null canonical target and opaque history-entry references. Reload/back navigation can recover that entry's work; a fresh New navigation does not catalogue or adopt another tab's unfinished creation.

## Editor integration

Use Joomla's existing editor registry and decorator APIs. `subscribeLifecycle` reports registry changes; `subscribeChange` returns an idempotent per-consumer disposer and must observe real content changes. `getElement()` returns the exact native textarea owned by the current provider, or null when unsupported. None, CodeMirror and TinyMCE implement this association; matching an ID alone is insufficient.

The binder requires a unique current in-form ID, expected input name, textarea kind, actual form ownership, eligibility, and an editor owning that node. It disposes and rebinds once on replacement, rejects ambiguous/foreign registrations, ignores retired callbacks and captures through live `getValue()`. Repeated notifications do not add subscriptions or manufacture saved baselines. Third-party providers must implement these contracts to participate; unsupported providers leave native saving available. This editor contract/provider layer is independently reviewable before the autosave consumer; no separate branch or PR is created here.

## Retained native-form workaround

The target `FieldModel::getForm` hardcodes `load_data=true`, ignoring its requested false argument. `DraftModel::form` therefore retains the narrow `autosave.form_data` state branch for explicit canonical preparation, avoiding failed-validation session values. Native form preprocessing remains in `loadForm`; ordinary native editing/failed-validation behavior is unchanged outside that state. Removing the branch requires separate evidence for correcting the inherited load-data contract across type/context and plugin behavior. No private cache reset or global session rewrite is introduced.

## Deployment and acceptance boundary

Supported schema entry points are the selected Joomla 6.2 baseline/fresh installation and the current `#__autosave_drafts` install/upgrade SQL. No supported public deployment of the earlier continuations/generations/canonical-actions design has been established. Those experimental tables/data are not automatically converted or dropped. Any support for such deployments needs a separately agreed, version-specific data policy.

Use the repository's existing unit, JavaScript, integration and system harnesses. The store integration cases use the real DDL; contention tests use independent connections to the same disposable standard test database, with an explicitly held owner lock and short database lock timeout. Never point these destructive fixtures at a live installation. Simulated DOM/model tests do not establish installed-form or driver acceptance.

Current verification details are reported with the implementation: MySQL 8.4 fresh schema/current upgrade and store/lock contention have been executed on an isolated instance. PostgreSQL execution is outstanding because no server executables were available. Installed-browser/editor/history coverage must be reported by actually executed context/provider; no all-25 browser acceptance claim follows from source or startup tests.
