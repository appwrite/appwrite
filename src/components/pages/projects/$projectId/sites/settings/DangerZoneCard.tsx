/**
 * Danger Zone Card Component
 *
 * Contains destructive actions for the site.
 */

import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { toast } from 'sonner'
import { Trash2 } from 'lucide-react'
import { useDeleteSite } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

interface DangerZoneCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  onDelete?: () => void
}

export function DangerZoneCard({
  projectId,
  siteId,
  site,
  onDelete,
}: DangerZoneCardProps) {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteSiteMutation = useDeleteSite(projectId)

  const handleDelete = () => {
    if (!siteId) return
    deleteSiteMutation.mutate(siteId, {
      onSuccess: () => {
        toast.success('Site deleted successfully')
        setDeleteDialogOpen(false)
        onDelete?.()
      },
      onError: (error: unknown) => {
        toast.error(getErrorMessage(error, 'Failed to delete site'))
      },
    })
  }

  return (
    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Danger Zone
        </h3>
      </div>
      <div className="border-t border-destructive/20" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          Permanently delete this site and all its data. This action cannot be
          undone.
        </p>

        {/* Site Info Summary */}
        {site && (
          <div className="flex items-center gap-3 mt-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              <Trash2 className="h-5 w-5 text-muted-foreground" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {site.name || 'Unnamed Site'}
              </p>
              <p className="text-[12px] text-muted-foreground">{site.$id}</p>
            </div>
          </div>
        )}
      </div>
      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogTrigger asChild>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={deleteSiteMutation.isPending}
            >
              <Trash2 className="mr-1.5 h-4 w-4" />
              Delete site
            </Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete Site</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete{' '}
                {site && (
                  <span className="font-medium text-foreground">
                    {site.name || 'this site'}
                  </span>
                )}{' '}
                and all its data? This action cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteSiteMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={handleDelete}
                disabled={deleteSiteMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
