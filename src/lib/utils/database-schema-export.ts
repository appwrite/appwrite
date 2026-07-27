/**
 * Database Schema Export Utility
 *
 * Generates database schema in various formats for AI agents and IDEs
 */

import { DatabaseType } from '@/lib/databases/database-type'
import { sdk } from '@/lib/appwrite/sdk'
import { isHtmlDarkChrome } from '@/lib/html-theme'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import {
  fetchProjectTableColumns,
  fetchProjectTableIndexes,
  fetchProjectTables,
  getDatabaseModel,
} from '@/lib/react-query/hooks/databases'

export interface DatabaseSchema {
  database: {
    id: string
    name: string
  }
  tables: TableSchema[]
}

export interface TableSchema {
  id: string
  name: string
  enabled: boolean
  rowSecurity: boolean
  columns: ColumnSchema[]
  indexes: IndexSchema[]
}

export interface ColumnSchema {
  key: string
  type: string
  required: boolean
  array?: boolean
  size?: number | null
  default?: string | null
  format?: string
  elements?: string[]
  min?: number | null
  max?: number | null
  relatedTable?: string
  relatedColumn?: string
  relationType?: string
}

export interface IndexSchema {
  key: string
  type: string
  attributes: string[]
  orders?: string[]
}

/** Normalize API values (including BigInt) to number | null for schema fields */
function toSchemaNumber(v: unknown): number | null {
  if (v == null) return null
  if (typeof v === 'bigint') return Number(v)
  if (typeof v === 'number' && !Number.isNaN(v)) return v
  return null
}

function readRowSecurity(
  table: Record<string, unknown>,
  kind: DatabaseType | string,
): boolean {
  if (kind === DatabaseType.Documentsdb || kind === DatabaseType.Vectorsdb) {
    return table.documentSecurity === true
  }
  return table.rowSecurity === true
}

/**
 * Fetches complete database schema including all tables, columns, and indexes
 */
export async function fetchDatabaseSchema(
  projectId: string,
  databaseId: string,
  dbKind: DatabaseRouteKind,
): Promise<DatabaseSchema> {
  const db = await getDatabaseModel(projectId, databaseId, dbKind)
  if (!db) {
    throw new Error('Database not found')
  }

  const kind = db.type ?? DatabaseType.Tablesdb
  const tablesResponse = await fetchProjectTables(
    projectId,
    databaseId,
    dbKind,
    0,
    1000,
  )

  const tables: TableSchema[] = await Promise.all(
    (tablesResponse.tables || []).map(async (table: Record<string, unknown>) => {
      const tableId = String(table.$id ?? '')
      const [columnsResponse, indexesResponse] = await Promise.all([
        fetchProjectTableColumns(projectId, databaseId, dbKind, tableId),
        fetchProjectTableIndexes(projectId, databaseId, dbKind, tableId),
      ])

      const columns: ColumnSchema[] = (columnsResponse.columns || []).map(
        (col: Record<string, unknown>) => ({
          key: String(col.key ?? col.$id ?? ''),
          type: String(col.type || 'string'),
          required: col.required === true,
          array: col.array === true,
          size: toSchemaNumber(col.size) ?? null,
          default: (col.default as string | null | undefined) ?? null,
          format: (col.format as string | undefined) || undefined,
          elements: (col.elements as string[] | undefined) || undefined,
          min: toSchemaNumber(col.min) ?? null,
          max: toSchemaNumber(col.max) ?? null,
          relatedTable:
            (col.relatedTable as string | undefined) ||
            (col.relatedCollection as string | undefined) ||
            undefined,
          relatedColumn:
            (col.relatedColumn as string | undefined) ||
            (col.relatedAttribute as string | undefined) ||
            undefined,
          relationType:
            (col.relationType as string | undefined) ||
            (col.relation as string | undefined) ||
            undefined,
        }),
      )

      const indexes: IndexSchema[] = (indexesResponse.indexes || []).map(
        (idx: Record<string, unknown>) => ({
          key: String(idx.key ?? idx.$id ?? ''),
          type: String(idx.type || 'key'),
          attributes: (idx.attributes as string[] | undefined) || [],
          orders: (idx.orders as string[] | undefined) || undefined,
        }),
      )

      return {
        id: tableId,
        name: String(table.name || 'Unnamed Table'),
        enabled: table.enabled !== false,
        rowSecurity: readRowSecurity(table, kind),
        columns,
        indexes,
      }
    }),
  )

  return {
    database: {
      id: db.$id,
      name: db.name || 'Unnamed Database',
    },
    tables,
  }
}

/**
 * JSON replacer that converts BigInt to string (JSON.stringify does not support BigInt)
 */
function jsonReplacer(_key: string, value: unknown): unknown {
  if (typeof value === 'bigint') {
    return value.toString()
  }
  return value
}

/**
 * Formats schema as JSON
 */
export function formatSchemaAsJSON(schema: DatabaseSchema): string {
  return JSON.stringify(schema, jsonReplacer, 2)
}

/**
 * Formats schema as Markdown
 */
