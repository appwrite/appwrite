import { useState } from 'react'
import {
  Copy,
  FileJson,
  KeyRound,
  Pencil,
  Trash2,
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
import { fetchApiKey } from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useDeleteApiKey } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

export type ApiKeyContextMenuKey = {
  id: string
  name?: string | null
  key?: string | null
  scopes?: string[]
  expire?: string | null
}

interface ApiKeyContextMenuProps {
  projectId: string
  apiKey: ApiKeyContextMenuKey
  children: React.ReactNode
  onUpdate?: (keyId: string) => void
}

export function ApiKeyContextMenu({
  projectId,
  apiKey,
  children,
  onUpdate,
}: ApiKeyContextMenuProps) {
  const t = useT()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteApiKey(projectId)

  if (!apiKey?.id) {
    return <>{children}</>
  }

  const hasName = !!apiKey.name
  const hasKey = !!apiKey.key

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(apiKey.id, {
      onSuccess: () => {
        toast.success(t('API key deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete API key'))
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
                  openDialogAfterOverlayCloses(() => onUpdate(apiKey.id))
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
                onSelect={() => copyToClipboard('ID', apiKey.id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', apiKey.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              {hasKey && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('API key', apiKey.key)}
                >
                  <ContextMenuIcon icon={KeyRound} />
                  {t('Copy key')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchApiKey(projectId, apiKey.id),
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
            <DialogTitle>{t('Delete API key')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}
              {apiKey.name ? ` "${apiKey.name}"` : ` ${t('this API key')}`}?{' '}
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
