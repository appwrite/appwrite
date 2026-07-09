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
import {
  Copy,
  Link2,
  FileJson,
  ExternalLink,
  Square,
  Table2,
  Workflow,
  Shield,
  Archive,
  ArrowRightLeft,
  Settings,
  Activity,
} from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { fetchProjectDatabase } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

type DatabaseContextMenuDatabase = {
  $id: string
  name?: string | null
}

interface DatabaseContextMenuProps {
  projectId: string
  database: DatabaseContextMenuDatabase
  showSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  children: React.ReactNode
}

export function DatabaseContextMenu({
  projectId,
  database,
  showSecuritySettings,
  showMonitor,
  showBackups,
  children,
}: DatabaseContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()

  const databaseHref = buildConsoleUrl(
    `/projects/${projectId}/databases/${database.$id}/`,
  )
  const hasName = !!database.name

  const navigateToTab = (
    path:
      | '/projects/$projectId/databases/$dbKind/$databaseId/'
      | '/projects/$projectId/databases/$dbKind/$databaseId/visualizer'
      | '/projects/$projectId/databases/$dbKind/$databaseId/monitor'
      | '/projects/$projectId/databases/$dbKind/$databaseId/db-security'
      | '/projects/$projectId/databases/$dbKind/$databaseId/backups'
      | '/projects/$projectId/databases/$dbKind/$databaseId/export-import'
      | '/projects/$projectId/databases/$dbKind/$databaseId/settings',
  ) => {
    navigate({
      to: path,
      params: { projectId, databaseId: database.$id },
    })
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() =>
            navigateToTab('/projects/$projectId/databases/$dbKind/$databaseId/')
          }
        >
          <ContextMenuIcon icon={Table2} />
          {t('Tables')}
        </ContextMenuItem>
        <ContextMenuItem
          onSelect={() =>
            navigateToTab(
              '/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
            )
          }
        >
          <ContextMenuIcon icon={Workflow} />
          {t('Visualizer')}
        </ContextMenuItem>
        {showMonitor && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
              )
            }
          >
            <ContextMenuIcon icon={Activity} />
            {t('Monitor')}
          </ContextMenuItem>
        )}
        {showSecuritySettings && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/db-security',
              )
            }
          >
            <ContextMenuIcon icon={Shield} />
            {t('Security')}
          </ContextMenuItem>
        )}
        {showBackups && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/backups',
              )
            }
          >
            <ContextMenuIcon icon={Archive} />
            {t('Backups')}
          </ContextMenuItem>
        )}
        <ContextMenuItem
          onSelect={() =>
            navigateToTab(
              '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
            )
          }
        >
          <ContextMenuIcon icon={ArrowRightLeft} />
          {t('Export / Import')}
        </ContextMenuItem>
        {showSecuritySettings && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/settings',
              )
            }
          >
            <ContextMenuIcon icon={Settings} />
            {t('Settings')}
          </ContextMenuItem>
        )}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', database.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {hasName && (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', database.name)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
            )}
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', databaseHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchProjectDatabase(projectId, database.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(databaseHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(databaseHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
