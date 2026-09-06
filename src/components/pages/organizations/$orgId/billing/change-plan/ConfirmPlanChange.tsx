import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { DowngradeDeletionStep } from '@/lib/billing/downgrade-deletion-steps'

/**
 * The one destructive confirmation in the downgrade flow. Only opened when
 * something is actually being deleted; `planLabel` is absent when the
 * organization itself goes, because then no plan change follows.
 */
export function ConfirmPlanChange({
  open,
  onOpenChange,
  planLabel,
  steps,
  deletedOrganizationName,
  confirming,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  planLabel?: string
  steps: DowngradeDeletionStep[]
  deletedOrganizationName?: string
  confirming: boolean
  onConfirm: () => void
}) {
  const t = useT()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Sits above the fullscreen wizard shell (z-[9998]), or it never shows. */}
      <DialogContent
        className="z-[10000] sm:max-w-md p-0"
        overlayClassName="z-[9999]"
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {planLabel
              ? t('Confirm plan change')
              : t('Confirm organization deletion')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {planLabel
              ? t(
                  'The following will be permanently deleted when your plan changes.',
                )
              : t('Everything below will be permanently deleted.')}{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {deletedOrganizationName ? (
            <p className="text-[13px] leading-normal text-red-600 dark:text-red-400">
              {deletedOrganizationName} {t('and all its resources')}
            </p>
          ) : null}
          {steps.length > 0 ? (
            <ul
              className={cn(
                'space-y-1.5',
                deletedOrganizationName && 'mt-2 border-t border-border pt-2',
              )}
            >
              {steps.map((step) => (
                <li
                  key={step.id}
                  className="flex items-start justify-between gap-3 text-[13px] leading-normal"
                >
                  <span className="text-foreground">{t(step.label)}</span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">
                    {step.count}
                  </span>
                </li>
              ))}
            </ul>
          ) : null}
          {planLabel ? (
            <p className="mt-4 text-[13px] leading-normal text-muted-foreground">
              {t('Your organization will move to the {plan} plan.').replace(
                '{plan}',
                planLabel,
              )}
            </p>
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={confirming}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            disabled={confirming}
            onClick={onConfirm}
          >
            {planLabel ? t('Delete and change plan') : t('Delete organization')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
