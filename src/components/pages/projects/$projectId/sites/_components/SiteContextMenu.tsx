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
  Settings,
  ScrollText,
  Variable,
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
import { fetchProjectSite, useDeleteSite } from '@/lib/react-query/hooks'
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

export type SiteContextMenuSite = {
  $id: string
  name?: string | null
}

interface SiteContextMenuProps {
  projectId: string
  site: SiteContextMenuSite
  children: React.ReactNode
}

export function SiteContextMenu({
  projectId,
  site,
  children,
}: SiteContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteSite(projectId)

  if (!site?.$id) {
    return <>{children}</>
  }

  const navigateToTab = (tab: string) => {
    const base = `/projects/${projectId}/sites/${site.$id}`
    const path = tab === 'deployments' ? base : `${base}/${tab}`
    navigate({
      to: path as '/projects/$projectId/sites/$siteId/',
      params: { projectId, siteId: site.$id },
    })
  }

  const siteHref = buildConsoleUrl(`/projects/${projectId}/sites/${site.$id}/`)

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(site.$id, {
      onSuccess: () => {
        toast.success(t('Site deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete site'))
      },
    })
  }

  const hasName = !!site.name

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
          <ContextMenuItem onSelect={() => navigateToTab('logs')}>
            <ContextMenuIcon icon={ScrollText} />
            {t('Logs')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('variables')}>
            <ContextMenuIcon icon={Variable} />
            {t('Variables')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('settings')}>
            <ContextMenuIcon icon={Settings} />
            {t('Settings')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={() => copyToClipboard('ID', site.$id)}>
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', site.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', siteHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProjectSite(projectId, site.$id),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(siteHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(siteHref)}>
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
        title="Delete site"
        description={
          <>
            {t(
              'Are you sure you want to delete this site? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={site.name?.trim() || site.$id}
        confirmPlaceholder="Enter site name"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
