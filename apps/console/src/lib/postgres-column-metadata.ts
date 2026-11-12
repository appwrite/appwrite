export type ParsedPostgresCheckConstraint = {
  name: string
  expression: string
}

export type ParsedPostgresForeignKey = {
  name: string
  reference: string
}

export type ParsedPostgresForeignKeyReference = {
  schema: string
  table: string
  column: string
}

export type PostgresForeignKeyState = {
  enabled: boolean
  schema: string
  table: string
  column: string
}

const CHECK_ENTRY_SEPARATOR = '\n'
const CHECK_NAME_SEPARATOR = '::'

export function parsePostgresColumnCheckConstraints(
  raw: string | null | undefined,
): ParsedPostgresCheckConstraint[] {
  if (!raw?.trim()) return []

  return raw
    .split(CHECK_ENTRY_SEPARATOR)
    .map((entry) => entry.trim())
    .filter(Boolean)
    .map((entry) => {
      const separatorIndex = entry.indexOf(CHECK_NAME_SEPARATOR)
      if (separatorIndex === -1) {
        return { name: '', expression: entry }
      }
      return {
        name: entry.slice(0, separatorIndex).trim(),
        expression: entry.slice(separatorIndex + CHECK_NAME_SEPARATOR.length).trim(),
      }
    })
    .filter((entry) => entry.expression)
}

export function formatPostgresColumnCheckConstraints(
  checks: ParsedPostgresCheckConstraint[],
): string | null {
  if (checks.length === 0) return null
  return checks
    .map((check) =>
      check.name
        ? `${check.name}${CHECK_NAME_SEPARATOR}${check.expression}`
        : check.expression,
    )
    .join(CHECK_ENTRY_SEPARATOR)
}

export function parsePostgresColumnForeignKeys(
  raw: string | null | undefined,
): ParsedPostgresForeignKey[] {
  if (!raw?.trim()) return []

  return raw
    .split(CHECK_ENTRY_SEPARATOR)
    .map((entry) => entry.trim())
    .filter(Boolean)
    .map((entry) => {
      const separatorIndex = entry.indexOf(CHECK_NAME_SEPARATOR)
      if (separatorIndex === -1) {
        return { name: '', reference: entry }
      }
      return {
        name: entry.slice(0, separatorIndex).trim(),
        reference: entry.slice(separatorIndex + CHECK_NAME_SEPARATOR.length).trim(),
      }
    })
    .filter((entry) => entry.reference)
}

export function formatPostgresColumnForeignKeys(
  foreignKeys: ParsedPostgresForeignKey[],
): string | null {
  if (foreignKeys.length === 0) return null
  return foreignKeys
    .map((foreignKey) =>
      foreignKey.name
        ? `${foreignKey.name}${CHECK_NAME_SEPARATOR}${foreignKey.reference}`
        : foreignKey.reference,
    )
    .join(CHECK_ENTRY_SEPARATOR)
}

export function formatPostgresForeignKeyReference(
  reference: ParsedPostgresForeignKeyReference,
): string {
  return `${reference.schema}.${reference.table}(${reference.column})`
}

export function createEmptyPostgresForeignKeyState(
  defaultSchema: string,
): PostgresForeignKeyState {
  return {
    enabled: false,
    schema: defaultSchema,
    table: '',
    column: '',
  }
}

export function parsePostgresForeignKeyStateFromRow(
  raw: string | null | undefined,
  defaultSchema: string,
): PostgresForeignKeyState {
  const foreignKeys = parsePostgresColumnForeignKeys(raw)
  if (foreignKeys.length === 0) {
    return createEmptyPostgresForeignKeyState(defaultSchema)
  }

  const parsedReference = parsePostgresForeignKeyReference(
    foreignKeys[0].reference,
    defaultSchema,
  )
  if (!parsedReference) {
    return createEmptyPostgresForeignKeyState(defaultSchema)
  }

  return {
    enabled: true,
    schema: parsedReference.schema,
    table: parsedReference.table,
    column: parsedReference.column,
  }
}

