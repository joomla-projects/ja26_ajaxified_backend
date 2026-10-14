# Auto save integration and public contracts

## Responsibility and service ownership

Native component models/controllers own canonical validation, persistence, plugin events, workflow, checkout and navigation. `DraftProviderInterface` owns selected values, native reads, authorization and immutable scope for one exact context. `DraftService` owns draft operations; `DraftStore` owns private rows, revisions and retention. The endpoint never executes a native business save.

`Joomla\CMS\Service\Provider\Autosave` registers `DraftService::class` and `DraftStore::class` lazily in the application container. Registration performs no component boot or database query. The endpoint and cleanup plugin receive shared services through their child containers. Native library helpers use the established Factory container boundary, without booting `com_autosave` to obtain a concrete extension getter. The endpoint's extension is not a cross-component service locator.

An extension implements `AutosaveServiceInterface`, uses `AutosaveServiceTrait`, and registers its component-owned providers by exact context. Construct native read models through its MVC factory, bind the authenticated current user and explicit model state, and reuse the finalized native form. Never build a second field-plugin discovery system or use the browser's locator as authorization.

The current resolver accepts only the listed native contexts. Registration alone does not expose arbitrary new component contexts. Additional support requires a deliberate allowlist entry and review of its native policy, scope, tasks and controls.

The provider descriptor includes exact context, nullable target, immutable scope, schema version, canonical revision, approved field descriptors and canonical selected values. Each field descriptor supplies its actual native input ID, input name, control kind and label. The browser requires unique ID, matching name, form association and supported DOM/control kind at admission, capture and recovery. A changed protected control stops preservation; it cannot become an unrelated value under the old key.

Ordinary fieldsets validate the individual radio/checkbox members. Editor fields also require exact textarea ownership from the registered decorator. Unknown controls are omitted. Keep source drafts after partial recovery, because not every selected value was restored.

## Native form fixes

`FieldModel::getForm($data, $loadData)` now forwards `$loadData` to `loadForm()` instead of always loading session data. The native form loader accepts explicit `data` in its existing options array for preprocessing and binding when `load_data` is false; normal `load_data=true` continues to use `loadFormData()`. This retains field-plugin type parameters without importing error-session values. Field identity also follows bound model state rather than the endpoint request. The `autosave.form_data` workaround has been removed. A native Text-plugin regression checks both canonical preparation and the normal error-data path.

Category, Guided Tour Step, Workflow/Stage/Transition and Language Override reads retain their explicit state/identity corrections. These are native read-contract changes and should be independently reviewed. The view-level policy helper checks every protected administrator group, regardless of rule order; the native controller and draft provider share that policy.

## Editor API proposal

The existing `JoomlaEditor` registry remains the owner of registered decorators and its legacy mirror. `subscribeLifecycle(callback)` returns an unsubscribe function. Callbacks receive immutable metadata `{type, id, editorType}`, with `type` equal to `registered` or `unregistered`; they receive neither content nor raw provider instances. Notifications occur after the registry mutation. Same-ID replacement releases the old provider.

`JoomlaEditorDecorator.getElement()` returns the owned native textarea or null when unsupported. `subscribeChange(callback)` returns an unsubscribe function for provider-neutral dirty notifications. Subscribers obtain values through the existing `getValue()` API when necessary. Unsupported observation throws `JoomlaEditorChangeObservationError` with code `unsupported_change_observation`; it does not claim an available observer. The None, CodeMirror and TinyMCE decorators integrate their existing native change/lifecycle APIs.

Disposal removes subscriptions. Programmatic changes and submit-time synchronization must not manufacture dirty revisions for unchanged values. Subscriber failures do not prevent other subscribers. Third-party decorators remain usable through their existing API; auto save requires the additional ownership and observation capability before protecting them.

These editor methods are public proposals requiring editor maintainers' review and real-provider browser acceptance. They should form an independently reviewed change rather than being treated as an internal implementation detail of this endpoint.

## Safe parameter declarations

The finalized native XML can declare a primitive recoverable parameter:

```xml
<field name="heading" type="text" label="Heading" autosave="true" />
<field name="credential" type="password" label="Credential" sensitive="true" />
```

`autosave="true"` is an explicit selection declaration, not permission to capture arbitrary form data. `sensitive="true"`, password/upload/hidden, disabled/readonly and unsupported complex widgets remain excluded. No custom-field value is automatically admitted merely because it renders inside a form. Native filtering and validation still happen when the actual item is saved.

## JSON endpoint and native actions

The component dispatcher reuses Joomla's application, input, session, component capability and JSON facilities. It needs its own draft-only operation dispatch and bounded JSON envelopes; `com_ajax`'s plugin/module routing does not itself supply this private-store/provider contract. The administrator route's narrow backend-access exception allows authenticated native editors without granting component management or bypassing provider authorization.

Every operation requires authenticated backend access, POST, the native CSRF token, valid JSON and a bounded envelope. Server-session admission is read before the session lock is released. Native success reconciliation remains optional and cannot change the native outcome. Browser hidden metadata identifies only the attempted draft revision and, after complete explicit recovery, its source revision.

Per-view task lists contain the actual native committing actions. Contact includes Save-to-Menu. Association-capable Article, Category, Contact, Newsfeed and Menu Item forms include `editAssociations`. Failed/cancelled native actions never retire a draft. Successful actions retire only the submitted/restored revision; later edits survive.

## Lifetime and concurrency invariants

| State | Trusted identity and allowed transition |
| --- | --- |
| Opened empty | Authorized native render fixes owner, ID, original revision, scope, projection and time. It carries no recovery payload. |
| Preserved | Current provider access/base/scope/projection checks precede a bounded selected payload. Higher revisions advance; an identical retry is idempotent. |
| Empty expired/purged | Only original, unexpired server-session admission can resume the same ID, after current native checks. No new guessed baseline is adopted. |
| Native-retired | Native success advances the submitted floor; later payload/recovered-source revisions remain recoverable. |
| Discarded | Owner-scoped terminal retirement prevents delayed requests from recreating the payload. |
| Admission lost/expired | Resume fails while local controls remain available for native saving. |
| Physically purged | Ordinary preservation never upserts an unknown ID. Admission has expired before its terminal rejection floor can be purged. |

The existing store serializes mutation by locking the native owner row. This protects absent-row resumption and multi-source retirement as well as ordinary revision updates. Existing draft-row locks alone do not protect an absent row. The current choice also serializes unrelated drafts for one owner and can contend with native user-row writes; it is a deliberate tradeoff requiring core maintainers' agreement and installed-load evaluation. Replacing it with a feature-owned mutex adds a storage/lifecycle contract; removing it without an equivalent mechanism would lose the proven guarantees. No such replacement is disguised as a routine cleanup in this change.

Scheduler cleanup discovers rows in batches and passes a monotonic deadline to the store, which checks the soft five-second budget before each individual delete. The run has a 1,000-row cap and native continuation. A database lock wait or one in-progress operation can exceed that soft budget. This is not a hard transaction timeout.

## Test and contribution boundaries

Integration uses the existing shared connection and `getSchemasToLoad()` datasets. The feature datasets retain actual installation definitions; registration tests separately compare fresh installation with upgrade DDL and administrator-choice preservation. Independent peer connections are restricted to real lock-contention checks. SQLite unit fixtures exercise portable behavior and do not substitute for MySQL/MariaDB/PostgreSQL acceptance.

Keep editor APIs, general FieldModel/view-level fixes and the draft feature independently reviewable when preparing commits/PRs. Do not claim browser acceptance from simulated DOM tests or PostgreSQL execution from SQLite conversion.