export function formatSchemaAsMarkdown(schema: DatabaseSchema): string {
  let markdown = `# Database Schema: ${schema.database.name}\n\n`
  markdown += `**Database ID:** \`${schema.database.id}\`\n\n`
  markdown += `## Tables\n\n`

  if (schema.tables.length === 0) {
    markdown += `No tables found.\n`
    return markdown
  }

  for (const table of schema.tables) {
    markdown += `### ${table.name}\n\n`
    markdown += `**Table ID:** \`${table.id}\`\n\n`
    markdown += `**Status:** ${table.enabled ? 'Enabled' : 'Disabled'}  \n`
    markdown += `**Row Security:** ${table.rowSecurity ? 'Enabled' : 'Disabled'}\n\n`

    if (table.columns.length > 0) {
      markdown += `#### Columns\n\n`
      markdown += `| Column | Type | Required | Default | Size | Format | Description |\n`
      markdown += `|--------|------|----------|---------|------|--------|------------|\n`

      for (const col of table.columns) {
        const typeDisplay = col.array ? `${col.type}[]` : col.type
        const requiredDisplay = col.required ? 'Yes' : 'No'
        const defaultDisplay = col.default !== null ? `\`${col.default}\`` : '-'
        const sizeDisplay = col.size ? col.size.toString() : '-'
        const formatDisplay = col.format || '-'

        let description = ''
        if (col.relatedTable) {
          description = `Relation to \`${col.relatedTable}\``
          if (col.relatedColumn) {
            description += `.\`${col.relatedColumn}\``
          }
          if (col.relationType) {
            description += ` (${col.relationType})`
          }
        }

        markdown += `| \`${col.key}\` | ${typeDisplay} | ${requiredDisplay} | ${defaultDisplay} | ${sizeDisplay} | ${formatDisplay} | ${description || '-'} |\n`
      }

      markdown += `\n`
    }

    if (table.indexes.length > 0) {
      markdown += `#### Indexes\n\n`
      markdown += `| Index | Type | Attributes |\n`
      markdown += `|-------|------|------------|\n`

      for (const idx of table.indexes) {
        const attributesDisplay = idx.attributes.join(', ')
        markdown += `| \`${idx.key}\` | ${idx.type} | ${attributesDisplay} |\n`
      }

      markdown += `\n`
    }

    markdown += `---\n\n`
  }

  return markdown
}

/**
 * Formats schema as TypeScript types
 */
export function formatSchemaAsTypeScript(schema: DatabaseSchema): string {
  let typescript = `// Database Schema: ${schema.database.name}\n`
  typescript += `// Database ID: ${schema.database.id}\n\n`
  typescript += `import type { Models } from '@appwrite.io/console'\n\n`

  if (schema.tables.length === 0) {
    typescript += `// No tables found.\n`
    return typescript
  }

  for (const table of schema.tables) {
    const typeName = `${toPascalCase(table.name)}Row`
    typescript += `export type ${typeName} = Models.Row & {\n`

    for (const col of table.columns) {
      const tsType = getTypeScriptType(col)
      const optional = col.required ? '' : '?'
      typescript += `  ${col.key}${optional}: ${tsType}\n`
    }

    typescript += `}\n\n`
  }

  return typescript
}

/**
 * Converts a string to PascalCase
 */
function toPascalCase(str: string): string {
  return str
    .replace(/[^a-zA-Z0-9]/g, ' ')
    .split(' ')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join('')
    .replace(/^[a-z]/, (char) => char.toUpperCase())
}

/**
 * Converts a column type to TypeScript type
 */
function getTypeScriptType(col: ColumnSchema): string {
  let baseType = 'string'

  switch (col.type) {
    case 'string':
    case 'varchar':
    case 'text':
    case 'mediumtext':
    case 'longtext':
      baseType = 'string'
      break
    case 'integer':
    case 'bigint':
    case 'double':
      baseType = 'number'
      break
    case 'boolean':
      baseType = 'boolean'
      break
    case 'datetime':
      baseType = 'string' // ISO date string
      break
    case 'email':
    case 'url':
    case 'ip':
      baseType = 'string'
      break
    case 'relationship':
      baseType = 'string' // Relationship IDs are strings
      break
    default:
      baseType = 'any'
  }

  if (col.array) {
    return `${baseType}[]`
  }

  if (!col.required) {
    return `${baseType} | null`
  }

  return baseType
}

/**
 * Formats schema as SVG diagram
 */
