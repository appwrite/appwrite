import {
  Copy,
  LayoutList,
  SearchCode,
  StopCircle,
  Unplug,
} from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
} from '@/components/global/shared/ContextMenuIcon'
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  isMysqlClientBackend,
  type MysqlActiveConnectionRow,
} from '@/lib/mysql-metrics'
import { useT } from '@/lib/i18n/translate'

type MysqlConnectionRowActionsMenuProps = {
  connection: MysqlActiveConnectionRow
  canManageConnections: boolean
  onOpenDetails: () => void
  onOpenInSqlEditor: (sql: string) => void
  onCancelQuery: () => void
  onTerminateConnection: () => void
}

function normalizeQuery(query: string | null): string | null {
  if (!query) return null
  const trimmed = query.trim()
  return trimmed || null
}

export function MysqlConnectionRowActionsMenu({
  connection,
  canManageConnections,
  onOpenDetails,
  onOpenInSqlEditor,
  onCancelQuery,
  onTerminateConnection,
}: MysqlConnectionRowActionsMenuProps) {
  const t = useT()
  const query = normalizeQuery(connection.query)
  const isClient = isMysqlClientBackend(connection)
  const canCancel = isClient && connection.state?.toLowerCase() === 'active'
  const canTerminate = isClient

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger
          onClick={(event) => event.stopPropagation()}
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-52">
        <DropdownMenuItem
          onClick={(event) => {
            event.stopPropagation()
            onOpenDetails()
          }}
        >
          <MenuItemContent icon={LayoutList}>{t('Overview')}</MenuItemContent>
        </DropdownMenuItem>
        {query ? (
          <>
            <DropdownMenuItem
              onClick={(event) => {
                event.stopPropagation()
                onOpenInSqlEditor(query)
              }}
            >
              <MenuItemContent icon={SearchCode}>
                {t('Open in SQL editor')}
              </MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              onClick={(event) => {
                event.stopPropagation()
                void copyToClipboard('Query', query)
              }}
            >
              <MenuItemContent icon={Copy}>{t('Copy query')}</MenuItemContent>
            </DropdownMenuItem>
          </>
        ) : null}
        {isClient ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              disabled={!canManageConnections || !canCancel}
              onClick={(event) => {
                event.stopPropagation()
                onCancelQuery()
              }}
            >
              <MenuItemContent icon={StopCircle}>{t('Cancel query')}</MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={!canManageConnections || !canTerminate}
              onClick={(event) => {
                event.stopPropagation()
                onTerminateConnection()
              }}
            >
              <MenuItemContent icon={Unplug}>
                {t('Terminate connection')}
              </MenuItemContent>
            </DropdownMenuItem>
          </>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
