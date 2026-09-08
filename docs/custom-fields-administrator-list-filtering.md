# Administrator Custom Field filtering

Custom Field filtering is opt-in. Edit an Article Custom Field, open **Options → Form Options**, choose **Show in Administrator List Filters**, and save it. Reopen the field to verify that the setting persisted. The field appears in the Articles SearchTools panel when all of these conditions hold:

- its context is `com_content.article`;
- it is published, visible to the current user, assigned to language `*`, globally category applicable, and not subform-only;
- its enabled field plugin declares filter options.

The first release supports the List, Radio, and Checkboxes plugins. Selections use `filter[customfield_<id>][]`. Values within one field are ORed; different fields are ANDed. Filters match persisted `#__fields_values` rows. Display defaults do not match when no row exists.

Comparison uses the database's native equality. Case, accents, Unicode equivalence, and trailing spaces can therefore follow the configured database collation. Historical values that are database-equal to a current option can match it.

## Extension integration

A participating list model obtains `FieldsFilterService` from the booted `com_fields` component. It prepares flat filter state, contributes the returned fingerprint to `getStoreId()`, augments the filter Form, merges dynamic active filters, and applies the prepared result to its query with a trusted item-key expression and `integer` or `string` identity kind. Raw browser input must never be passed to the query applicator.

An option-backed Fields plugin subscribes to `onCustomFieldsGetFilterOptions` and adds one declaration containing `options` (ordered `value`/`text` string pairs). Every administrator Custom Field filter is multiselect; selections within one field are ORed independently of the field's editing cardinality. Plugins supply no SQL or request names. No declaration means unsupported; conflicting or malformed declarations make that field unavailable.

```php
public function declareFilterOptions(GetFilterOptionsEvent $event): void
{
    if (!$this->isTypeSupported($event->getField()->type)) {
        return;
    }

    $event->addResult([
        'options' => [
            ['value' => 'north', 'text' => 'North'],
            ['value' => 'south', 'text' => 'South'],
        ],
    ]);
}
```

The prepared result is immutable and contains eligible control metadata, canonical ID-to-string-array selections, structured issues, rejection state, and a deterministic query fingerprint. The host classifies explicit requests, reconciles session state, resets pagination, reports generic messages, and binds the effective state. Complete explicit submissions replace remembered dynamic selections; an omitted multiselect is cleared. Without an explicit filter submission, remembered selections are restored after revalidation. Invalid explicit requests fail closed and are not remembered. Invalid programmatic state raises an exception; stale remembered values are removed once and warned once.

Explicit invalid selections fail closed and show a generic error. Stale remembered selections are removed with a warning. Limits are 32 fields, 100 values per field, 256 total values, 1,024 bytes per token, 64 KiB of selected token data, and 1,000 declared options per field. Limits reject rather than truncate.

The query uses one correlated `EXISTS` per active field, so duplicate value rows cannot multiply Articles or totals. Integer host keys are converted through the database query abstraction before comparison with the textual `item_id`; string-key hosts supply their trusted expression directly. Values `0` and `01` remain distinct request tokens. Persisted comparison remains database-native, including collation behavior; absent/NULL rows and display defaults are not synthesized.

No schema change is included. The existing schema has separate `field_id` and `item_id` indexes, not a composite index. A composite index and its column order must be justified by disposable MySQL/PostgreSQL query-plan measurements before inclusion; do not benchmark against a populated site database. PHP `max_input_vars` and request-body limits apply before Joomla can validate the parsed map, so deployments must size those limits for the documented bounds. The application never truncates an observed selection map, but it cannot reconstruct input removed by PHP before parsing.

## Browser acceptance

Run the administrator system tests using the repository's normal Cypress environment:

```text
npm run cypress:run -- --spec tests/System/integration/administrator/components/com_content/CustomFieldFilters.cy.js
```

Manual acceptance:

1. Create two global, language-All Article fields using supported plugins, enable **Options → Form Options → Show in Administrator List Filters**, save, and reopen each field to verify that the setting persisted.
2. Give Region the values India/Japan and Priority the value High.
3. Persist corresponding values on several articles.
4. Open Articles and confirm both controls are labelled and keyboard operable.
5. Select India and Japan plus High and a normal Published filter; confirm AND/OR semantics, totals, ordering, and pagination.
6. Clear filters and confirm all articles return and the page offset resets.
7. Repeat with Ajax enabled and disabled.
8. Remove an option or revoke field access and confirm remembered state is cleared with a generic warning.
9. Submit a forged token and confirm a generic error and no broadened successful result.

The implementation adds no Custom-Field-specific JavaScript and has no Autosave dependency.
