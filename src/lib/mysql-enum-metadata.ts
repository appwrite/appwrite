import type { MysqlSchemaEnumRow } from '@/lib/mysql-sql'
import {
  buildMysqlAddEnumValueSql,
  buildMysqlEnumCommentSql,
  buildMysqlRenameEnumTypeSql,
  buildMysqlRenameEnumValueSql,
} from '@/lib/mysql-enum-ddl'

export type MysqlEnumValueEntry = {
  id: string
  value: string
  /** Set for values loaded from the database. */
  originalValue?: string
}

export type MysqlEnumFormState = {
  name: string
  entries: MysqlEnumValueEntry[]
  comment: string
}

export function createEnumValueEntry(
  value = '',
  originalValue?: string,
): MysqlEnumValueEntry {
  return {
    id: crypto.randomUUID(),
    value,
    originalValue,
  }
}

export function createDefaultMysqlEnumFormState(): MysqlEnumFormState {
  return {
    name: '',
    entries: [createEnumValueEntry()],
    comment: '',
  }
}

export function createMysqlEnumFormStateFromRow(
  row: MysqlSchemaEnumRow,
): MysqlEnumFormState {
  return {
    name: row.enum_name,
    entries: row.values.map((value) => createEnumValueEntry(value, value)),
    comment: row.enum_comment?.trim() ?? '',
  }
}

export function parseMysqlEnumValues(raw: unknown): string[] {
  if (Array.isArray(raw)) {
    return raw.map(String)
  }
  if (typeof raw === 'string') {
    const trimmed = raw.trim()
    if (!trimmed) return []

    // COLUMN_TYPE from information_schema, e.g. enum('a','b','c')
    const enumMatch = trimmed.match(/^enum\s*\((.*)\)\s*$/i)
    if (enumMatch) {
      const inner = enumMatch[1]
      const values: string[] = []
      const literalRe = /'((?:[^'\\]|\\.|'')*)'/g
      let match: RegExpExecArray | null
      while ((match = literalRe.exec(inner)) !== null) {
        values.push(match[1].replace(/''/g, "'").replace(/\\'/g, "'"))
      }
      return values
    }

    try {
      const parsed: unknown = JSON.parse(trimmed)
      return Array.isArray(parsed) ? parsed.map(String) : []
    } catch {
      return []
    }
  }
  return []
}

export function isMysqlEnumValueEntryNew(
  entry: MysqlEnumValueEntry,
): boolean {
  return entry.originalValue == null
}

const MYSQL_IDENTIFIER_PATTERN = /^[a-z_][a-z0-9_$]*$/i

export function isValidMysqlEnumIdentifier(name: string): boolean {
  const trimmed = name.trim()
  return trimmed.length > 0 && MYSQL_IDENTIFIER_PATTERN.test(trimmed)
}

function getNormalizedEnumEntries(
  entries: MysqlEnumValueEntry[],
): MysqlEnumValueEntry[] {
  return entries.filter(
    (entry) => entry.value.trim() || entry.originalValue != null,
  )
}

function validateEnumValueLabels(labels: string[]): string | null {
  if (labels.length === 0) return 'Add at least one enum value.'

  const seen = new Set<string>()
  for (const label of labels) {
    if (seen.has(label)) return 'Duplicate enum value.'
    seen.add(label)
  }

  return null
}

export function validateMysqlEnumCreateForm(
  state: MysqlEnumFormState,
): string | null {
  const trimmedName = state.name.trim()
  if (!trimmedName) return 'Enum name is required.'
  if (!isValidMysqlEnumIdentifier(trimmedName)) {
    return 'Enum name must start with a letter or underscore and contain only letters, numbers, and underscores.'
  }

  const labels = getNormalizedEnumEntries(state.entries)
    .map((entry) => entry.value.trim())
    .filter(Boolean)

  return validateEnumValueLabels(labels)
}

