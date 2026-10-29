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
import * as actualRouter from '@tanstack/react-router'
import { cleanup, render } from '@testing-library/react'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'
import * as actualRequireAuth from '../../src/components/global/auth/RequireAuth.tsx'
import * as actualOperationsLock from '../../src/components/pages/projects/$projectId/databases/_components/DatabaseOperationsLockContext.tsx'
import * as actualConsoleProfile from '../../src/hooks/use-console-profile.ts'
import * as actualMediaMinWidth from '../../src/hooks/use-media-min-width.ts'
import * as actualMonitorFilters from '../../src/hooks/use-database-monitor-chart-filters.ts'
import * as actualTranslate from '../../src/lib/i18n/translate.ts'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'
import * as actualStripe from '@stripe/stripe-js'

const consoleRoot = join(import.meta.dir, '../..')

const spreadsheetPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/tablesdb/Spreadsheet.tsx',
)
const indexesSpreadsheetPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/tablesdb/IndexesSpreadsheet.tsx',
)
const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const operationsLockPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/_components/DatabaseOperationsLockContext.tsx',
)
const requireAuthPath = join(
  consoleRoot,
  'src/components/global/auth/RequireAuth.tsx',
)
const consoleProfilePath = join(consoleRoot, 'src/hooks/use-console-profile.ts')
const mediaMinWidthPath = join(consoleRoot, 'src/hooks/use-media-min-width.ts')
const monitorFiltersPath = join(
  consoleRoot,
  'src/hooks/use-database-monitor-chart-filters.ts',
)
const translatePath = join(consoleRoot, 'src/lib/i18n/translate.ts')
const workspacePath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/tablesdb/Workspace.tsx',
)

type MockTable = {
  $id: string
  name: string
  databaseId: string
  rows?: number
  columns?: number
  indexes?: number
  enabled?: boolean
}

type MockState = {
  dbTables: MockTable[]
  tableDetailLoading: boolean
  tableDataForStatus: Record<string, unknown> | null | undefined
}

let mockState: MockState = {
  dbTables: [],
  tableDetailLoading: true,
  tableDataForStatus: null,
}

function nullComponent() {
  return null
}

function installMocks() {
  mock.module(spreadsheetPath, () => ({
    DocumentsRowCreateBridge: nullComponent,
    ColumnsSpreadsheet: () =>
      createElement('div', {
        'data-testid': 'columns-spreadsheet',
      }),
    RowsSpreadsheet: nullComponent,
    TableSecurity: nullComponent,
    TableSettings: nullComponent,
  }))

  mock.module(indexesSpreadsheetPath, () => ({
    IndexesSpreadsheet: () =>
      createElement('div', {
        'data-testid': 'indexes-spreadsheet',
      }),
  }))

  mock.module(hooksPath, () => ({
    ...actualHooks,
    useProjectDatabase: () => ({
      database: {
        $id: 'db-1',
        name: 'Test database',
        enabled: true,
      },
      isLoading: false,
    }),
    useResolvedProductDatabaseLifecycleStatus: () => 'ready',
    useProjectTables: () => ({
      tables: mockState.dbTables,
      total: mockState.dbTables.length,
      isLoading: false,
      isFetching: false,
      refetch: async () => ({}),
    }),
    useProjectTable: () => ({
      table: mockState.tableDataForStatus,
      isLoading: mockState.tableDetailLoading,
    }),
    useProjectTableColumns: () => ({ columns: [] }),
    useProjectTableIndexes: () => ({ indexes: [] }),
    useProject: () => ({ project: { teamId: 'team-1' } }),
    useOrganizationPlan: () => ({ plan: undefined }),
    useOrganizationScopes: () => ({
      access: {
        isOwner: true,
        isDeveloper: true,
        canWriteTables: true,
        canWriteRows: true,
      },
    }),
    useTablesDbRowsListColumns: () => ({ savedAttrKeys: [] }),
  }))

  mock.module(operationsLockPath, () => ({
    ...actualOperationsLock,
    useDatabaseTableOperationsAccess: () => ({
      canWrite: true,
      writeDisabled: false,
      writeTooltip: undefined,
      isOperationsLocked: false,
      isWriteLocked: false,
    }),
    useDatabaseRowOperationsAccess: () => ({
      canWrite: true,
      writeDisabled: false,
      writeTooltip: undefined,
      isOperationsLocked: false,
      isWriteLocked: false,
    }),
  }))

  mock.module(requireAuthPath, () => ({
    ...actualRequireAuth,
    useAuth: () => ({ account: { prefs: {} } }),
  }))

  mock.module(consoleProfilePath, () => ({
    ...actualConsoleProfile,
    useConsoleProfile: () => ({
      profileId: 'cloud',
      profile: { id: 'cloud', features: { orgRoles: false, agent: false } },
      features: { orgRoles: false, agent: false },
    }),
  }))

  mock.module(mediaMinWidthPath, () => ({
    ...actualMediaMinWidth,
    useMediaMinWidth: () => false,
  }))

  mock.module(monitorFiltersPath, () => ({
    ...actualMonitorFilters,
    useDatabaseMonitorChartFilters: () => ({
      dateRange: { from: new Date(), to: new Date() },
      chartInterval: '1h',
      dateRangePresetId: '1h',
      planChartIntervals: ['1h'],
      setDateRange: () => {},
      setChartInterval: () => {},
      refreshRollingDateRange: () => {},
      reset: () => {},
    }),
  }))

  mock.module(translatePath, () => {
    const identity = (text: string) => text
    return {
      ...actualTranslate,
      useT: () => identity,
      translate: identity,
      translateText: identity,
    }
  })

  mock.module('@stripe/stripe-js', () => ({
    ...actualStripe,
    loadStripe: async () => null,
  }))

  mock.module('@tanstack/react-router', () => ({
    ...actualRouter,
    Link: ({
      children,
      ...props
    }: {
      children?: ReactNode
      to?: string
    }) => createElement('a', props, children),
    useNavigate: () => () => {},
    useParams: () => ({
      projectId: 'project-1',
      databaseId: 'db-1',
      tableId: 'table-1',
      dbKind: 'tablesdb',
    }),
    useLocation: () => ({
      pathname:
        '/projects/project-1/databases/tablesdb/db-1/tables/table-1/columns',
      search: {},
      href: 'http://localhost/projects/project-1/databases/tablesdb/db-1/tables/table-1/columns',
    }),
    useSearch: () => ({}),
  }))
}

