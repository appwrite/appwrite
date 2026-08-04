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
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
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
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(func.$id, {
      onSuccess: () => {
        toast.success(t('Function deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete function'))
      },
    })
  }

  const hasName = !!func.name

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem onSelect={() => navigateToTab('deployments')}>
            <ContextMenuIcon icon={FolderGit} />
            {t('Deployments')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('domains')}>
            <ContextMenuIcon icon={Globe} />
            {t('Domains')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('executions')}>
            <ContextMenuIcon icon={Play} />
            {t('Executions')}
          </ContextMenuItem>
          {showSecuritySettings && (
            <>
              <ContextMenuItem onSelect={() => navigateToTab('variables')}>
                <ContextMenuIcon icon={Variable} />
                {t('Variables')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('security')}>
                <ContextMenuIcon icon={Shield} />
                {t('Security')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={() => navigateToTab('settings')}>
                <ContextMenuIcon icon={Settings} />
                {t('Settings')}
              </ContextMenuItem>
            </>
          )}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', func.$id)}>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', func.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', functionHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProjectFunction(projectId, func.$id),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(functionHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(functionHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete function"
        description={
          <>
            {t(
              'Are you sure you want to delete this function? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={func.name?.trim() || func.$id}
        confirmPlaceholder="Enter function name"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
