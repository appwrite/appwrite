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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useNavigate } from '@tanstack/react-router'
import { useDeleteSite } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

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

  const siteHref = buildConsoleUrl(
    `/projects/${projectId}/sites/${site.$id}/`,
  )

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  const handleConfirmDelete = () => {
    deleteMutation.mutate(site.$id, {
      onSuccess: () => {
        toast.success('Site deleted')
        setDeleteDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete site')
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
            Deployments
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('domains')}>
            <ContextMenuIcon icon={Globe} />
            Domains
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('logs')}>
            <ContextMenuIcon icon={ScrollText} />
            Logs
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('variables')}>
            <ContextMenuIcon icon={Variable} />
            Variables
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => navigateToTab('settings')}>
            <ContextMenuIcon icon={Settings} />
            Settings
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', site.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', site.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', siteHref)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard(
                    'JSON',
                    toPrettyJson({
                      id: site.$id,
                      name: site.name ?? null,
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
          <ContextMenuItem onSelect={() => openInNewTab(siteHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(siteHref)}>
            <ContextMenuIcon icon={Square} />
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete site</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this site? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
