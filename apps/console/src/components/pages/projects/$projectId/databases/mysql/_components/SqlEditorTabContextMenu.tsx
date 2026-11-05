import { useMemo, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  Copy,
  ExternalLink,
  FileJson,
  Key,
  LayoutGrid,
  Rows3,
  Settings,
  Square,
  X,
} from 'lucide-react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  parseMysqlTableId,
  mysqlNav,
  type MysqlTableTab,
} from '@/lib/mysql-database-routes'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import {
  useMysqlSidebar,
  type SqlEditorTab,
} from './MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

type SqlEditorTabContextMenuProps = {
  projectId: string
  databaseId: string
  tab: SqlEditorTab
  canClose: boolean
  hasOtherTabs: boolean
  onSelectTab: (tabId: string) => void
  onCloseTab: (tabId: string) => void
  children: ReactNode
}

const TABLE_TABS: {
  id: string
  label: string
  path: MysqlTableTab
  icon: typeof LayoutGrid
}[] = [
  { id: 'rows', label: 'Rows', path: 'rows', icon: Rows3 },
  { id: 'columns', label: 'Columns', path: 'columns', icon: LayoutGrid },
  { id: 'indexes', label: 'Indexes', path: 'indexes', icon: Key },
  { id: 'settings', label: 'Settings', path: 'settings', icon: Settings },
]

export function SqlEditorTabContextMenu({
  projectId,
  databaseId,
  tab,
  canClose,
  hasOtherTabs,
  onSelectTab,
  onCloseTab,
  children,
}: SqlEditorTabContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const { duplicateQueryTab, closeOtherTabs } = useMysqlSidebar()

  const tableId = tab.tableId
  const { table: tableName } = tableId
    ? parseMysqlTableId(tableId)
    : { table: tab.title }

  const tableNav = useMemo(
    () =>
      tableId
        ? mysqlNav({ projectId, databaseId }).table({ tableId })
        : null,
    [databaseId, projectId, tableId],
  )

  const tableRowsHref = useMemo(() => {
    if (!tableId) return null
    return buildConsoleUrl(
      `/projects/${projectId}/databases/mysql/${databaseId}/tables/${encodeURIComponent(tableId)}/rows`,
    )
  }, [databaseId, projectId, tableId])

  const handleGoToTableTab = (path: MysqlTableTab) => {
    if (!tableNav) return
    if (path === 'rows') {
      navigate({ ...tableNav.rows() })
      return
    }
    if (path === 'columns') {
      navigate({ ...tableNav.columns() })
      return
    }
    if (path === 'indexes') {
      navigate({ ...tableNav.indexes() })
      return
    }
    navigate({ ...tableNav.settings() })
  }

  const handleCopyAsJson = () => {
    void copyToClipboard(
      'JSON',
      toPrettyJson({
        id: tab.id,
        title: tab.title,
        sql: tab.sql,
        ...(tab.tableId ? { tableId: tab.tableId } : {}),
      }),
    )
  }

  const handleDuplicate = () => {
    duplicateQueryTab(tab.sql)
  }

  const handleCloseOtherTabs = () => {
    closeOtherTabs(tab.id)
    onSelectTab(tab.id)
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-52">
        {tableId && tableNav ? (
          <>
            {TABLE_TABS.map(({ id, label, path, icon: Icon }) => (
              <ContextMenuItem key={id} onSelect={() => handleGoToTableTab(path)}>
                <ContextMenuIcon icon={Icon} />
                {label}
              </ContextMenuItem>
            ))}
            <ContextMenuSeparator />
          </>
        ) : null}
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            {tableId ? (
              <ContextMenuItem
                onSelect={() => void copyToClipboard('ID', tableId)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() => void copyToClipboard('Name', tableName)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            {tableRowsHref ? (
              <ContextMenuItem
                onSelect={() => void copyToClipboard('Link', tableRowsHref)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy link')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() => void copyToClipboard('SQL', tab.sql)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy SQL')}
            </ContextMenuItem>
            {!tableId ? (
              <ContextMenuItem onSelect={handleDuplicate}>
                <ContextMenuIcon icon={Copy} />
                {t('Duplicate')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem onSelect={handleCopyAsJson}>
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {tableRowsHref ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onSelect={() => openInNewTab(tableRowsHref)}>
              <ContextMenuIcon icon={ExternalLink} />
              {t('Open in new tab')}
            </ContextMenuItem>
            <ContextMenuItem onSelect={() => openInNewWindow(tableRowsHref)}>
              <ContextMenuIcon icon={Square} />
              {t('Open in new window')}
            </ContextMenuItem>
          </>
        ) : null}
        {canClose ? (
          <>
            <ContextMenuSeparator />
            {hasOtherTabs ? (
              <ContextMenuItem onSelect={handleCloseOtherTabs}>
                <ContextMenuIcon icon={X} />
                {t('Close other tabs')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem onSelect={() => onCloseTab(tab.id)}>
              <ContextMenuIcon icon={X} />
              {t('Close')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
