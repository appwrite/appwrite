import { useState } from 'react'
import {
  Copy,
  Trash2,
  Link2,
  ExternalLink,
  Square,
  FileJson,
  FolderGit,
  Globe,
  Play,
  Variable,
  Shield,
  Settings,
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
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import {
  fetchProjectFunction,
  useDeleteFunction,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowFunctionSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

export type FunctionContextMenuFunction = {
  $id: string
  name?: string | null
}

interface FunctionContextMenuProps {
  projectId: string
  func: FunctionContextMenuFunction
  children: React.ReactNode
}

export function FunctionContextMenu({
  projectId,
  func,
  children,
}: FunctionContextMenuProps) {
  const navigate = useNavigate()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteFunction(projectId)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecuritySettings = canShowFunctionSecuritySettings(access, features)

  if (!func?.$id) {
    return <>{children}</>
  }

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/functions/${func.$id}`
    const path = tab === 'deployments' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/functions/$functionId',
      params: { projectId, functionId: func.$id },
    })
  }

  const functionHref = buildConsoleUrl(
    `/projects/${projectId}/functions/${func.$id}/`,
  )

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  const handleConfirmDelete = () => {
    deleteMutation.mutate(func.$id, {
      onSuccess: () => {
        toast.success('Function deleted')
        setDeleteDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete function')
      },
    })
  }

  const hasName = !!func.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent
          className="w-56"
          data-analytics-surface="function_context_menu"
          data-analytics-resource="function"
        >
          <ContextMenuItem
            onSelect={() => navigateToTab('deployments')}
            data-analytics-id="function_context_tab"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
            data-analytics-prop-tab="deployments"
          >
            <ContextMenuIcon icon={FolderGit} />
            Deployments
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => navigateToTab('domains')}
            data-analytics-id="function_context_tab"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
            data-analytics-prop-tab="domains"
          >
            <ContextMenuIcon icon={Globe} />
            Domains
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => navigateToTab('executions')}
            data-analytics-id="function_context_tab"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
            data-analytics-prop-tab="executions"
          >
            <ContextMenuIcon icon={Play} />
            Executions
          </ContextMenuItem>
          {showSecuritySettings && (
            <>
              <ContextMenuItem
                onSelect={() => navigateToTab('variables')}
                data-analytics-id="function_context_tab"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
                data-analytics-prop-tab="variables"
              >
                <ContextMenuIcon icon={Variable} />
                Variables
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => navigateToTab('security')}
                data-analytics-id="function_context_tab"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
                data-analytics-prop-tab="security"
              >
                <ContextMenuIcon icon={Shield} />
                Security
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() => navigateToTab('settings')}
                data-analytics-id="function_context_tab"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
                data-analytics-prop-tab="settings"
              >
                <ContextMenuIcon icon={Settings} />
                Settings
              </ContextMenuItem>
            </>
          )}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', func.$id)}
                data-analytics-id="function_context_copy_id"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', func.name)}
                  data-analytics-id="function_context_copy_name"
                  data-analytics-surface="function_context_menu"
                  data-analytics-resource="function"
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', functionHref)}
                data-analytics-id="function_context_copy_link"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProjectFunction(projectId, func.$id),
                  )
                }
                data-analytics-id="function_context_copy_json"
                data-analytics-surface="function_context_menu"
                data-analytics-resource="function"
              >
                <ContextMenuIcon icon={FileJson} />
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() => openInNewTab(functionHref)}
            data-analytics-id="function_context_open_new_tab"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
          >
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() => openInNewWindow(functionHref)}
            data-analytics-id="function_context_open_new_window"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
          >
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={handleDeleteClick}
            data-analytics-id="function_context_delete_open"
            data-analytics-surface="function_context_menu"
            data-analytics-resource="function"
          >
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent
          className="sm:max-w-md p-0"
          data-analytics-surface="function_delete_dialog"
          data-analytics-resource="function"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete function</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this function? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
              data-analytics-id="function_delete_cancel"
              data-analytics-surface="function_delete_dialog"
              data-analytics-resource="function"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteMutation.isPending}
              data-analytics-id="function_delete_confirm"
              data-analytics-surface="function_delete_dialog"
              data-analytics-resource="function"
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
