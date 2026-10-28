import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, test } from 'bun:test'

const consoleRoot = join(import.meta.dir, '../..')

const tablesdbWorkspacePath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/tablesdb/Workspace.tsx',
)

const documentsdbWorkspacePath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/documentsdb/Workspace.tsx',
)

/**
 * Regression for CONSOLE-V4-1RC: ColumnsSpreadsheet reads table.$id on mount.
 * Workspace must not render column/index spreadsheets until a table is selected
 * (same guard as documents rows / documentsdb columns).
 */
describe('tablesdb Workspace columns tab without selected table', () => {
  test('matches documentsdb guard before mounting column and index spreadsheets', () => {
    const tablesdb = readFileSync(tablesdbWorkspacePath, 'utf8')
    const documentsdb = readFileSync(documentsdbWorkspacePath, 'utf8')

    expect(documentsdb).toContain("{activeTab === 'columns' && selectedTable ? (")
    expect(documentsdb).toContain("{activeTab === 'indexes' && selectedTable ? (")

    expect(tablesdb).toContain("{selectedTable && activeTab === 'documents'")
    expect(tablesdb).toContain("{activeTab === 'columns' && selectedTable ? (")
    expect(tablesdb).toContain("{activeTab === 'indexes' && selectedTable ? (")
    expect(tablesdb).not.toMatch(
      /\{activeTab === 'columns' && \(\s*\n\s*<ColumnsSpreadsheet/,
    )
    expect(tablesdb).not.toMatch(
      /\{activeTab === 'indexes' && \(\s*\n\s*<IndexesSpreadsheet/,
    )
  })
})
