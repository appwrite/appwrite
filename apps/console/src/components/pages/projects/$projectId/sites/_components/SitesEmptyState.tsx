import { Check, GitBranch, Globe, Lock, Sparkles } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: GitBranch,
    title: 'Connect a repository',
    description:
      'Deploy on every push to your production branch, with a preview URL for every other branch.',
  },
  {
    icon: Sparkles,
    title: 'Pick a framework',
    description:
      'Appwrite detects TanStack Start, Next.js, Nuxt, SvelteKit, and more, and sets up the build for you.',
  },
  {
    icon: Globe,
    title: 'Go live',
    description:
      'Get an appwrite.network URL right away, then add your own domain.',
  },
]

const FRAMEWORKS = [
  'tanstack-start',
  'nextjs',
  'nuxt',
  'sveltekit',
  'astro',
  'remix',
]

const BUILD_STEPS = [
  { label: 'build', time: '38s' },
  { label: 'deploy', time: '4s' },
]

/** Decorative live site in a browser, its latest deployment, and supported frameworks. */
function BrowserVisual() {
  return (
    <ProductEmptyStateVisual className="w-[460px]">
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          {[0, 1, 2].map((dot) => (
            <span
              key={dot}
              className="h-2 w-2 rounded-full bg-muted-foreground/25"
            />
          ))}
          <span
            dir="ltr"
            className="mx-auto flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-0.5"
          >
            <Lock className="h-2.5 w-2.5 text-muted-foreground" />
            <span className="font-mono text-[10px] text-foreground">
              my-app.appwrite.network
            </span>
          </span>
          <span className="w-8" />
        </div>
        <div className="bg-background px-5 pb-5 pt-3">
          <div className="flex items-center gap-3">
            <span className="h-4 w-4 rounded bg-foreground/80" />
            {['w-8', 'w-10', 'w-7'].map((width) => (
              <span
                key={width}
                className={cn(
                  'h-1.5 rounded-full bg-muted-foreground/25',
                  width,
                )}
              />
            ))}
            <span className="ms-auto h-4 w-12 rounded-full bg-[var(--brand-cta)]" />
          </div>
          <div className="mt-6 flex flex-col items-center">
            <span className="h-3 w-52 rounded-full bg-foreground/70" />
            <span className="mt-2 h-3 w-36 rounded-full bg-foreground/70" />
            <span className="mt-3 h-1.5 w-44 rounded-full bg-muted-foreground/25" />
            <span className="mt-4 flex gap-2">
              <span className="h-5 w-16 rounded-md bg-[var(--brand-cta)]" />
              <span className="h-5 w-16 rounded-md border border-border" />
            </span>
          </div>
          <div className="mt-6 grid grid-cols-3 gap-2">
            {[0, 1, 2].map((card) => (
              <span
                key={card}
                className="rounded-md border border-border bg-muted/30 p-2"
              >
                <span className="block h-6 rounded bg-muted-foreground/15" />
                <span className="mt-2 block h-1.5 w-3/4 rounded-full bg-muted-foreground/25" />
              </span>
            ))}
          </div>
        </div>
      </div>

      <div className="absolute -end-24 top-16 w-48 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-2">
          <FrameworkIcon framework="tanstack-start" size="sm" className="h-4 w-4" />
          <span dir="ltr" className="font-mono text-[10px] text-foreground">
            main · 8f3a2c1
          </span>
          <span className="ms-auto h-1.5 w-1.5 rounded-full bg-emerald-500" />
        </div>
        <div className="mt-2.5 space-y-1.5">
          {BUILD_STEPS.map((step) => (
            <div
              key={step.label}
              className="flex items-center gap-1.5 font-mono text-[10px]"
            >
              <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
              <span className="text-muted-foreground">{step.label}</span>
              <span className="ms-auto tabular-nums text-muted-foreground">
                {step.time}
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="absolute -start-14 bottom-8 grid grid-cols-2 gap-1.5 rounded-lg border border-border bg-popover p-1.5 shadow-lg">
        {FRAMEWORKS.map((framework) => (
          <span
            key={framework}
            className="flex h-7 w-7 items-center justify-center rounded-md bg-muted/60"
          >
            <FrameworkIcon
              framework={framework}
              size="sm"
              className="h-4 w-4"
            />
          </span>
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

export function SitesEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl('/docs/products/sites', features.marketing)

  return (
    <div className="mx-auto w-full max-w-4xl py-8 sm:py-12">
      <ProductEmptyStateHero
        visual={<BrowserVisual />}
        icon={Globe}
        title={t('Create your first site')}
        description={t(
          'Deploy web apps from Git or a template. Every push gets a build, a preview URL, and fast global delivery.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create site')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