export function validateMysqlEnumUpdateForm(args: {
  state: MysqlEnumFormState
  original: MysqlSchemaEnumRow
}): string | null {
  const trimmedName = args.state.name.trim()
  if (!trimmedName) return 'Enum name is required.'
  if (!isValidMysqlEnumIdentifier(trimmedName)) {
    return 'Enum name must start with a letter or underscore and contain only letters, numbers, and underscores.'
  }

  for (const entry of args.state.entries) {
    if (entry.originalValue != null && !entry.value.trim()) {
      return 'Enum values cannot be empty.'
    }
  }

  const labels = getNormalizedEnumEntries(args.state.entries)
    .map((entry) => entry.value.trim())
    .filter(Boolean)

  const duplicateError = validateEnumValueLabels(labels)
  if (duplicateError && labels.length > 0) return duplicateError

  if (!hasMysqlEnumFormChanges(args.state, args.original)) {
    return 'No changes to save.'
  }

  return null
}

export function hasMysqlEnumFormChanges(
  state: MysqlEnumFormState,
  original: MysqlSchemaEnumRow,
): boolean {
  if (state.name.trim() !== original.enum_name) return true

  const nextComment = state.comment.trim()
  const savedComment = original.enum_comment?.trim() ?? ''
  if (nextComment !== savedComment) return true

  const entries = getNormalizedEnumEntries(state.entries).filter(
    (entry) => entry.value.trim(),
  )

  for (const entry of entries) {
    if (isMysqlEnumValueEntryNew(entry)) return true
    if (
      entry.originalValue != null &&
      entry.value.trim() !== entry.originalValue
    ) {
      return true
    }
  }

  return false
}

export function normalizeMysqlEnumFormValues(
  entries: MysqlEnumValueEntry[],
): string[] {
  return getNormalizedEnumEntries(entries)
    .map((entry) => entry.value.trim())
    .filter(Boolean)
}

export function buildMysqlEnumUpdateStatements(
  schema: string,
  original: MysqlSchemaEnumRow,
  state: MysqlEnumFormState,
): string[] {
  const statements: string[] = []
  const trimmedName = state.name.trim()
  const targetEnumName =
    trimmedName !== original.enum_name ? trimmedName : original.enum_name

  if (trimmedName !== original.enum_name) {
    statements.push(
      buildMysqlRenameEnumTypeSql(schema, original.enum_name, trimmedName),
    )
  }

  const entries = getNormalizedEnumEntries(state.entries).filter(
    (entry) => entry.value.trim(),
  )

  for (const entry of entries) {
    if (
      entry.originalValue != null &&
      entry.value.trim() !== entry.originalValue
    ) {
      statements.push(
        buildMysqlRenameEnumValueSql(
          schema,
          targetEnumName,
          entry.originalValue,
          entry.value.trim(),
        ),
      )
    }
  }

  for (let index = 0; index < entries.length; index += 1) {
    const entry = entries[index]!
    if (!isMysqlEnumValueEntryNew(entry)) continue

    const label = entry.value.trim()
    if (!label) continue

    let afterLabel: string | undefined
    for (let prevIndex = index - 1; prevIndex >= 0; prevIndex -= 1) {
      const previousLabel = entries[prevIndex]!.value.trim()
      if (previousLabel) {
        afterLabel = previousLabel
        break
      }
    }

    if (afterLabel) {
      statements.push(
        buildMysqlAddEnumValueSql(schema, targetEnumName, label, {
          after: afterLabel,
        }),
      )
      continue
    }

    let beforeLabel: string | undefined
    for (let nextIndex = index + 1; nextIndex < entries.length; nextIndex += 1) {
      const nextEntry = entries[nextIndex]!
      if (nextEntry.originalValue != null && nextEntry.value.trim()) {
        beforeLabel = nextEntry.value.trim()
        break
      }
    }

    if (beforeLabel) {
      statements.push(
        buildMysqlAddEnumValueSql(schema, targetEnumName, label, {
          before: beforeLabel,
        }),
      )
    } else {
      statements.push(buildMysqlAddEnumValueSql(schema, targetEnumName, label))
    }
  }

  const nextComment = state.comment.trim()
  const savedComment = original.enum_comment?.trim() ?? ''
  if (nextComment !== savedComment) {
    statements.push(
      buildMysqlEnumCommentSql(schema, targetEnumName, nextComment || null),
    )
  }

  return statements
}
