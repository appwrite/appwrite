import {
  Copy,
  FileJson,
  LayoutList,
  SearchCode,
  StopCircle,
  Unplug,
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
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  isPostgresClientBackend,
  serializePostgresActiveConnectionJson,
  type PostgresActiveConnectionRow,
} from '@/lib/postgres-metrics'
import { useT } from '@/lib/i18n/translate'

type PostgresConnectionContextMenuProps = {
  connection: PostgresActiveConnectionRow
  canManageConnections: boolean
  onOpenDetails: () => void
  onOpenInSqlEditor: (sql: string) => void
  onCancelQuery: () => void
  onTerminateConnection: () => void
  children: React.ReactNode
}

function normalizeQuery(query: string | null): string | null {
  if (!query) return null
  const trimmed = query.trim()
  return trimmed || null
}

export function PostgresConnectionContextMenu({
  connection,
  canManageConnections,
  onOpenDetails,
  onOpenInSqlEditor,
  onCancelQuery,
  onTerminateConnection,
  children,
}: PostgresConnectionContextMenuProps) {
  const t = useT()
  const query = normalizeQuery(connection.query)
  const isClient = isPostgresClientBackend(connection)
  const canCancel = isClient && connection.state?.toLowerCase() === 'active'
  const canTerminate = isClient

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-52">
        <ContextMenuItem onSelect={() => onOpenDetails()}>
          <ContextMenuIcon icon={LayoutList} />
          {t('Overview')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() =>
                void copyToClipboard('PID', String(connection.pid))
              }
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy PID')}
            </ContextMenuItem>
            {query ? (
              <ContextMenuItem
                onSelect={() => void copyToClipboard('Query', query)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy query')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyToClipboard(
                  'JSON',
                  serializePostgresActiveConnectionJson(connection),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {query ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onSelect={() => onOpenInSqlEditor(query)}>
              <ContextMenuIcon icon={SearchCode} />
              {t('Open in SQL editor')}
            </ContextMenuItem>
          </>
        ) : null}
        {isClient ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              disabled={!canManageConnections || !canCancel}
              onSelect={() => onCancelQuery()}
            >
              <ContextMenuIcon icon={StopCircle} />
              {t('Cancel query')}
            </ContextMenuItem>
            <ContextMenuItem
              disabled={!canManageConnections || !canTerminate}
              onSelect={() => onTerminateConnection()}
            >
              <ContextMenuIcon icon={Unplug} />
              {t('Terminate connection')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
