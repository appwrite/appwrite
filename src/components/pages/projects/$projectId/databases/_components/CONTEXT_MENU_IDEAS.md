# Context menu ideas for resources

Use this as a checklist when adding context menus to tables, databases, buckets, functions, and other resources.

## Implemented (Table context menu)

- **Duplicate structure** – Create a new resource with the same structure (e.g. table columns).
- **Open in new tab** – `window.open(href, '_blank', 'noopener,noreferrer')`.
- **Open in new window** – Same with optional window features (e.g. `width=1200,height=800`).
- **Copy [resource] ID** – Copy `$id` to clipboard.
- **Copy link** – Copy the current page URL to clipboard.
- **View as JSON** – Show resource payload in a dialog with copy.
- **Copy as JSON** – Copy the resource object as JSON to clipboard (no dialog).

## Suggested for other resources

### Generic (most resources)

- **Copy ID** – Same as above, generic label. ✓ (table: “Copy table ID”)
- **Copy link** – Copy the current page URL to clipboard. ✓
- **View as JSON** / **Copy as JSON** – View or copy the resource object. ✓
- **Open in new tab** / **Open in new window** – For detail pages.
- **Refresh** – Refetch / invalidate and refetch (e.g. for stale data).

### Create / duplicate

- **Duplicate** – Clone resource (e.g. duplicate function, duplicate bucket).
- **Duplicate structure** – Clone schema only (like tables: columns, no data).
- **Create similar** – Open create dialog pre-filled from this resource.

### Navigation

- **Go to [parent]** – e.g. “Go to database”, “Go to project”.
- **Open [related resource]** – e.g. “Open bucket”, “Open deployment”.

### Actions (where applicable)

- **Rename** – Open rename dialog or inline edit.
- **Settings** / **Security** – Link to settings/security tab.
- **Enable / Disable** – Toggle with confirmation.
- **Delete** – With confirmation (consider grouping under “Danger” or separator).

### Export / share

- **Export** – CSV, JSON, backup (resource-specific).
- **Copy share link** – Copy URL with optional query params.
- **Copy API snippet** – e.g. cURL or SDK snippet for this resource.

### Reusable component pattern

For a shared context menu across resources, consider:

- A **`ResourceContextMenu`** that accepts:
  - `href` – for “Open in new tab/window”.
  - `resource` – object for “View as JSON” and “Copy ID”.
  - `resourceLabel` – e.g. “table”, “bucket” (for “Copy table ID”).
  - `customItems` – array of `{ label, icon, onSelect }` for resource-specific items (Duplicate structure, Settings, Delete, etc.).

Then each resource (tables, buckets, functions, etc.) composes the generic items plus its custom items.
