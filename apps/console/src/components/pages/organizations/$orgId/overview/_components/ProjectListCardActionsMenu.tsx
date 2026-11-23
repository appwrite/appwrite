import { Copy, FileJson, Link2, Settings } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  fetchProject,
  getProjectListItemEndpoint,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
} from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

type ProjectListCardActionsMenuProps = {
  project: ProjectListItem
  showSettingsTab: boolean
}

export function ProjectListCardActionsMenu({
  project,
  showSettingsTab,
}: ProjectListCardActionsMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const projectHref = buildConsoleUrl(`/projects/${project.$id}`)
  const endpoint = getProjectListItemEndpoint(project)
  const hasName = !!project.name

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger
          compact
          onClick={(event) => event.stopPropagation()}
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        className="w-56"
        onClick={(event) => event.stopPropagation()}
      >
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <MenuItemIcon icon={Copy} />
            {t('Copy')}
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem
              onSelect={() => copyToClipboard('ID', project.$id)}
            >
              <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Endpoint', endpoint)}
            >
              <MenuItemContent icon={Copy}>{t('Copy endpoint')}</MenuItemContent>
            </DropdownMenuItem>
            {hasName ? (
              <DropdownMenuItem
                onSelect={() => copyToClipboard('Name', project.name)}
              >
                <MenuItemContent icon={Copy}>{t('Copy name')}</MenuItemContent>
              </DropdownMenuItem>
            ) : null}
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Link', projectHref)}
            >
              <MenuItemContent icon={Link2}>{t('Copy link')}</MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchProject(project.$id))
              }
            >
              <MenuItemContent icon={FileJson}>{t('Copy as JSON')}</MenuItemContent>
            </DropdownMenuItem>
          </DropdownMenuSubContent>
        </DropdownMenuSub>
        {showSettingsTab ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onSelect={() =>
                navigate({
                  to: '/projects/$projectId/settings',
                  params: { projectId: project.$id },
                })
              }
            >
              <MenuItemContent icon={Settings}>{t('Settings')}</MenuItemContent>
            </DropdownMenuItem>
          </>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
