import { Check, Hammer, Loader2, Package, Send, Smartphone } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { PlatformIcon } from './platform'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Smartphone,
    title: 'Create the app',
    description:
      'Pick Flutter, React Native, native Android or native iOS, and the platforms you ship to.',
  },
  {
    icon: Hammer,
    title: 'Build every platform',
    description:
      'Each version gets its own build per platform, with status and duration tracked here.',
  },
  {
    icon: Send,
    title: 'Submit to the stores',
    description:
      'Send builds to Google Play and App Store Connect, or submit them automatically.',
  },
]

const BUILDS = [
  { platform: 'android', file: 'app-release.aab', status: 'ready' },
  { platform: 'ios', file: 'Runner.ipa', status: 'building' },
] as const

const STORES = ['Google Play', 'App Store Connect'] as const

function ReleaseVisual() {
  return (
    <ProductEmptyStateVisual className="flex items-center pb-2">
      <div className="w-64 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
          <Package className="h-3.5 w-3.5 text-muted-foreground" />
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            my-app
          </span>
          <span
            dir="ltr"
            className="ms-auto font-mono text-[10px] text-muted-foreground"
          >
            v1.4.0
          </span>
        </div>
        <ul className="divide-y divide-border">
          {BUILDS.map((build) => (
            <li
              key={build.platform}
              className="flex items-center gap-2.5 px-3 py-2.5"
            >
              <PlatformIcon
                platform={build.platform}
                className="h-3.5 w-3.5 text-muted-foreground"
              />
              <span
                dir="ltr"
                className="min-w-0 flex-1 truncate font-mono text-[10px] text-foreground"
              >
                {build.file}
              </span>
              {build.status === 'ready' ? (
                <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
              ) : (
                <Loader2 className="h-3 w-3 animate-spin text-amber-600 dark:text-amber-400" />
              )}
            </li>
          ))}
        </ul>
      </div>

      <div className="flex items-center px-1">
        <span className="h-px w-8 bg-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)]" />
      </div>

      <ul className="w-40 space-y-1.5">
        {STORES.map((store, index) => (
          <li
            key={store}
            className={cn(
              'flex items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-start shadow-sm',
              index === 1
                ? 'border-border opacity-60'
                : 'border-[color-mix(in_oklch,var(--brand-cta)_45%,transparent)]',
            )}
          >
            <span
              className={cn(
                'h-1.5 w-1.5 shrink-0 rounded-full',
                index === 1
                  ? 'bg-muted-foreground/30'
                  : 'bg-[var(--brand-cta)]',
              )}
            />
            <span dir="ltr" className="truncate text-[11px] text-foreground">
              {store}
            </span>
          </li>
        ))}
      </ul>
    </ProductEmptyStateVisual>
  )
}

export function AppsEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<ReleaseVisual />}
        icon={Package}
        title={t('Ship your apps to the stores')}
        description={t(
          'Build Android and iOS releases from one place and submit them to Google Play and App Store Connect.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onCreate}
            disabled={createDisabled}
            disabledTooltip={createDisabledTooltip}
          >
            {t('Create app')}
          </ProductEmptyStateCreateButton>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