export function formatSchemaAsSVG(schema: DatabaseSchema): string {
  const isDark =
    typeof window !== 'undefined' &&
    (isHtmlDarkChrome() ||
      window.matchMedia('(prefers-color-scheme: dark)').matches)

  const cardColor = isDark ? '#242424' : '#ffffff'
  const foregroundColor = isDark ? '#ffffff' : '#000000'
  const borderColor = isDark ? '#444444' : '#b3b3b3'
  const headerBgColor = isDark ? '#2a2a2a' : '#f0f0f0'

  const tableWidth = 250
  const tableHeight = 200
  const tableSpacing = 50
  const padding = 50

  // Calculate grid layout
  const cols = Math.ceil(Math.sqrt(schema.tables.length))
  const rows = Math.ceil(schema.tables.length / cols)
  const totalWidth = cols * tableWidth + (cols - 1) * tableSpacing + padding * 2
  const totalHeight =
    rows * tableHeight + (rows - 1) * tableSpacing + padding * 2

  let svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg width="${totalWidth}" height="${totalHeight}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <style>
      .table-header { font-weight: 500; font-size: 13px; }
      .column-name { font-size: 12px; }
      .column-type { font-size: 10px; opacity: 0.8; }
    </style>
  </defs>
  <rect width="100%" height="100%" fill="${cardColor}" />
  
  <text x="${totalWidth / 2}" y="30" text-anchor="middle" font-size="18" font-weight="600" fill="${foregroundColor}">
    ${escapeXml(schema.database.name)}
  </text>
`

  schema.tables.forEach((table, index) => {
    const col = index % cols
    const row = Math.floor(index / cols)
    const x = padding + col * (tableWidth + tableSpacing)
    const y = padding + 60 + row * (tableHeight + tableSpacing)

    // Table box
    svg += `  <rect x="${x}" y="${y}" width="${tableWidth}" height="${tableHeight}" rx="8" fill="${cardColor}" stroke="${borderColor}" stroke-width="1" />\n`

    // Header
    svg += `  <rect x="${x}" y="${y}" width="${tableWidth}" height="40" rx="8" fill="${headerBgColor}" />\n`
    svg += `  <line x1="${x}" y1="${y + 40}" x2="${x + tableWidth}" y2="${y + 40}" stroke="${borderColor}" stroke-width="1" />\n`

    // Table name
    svg += `  <text x="${x + 12}" y="${y + 26}" class="table-header" fill="${foregroundColor}">${escapeXml(table.name)}</text>\n`

    // Columns (show first 5)
    const visibleColumns = table.columns.slice(0, 5)
    visibleColumns.forEach((col, colIndex) => {
      const colY = y + 50 + colIndex * 25
      svg += `  <text x="${x + 12}" y="${colY}" class="column-name" fill="${foregroundColor}">${escapeXml(col.key)}</text>\n`
      svg += `  <text x="${x + tableWidth - 12}" y="${colY}" class="column-type" text-anchor="end" fill="${foregroundColor}">${escapeXml(col.type)}</text>\n`
    })

    if (table.columns.length > 5) {
      svg += `  <text x="${x + tableWidth / 2}" y="${y + tableHeight - 10}" text-anchor="middle" font-size="10" fill="${foregroundColor}" opacity="0.6">+${table.columns.length - 5} more</text>\n`
    }
  })

  svg += `</svg>`

  return svg
}

function escapeXml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

/**
 * Generates deep link URL for opening schema in Cursor IDE
 * Based on: https://cursor.com/docs/integrations/deeplinks
 */
export function getCursorDeepLink(schema: DatabaseSchema): string {
  const json = formatSchemaAsJSON(schema)
  const prompt = `Here is my database schema:\n\n${json}\n\nHelp me understand and work with this database structure.`
  const encoded = encodeURIComponent(prompt)
  // Cursor uses app protocol: cursor://anysphere.cursor-deeplink/prompt?text=...
  return `cursor://anysphere.cursor-deeplink/prompt?text=${encoded}`
}

/**
 * Generates deep link URL for opening schema in Lovable IDE
 * Based on: https://lovable.dev/blog/introducing-lovable-api-build-with-url
 */
export function getLovableDeepLink(schema: DatabaseSchema): string {
  const json = formatSchemaAsJSON(schema)
  const prompt = `Here is my database schema:\n\n${json}\n\nHelp me understand and work with this database structure.`
  const encoded = encodeURIComponent(prompt)
  // Lovable uses ?autosubmit=true#prompt= format
  return `https://lovable.dev/?autosubmit=true#prompt=${encoded}`
}

/**
 * Generates deep link URL for opening schema in ChatGPT
 */
export function getChatGPTDeepLink(schema: DatabaseSchema): string {
  const markdown = formatSchemaAsMarkdown(schema)
  const prompt = `Here is my database schema:\n\n${markdown}\n\nHelp me understand and work with this database structure.`
  const encoded = encodeURIComponent(prompt)
  // ChatGPT uses ?prompt= parameter
  return `https://chatgpt.com/?prompt=${encoded}`
}

/**
 * Generates deep link URL for opening schema in Claude
 */
export function getClaudeDeepLink(schema: DatabaseSchema): string {
  const markdown = formatSchemaAsMarkdown(schema)
  const prompt = `Here is my database schema:\n\n${markdown}\n\nHelp me understand and work with this database structure.`
  const encoded = encodeURIComponent(prompt)
  // Claude uses ?q= parameter
  return `https://claude.ai/new?q=${encoded}`
}

/**
 * Downloads a blob as a file
 */
export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

/**
 * Downloads content as a file
 */
export function downloadAsFile(
  content: string,
  filename: string,
  mimeType: string = 'text/plain',
) {
  downloadBlob(new Blob([content], { type: mimeType }), filename)
}
