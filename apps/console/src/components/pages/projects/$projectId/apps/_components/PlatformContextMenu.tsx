import { useState } from 'react'
import { Copy, FileJson, Pencil, Tag, Trash2 } from 'lucide-react'
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
import { sdk } from '@/lib/appwrite/sdk'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useDeletePlatform } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getPlatformIdentifier,
  type ProjectPlatform,
} from '@/lib/utils/platform'
import { useT } from '@/lib/i18n/translate'

interface PlatformContextMenuProps {
  projectId: string
  platform: ProjectPlatform
  children: React.ReactNode
  onUpdate?: (platform: ProjectPlatform) => void
}

export function PlatformContextMenu({
  projectId,
  platform,
  children,
  onUpdate,
}: PlatformContextMenuProps) {
  const t = useT()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeletePlatform(projectId)

  if (!platform?.$id) {
    return <>{children}</>
  }

  const hasName = !!platform.name
  const identifier = getPlatformIdentifier(platform)
  const hasIdentifier = !!identifier

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(platform.$id, {
      onSuccess: () => {
        toast.success(t('App deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete app'))
      },
    })
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          {onUpdate && (
            <>
              <ContextMenuItem
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => onUpdate(platform))
                }
              >
                <ContextMenuIcon icon={Pencil} />
                {t('Update')}
              </ContextMenuItem>
              <ContextMenuSeparator />
            </>
          )}
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', platform.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', platform.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              {hasIdentifier && (
                <ContextMenuItem
                  onSelect={() =>
                    copyToClipboard('Identifier', identifier)
                  }
                >
                  <ContextMenuIcon icon={Tag} />
                  {t('Copy identifier')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    sdk
                      .forProject(projectId)
                      .project.getPlatform({ platformId: platform.$id }),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() =>
              openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
            }
          >
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete app')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}
              {platform.name ? ` "${platform.name}"` : ` ${t('this app')}`}?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
