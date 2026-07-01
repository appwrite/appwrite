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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useDeleteApiKey } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

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
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteApiKey(projectId)

  if (!apiKey?.id) {
    return <>{children}</>
  }

  const hasName = !!apiKey.name
  const hasKey = !!apiKey.key

  const handleDelete = () => {
    deleteMutation.mutate(apiKey.id, {
      onSuccess: () => {
        toast.success('API key deleted')
        setDeleteDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete API key')
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
              <ContextMenuItem onSelect={() => onUpdate(apiKey.id)}>
                <ContextMenuIcon icon={Pencil} />
                Update
              </ContextMenuItem>
              <ContextMenuSeparator />
            </>
          )}
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', apiKey.id)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy ID
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', apiKey.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  Copy name
                </ContextMenuItem>
              )}
              {hasKey && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('API key', apiKey.key)}
                >
                  <ContextMenuIcon icon={KeyRound} />
                  Copy key
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
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => setDeleteDialogOpen(true)}>
            <ContextMenuIcon icon={Trash2} />
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete API key</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete
              {apiKey.name ? ` "${apiKey.name}"` : ' this API key'}? This action
              cannot be undone.
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
              onClick={handleDelete}
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
