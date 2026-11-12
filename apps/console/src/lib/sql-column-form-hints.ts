const FALLBACK_COLUMN_NAME = 'column_name'

export function getSqlColumnCheckExample(
  columnName: string,
  typeGroup: string,
): string {
  const column = columnName.trim() || FALLBACK_COLUMN_NAME
  switch (typeGroup) {
    case 'Integer':
    case 'Decimal':
      return `${column} > 0`
    case 'Boolean':
      return `${column} = true`
    default:
      return `length(${column}) < 500`
  }
}

/** True when a check looks like `col = 5` (unquoted number). */
export function isTextColumnComparedToNumber(expression: string): boolean {
  return /^\s*[\w."`]+\s*=\s*\d+\s*$/.test(expression)
}

/**
 * True when Unique/PK + NOT NULL would assign the same constant (or empty)
 * value to every existing row. Expression defaults such as UUID() can differ.
 */
export function uniqueNotNullUsesConstantDefault(options: {
  unique: boolean
  nullable: boolean
  defaultValue: string
  defaultKind: 'value' | 'expression'
}): boolean {
  if (!options.unique || options.nullable) return false
  if (options.defaultKind === 'expression' && options.defaultValue.trim()) {
    return false
  }
  return true
}
