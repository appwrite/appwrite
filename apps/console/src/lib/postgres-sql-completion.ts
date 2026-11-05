import type { IDisposable, IRange, languages } from 'monaco-editor'
import type { PostgresColumnRow, PostgresTableRow } from '@/lib/postgres-sql'

export type PostgresSqlCompletionTableRef = {
  schema: string
  table: string
}

export type PostgresSqlCompletionResolvers = {
  searchSchemas: (search: string) => Promise<string[]>
  searchTables: (
    schema: string | undefined,
    search: string,
  ) => Promise<PostgresTableRow[]>
  resolveTableColumns: (
    tables: PostgresSqlCompletionTableRef[],
  ) => Promise<PostgresColumnRow[]>
  resolveTableRef: (ref: string) => Promise<PostgresSqlCompletionTableRef | null>
}

type Monaco = typeof import('monaco-editor')

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

function addTableRefToken(refs: Set<string>, token: string | undefined) {
  if (!token) return
  const cleaned = token.replace(/^["'`(]+|["'`)]+$/g, '').trim()
  if (cleaned) refs.add(cleaned)
}

function extractReferencedTableTokens(text: string): string[] {
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

  return Array.from(refs)
}

function tablesNeedingColumns(
  textUntilPosition: string,
): PostgresSqlCompletionTableRef[] {
  const qualified = parseQualifiedPrefix(textUntilPosition)
  if (qualified?.type === 'columns') {
    return [{ schema: qualified.schema, table: qualified.table }]
  }
  return []
}

function schemaSuggestions(
  monaco: Monaco,
  schemas: string[],
  prefix: string,
  range: IRange,
): languages.CompletionItem[] {
  return schemas
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
  tables: PostgresTableRow[],
  prefix: string,
  range: IRange,
): languages.CompletionItem[] {
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
  columns: PostgresColumnRow[],
  prefix: string,
  range: IRange,
  tables: PostgresSqlCompletionTableRef[],
  qualifyColumns: boolean,
): languages.CompletionItem[] {
  const suggestions: languages.CompletionItem[] = []
  const seen = new Set<string>()

  for (const table of tables) {
    const tableColumns = columns.filter(
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

async function resolveReferencedTables(
  text: string,
  resolveTableRef: PostgresSqlCompletionResolvers['resolveTableRef'],
): Promise<PostgresSqlCompletionTableRef[]> {
  const tokens = extractReferencedTableTokens(text)
  const resolved: PostgresSqlCompletionTableRef[] = []
  const seen = new Set<string>()

  for (const token of tokens) {
    const table = await resolveTableRef(token)
    if (!table) continue
    const key = `${table.schema}.${table.table}`
    if (seen.has(key)) continue
    seen.add(key)
    resolved.push(table)
  }

  return resolved
}

async function buildSuggestions(
  monaco: Monaco,
  resolvers: PostgresSqlCompletionResolvers,
  textUntilPosition: string,
  prefix: string,
  range: IRange,
): Promise<languages.CompletionItem[]> {
  const qualified = parseQualifiedPrefix(textUntilPosition)
  if (qualified?.type === 'columns') {
    const columns = await resolvers.resolveTableColumns([
      { schema: qualified.schema, table: qualified.table },
    ])
    return columnSuggestions(
      monaco,
      columns,
      prefix,
      range,
      [{ schema: qualified.schema, table: qualified.table }],
      false,
    )
  }

  if (qualified?.type === 'tables') {
    const tables = await resolvers.searchTables(qualified.schema, prefix)
    return tableSuggestions(monaco, tables, prefix, range)
  }

  if (TABLE_CONTEXT_PATTERN.test(textUntilPosition)) {
    const tables = await resolvers.searchTables(undefined, prefix)
    return tableSuggestions(monaco, tables, prefix, range)
  }

  const explicitTables = tablesNeedingColumns(textUntilPosition)
  if (explicitTables.length > 0) {
    const columns = await resolvers.resolveTableColumns(explicitTables)
    return columnSuggestions(
      monaco,
      columns,
      prefix,
      range,
      explicitTables,
      false,
    )
  }

  const referencedTables = await resolveReferencedTables(
    textUntilPosition,
    resolvers.resolveTableRef,
  )
  if (referencedTables.length > 0) {
    const columns = await resolvers.resolveTableColumns(referencedTables)
    const columnItems = columnSuggestions(
      monaco,
      columns,
      prefix,
      range,
      referencedTables,
      referencedTables.length > 1,
    )
    if (columnItems.length > 0) {
      return columnItems
    }
  }

  const [schemas, tables] = await Promise.all([
    resolvers.searchSchemas(prefix),
    resolvers.searchTables(undefined, prefix),
  ])

  return [
    ...schemaSuggestions(monaco, schemas, prefix, range),
    ...tableSuggestions(monaco, tables, prefix, range),
  ]
}

export function registerPostgresSqlCompletionProvider(
  monaco: Monaco,
  getResolvers: () => PostgresSqlCompletionResolvers,
): IDisposable {
  return monaco.languages.registerCompletionItemProvider('sql', {
    triggerCharacters: ['.', ' ', ','],
    async provideCompletionItems(model, position) {
      const resolvers = getResolvers()
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
        suggestions: await buildSuggestions(
          monaco,
          resolvers,
          textUntilPosition,
          word.word,
          range,
        ),
      }
    },
  })
}
