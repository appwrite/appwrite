/**
 * Relationship column values. The API returns populated rows but accepts ids,
 * and the row editor carries both shapes plus its own empty placeholders; these
 * helpers are the single place that reconciles them.
 */

export function getRelationshipKind(columnInfo?: unknown): string | undefined {
  const col = columnInfo as
    | { relationType?: string; relationshipType?: string }
    | undefined

  return col?.relationshipType || col?.relationType || undefined
}

export function getRelationshipSide(columnInfo?: unknown): string | undefined {
  return (columnInfo as { side?: string } | undefined)?.side || undefined
}

/**
 * Whether this side of the relationship holds many related rows.
 *
 * The relation type alone is not enough: the child side of a one-to-many holds a
 * single row, and the child side of a many-to-one holds many.
 */
export function isMultiRelationship(columnInfo?: unknown): boolean {
  const relationType = getRelationshipKind(columnInfo)
  const side = getRelationshipSide(columnInfo)
  if (relationType === 'manyToMany') return true
  if (relationType === 'oneToMany') return side !== 'child'
  if (relationType === 'manyToOne') return side === 'child'
  return false
}

/** Related row id, from either an id string or a populated related row. */
export function toRelatedRowId(value: unknown): string | null {
  if (typeof value === 'string') return value.trim() ? value : null
  if (value && typeof value === 'object') {
    const id = (value as { $id?: unknown }).$id
    if (typeof id === 'string' && id) return id
  }
  return null
}

/** Current value as a list, so single- and multi-sided relationships share code. */
export function toRelatedRowList(value: unknown): unknown[] {
  if (Array.isArray(value)) return value
  if (value === null || value === undefined || value === '') return []
  return [value]
}

/**
 * A relationship value shaped for create/update: ids, with an emptied to-many
 * going out as `[]` rather than null.
 */
export function toRelationshipPayloadValue(
  value: unknown,
  columnInfo?: unknown,
): string[] | string | null {
  if (isMultiRelationship(columnInfo)) {
    return toRelatedRowList(value)
      .map((item) => toRelatedRowId(item))
      .filter((id): id is string => id !== null)
  }
  return toRelatedRowId(value)
}

/**
 * Label for a related row when the related table's schema is not loaded: the
 * first non-empty string field, else the id.
 */
export function relatedRowLabel(value: unknown): string {
  const id = toRelatedRowId(value)
  if (!value || typeof value !== 'object') return id ?? String(value ?? '')
  for (const [key, field] of Object.entries(value as Record<string, unknown>)) {
    if (key.startsWith('$')) continue
    if (typeof field === 'string' && field.trim()) return field
  }
  return id ?? ''
}
