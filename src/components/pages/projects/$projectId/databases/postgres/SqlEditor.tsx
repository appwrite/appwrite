import { useCallback } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import {
  useExecutePostgresSql,
  useProject,
} from '@/lib/react-query/hooks'
import { postgresTableRows } from '@/lib/postgres-database-routes'
import { PostgresShell } from './PostgresShell'
import { SqlWorkbench } from './SqlWorkbench'
import { SqlWorkbenchPanelEmptyState } from './_components/SqlWorkbenchPanelEmptyState'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'

type SqlEditorProps = {
  databaseId: string
}

export function View({ databaseId }: SqlEditorProps) {
  return (
    <PostgresShell databaseId={databaseId} databaseTab="sql">
      <SqlEditorContent databaseId={databaseId} />
    </PostgresShell>
  )
}

function SqlEditorContent({ databaseId }: SqlEditorProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { account } = useAuth()
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(teamId ?? undefined)
  const canSaveTeam = canSaveTeamFilters(access, features)

  const {
    tabs,
    activeTabId,
    activeTab,
    setActiveTabId,
    updateActiveTabSql,
    setActiveTabResult,
    createTab,
    closeTab,
    reorderTabs,
    addRecentQuery,
  } = usePostgresSidebar()

  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const handleRunSql = useCallback(async () => {
    const trimmed = activeTab.sql.trim()
    if (!trimmed) return
    setActiveTabResult(null, null)
    try {
      const result = await executeSql.mutateAsync(trimmed)
      setActiveTabResult(result, null)
      addRecentQuery(trimmed)
    } catch (error) {
      setActiveTabResult(null, error)
    }
  }, [activeTab.sql, addRecentQuery, executeSql, setActiveTabResult])

  const handleSelectTab = useCallback(
    (tabId: string) => {
      setActiveTabId(tabId)
      const tab = tabs.find((entry) => entry.id === tabId)
      if (tab?.tableId) {
        navigate({
          ...postgresTableRows({
            projectId,
            databaseId,
            tableId: tab.tableId,
          }),
          replace: true,
        })
      }
    },
    [databaseId, navigate, projectId, setActiveTabId, tabs],
  )

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <SqlWorkbench
        projectId={projectId}
        databaseId={databaseId}
        tabs={tabs}
        activeTabId={activeTabId}
        sql={activeTab.sql}
        onSqlChange={updateActiveTabSql}
        onSelectTab={handleSelectTab}
        onCreateTab={createTab}
        onCloseTab={closeTab}
        onReorderTabs={reorderTabs}
        onRun={handleRunSql}
        isRunning={executeSql.isPending}
        error={activeTab.error ?? executeSql.error}
        result={activeTab.result}
        account={account}
        teamId={teamId}
        canSaveTeam={canSaveTeam}
      >
        <SqlWorkbenchPanelEmptyState variant="results" />
      </SqlWorkbench>
    </div>
  )
}
