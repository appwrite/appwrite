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
  BarChart3,
  Archive,
  ArrowRightLeft,
  Settings,
} from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

type DatabaseContextMenuDatabase = {
  $id: string
  name?: string | null
}

interface DatabaseContextMenuProps {
  projectId: string
  database: DatabaseContextMenuDatabase
  showSecuritySettings: boolean
  showBackups: boolean
  showInsights: boolean
  children: React.ReactNode
}

export function DatabaseContextMenu({
  projectId,
  database,
  showSecuritySettings,
  showBackups,
  showInsights,
  children,
}: DatabaseContextMenuProps) {
  const navigate = useNavigate()

  const databaseHref = buildConsoleUrl(
    `/projects/${projectId}/databases/${database.$id}/`,
  )
  const hasName = !!database.name

  const navigateToTab = (
    path:
      | '/projects/$projectId/databases/$databaseId/'
      | '/projects/$projectId/databases/$databaseId/visualizer'
      | '/projects/$projectId/databases/$databaseId/security'
      | '/projects/$projectId/databases/$databaseId/insights'
      | '/projects/$projectId/databases/$databaseId/backups'
      | '/projects/$projectId/databases/$databaseId/export-import'
      | '/projects/$projectId/databases/$databaseId/settings',
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
            navigateToTab('/projects/$projectId/databases/$databaseId/')
          }
        >
          <ContextMenuIcon icon={Table2} />
          Tables
        </ContextMenuItem>
        <ContextMenuItem
          onSelect={() =>
            navigateToTab(
              '/projects/$projectId/databases/$databaseId/visualizer',
            )
          }
        >
          <ContextMenuIcon icon={Workflow} />
          Visualizer
        </ContextMenuItem>
        {showSecuritySettings && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$databaseId/security',
              )
            }
          >
            <ContextMenuIcon icon={Shield} />
            Security
          </ContextMenuItem>
        )}
        {showInsights && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$databaseId/insights',
              )
            }
          >
            <ContextMenuIcon icon={BarChart3} />
            Insights
          </ContextMenuItem>
        )}
        {showBackups && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$databaseId/backups',
              )
            }
          >
            <ContextMenuIcon icon={Archive} />
            Backups
          </ContextMenuItem>
        )}
        <ContextMenuItem
          onSelect={() =>
            navigateToTab(
              '/projects/$projectId/databases/$databaseId/export-import',
            )
          }
        >
          <ContextMenuIcon icon={ArrowRightLeft} />
          Export / Import
        </ContextMenuItem>
        {showSecuritySettings && (
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$databaseId/settings',
              )
            }
          >
            <ContextMenuIcon icon={Settings} />
            Settings
          </ContextMenuItem>
        )}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', database.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            {hasName && (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', database.name)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy name
              </ContextMenuItem>
            )}
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', databaseHref)}
            >
              <ContextMenuIcon icon={Link2} />
              Copy link
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard(
                  'JSON',
                  toPrettyJson({
                    id: database.$id,
                    name: database.name ?? null,
                  }),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(databaseHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          Open in new tab
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(databaseHref)}>
          <ContextMenuIcon icon={Square} />
          Open in new window
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
