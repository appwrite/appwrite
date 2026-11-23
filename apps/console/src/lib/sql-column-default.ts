export type SqlColumnDefaultKind = 'value' | 'expression'

export type ParsedSqlColumnDefault = {
  kind: SqlColumnDefaultKind
  value: string
  isNull?: boolean
}

const MYSQL_DEFAULT_KEYWORDS = new Set([
  'NULL',
  'TRUE',
  'FALSE',
  'CURRENT_TIMESTAMP',
  'CURRENT_DATE',
  'CURRENT_TIME',
  'LOCALTIME',
  'LOCALTIMESTAMP',
  'UTC_DATE',
  'UTC_TIME',
  'UTC_TIMESTAMP',
])

const POSTGRES_TEXT_CASTS = new Set([
  'text',
  'character varying',
  'varchar',
  'character',
  'char',
  'bpchar',
  'name',
  'citext',
])

export function getPreferredSqlColumnDefaultKind(input: {
  typeGroup: string
  typeId: string
}): SqlColumnDefaultKind {
  if (input.typeGroup === 'Date & time') return 'expression'
  if (
    input.typeId === 'uuid' ||
    input.typeId === 'json' ||
    input.typeId === 'jsonb'
  ) {
    return 'expression'
  }
  return 'value'
}

export function sqlColumnDefaultIsNull(
  parsed: ParsedSqlColumnDefault,
): boolean {
  if (parsed.isNull) return true
  return parsed.value.trim().toUpperCase() === 'NULL'
}

export function sqlColumnDefaultsEqual(
  left: ParsedSqlColumnDefault,
  right: ParsedSqlColumnDefault,
): boolean {
  const leftNull = sqlColumnDefaultIsNull(left)
  const rightNull = sqlColumnDefaultIsNull(right)
  if (leftNull || rightNull) return leftNull && rightNull
  if (!left.value && !right.value) return true
  return left.kind === right.kind && left.value === right.value
}

export function resolveSqlColumnDefaultEmission(input: {
  isNull: boolean
  value: string
  kind: SqlColumnDefaultKind
}): 'null' | 'omit' | { kind: SqlColumnDefaultKind; value: string } {
  if (input.isNull) return 'null'
  if (input.kind === 'expression') {
    const trimmed = input.value.trim()
    return trimmed ? { kind: 'expression', value: trimmed } : 'omit'
  }
  return { kind: 'value', value: input.value }
}

function unwrapOuterParens(sql: string): string {
  let remaining = sql.trim()
  while (remaining.startsWith('(') && remaining.endsWith(')') && remaining.length >= 2) {
    let depth = 0
    let inString = false
    let wrapsAll = true
    for (let index = 0; index < remaining.length; index += 1) {
      const char = remaining[index]
      if (inString) {
        if (char === "'" && remaining[index + 1] === "'") {
          index += 1
          continue
        }
        if (char === "'") inString = false
        continue
      }
      if (char === "'") {
        inString = true
        continue
      }
      if (char === '(') depth += 1
      if (char === ')') {
        depth -= 1
        if (depth === 0 && index !== remaining.length - 1) {
          wrapsAll = false
          break
        }
      }
    }
    if (!wrapsAll || depth !== 0) break
    remaining = remaining.slice(1, -1).trim()
  }
  return remaining
}

function readSqlStringLiteral(
  sql: string,
): { value: string; rest: string } | null {
  if (!sql.startsWith("'")) return null
  let value = ''
  for (let index = 1; index < sql.length; index += 1) {
    const char = sql[index]
    if (char === "'") {
      if (sql[index + 1] === "'") {
        value += "'"
        index += 1
        continue
      }
      return { value, rest: sql.slice(index + 1).trim() }
    }
    value += char
  }
  return null
}

function looksLikeSqlDefaultKeyword(
  value: string,
  keywords: Set<string>,
): boolean {
  return keywords.has(value.toUpperCase())
}

function looksLikeSqlFunctionOrNumber(value: string): boolean {
  if (/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/.test(value)) return true
  if (/^[a-zA-Z_][\w.]*\s*\(/.test(value)) return true
  return false
}

function isSqlNullKeyword(value: string): boolean {
  return value.trim().toUpperCase() === 'NULL'
}

export function parsePostgresStoredColumnDefault(
  stored: string | null | undefined,
): ParsedSqlColumnDefault {
  const trimmed = stored?.trim() ?? ''
  if (!trimmed) return { kind: 'value', value: '', isNull: true }

  const unwrapped = unwrapOuterParens(trimmed)
  if (isSqlNullKeyword(unwrapped)) {
    return { kind: 'value', value: '', isNull: true }
  }
  const literal = readSqlStringLiteral(unwrapped)
  if (literal && literal.rest === '') {
    return { kind: 'value', value: literal.value }
  }
  if (literal?.rest.startsWith('::')) {
    const typeName = literal.rest
      .slice(2)
      .trim()
      .replace(/\s*\([^)]*\)\s*$/, '')
      .toLowerCase()
    if (POSTGRES_TEXT_CASTS.has(typeName)) {
      return { kind: 'value', value: literal.value }
    }
  }

  return { kind: 'expression', value: trimmed }
}

export function parseMysqlStoredColumnDefault(
  stored: string | null | undefined,
): ParsedSqlColumnDefault {
  const trimmed = stored?.trim() ?? ''
  if (!trimmed) return { kind: 'value', value: '', isNull: true }

  const unwrapped = unwrapOuterParens(trimmed)
  if (isSqlNullKeyword(unwrapped)) {
    return { kind: 'value', value: '', isNull: true }
  }
  const withoutCharset = unwrapped.replace(/^_[a-zA-Z0-9]+/, '')
  const literal = readSqlStringLiteral(withoutCharset)
  if (literal && literal.rest === '') {
    return { kind: 'value', value: literal.value }
  }

  if (
    looksLikeSqlDefaultKeyword(unwrapped, MYSQL_DEFAULT_KEYWORDS) ||
    /^(?:CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIME|LOCALTIMESTAMP|UTC_TIMESTAMP|NOW)(?:\s*\(\s*\d*\s*\))?$/i.test(
      unwrapped,
    ) ||
    looksLikeSqlFunctionOrNumber(unwrapped) ||
    (unwrapped.startsWith('(') && unwrapped.endsWith(')'))
  ) {
    return { kind: 'expression', value: trimmed }
  }

  return { kind: 'value', value: trimmed }
}

export function storedColumnDefaultForForm(
  parsed: ParsedSqlColumnDefault,
  preferredKind: SqlColumnDefaultKind,
): ParsedSqlColumnDefault {
  if (sqlColumnDefaultIsNull(parsed)) {
    return { kind: preferredKind, value: '', isNull: true }
  }
  if (!parsed.value) return { kind: preferredKind, value: '' }
  return parsed
}
