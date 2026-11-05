export const CUSTOM_COLLECTION_INDEX_ATTRIBUTE_VALUE =
  '__collectionCustomIndexAttribute__' as const

export type IndexableCollectionField = {
  key: string
  type: string
  required?: boolean
  array?: boolean
}

export const COLLECTION_EXPORT_SYSTEM_FIELDS = [
  '$id',
  '$createdAt',
  '$updatedAt',
] as const

export function getCollectionAttributeKey(
  raw: Record<string, unknown>,
): string {
  return String(raw.key ?? raw.name ?? raw.attribute ?? raw.$id ?? '').trim()
}

export function collectDocumentPayloadFieldKeys(
  rows: Record<string, unknown>[],
): string[] {
  const keys = new Set<string>()
  for (const row of rows) {
    for (const key of Object.keys(row)) {
      if (!key.startsWith('$')) keys.add(key)
    }
  }
  return [...keys].sort((a, b) => a.localeCompare(b))
}

function inferFieldTypeFromDocumentValues(
  rows: Record<string, unknown>[],
  key: string,
): string {
  for (const row of rows) {
    const value = row[key]
    if (value === null || value === undefined) continue
    if (typeof value === 'boolean') return 'boolean'
    if (typeof value === 'number') {
      return Number.isInteger(value) ? 'integer' : 'float'
    }
    if (typeof value === 'string') return 'string'
  }
  return 'string'
}

/**
 * Merge collection schema attributes with field keys discovered on documents.
 * DocumentsDB collections are often schemaless: fields exist on documents even when
 * `getCollection().attributes` is empty.
 */
export function buildCollectionIndexableAttributes(
  schemaAttributes: unknown[] | undefined,
  documentRows: Record<string, unknown>[],
): IndexableCollectionField[] {
  const byKey = new Map<string, IndexableCollectionField>()

  if (Array.isArray(schemaAttributes)) {
    for (const raw of schemaAttributes) {
      const attribute = raw as Record<string, unknown>
      const key = getCollectionAttributeKey(attribute)
      if (!key || key.startsWith('$')) continue
      byKey.set(key, {
        key,
        type: String(attribute.type ?? 'string'),
        required: attribute.required as boolean | undefined,
        array: Boolean(attribute.array),
      })
    }
  }

  for (const key of collectDocumentPayloadFieldKeys(documentRows)) {
    if (byKey.has(key)) continue
    byKey.set(key, {
      key,
      type: inferFieldTypeFromDocumentValues(documentRows, key),
      array: false,
    })
  }

  return [...byKey.values()].sort((a, b) => a.key.localeCompare(b.key))
}

/**
 * Column keys for CSV export on Documents/Vectors collections: system metadata
 * fields plus schema attributes and keys discovered on sample documents.
 */
export function buildCollectionExportColumnKeys(
  schemaAttributes: unknown[] | undefined,
  documentRows: Record<string, unknown>[],
): string[] {
  const keys = new Set<string>(COLLECTION_EXPORT_SYSTEM_FIELDS)

  if (Array.isArray(schemaAttributes)) {
    for (const raw of schemaAttributes) {
      const key = getCollectionAttributeKey(raw as Record<string, unknown>)
      if (!key || key.startsWith('$')) continue
      keys.add(key)
    }
  }

  for (const key of collectDocumentPayloadFieldKeys(documentRows)) {
    keys.add(key)
  }

  const system = COLLECTION_EXPORT_SYSTEM_FIELDS.filter((key) => keys.has(key))
  const rest = [...keys]
    .filter(
      (key) =>
        !COLLECTION_EXPORT_SYSTEM_FIELDS.includes(
          key as (typeof COLLECTION_EXPORT_SYSTEM_FIELDS)[number],
        ),
    )
    .sort((a, b) => a.localeCompare(b))

  return [...system, ...rest]
}
