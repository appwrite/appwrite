import {
  Archive,
  CalendarClock,
  Check,
  HardDriveDownload,
  Lock,
  RotateCcw,
} from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: CalendarClock,
    title: 'Schedule a policy',
    description:
      'Pick how often to back up and how long to keep each copy. Appwrite runs it for you from then on.',
  },
  {
    icon: HardDriveDownload,
    title: 'Back up on demand',
    description:
      'Take a manual backup before a risky migration or a big import, alongside your scheduled ones.',
  },
  {
    icon: RotateCcw,
    title: 'Restore in a few clicks',
    description:
      'Bring any backup back as a new database, so you can check the data before you switch over.',
  },
]

const SNAPSHOTS = [
  { day: '24', height: 'h-5' },
  { day: '25', height: 'h-6' },
  { day: '26', height: 'h-6' },
  { day: '27', height: 'h-7' },
  { day: '28', height: 'h-7' },
  { day: '29', height: 'h-8' },
  { day: '30', height: 'h-9' },
]

/** Decorative backup policy with a week of snapshots and a restore in progress. */
function BackupsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[340px] pb-6">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2.5">
          <span className="flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
            <Archive className="h-3.5 w-3.5" />
          </span>
          <span className="h-1.5 w-20 rounded-full bg-foreground/50" />
          <span
            dir="ltr"
            className="ms-auto rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
          >
            0 2 * * *
          </span>
        </div>
        <div className="px-3 pb-3 pt-4">
          <div dir="ltr" className="flex items-end justify-between gap-2">
            {SNAPSHOTS.map((snapshot, index) => {
              const latest = index === SNAPSHOTS.length - 1
              return (
                <div
                  key={snapshot.day}
                  className="flex flex-1 flex-col items-center gap-1.5"
                >
                  <span
                    className={cn(
                      'w-full rounded-sm',
                      snapshot.height,
                      latest
                        ? 'bg-[var(--brand-cta)]'
                        : 'bg-muted-foreground/20',
                    )}
                  />
                  <span
                    className={cn(
                      'font-mono text-[9px]',
                      latest ? 'text-foreground' : 'text-muted-foreground',
                    )}
                  >
                    {snapshot.day}
                  </span>
                </div>
              )
            })}
          </div>
          <div className="mt-3 flex items-center gap-1.5 border-t border-border pt-2.5">
            <CalendarClock className="h-3 w-3 text-muted-foreground" />
            <span className="h-1.5 w-24 rounded-full bg-muted-foreground/20" />
            <span dir="ltr" className="ms-auto font-mono text-[10px] text-muted-foreground">
              7d
            </span>
          </div>
        </div>
      </div>

      <div className="absolute -end-36 top-8 w-44 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <RotateCcw className="h-3 w-3 text-[var(--brand-cta)]" />
          <span className="h-1.5 w-14 rounded-full bg-foreground/50" />
        </div>
        <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted">
          <span className="block h-full w-2/3 rounded-full bg-[var(--brand-cta)]" />
        </div>
        <div className="mt-2 flex items-center gap-1.5">
          <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
          <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            sun-02:00
          </span>
        </div>
      </div>

      <div className="absolute -start-20 bottom-0 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Lock className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          AES-256
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function BackupsEmptyState({
  docsPath,
  onCreatePolicy,
  onManualBackup,
  createPolicyDisabled = false,
  createPolicyDisabledTooltip,
}: {
  docsPath: string
  onCreatePolicy: () => void
  onManualBackup: () => void
  createPolicyDisabled?: boolean
  createPolicyDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-4 sm:py-8">
      <ProductEmptyStateHero
        visual={<BackupsVisual />}
        icon={Archive}
        title={t('Keep this database safe')}
        description={t(
          'Back up on a schedule, keep copies for as long as you need, and restore any of them when something goes wrong.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreatePolicy}
              disabled={createPolicyDisabled}
              disabledTooltip={createPolicyDisabledTooltip}
            >
              {t('Create policy')}
            </ProductEmptyStateCreateButton>
            <Button
              variant="outline"
              className="h-9 text-[13px]"
              onClick={onManualBackup}
            >
              {t('Manual backup')}
            </Button>
            <ProductEmptyStateDocsButton path={docsPath} />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
