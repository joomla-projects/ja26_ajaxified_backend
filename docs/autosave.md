# Administrator recovery drafts

Auto save preserves selected incomplete form values as a private recovery draft. Native Save and Apply still save the actual item, run Joomla's validation and plugins, and control navigation. A draft does not publish an item, change its workflow, or create a content-history version.

## Editing and recovery

Supported administrator forms activate automatically. The compact status shows **Saving...** while protection is pending and **Saved** only after the server acknowledges a private snapshot. Its help describes the selected fields. Settings outside that scope may need to be entered again after recovery. Passwords, uploads, disabled controls and unknown complex widgets are excluded.

An unchanged form does not write periodic snapshots. The maximum attempt interval defaults to 30 seconds; the component's **Maximum Attempt Interval (seconds)** parameter changes that deadline. Network failures, unavailable controls and suspended tabs can delay protection. Use native saving when immediate persistence is needed.

When **Autosaved Draft Available** appears, choose a draft and **Review draft** to compare the current saved values with the recoverable values. **Restore Draft** changes supported controls in the current form. It does not save the item. If local edits would be replaced, Joomla asks for confirmation. Save the recovered item normally after checking it.

**Continue Without Restoring** keeps the current form values. It does not delete the draft. **Discard Draft** deletes the selected private recovery payload after confirmation and leaves the item unchanged. **Retry Draft Save** retries uncertain protection. A failed or cancelled native submission leaves editing available; cancelled submission also releases the automatic draft writer.

A stale draft is disclosed for explicit comparison. Auto save does not silently overwrite a changed item or automatically adopt a new canonical baseline. A draft from an uncertain creation has no confirmed item identity; check existing items before restoring it into a new item.

## Availability and retention

Only the draft owner can discover or read it, and current native permissions, checkout and editing scope are checked again. Losing access or changing the session can stop protection or recovery without erasing displayed local values. Normal saving still uses Joomla's own access and session checks.

Empty render bindings last one hour. A preserved payload lasts seven idle days, with a 30-day absolute limit from the original form opening. Retirement metadata lasts one day to reject delayed writes. The maximum stored payload is 4 MiB; the JSON request limit is 5 MiB. These bounds protect a background persistence service and its storage, rather than changing native field validation.

An untouched form can resume an expired empty binding only while its original server-session admission remains valid and its canonical base, scope and projection still match. Admission lasts at most one day, and the session retains at most 128 admissions. If that provenance expires or is evicted, keep the local work with native Save and then open a fresh form. An unchanged-form heartbeat does not extend retention.

Expired data becomes inaccessible immediately. Physical removal depends on the **Autosave** task plugin and its hourly **Autosave draft cleanup** scheduled task. Check that the task is enabled and that the site's Scheduler runs normally. Cleanup uses native continuation for a backlog and never deletes canonical content.

If optional draft assets cannot initialize, the native form continues without draft controls. If storage or a provider fails after mounting, the status reports unavailability. Missing support is not an acknowledgement that edits were preserved.

## Supported native contexts

Article; Category; Banner and Banner Client; Contact; Field and Field Group; Guided Tour and Step; Smart Search Filter; Menu Item and Menu Type; Content Language and Language Override; Module; Newsfeed; Redirect Link; Tag; Template Style; User Group, Access Level and User Note; Workflow, Stage and Transition.

Template Style supports existing styles. New User account forms and password values are not included. Dynamic parameters participate only when their finalized native form declares a supported, safe control.

For extension integration and lifecycle decisions, see [the developer contract](autosave-developer.md). For installed acceptance, see [the browser checklist](autosave-browser-checklist.md).
