/** Parse ENUM labels from information_schema COLUMN_TYPE, e.g. enum('a','b','c'). */
export function parseMysqlEnumValues(raw: unknown): string[] {
  if (Array.isArray(raw)) {
    return raw.map(String)
  }
  if (typeof raw !== 'string') return []

  const trimmed = raw.trim()
  if (!trimmed) return []

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

export function normalizeMysqlEnumValues(
  values: string[] | undefined,
): string[] {
  return (values ?? []).map((value) => value.trim()).filter(Boolean)
}

export function validateMysqlEnumValues(
  values: string[] | undefined,
): string | null {
  const labels = normalizeMysqlEnumValues(values)
  if (labels.length === 0) return 'Add at least one enum value.'

  const seen = new Set<string>()
  for (const label of labels) {
    if (seen.has(label)) return 'Duplicate enum value.'
    seen.add(label)
  }

  return null
}