beforeAll(async () => {
  GlobalRegistrator.register()
  installMocks()
})

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  mockState = {
    dbTables: [],
    tableDetailLoading: true,
    tableDataForStatus: null,
  }
})

function renderWorkspace(activeTab: 'columns' | 'indexes') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return import(workspacePath).then(({ Workspace }) =>
    render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Workspace, {
          databaseId: 'db-1',
          tableId: 'table-1',
          activeTab,
        }),
      ),
    ),
  )
}

describe('tablesdb Workspace spreadsheet mounts', () => {
  test('does not render column or index spreadsheets while no table is selected', async () => {
    mockState = {
      dbTables: [],
      tableDetailLoading: true,
      tableDataForStatus: null,
    }

    const columnsView = await renderWorkspace('columns')
    expect(columnsView.queryByTestId('columns-spreadsheet')).toBeNull()
    expect(columnsView.queryByTestId('indexes-spreadsheet')).toBeNull()

    const indexesView = await renderWorkspace('indexes')
    expect(indexesView.queryByTestId('columns-spreadsheet')).toBeNull()
    expect(indexesView.queryByTestId('indexes-spreadsheet')).toBeNull()
  })

  test('renders the column spreadsheet when a table is selected on the columns tab', async () => {
    mockState = {
      dbTables: [
        {
          $id: 'table-1',
          name: 'Users',
          databaseId: 'db-1',
          rows: 0,
          columns: 0,
          indexes: 0,
          enabled: true,
        },
      ],
      tableDetailLoading: false,
      tableDataForStatus: {
        $id: 'table-1',
        name: 'Users',
        total: 0,
        attributes: [],
        indexes: [],
        enabled: true,
      },
    }

    const view = await renderWorkspace('columns')
    expect(view.getByTestId('columns-spreadsheet')).toBeTruthy()
    expect(view.queryByTestId('indexes-spreadsheet')).toBeNull()
  })

  test('renders the index spreadsheet when a table is selected on the indexes tab', async () => {
    mockState = {
      dbTables: [
        {
          $id: 'table-1',
          name: 'Users',
          databaseId: 'db-1',
          rows: 0,
          columns: 0,
          indexes: 0,
          enabled: true,
        },
      ],
      tableDetailLoading: false,
      tableDataForStatus: {
        $id: 'table-1',
        name: 'Users',
        total: 0,
        attributes: [],
        indexes: [],
        enabled: true,
      },
    }

    const view = await renderWorkspace('indexes')
    expect(view.getByTestId('indexes-spreadsheet')).toBeTruthy()
    expect(view.queryByTestId('columns-spreadsheet')).toBeNull()
  })
})
