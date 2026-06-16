import { Copy, FileJson, Link2, Settings } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
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

type ProjectListCardActionsMenuProps = {
  project: ProjectListItem
  showSettingsTab: boolean
}

export function ProjectListCardActionsMenu({
  project,
  showSettingsTab,
}: ProjectListCardActionsMenuProps) {
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
            <Copy className="mr-2 h-4 w-4" />
            Copy
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem
              onSelect={() => copyToClipboard('ID', project.$id)}
            >
              <Copy className="mr-2 h-4 w-4" />
              Copy ID
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Endpoint', endpoint)}
            >
              <Link2 className="mr-2 h-4 w-4" />
              Copy endpoint
            </DropdownMenuItem>
            {hasName ? (
              <DropdownMenuItem
                onSelect={() => copyToClipboard('Name', project.name)}
              >
                <Copy className="mr-2 h-4 w-4" />
                Copy name
              </DropdownMenuItem>
            ) : null}
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Link', projectHref)}
            >
              <Link2 className="mr-2 h-4 w-4" />
              Copy link
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchProject(project.$id))
              }
            >
              <FileJson className="mr-2 h-4 w-4" />
              Copy as JSON
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
              <Settings className="mr-2 h-4 w-4" />
              Settings
            </DropdownMenuItem>
          </>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
