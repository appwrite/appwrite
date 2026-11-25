import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  mock,
  test,
} from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, render } from '@testing-library/react'
import { createElement, type ComponentType } from 'react'
import { join } from 'node:path'
import * as actualMysqlSql from '../../src/lib/mysql-sql.ts'
import * as actualPostgresSql from '../../src/lib/postgres-sql.ts'
import { getMysqlSqlEditorActions } from '../../src/lib/mysql-sql-editor-actions.ts'
import { getPostgresSqlEditorActions } from '../../src/lib/postgres-sql-editor-actions.ts'

const consoleRoot = join(import.meta.dir, '../..')
const databases = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases',
)

/** The formatter loads on first use; each call waits here until resolved. */
let pendingFormat: ((formatted: string) => void) | null = null
function formatLater() {
  return new Promise<string>((resolve) => {
    pendingFormat = resolve
  })
}

function nullComponent() {
  return null
}

beforeAll(() => {
  GlobalRegistrator.register()
  mock.module(join(consoleRoot, 'src/lib/mysql-sql.ts'), () => ({
    ...actualMysqlSql,
    formatMysqlSql: formatLater,
  }))
  mock.module(join(consoleRoot, 'src/lib/postgres-sql.ts'), () => ({
    ...actualPostgresSql,
    formatPostgresSql: formatLater,
  }))
  // The editor chrome needs the router and auth; the format action is
  // registered by the workbench itself, so the tests drive it directly.
  for (const engine of ['mysql', 'postgres']) {
    const prefix = engine === 'mysql' ? 'Mysql' : 'Postgres'
    mock.module(
      join(databases, `${engine}/${prefix}SqlEditorContainer.tsx`),
      () => ({
        [`${prefix}SqlEditorContainer`]: nullComponent,
      }),
    )
    mock.module(
      join(databases, `${engine}/_components/Save${prefix}QueryDialog.tsx`),
      () => ({ [`Save${prefix}QueryDialog`]: nullComponent }),
    )
  }
})

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  pendingFormat = null
})

const workbenches = [
  {
    engine: 'mysql',
    getActions: getMysqlSqlEditorActions,
  },
  {
    engine: 'postgres',
    getActions: getPostgresSqlEditorActions,
  },
] as const

async function renderWorkbench(engine: string) {
  const { SqlWorkbench } = (await import(
    join(databases, `${engine}/SqlWorkbench.tsx`)
  )) as { SqlWorkbench: ComponentType<Record<string, unknown>> }
  const onSqlChange = mock(() => {})
  const tabs = [
    { id: 'tab-1', title: 'Query 1', sql: 'select 1' },
    { id: 'tab-2', title: 'Query 2', sql: 'select 2' },
  ]
  const queryClient = new QueryClient()
  const props = (activeTabId: string, sql: string) => ({
    projectId: 'project-1',
    databaseId: 'db-1',
    tabs,
    activeTabId,
    sql,
    onSqlChange,
    onSelectTab: () => {},
    onCreateTab: () => {},
    onCloseTab: () => {},
    onReorderTabs: () => {},
    onRenameTab: () => {},
    onRun: () => {},
    onExplain: () => {},
    onAnalyze: () => {},
    isRunning: false,
    isExplaining: false,
    error: null,
    result: null,
    explainResult: null,
    account: undefined,
    teamId: null,
    canSaveTeam: false,
  })
  const view = (activeTabId: string, sql: string) =>
    createElement(
      QueryClientProvider,
      { client: queryClient },
      createElement(SqlWorkbench, props(activeTabId, sql)),
    )
  const { rerender } = render(view('tab-1', 'select 1'))
  return {
    onSqlChange,
    show: (activeTabId: string, sql: string) =>
      rerender(view(activeTabId, sql)),
  }
}

async function finishFormat(formatted: string) {
  await act(async () => {
    pendingFormat?.(formatted)
  })
}

describe.each(workbenches)(
  '$engine SqlWorkbench format',
  ({ engine, getActions }) => {
    test('applies the formatted SQL to the query it was made from', async () => {
      const { onSqlChange } = await renderWorkbench(engine)

      getActions()?.format()
      await finishFormat('SELECT\n  1')

      expect(onSqlChange).toHaveBeenCalledWith('SELECT\n  1')
    })

    test('keeps an edit made while the formatter loads', async () => {
      const { onSqlChange, show } = await renderWorkbench(engine)

      getActions()?.format()
      show('tab-1', 'select 1, 2')
      await finishFormat('SELECT\n  1')

      expect(onSqlChange).not.toHaveBeenCalled()
    })

    test('leaves another tab alone when the user switches before it loads', async () => {
      const { onSqlChange, show } = await renderWorkbench(engine)

      getActions()?.format()
      show('tab-2', 'select 2')
      await finishFormat('SELECT\n  1')

      expect(onSqlChange).not.toHaveBeenCalled()
    })
  },
)
