import { useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { DistributionSubmission } from '@/lib/appwrite/distribution'
import {
  MAX_ROLLOUT_PERCENT,
  MIN_ROLLOUT_PERCENT,
  ROLLOUT_MISMATCH_MESSAGE,
  isRolloutPercent,
  rolloutMismatch,
  rolloutMismatchMessage,
} from '@/lib/distribution/releases'
import { rolloutPercent } from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'
import { useUpdateDistributionSubmission } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type UpdateRolloutProps = {
  projectId: string
  submission: DistributionSubmission | null
  onOpenChange: (open: boolean) => void
}

export function UpdateRollout({
  projectId,
  submission,
  onOpenChange,
}: UpdateRolloutProps) {
  return (
    <Dialog
      open={submission !== null}
      onOpenChange={(open) => {
        if (!open) onOpenChange(false)
      }}
    >
      {submission ? (
        <UpdateRolloutContent
          key={submission.$id}
          projectId={projectId}
          submission={submission}
          onClose={() => onOpenChange(false)}
        />
      ) : null}
    </Dialog>
  )
}

function UpdateRolloutContent({
  projectId,
  submission,
  onClose,
}: {
  projectId: string
  submission: DistributionSubmission
  onClose: () => void
}) {
  const t = useT()
  const updateSubmission = useUpdateDistributionSubmission(projectId)
  const current = rolloutPercent(submission.rollout)
  const [percent, setPercent] = useState(String(current))
  const [error, setError] = useState<string | null>(null)

  const valid = isRolloutPercent(percent)
  const changed = valid && Number(percent) !== current

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!changed) return
    setError(null)
    const rollout = Number(percent) / 100
    updateSubmission.mutate(
      {
        appId: submission.appId,
        submissionId: submission.$id,
        rollout,
      },
      {
        onSuccess: (updated) => {
          const mismatch = rolloutMismatch(rollout, updated.rollout)
          if (mismatch) {
            toast.warning(
              rolloutMismatchMessage(t(ROLLOUT_MISMATCH_MESSAGE), mismatch),
            )
          } else {
            toast.success(t('Rollout updated'))
          }
          onClose()
        },
        onError: (mutationError) =>
          setError(
            getErrorMessage(mutationError, t('Failed to update the rollout')),
          ),
      },
    )
  }

  return (
    <DialogContent
      className="sm:max-w-md p-0"
      onInteractOutside={(event) => {
        if (updateSubmission.isPending) event.preventDefault()
      }}
      onEscapeKeyDown={(event) => {
        if (updateSubmission.isPending) event.preventDefault()
      }}
    >
      <DialogHeader className="px-6 pt-6 pb-4 text-start">
        <DialogTitle>{t('Update rollout')}</DialogTitle>
        <DialogDescription className="text-[13px] mt-2">
          {t(
            'Choose the percentage of Google Play users who receive this version. Use 100% to finish the staged rollout.',
          )}
        </DialogDescription>
      </DialogHeader>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="space-y-2 px-6 py-4">
          <Label htmlFor="update-rollout-percent" className="text-[13px]">
            {t('Rollout percentage')}
          </Label>
          <div className="relative w-32">
            <Input
              id="update-rollout-percent"
              type="number"
              inputMode="numeric"
              min={MIN_ROLLOUT_PERCENT}
              max={MAX_ROLLOUT_PERCENT}
              step={1}
              value={percent}
              onChange={(event) => {
                setPercent(event.target.value)
                setError(null)
              }}
              autoFocus
              disabled={updateSubmission.isPending}
              aria-invalid={!valid}
              className={cn(
                'h-9 pe-8 text-[13px]',
                !valid && 'border-destructive',
              )}
            />
            <span className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
              %
            </span>
          </div>
          <p
            className={cn(
              'text-[12px]',
              valid ? 'text-muted-foreground' : 'text-destructive',
            )}
          >
            {t('Enter a whole number from 1 to 100.')}
          </p>
          {error ? (
            <p
              role="alert"
              data-testid="update-rollout-error"
              className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
            >
              {error}
            </p>
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={onClose}
            disabled={updateSubmission.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="submit"
            className="h-9 text-[13px]"
            disabled={!changed || updateSubmission.isPending}
          >
            {t('Update')}
          </Button>
        </div>
      </form>
    </DialogContent>
  )
}
