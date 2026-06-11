import type { IDisposable, IRange, languages } from 'monaco-editor'
import type { PostgresColumnRow, PostgresTableRow } from '@/lib/postgres-sql'

export type PostgresSqlCompletionCatalog = {
  schemas: string[]
  tables: PostgresTableRow[]
  columns: PostgresColumnRow[]
}

type Monaco = typeof import('monaco-editor')

type TableRef = {
  schema: string
  table: string
}

const IDENT = String.raw`(?:"[^"]+"|[a-zA-Z_][\w$]*)`
const TABLE_CONTEXT_PATTERN =
  /\b(?:FROM|JOIN|INTO|UPDATE|TABLE|ONLY)\s+$/i

function unquoteIdentifier(identifier: string): string {
  if (identifier.startsWith('"') && identifier.endsWith('"')) {
    return identifier.slice(1, -1).replace(/""/g, '"')
  }
  return identifier
}

function matchesPrefix(label: string, prefix: string): boolean {
  if (!prefix) return true
  return label.toLowerCase().startsWith(prefix.toLowerCase())
}

function parseQualifiedPrefix(
  text: string,
): { type: 'columns'; schema: string; table: string } | { type: 'tables'; schema: string } | null {
  const tableColumnMatch = text.match(
    new RegExp(`(${IDENT}\\.${IDENT})\\.\\s*$`, 'i'),
  )
  if (tableColumnMatch) {
    const [schema, table] = tableColumnMatch[1].split('.').map(unquoteIdentifier)
    return { type: 'columns', schema, table }
  }

  const schemaMatch = text.match(new RegExp(`(${IDENT})\\.\\s*$`, 'i'))
  if (schemaMatch) {
    return { type: 'tables', schema: unquoteIdentifier(schemaMatch[1]) }
  }

  return null
}

function resolveTableRef(
  ref: string,
  catalog: PostgresSqlCompletionCatalog,
): TableRef | null {
  const parts = ref.split('.').map(unquoteIdentifier)
  if (parts.length === 2) {
    return { schema: parts[0], table: parts[1] }
  }
  if (parts.length === 1) {
    const matches = catalog.tables.filter((row) => row.table_name === parts[0])
    if (matches.length === 0) return null
    const publicMatch = matches.find((row) => row.table_schema === 'public')
    const chosen = publicMatch ?? matches[0]
    return { schema: chosen.table_schema, table: chosen.table_name }
  }
  return null
}

