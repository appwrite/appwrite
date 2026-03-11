# Table Filters and Search – Implementation

This doc ties the [Table Filters and Search Rebuild Guide](#overview) to the codebase: where the shared pieces live and how to migrate list views.

## Overview

- **Search:** URL param `search`; debounced input; pass to list APIs that support it.
- **Filters:** URL param `query` = JSON-encoded array of **compact keys** `{ c, o, v? }` (columnId, operatorKey, value). No tag stored; query strings and display tags are rebuilt from keys when decoding/rendering.
- **Pagination:** `page` (1-based), optional `limit`. Reset page when search or filters change.
- **Single source of truth:** URL. Same encoding/decoding everywhere; shareable links and back/forward work.

## Shared library: `src/lib/table-filters/`

| File | Purpose |
|------|--------|
| **types.ts** | `FilterTagValue`, `CompactFilterKey`, `FilterMap`, `FilterColumn`, `FilterColumnType`, `FilterOperatorDef`. |
| **url.ts** | `getSearch`, `getPage`, `getLimit`, `getQueryParam`, `queryParamToMap`, `mapToQueryParam`, `buildListSearchParams`. |
| **operators.ts** | `FILTER_OPERATORS`, `getOperatorsForType`, `buildFilterQueryString`, `buildFilterTag`, `buildFilterTagFromCompactKey`. Supports comparison (equal, not equal, &lt;, &lt;=, &gt;, &gt;=, between, not between), string (starts/ends with, not starts/ends with, contains, not contains, search, not search, regex), null (is null, is not null), existence (exists, not exists). See [Appwrite Queries](https://appwrite.io/docs/products/databases/queries). |
| **search-schema.ts** | Zod `listSearchSchema` for route `validateSearch` (search, query, page, limit). |
| **index.ts** | Re-exports. |

Use the barrel: `import { getSearch, queryParamToMap, listSearchSchema, ... } from '@/lib/table-filters'`.

## Load flow (per guide §6)

1. Read URL: `getSearch(url)`, `getPage(url)`, `getLimit(url)`, `getQueryParam(url)`.
2. Decode filters: `parsedQueries = queryParamToMap(queryParam)`.
3. Build query array: `[Query.limit(limit), Query.offset((page-1)*limit), Query.orderDesc('...'), ...parsedQueries.values()]`.
4. Call list API: `client.<resource>.list({ queries: queryArray, search })`.
5. When search or filters change, navigate with updated params and **no** `page` (or `page=1`).

## Route migration

1. **Add list search schema** to the route:
   ```ts
   import { listSearchSchema } from '@/lib/table-filters'
   validateSearch: listSearchSchema,
   ```
2. **In the page/View:** Read URL via `useSearch()` (validated) or `new URL(location.href)` and pass to `getSearch`, `getPage`, `getLimit`, `queryParamToMap`.
3. **Sync search to URL:** On change, navigate with `buildListSearchParams({ search, query, page, limit })`; drop `page` when search or query changes.
4. **Debounce search input** (e.g. 250–300 ms) before updating URL.

## Filter UI (per guide §5)

- **Filters button:** Opens popover with column select, operator select, value input; “Add condition”, “Clear all”, “Apply”.
- **Apply:** Commit in-panel state to `FilterMap`, then navigate with `mapToQueryParam(map)` in `query` param.
- **Active tags:** One tag per filter; remove = `map.delete(compactKey)` then apply (update URL). Tags are built from compact keys via `buildFilterTagFromCompactKey`.
- **Filter config:** Either **schema-driven** (columns from API) or **predefined** (config/registry). Both must output the same `FilterColumn[]` contract (id, title, type, format?, elements?, array?, filter?).

## Predefined filter config example

For a fixed-attribute list (e.g. users), define columns and pass to the shared filter UI:

```ts
import type { FilterColumn } from '@/lib/table-filters'

export const usersFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'email', title: 'Email', type: 'string' },
  { id: 'status', title: 'Status', type: 'enum', elements: [{ value: 'enabled', label: 'Enabled' }, { value: 'disabled', label: 'Disabled' }] },
  { id: '$createdAt', title: 'Created', type: 'datetime' },
]
```

Schema-driven lists (e.g. table rows): map entity fields to `FilterColumn[]` and optionally add system columns (`$id`, `$createdAt`, `$updatedAt`).

## Empty states (per guide §8)

- No results + filters active: “No &lt;resource&gt; match your filters.” + “Clear filters” (clear map, apply).
- No results + search active: “No results for ‘&lt;term&gt;’.” + “Clear search” or “Clear all”.

## Checklist (per guide §11)

| Area | Implementation |
|------|----------------|
| Search | URL param `search`; debounced input; sync from URL on load; pass to list APIs that support it; clear page when search changes. |
| Filter state | `Map<CompactFilterKey, string>`; URL stores compact keys only; encode/decode with `mapToQueryParam` / `queryParamToMap`. |
| Apply | Navigate to current path with `?query=...` (and search, page, limit as needed). |
| Operators | `FILTER_OPERATORS` + `getOperatorsForType`; build strings with `buildFilterQueryString`. |
| UI | Filters button + popover (column, operator, value; Add condition, Clear all, Apply); tag list with remove and Clear all. |
| Load | Use `getSearch`, `getQueryParam`, `getPage`, `getLimit`; `queryParamToMap`; build queries; call `list({ queries, search })`. |
| Empty states | EmptyFilter (clear filters), EmptySearch (no results for term). |
| Filter config | Single column contract; schema-driven or predefined; same UI and URL for both. |
