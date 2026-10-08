/**
 * Table filters and search – shared types (see TABLE_FILTERS_AND_SEARCH.md).
 *
 * Filter state is a map: key = CompactFilterKey (column, operator, value), value = Appwrite Query string.
 * URL param `query` stores only compact keys; query strings and display tags are rebuilt on decode/render.
 */

/** Display tag for one filter condition (shown in UI as e.g. "**name** equal **John**"). */
export interface FilterTagValue {
  tag: string
  value: string | number | string[]
}

/**
 * Compact filter key for URL storage (no tag string).
 * Stored in URL as array of these; tag is derived from column + operator + value when rendering.
 */
export interface CompactFilterKey {
  /** Column/attribute id (e.g. "name", "$createdAt") */
  c: string
  /** Operator key (e.g. "equal", "greaterThan") */
  o: string
  /** Value; omit for no-value operators (isNull, isNotNull, exists, notExists) */
  v?: string | number | boolean | string[]
}

/**
 * Filter state: map from compact key to Query string.
 * URL stores only compact keys; query strings are rebuilt on decode.
 */
export type FilterMap = Map<CompactFilterKey, string>

/**
 * Filter column contract – same for schema-driven and predefined lists.
 * Drives operator options and value widget (text, number, date, select, etc.).
 */
export interface FilterColumn {
  /** Attribute/key used in Query calls (e.g. name, $createdAt, status). */
  id: string
  /** Label shown in the UI (e.g. "Name", "Created", "Status"). */
  title: string
  /** Data type for allowed operators and value input. */
  type: FilterColumnType
  /** e.g. "enum" when value is one of a fixed set. */
  format?: string
  /** For enum/select: allowed values and display labels. */
  elements?: Array<{
    value: string | number
    label: string
    description?: string
  }>
  /**
   * For enum columns: when false or unset, the attribute is required (no "is null" / "is not null").
   * When true, the attribute can be null (show nullability operators).
   */
  optional?: boolean
  /** If true, value can be an array (multi-select, contains, etc.). */
  array?: boolean
  /** If false, hide this column from the filter list. */
  filter?: boolean
  /**
   * If true, this column is part of a fulltext index and "search" / "does not match search"
   * operators are allowed. Only set when index data is available (e.g. database table rows).
   */
  fulltextSearchable?: boolean
  /**
   * When true, user enters the document attribute key (and value type) in the filter form.
   * Used for Documents DB where payload keys are not fixed by schema. Omit on Tables DB.
   */
  customAttributeSlot?: boolean
  /** When set, only these operator keys are shown for this column (e.g. usage API allowlists). */
  allowedOperators?: string[]
}

export type FilterColumnType =
  | 'string'
  | 'integer'
  | 'bigint'
  | 'double'
  | 'boolean'
  | 'datetime'
  | 'enum'
  | 'point'
  | 'linestring'
  | 'polygon'
  | 'varchar'
  | 'text'

/** Operator key used in config; label is for UI. */
export interface FilterOperatorDef {
  key: string
  label: string
  /** Column types this operator applies to. */
  types: FilterColumnType[]
  /** No value input (e.g. is null / is not null). */
  noValue?: boolean
}
