import {
  ArrowRightLeft,
  Check,
  ClipboardList,
  KeyRound,
  ListChecks,
} from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: KeyRound,
    title: 'Connect a source',
    description:
      'Choose another Appwrite project, Firebase, Supabase, or NHost and enter its access credentials.',
  },
  {
    icon: ClipboardList,
    title: 'Review the report',
    description:
      'See how many users, tables, files, and functions the source has before anything moves.',
  },
  {
    icon: ListChecks,
    title: 'Pick what to import',
    description:
      'Select the resources you want and follow the progress here while the migration runs.',
  },
]

const SOURCES = ['Appwrite', 'Firebase', 'Supabase', 'NHost'] as const

const RESOURCES = [
  { label: 'Users', value: 1284, progress: 100 },
  { label: 'Tables', value: 12, progress: 100 },
  { label: 'Files', value: 3460, progress: 64 },
] as const

/** Decorative source list feeding a migration in progress. */
function MigrationsVisual() {
  const t = useT()
  return (
    <ProductEmptyStateVisual className="flex items-center pb-2">
      <ul className="w-36 space-y-1.5">
        {SOURCES.map((source, index) => (
          <li
            key={source}
            className={cn(
              'flex items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-start shadow-sm',
              index === 1
                ? 'border-[color-mix(in_oklch,var(--brand-cta)_50%,transparent)]'
                : 'border-border opacity-60',
            )}
          >
            <span
              className={cn(
                'h-1.5 w-1.5 shrink-0 rounded-full',
                index === 1
                  ? 'bg-[var(--brand-cta)]'
                  : 'bg-muted-foreground/30',
              )}
            />
            <span dir="ltr" className="text-[11px] text-foreground">
              {source}
            </span>
          </li>
        ))}
      </ul>

      <div className="flex items-center px-1 text-muted-foreground/60">
        <span className="h-px w-6 bg-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)]" />
        <ArrowRightLeft className="mx-1 h-3.5 w-3.5" />
        <span className="h-px w-6 bg-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)]" />
      </div>

      <div className="w-60 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <img
            src="/icons/appwrite.svg"
            alt=""
            className="h-3.5 w-3.5 opacity-70"
          />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
        </div>
        <ul className="space-y-2.5 px-3 py-3">
          {RESOURCES.map((resource) => (
            <li key={resource.label}>
              <div className="flex items-center justify-between text-[11px]">
                <span className="text-foreground">{t(resource.label)}</span>
                <span className="flex items-center gap-1 font-mono tabular-nums text-muted-foreground">
                  {resource.value.toLocaleString('en-US')}
                  {resource.progress === 100 ? (
                    <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                  ) : null}
                </span>
              </div>
              <div className="mt-1 h-1 overflow-hidden rounded-full bg-muted">
                <div
                  className={cn(
                    'h-full rounded-full',
                    resource.progress === 100
                      ? 'bg-emerald-500/70'
                      : 'bg-[var(--brand-cta)]',
                  )}
                  style={{ width: `${resource.progress}%` }}
                />
              </div>
            </li>
          ))}
        </ul>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function MigrationsEmptyState({
  onImport,
  importDisabled = false,
  importDisabledTooltip,
}: {
  onImport: () => void
  importDisabled?: boolean
  importDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<MigrationsVisual />}
        icon={ArrowRightLeft}
        title={t('Bring your data to Appwrite')}
        description={t(
          'Move users, databases, files, and functions from another Appwrite project or a different platform. Your source stays untouched while the import runs.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onImport}
              disabled={importDisabled}
              disabledTooltip={importDisabledTooltip}
            >
              {t('Import data')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/advanced/migrations" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
