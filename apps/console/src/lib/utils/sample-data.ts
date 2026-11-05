import { faker } from '@faker-js/faker'
import { ID } from '@appwrite.io/console'

/**
 * Normalize a value that may be number or bigint (e.g. from API) to a safe number.
 * JSON and faker expect Number; BigInt causes "can't convert big int value to number".
 */
function toSafeNumber(value: number | bigint | null | undefined): number {
  if (value === null || value === undefined) return 0
  if (typeof value === 'bigint') {
    const n = Number(value)
    if (!Number.isFinite(n)) return 0
    return Math.max(
      -Number.MAX_SAFE_INTEGER,
      Math.min(Number.MAX_SAFE_INTEGER, n),
    )
  }
  return Number(value)
}

export interface Column {
  key: string
  type: string
  size?: number | null
  required?: boolean
  array?: boolean
  default?: string | null
  format?: string
  elements?: string[]
  min?: number | null
  max?: number | null
  status?: string
}

/**
 * Generate a value for a string column based on its format and name
 */
function generateStringValue(column: Column): string {
  const format = column.format?.toLowerCase()
  const columnName = column.key.toLowerCase()

  // Handle specific formats
  if (format === 'email') {
    return faker.internet.email()
  }
  if (format === 'ip') {
    return faker.internet.ip()
  }
  if (format === 'url') {
    return faker.internet.url()
  }
  if (format === 'enum' && column.elements && column.elements.length > 0) {
    return faker.helpers.arrayElement(column.elements)
  }

  // Smart name matching
  if (
    columnName.includes('name') &&
    !columnName.includes('username') &&
    !columnName.includes('domain')
  ) {
    return faker.person.fullName()
  }
  if (columnName.includes('email')) {
    return faker.internet.email()
  }
  if (columnName.includes('title')) {
    return faker.lorem.words({ min: 1, max: 3 })
  }
  if (columnName.includes('description') || columnName.includes('content')) {
    return faker.lorem.paragraph()
  }
  if (columnName.includes('address')) {
    return faker.location.streetAddress()
  }
  if (columnName.includes('city')) {
    return faker.location.city()
  }
  if (columnName.includes('country')) {
    return faker.location.country()
  }
  if (columnName.includes('company')) {
    return faker.company.name()
  }

  // Default: random words
  const words = faker.lorem.words({ min: 1, max: 5 })
  const maxSize =
    column.size != null ? Math.min(toSafeNumber(column.size), 1000) : 1000
  return words.length > maxSize ? words.slice(0, maxSize) : words
}

/**
 * Generate a value for an integer column
 */
function generateIntegerValue(column: Column): number {
  let min = toSafeNumber(column.min ?? 0)
  let max = toSafeNumber(column.max ?? min + 100)

  // If no max specified, use a reasonable default
  if (column.max === null || column.max === undefined) {
    max = min + 100
  }

  // Cap at safe integer max
  max = Math.min(max, Number.MAX_SAFE_INTEGER)
  min = Math.max(min, -Number.MAX_SAFE_INTEGER)

  return faker.number.int({ min, max })
}

/**
 * Generate a value for a double/float column
 */
function generateDoubleValue(column: Column): number {
  let min = toSafeNumber(column.min ?? 0)
  let max = toSafeNumber(column.max ?? min + 100)

  // If no max specified, use a reasonable default
  if (column.max === null || column.max === undefined) {
    max = min + 100
  }

  // Cap at safe integer max
  max = Math.min(max, Number.MAX_SAFE_INTEGER)
  min = Math.max(min, -Number.MAX_SAFE_INTEGER)

  return parseFloat(
    faker.number.float({ min, max, fractionDigits: 4 }).toFixed(4),
  )
}

/**
 * Generate a value for a boolean column
 */
function generateBooleanValue(): boolean {
  return faker.datatype.boolean()
}

/**
 * Generate a value for a datetime column
 */
function generateDatetimeValue(): string {
  // Random date within the last 365 days
  const daysAgo = faker.number.int({ min: 0, max: 365 })
  const date = new Date()
  date.setDate(date.getDate() - daysAgo)
  return date.toISOString()
}

/**
 * Generate a value for a point column (geographic coordinates)
 */
function generatePointValue(): [number, number] {
  return [faker.location.longitude(), faker.location.latitude()]
}

/**
 * Generate a value for a linestring column
 */
