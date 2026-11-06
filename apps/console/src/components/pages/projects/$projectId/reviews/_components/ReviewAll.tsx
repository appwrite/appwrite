import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { VCSDetectionType } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Progress } from '@/components/ui/progress'
import {
  useCreateAllReviewRepositories,
  useRepositories,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type ReviewAllProps = {
  projectId: string
  installationId: string
  organization: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Turns code review on for every repository of a GitHub installation. Stays
 * open with a progress bar while it runs, since a large installation takes a
 * while.
 */
export function ReviewAll({
  projectId,
  installationId,
  organization,
  open,
  onOpenChange,
}: ReviewAllProps) {
  const t = useT()
  // Counted without a search, so the dialog says how many it turns on.
  const { data } = useRepositories(
    open ? projectId : null,
    installationId,
    VCSDetectionType.Runtime,
    0,
    1,
  )
  const total = data?.total

  const [progress, setProgress] = useState<{ done: number; total: number }>()
  const mutation = useCreateAllReviewRepositories(projectId)
  const running = mutation.isPending

  useEffect(() => {
    if (open) setProgress(undefined)
  }, [open])

  const start = () => {
    mutation.mutate(
      {
        installationId,
        onProgress: (done, all) => setProgress({ done, total: all }),
      },
      {
        onSuccess: ({ added, skipped, failed }) => {
          const summary = [
            `${added} ${t('repositories added')}`,
            skipped > 0 ? `${skipped} ${t('repositories already on')}` : null,
            failed > 0 ? `${failed} ${t('repositories failed')}` : null,
          ]
            .filter(Boolean)
            .join(', ')
          if (failed > 0) {
            toast.error(t('Some repositories could not be added'), {
              description: summary,
            })
          } else {
            toast.success(t('Code review turned on'), { description: summary })
          }
          onOpenChange(false)
        },
        onError: (error) => {
          toast.error(
            getErrorMessage(error, t('Failed to turn on code review')),
          )
        },
      },
    )
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Closing mid-way would hide the result; let it finish.
        if (!running) onOpenChange(next)
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Review all repositories')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {total === undefined
              ? t('Counting repositories...')
              : `${t('Turn on code review for every repository in')} ${organization} (${total}). ${t('Repositories added to the installation later are not included.')}`}
          </DialogDescription>
        </DialogHeader>
        {running && (
          <div className="px-6 pb-4 space-y-2">
            <Progress
              value={
                progress && progress.total > 0
                  ? (progress.done / progress.total) * 100
                  : 0
              }
            />
            <p className="text-[12px] text-muted-foreground">
              {progress
                ? `${progress.done} / ${progress.total}`
                : t('Counting repositories...')}
            </p>
          </div>
        )}
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            disabled={running}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button disabled={total === undefined || running} onClick={start}>
            {running && <Loader2 className="h-4 w-4 animate-spin" />}
            {running ? t('Adding repositories...') : t('Review all')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
