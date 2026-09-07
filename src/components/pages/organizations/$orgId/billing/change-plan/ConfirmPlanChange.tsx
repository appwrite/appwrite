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
import type {
  DowngradeDeletionItem,
  PendingDowngradeDeletions,
} from './DowngradeValidation'

function DeletionGroup({
  title,
  items,
}: {
  title: string
  items: DowngradeDeletionItem[]
}) {
  if (items.length === 0) return null

  return (
    <div className="space-y-1.5">
      <div className="flex items-baseline justify-between gap-3">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <span className="shrink-0 text-[13px] tabular-nums text-muted-foreground">
          {items.length}
        </span>
      </div>
      <ul className="space-y-1.5">
        {items.map((item) => (
          <li key={item.id} className="min-w-0">
            <p
              className="truncate text-[13px] leading-normal text-foreground"
              title={item.name}
            >
              {item.name}
            </p>
            <p className="break-all font-mono text-[12px] leading-normal text-muted-foreground">
              {item.id}
            </p>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * The one destructive confirmation in the downgrade flow. Only opened when
 * something is actually being deleted; `planLabel` is absent when the
 * organization itself goes, because then no plan change follows.
 */
export function ConfirmPlanChange({
  open,
  onOpenChange,
  planLabel,
  deletions,
  deletedOrganizationName,
  confirming,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  planLabel?: string
  deletions?: PendingDowngradeDeletions | null
  deletedOrganizationName?: string
  confirming: boolean
  onConfirm: () => void
}) {
  const t = useT()

  const hasManifest =
    !!deletions &&
    (deletions.projects.length > 0 ||
      deletions.memberships.length > 0 ||
      deletions.domains.length > 0 ||
      deletions.addons.length > 0 ||
      deletions.projectResources.length > 0)
  const showBody = !!deletedOrganizationName || hasManifest

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
        {showBody ? (
          // Only the manifest scrolls, so the confirm button never moves.
          <div className="max-h-[50vh] overflow-y-auto px-6 py-4">
            {deletedOrganizationName ? (
              <p className="text-[13px] leading-normal text-red-600 dark:text-red-400">
                {deletedOrganizationName} {t('and all its resources')}
              </p>
            ) : null}
            {hasManifest && deletions ? (
              <div
                className={cn(
                  'space-y-4',
                  deletedOrganizationName && 'mt-4 border-t border-border pt-4',
                )}
              >
                <DeletionGroup
                  title={t('Projects')}
                  items={deletions.projects}
                />
                <DeletionGroup
                  title={t('Members')}
                  items={deletions.memberships}
                />
                <DeletionGroup title={t('Domains')} items={deletions.domains} />
                <DeletionGroup title={t('Addons')} items={deletions.addons} />
                {deletions.projectResources.map((project) => (
                  <div
                    key={project.projectId}
                    className="space-y-3 rounded-lg border border-border bg-card/50 p-3"
                  >
                    <div className="min-w-0">
                      <p
                        className="truncate text-[13px] font-medium leading-normal text-foreground"
                        title={project.projectName}
                      >
                        {project.projectName}
                      </p>
                      <p className="break-all font-mono text-[12px] leading-normal text-muted-foreground">
                        {project.projectId}
                      </p>
                    </div>
                    {project.types.map((group) => (
                      <DeletionGroup
                        key={group.type}
                        title={t(group.label)}
                        items={group.items}
                      />
                    ))}
                  </div>
                ))}
              </div>
            ) : null}
          </div>
        ) : null}
        {planLabel ? (
          <p
            className={cn(
              'px-6 pb-4 text-[13px] leading-normal text-muted-foreground',
              !showBody && 'pt-4',
            )}
          >
            {t('Your organization will move to the {plan} plan.').replace(
              '{plan}',
              planLabel,
            )}
          </p>
        ) : null}
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
