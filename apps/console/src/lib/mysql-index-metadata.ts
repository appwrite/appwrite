export type MysqlIndexAlgorithm =
  | 'btree'
  | 'hash'
  | 'gist'
  | 'gin'
  | 'spgist'
  | 'brin'

export type MysqlIndexFormState = {
  name: string
  algorithm: MysqlIndexAlgorithm
  unique: boolean
  columns: string[]
  condition: string
  includeColumns: string[]
  comment: string
}

export const MYSQL_INDEX_ALGORITHMS: {
  id: MysqlIndexAlgorithm
  label: string
  description: string
}[] = [
  {
    id: 'btree',
    label: 'B-tree',
    description:
      'Default choice. Supports equality, range scans, sorting, and composite keys.',
  },
  {
    id: 'hash',
    label: 'Hash',
    description:
      'Fast equality lookups on one column. No range scans, sorting, or multi-column keys.',
  },
  {
    id: 'gist',
    label: 'GiST',
    description:
      'Generalized search for geometry, geography, ranges, and nearest-neighbor queries.',
  },
  {
    id: 'gin',
    label: 'GIN',
    description:
      'Inverted index for JSONB, arrays, full-text search, and other composite values.',
  },
  {
    id: 'spgist',
    label: 'SP-GiST',
    description:
      'Space-partitioned trees for non-uniform data such as phone numbers and IP ranges.',
  },
  {
    id: 'brin',
    label: 'BRIN',
    description:
      'Compact block summaries for very large tables with naturally ordered columns.',
  },
]

function splitMysqlIndexColumnList(raw: string): string[] {
  return raw
    .split(',')
    .map((part) =>
      part
        .trim()
        .replace(/^"(.+)"$/, '$1')
        .replace(/\s+(ASC|DESC|nulls first|nulls last)$/i, '')
        .trim(),
    )
    .filter(Boolean)
}

export function parseMysqlIndexKeyColumns(definition: string): string[] {
  const usingMatch = definition.match(/USING\s+\w+\s*\(([^)]+)\)/i)
  if (usingMatch?.[1]) {
    return splitMysqlIndexColumnList(usingMatch[1])
  }

  const onMatch = definition.match(/ON\s+\S+\s*\(([^)]+)\)/i)
  if (onMatch?.[1]) {
    return splitMysqlIndexColumnList(onMatch[1])
  }

  return []
}

export function parseMysqlIndexIncludeColumns(definition: string): string[] {
  const match = definition.match(/INCLUDE\s*\(([^)]+)\)/i)
  if (!match?.[1]) return []
  return splitMysqlIndexColumnList(match[1])
}

export function parseMysqlIndexConditionFromDefinition(
  definition: string,
): string {
  const match = definition.match(/\bWHERE\s+(.+)$/is)
  return match?.[1]?.trim() ?? ''
}

export function createDefaultMysqlIndexFormState(): MysqlIndexFormState {
  return {
    name: '',
    algorithm: 'btree',
    unique: false,
    columns: [],
    condition: '',
    includeColumns: [],
    comment: '',
  }
}

export function validateMysqlIndexFormState(
  state: MysqlIndexFormState,
): string | null {
  if (!state.name.trim()) {
    return 'Index name is required.'
  }
  if (state.columns.length === 0) {
    return 'Select at least one key column.'
  }
  if (state.algorithm === 'hash' && state.columns.length > 1) {
    return 'Hash indexes support only one key column.'
  }

  const includeOverlap = state.includeColumns.filter((column) =>
    state.columns.includes(column),
  )
  if (includeOverlap.length > 0) {
    return 'Include columns must be different from key columns.'
  }

  return null
}

export function formatMysqlIndexMetadataPreview(
  value: string | null | undefined,
  maxLength = 48,
): string {
  if (!value?.trim()) return '-'
  const singleLine = value.replace(/\s+/g, ' ').trim()
  if (singleLine.length <= maxLength) return singleLine
  return `${singleLine.slice(0, maxLength - 1)}…`
}

export function getMysqlIndexAlgorithmLabel(
  algorithm: string | null | undefined,
): string {
  if (!algorithm?.trim()) return 'B-tree'
  const normalized = algorithm.trim().toLowerCase()
  return (
    MYSQL_INDEX_ALGORITHMS.find((entry) => entry.id === normalized)?.label ??
    normalized.toUpperCase()
  )
}

export function getMysqlIndexAlgorithmDefinition(
  algorithm: MysqlIndexAlgorithm,
) {
  return MYSQL_INDEX_ALGORITHMS.find((entry) => entry.id === algorithm)!
}

export function getMysqlIndexAlgorithmSearchValue(entry: {
  id: MysqlIndexAlgorithm
  label: string
  description: string
}): string {
  return [entry.label, entry.id, entry.description].join(' ')
}

/** @deprecated Use parseMysqlIndexKeyColumns */
export function parseMysqlIndexColumnsFromDefinition(
  definition: string,
): string[] {
  return parseMysqlIndexKeyColumns(definition)
}