export function postgresForeignKeyStateToReference(
  state: PostgresForeignKeyState,
): ParsedPostgresForeignKeyReference | null {
  if (!state.enabled) return null

  const schema = state.schema.trim()
  const table = state.table.trim()
  const column = state.column.trim()
  if (!schema || !table || !column) return null

  return { schema, table, column }
}

export function getPostgresForeignKeyReferenceForCompare(
  state: PostgresForeignKeyState,
): string {
  const reference = postgresForeignKeyStateToReference(state)
  if (!reference) return ''
  return formatPostgresForeignKeyReference(reference)
}

export function validatePostgresForeignKeyState(
  state: PostgresForeignKeyState,
): string | null {
  if (!state.enabled) return null
  if (!state.schema.trim()) return 'Select a schema for the foreign key.'
  if (!state.table.trim()) return 'Select a referenced table for the foreign key.'
  if (!state.column.trim()) return 'Select a referenced column for the foreign key.'
  return null
}

export function parsePostgresForeignKeyReference(
  input: string,
  defaultSchema: string,
): ParsedPostgresForeignKeyReference | null {
  const trimmed = input.trim()
  if (!trimmed) return null

  const openParen = trimmed.indexOf('(')
  const closeParen = trimmed.lastIndexOf(')')
  if (openParen === -1 || closeParen <= openParen) return null

  const column = trimmed.slice(openParen + 1, closeParen).trim()
  const tablePart = trimmed.slice(0, openParen).trim()
  if (!column || !tablePart) return null

  const lastDot = tablePart.lastIndexOf('.')
  if (lastDot === -1) {
    return {
      schema: defaultSchema,
      table: tablePart,
      column,
    }
  }

  const schema = tablePart.slice(0, lastDot).trim()
  const table = tablePart.slice(lastDot + 1).trim()
  if (!schema || !table) return null

  return { schema, table, column }
}

export function getPostgresColumnCheckExpressionForEdit(
  raw: string | null | undefined,
): string {
  const checks = parsePostgresColumnCheckConstraints(raw)
  if (checks.length === 0) return ''
  if (checks.length === 1) return checks[0].expression
  return checks.map((check) => check.expression).join('\n')
}

export function getPostgresColumnForeignKeyReferenceForEdit(
  raw: string | null | undefined,
): string {
  const foreignKeys = parsePostgresColumnForeignKeys(raw)
  if (foreignKeys.length === 0) return ''
  if (foreignKeys.length === 1) return foreignKeys[0].reference
  return foreignKeys.map((foreignKey) => foreignKey.reference).join('\n')
}

export function buildPostgresColumnConstraintName(
  tableName: string,
  columnName: string,
  suffix: 'check' | 'fkey' | 'pkey' | 'key',
): string {
  const normalized = `${tableName}_${columnName}_${suffix}`.replace(/[^a-zA-Z0-9_]/g, '_')
  return normalized.slice(0, 63)
}

export function formatPostgresColumnMetadataPreview(
  value: string | null | undefined,
  maxLength = 48,
): string {
  if (!value?.trim()) return '-'
  const singleLine = value.replace(/\s+/g, ' ').trim()
  if (singleLine.length <= maxLength) return singleLine
  return `${singleLine.slice(0, maxLength - 1)}…`
}

export function getPostgresColumnCheckDisplay(
  raw: string | null | undefined,
): string {
  const checks = parsePostgresColumnCheckConstraints(raw)
  if (checks.length === 0) return ''
  return checks.map((check) => check.expression).join('; ')
}

export function getPostgresColumnForeignKeyDisplay(
  raw: string | null | undefined,
): string {
  const foreignKeys = parsePostgresColumnForeignKeys(raw)
  if (foreignKeys.length === 0) return ''
  return foreignKeys.map((foreignKey) => foreignKey.reference).join(', ')
}
