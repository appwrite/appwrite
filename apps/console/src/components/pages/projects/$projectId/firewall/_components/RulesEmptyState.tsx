import type { ReactNode } from 'react'
import { ArrowDownUp, Check, Filter, Gauge, Shield, X } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import type { FirewallResourceType } from '@/lib/firewall/conditions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Filter,
    title: 'Match requests',
    description:
      'Target traffic by IP, country, path, method, headers, or user agent.',
  },
  {
    icon: Gauge,
    title: 'Choose an action',
    description:
      'Deny, challenge, rate limit, redirect, or bypass whatever the rule matches.',
  },
  {
    icon: ArrowDownUp,
    title: 'Set the priority',
    description:
      'Lower numbers run first, and the first rule that matches decides what happens to a request.',
  },
]

const COPY: Record<FirewallResourceType, { title: string; description: string }> =
  {
    api: {
      title: 'Protect your project API',
      description:
        'Firewall rules run on every request before it reaches Appwrite. Block countries, rate limit sign-ins, challenge bots, or allow only trusted IPs.',
    },
    functions: {
      title: 'Protect this function',
      description:
        'Firewall rules run on every request to this function before your code executes, so abusive traffic never costs you an execution.',
    },
    sites: {
      title: 'Protect this site',
      description:
        'Firewall rules run on every request to this site before it is served. Block scrapers, rate limit forms, or put the site behind a maintenance page.',
    },
  }

const REQUESTS = [
  { method: 'POST', path: '/v1/account/sessions', outcome: 'limit' },
  { method: 'GET', path: '/v1/storage/files', outcome: 'allow' },
  { method: 'GET', path: '/wp-admin.php', outcome: 'deny' },
  { method: 'GET', path: '/v1/databases', outcome: 'allow' },
] as const

const OUTCOME_STYLES = {
  allow: {
    icon: Check,
    className:
      'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  },
  limit: {
    icon: Gauge,
    className:
      'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
  },
  deny: {
    icon: X,
    className: 'border-border bg-muted text-muted-foreground',
  },
} as const

/** Decorative request log filtered by a rate-limit rule. */
function FirewallVisual() {
  return (
    <ProductEmptyStateVisual className="w-[400px] pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Shield className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
        </div>
        <ul dir="ltr" className="divide-y divide-border">
          {REQUESTS.map((request) => {
            const outcome = OUTCOME_STYLES[request.outcome]
            const Icon = outcome.icon
            return (
              <li
                key={request.path}
                className={cn(
                  'flex items-center gap-2.5 px-3 py-2.5 font-mono text-[10px]',
                  request.outcome === 'deny' && 'opacity-60',
                )}
              >
                <span className="w-8 shrink-0 text-muted-foreground">
                  {request.method}
                </span>
                <span
                  className={cn(
                    'min-w-0 flex-1 truncate text-foreground',
                    request.outcome === 'deny' && 'line-through',
                  )}
                >
                  {request.path}
                </span>
                <span
                  className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-md border',
                    outcome.className,
                  )}
                >
                  <Icon className="h-3 w-3" />
                </span>
              </li>
            )
          })}
        </ul>
      </div>

      <div className="absolute -end-48 top-8 w-48 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <Gauge className="h-3 w-3 text-amber-600 dark:text-amber-400" />
          <span className="h-1.5 w-16 rounded-full bg-foreground/50" />
        </div>
        <div dir="ltr" className="mt-2.5 space-y-1.5 font-mono text-[10px]">
          <RuleLine label="path">/v1/account/*</RuleLine>
          <RuleLine label="limit">10 / 1m</RuleLine>
        </div>
      </div>

      <div className="absolute -start-16 -bottom-3 flex items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span className="h-1.5 w-1.5 rounded-full bg-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          country
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          not in [US, DE, IL]
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

function RuleLine({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center gap-2">
      <span className="w-9 text-muted-foreground">{label}</span>
      <span className="truncate text-foreground">{children}</span>
    </div>
  )
}

export function RulesEmptyState({
  scope,
  presets,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  scope: FirewallResourceType
  /** Preset picker rendered next to the create button. */
  presets?: ReactNode
  onCreate: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const copy = COPY[scope] ?? COPY.api
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<FirewallVisual />}
        icon={Shield}
        title={t(copy.title)}
        description={t(copy.description)}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create rule')}
            </ProductEmptyStateCreateButton>
            {presets}
            <ProductEmptyStateDocsButton path="/docs/products/firewall/rules" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