function addTableRefToken(refs: Set<string>, token: string | undefined) {
  if (!token) return
  const cleaned = token.replace(/^["'`(]+|["'`)]+$/g, '').trim()
  if (cleaned) refs.add(cleaned)
}

function extractReferencedTables(
  text: string,
  catalog: PostgresSqlCompletionCatalog,
): TableRef[] {
  const refs = new Set<string>()

  const fromMatch = text.match(
    /\bFROM\s+([\s\S]+?)(?=\bWHERE\b|\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|\bUNION\b|$)/i,
  )
  if (fromMatch) {
    for (const part of fromMatch[1].split(',')) {
      const token = part.trim().split(/\s+/)[0]
      addTableRefToken(refs, token)
    }
  }

  for (const joinMatch of text.matchAll(
    /\b(?:INNER\s+|LEFT\s+|RIGHT\s+|FULL\s+|CROSS\s+)?JOIN\s+((?:"[^"]+"|[\w.]+))/gi,
  )) {
    addTableRefToken(refs, joinMatch[1])
  }

  const updateMatch = text.match(/\bUPDATE\s+((?:"[^"]+"|[\w.]+))/i)
  addTableRefToken(refs, updateMatch?.[1])

  const resolved: TableRef[] = []
  for (const ref of refs) {
    const table = resolveTableRef(ref, catalog)
    if (table) resolved.push(table)
  }
  return resolved
}

function schemaSuggestions(
  monaco: Monaco,
  catalog: PostgresSqlCompletionCatalog,
  prefix: string,
  range: IRange,
): languages.CompletionItem[] {
  return catalog.schemas
    .filter((schema) => matchesPrefix(schema, prefix))
    .map((schema) => ({
      label: schema,
      kind: monaco.languages.CompletionItemKind.Module,
      insertText: schema,
      detail: 'schema',
      sortText: `1_${schema}`,
      range,
    }))
}

function tableSuggestions(
  monaco: Monaco,
  catalog: PostgresSqlCompletionCatalog,
  prefix: string,
  range: IRange,
  schemaFilter?: string,
): languages.CompletionItem[] {
  const tables = schemaFilter
    ? catalog.tables.filter((row) => row.table_schema === schemaFilter)
    : catalog.tables

  return tables
    .filter((row) => {
      const qualified = `${row.table_schema}.${row.table_name}`
      return (
        matchesPrefix(row.table_name, prefix) ||
        matchesPrefix(qualified, prefix)
      )
    })
    .map((row) => {
      const qualified = `${row.table_schema}.${row.table_name}`
      const typeLabel = row.table_type === 'VIEW' ? 'view' : 'table'
      return {
        label: row.table_name,
        kind: monaco.languages.CompletionItemKind.Struct,
        insertText: qualified,
        detail: `${row.table_schema} · ${typeLabel}`,
        sortText: `2_${qualified}`,
        range,
      }
    })
}

function columnSuggestions(
  monaco: Monaco,
  catalog: PostgresSqlCompletionCatalog,
  prefix: string,
  range: IRange,
  tables: TableRef[],
  qualifyColumns: boolean,
): languages.CompletionItem[] {
  const suggestions: languages.CompletionItem[] = []
  const seen = new Set<string>()

  for (const table of tables) {
    const tableColumns = catalog.columns.filter(
      (column) =>
        column.table_schema === table.schema &&
        column.table_name === table.table,
    )

    for (const column of tableColumns) {
      const label = column.column_name
      const insertText = qualifyColumns
        ? `${table.table}.${label}`
        : label
      const dedupeKey = `${table.schema}.${table.table}.${label}`
      if (seen.has(dedupeKey)) continue
      if (!matchesPrefix(label, prefix) && !matchesPrefix(insertText, prefix)) {
        continue
      }
      seen.add(dedupeKey)
      suggestions.push({
        label,
        kind: monaco.languages.CompletionItemKind.Field,
        insertText,
        detail: `${column.data_type} · ${table.schema}.${table.table}`,
        sortText: `3_${dedupeKey}`,
        range,
      })
    }
  }

  return suggestions
}

function buildSuggestions(
  monaco: Monaco,
  catalog: PostgresSqlCompletionCatalog,
  textUntilPosition: string,
  prefix: string,
  range: IRange,
): languages.CompletionItem[] {
  if (
    catalog.schemas.length === 0 &&
    catalog.tables.length === 0 &&
    catalog.columns.length === 0
  ) {
    return []
  }

  const qualified = parseQualifiedPrefix(textUntilPosition)
  if (qualified?.type === 'columns') {
    return columnSuggestions(
      monaco,
      catalog,
      prefix,
      range,
      [{ schema: qualified.schema, table: qualified.table }],
      false,
    )
  }

  if (qualified?.type === 'tables') {
    return tableSuggestions(
      monaco,
      catalog,
      prefix,
      range,
      qualified.schema,
    )
  }

  if (TABLE_CONTEXT_PATTERN.test(textUntilPosition)) {
    return tableSuggestions(monaco, catalog, prefix, range)
  }

  const referencedTables = extractReferencedTables(textUntilPosition, catalog)
  if (referencedTables.length > 0) {
    const columnItems = columnSuggestions(
      monaco,
      catalog,
      prefix,
      range,
      referencedTables,
      referencedTables.length > 1,
    )
    if (columnItems.length > 0) {
      return columnItems
    }
  }

  return [
    ...schemaSuggestions(monaco, catalog, prefix, range),
    ...tableSuggestions(monaco, catalog, prefix, range),
    ...columnSuggestions(
      monaco,
      catalog,
      prefix,
      range,
      catalog.tables.map((row) => ({
        schema: row.table_schema,
        table: row.table_name,
      })),
      false,
    ),
  ]
}

export function registerPostgresSqlCompletionProvider(
  monaco: Monaco,
  getCatalog: () => PostgresSqlCompletionCatalog,
): IDisposable {
  return monaco.languages.registerCompletionItemProvider('sql', {
    triggerCharacters: ['.', ' ', ','],
    provideCompletionItems(model, position) {
      const catalog = getCatalog()
      const textUntilPosition = model.getValueInRange({
        startLineNumber: 1,
        startColumn: 1,
        endLineNumber: position.lineNumber,
        endColumn: position.column,
      })
      const word = model.getWordUntilPosition(position)
      const range = {
        startLineNumber: position.lineNumber,
        endLineNumber: position.lineNumber,
        startColumn: word.startColumn,
        endColumn: position.column,
      }

      return {
        suggestions: buildSuggestions(
          monaco,
          catalog,
          textUntilPosition,
          word.word,
          range,
        ),
      }
    },
  })
}
