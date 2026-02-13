/**
 * Text-like column types (string, varchar, text, mediumtext, longtext).
 * Used for display name eligibility, filters, row cell component selection.
 */
export const TEXT_TYPES = [
  'string',
  'varchar',
  'text',
  'mediumtext',
  'longtext',
] as const

export type TextColumnType = (typeof TEXT_TYPES)[number]

/**
 * Returns true for any string-like column type.
 */
export function isTextType(type: string | undefined): boolean {
  if (!type) return false
  return TEXT_TYPES.includes(type.toLowerCase() as TextColumnType)
}

/**
 * Column types that have a "size" attribute (shown in columns list, used for index key length).
 * Only string and varchar (not format variants like email/url/enum, not $id).
 */
export const SIZED_STRING_TYPES = ['string', 'varchar'] as const

export function hasColumnSize(type: string | undefined): boolean {
  if (!type) return false
  return SIZED_STRING_TYPES.includes(type.toLowerCase() as 'string' | 'varchar')
}