function generateLinestringValue(): Array<[number, number]> {
  // Generate 5 coordinate pairs
  return Array.from({ length: 5 }, () => [
    faker.location.longitude(),
    faker.location.latitude(),
  ])
}

/**
 * Generate a value for a polygon column
 */
function generatePolygonValue(): Array<[number, number]> {
  // Generate a closed polygon (first point repeated at end)
  const points = Array.from({ length: 4 }, () => [
    faker.location.longitude(),
    faker.location.latitude(),
  ])
  // Close the polygon by repeating the first point
  return [...points, points[0]]
}

/**
 * Generate a value for an array column
 */
function generateArrayValue(column: Column, baseType: string): unknown[] {
  const itemCount = faker.number.int({ min: 1, max: 5 })
  const baseColumn = { ...column, array: false }

  switch (baseType) {
    case 'string':
      return Array.from({ length: itemCount }, () =>
        generateStringValue(baseColumn),
      )
    case 'integer':
    case 'bigint':
      return Array.from({ length: itemCount }, () =>
        generateIntegerValue(baseColumn),
      )
    case 'float':
    case 'double':
      return Array.from({ length: itemCount }, () =>
        generateDoubleValue(baseColumn),
      )
    case 'boolean':
      return Array.from({ length: itemCount }, () => generateBooleanValue())
    case 'datetime':
      return Array.from({ length: itemCount }, () => generateDatetimeValue())
    default:
      return Array.from({ length: itemCount }, () =>
        generateStringValue(baseColumn),
      )
  }
}

/**
 * Generate a single row of sample data based on column definitions
 */
export function generateSampleRow(columns: Column[]): Record<string, unknown> {
  const row: Record<string, unknown> = {
    $id: ID.unique(),
  }

  // Filter columns: exclude relationship columns and system columns, include available columns (or columns without status set)
  const validColumns = columns.filter((col) => {
    const colKey = col.key
    // Exclude system columns (starting with $)
    if (colKey && colKey.startsWith('$')) {
      return false
    }
    // Exclude relationship columns
    if (col.type === 'relationship') {
      return false
    }
    // Include columns that are available or don't have a status field (default to available)
    return !col.status || col.status === 'available'
  })

  for (const column of validColumns) {
    let value: unknown

    if (column.array) {
      // Extract base type (remove 'array' prefix if present)
      const baseType = column.type.replace(/^array/, '').toLowerCase()
      value = generateArrayValue(column, baseType)
    } else {
      switch (column.type.toLowerCase()) {
        case 'string':
        case 'varchar':
        case 'email':
        case 'url':
        case 'ip':
        case 'enum':
          value = generateStringValue(column)
          // Truncate to column size limit (varchar/string use size; cap at 1000 for faker sanity)
          if (column.size != null && typeof value === 'string') {
            const maxSize = Math.min(toSafeNumber(column.size), 1000)
            value = value.slice(0, maxSize)
          }
          break
        case 'text':
        case 'mediumtext':
        case 'longtext':
          value = generateStringValue(column)
          // Reasonable length for faker; backend enforces max
          if (typeof value === 'string' && value.length > 1000) {
            value = value.slice(0, 1000)
          }
          break
        case 'integer':
        case 'bigint':
          value = generateIntegerValue(column)
          break
        case 'float':
        case 'double':
          value = generateDoubleValue(column)
          break
        case 'boolean':
          value = generateBooleanValue()
          break
        case 'datetime':
          value = generateDatetimeValue()
          break
        case 'point':
          value = generatePointValue()
          break
        case 'linestring':
          value = generateLinestringValue()
          break
        case 'polygon':
          value = generatePolygonValue()
          break
        default:
          // Default to string for unknown types (e.g. new text types)
          value = generateStringValue(column)
          if (column.size != null && typeof value === 'string') {
            const maxSize = Math.min(toSafeNumber(column.size), 1000)
            value = value.slice(0, maxSize)
          } else if (typeof value === 'string' && value.length > 1000) {
            value = value.slice(0, 1000)
          }
      }
    }

    row[column.key] = value
  }

  return row
}

/**
 * Generate multiple rows of sample data
 */
export function generateSampleRows(
  columns: Column[],
  count: number,
): Record<string, unknown>[] {
  return Array.from({ length: count }, () => generateSampleRow(columns))
}
