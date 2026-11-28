import { ArrowRight, GitBranch } from 'lucide-react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import {
  REVIEWS_GIT_PROVIDERS,
  ReviewsProviderMark,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductIcon } from '@/lib/products/types'
import { cn } from '@/lib/utils'

type Stage = {
  step: string
  title: string
  description: string
  icons: ProductIcon[]
  href?: string
  current?: boolean
}

/**
 * A pull request's whole life on one platform: the Git connection that
 * deploys also reviews, then the same project hosts, watches, and pages.
 */
const STAGES: Stage[] = [
  {
    step: 'Connect',
    title: 'One Git connection',
    description:
      'The Appwrite Git app that deploys your Sites and Functions reviews their pull requests too.',
    icons: [GitBranch],
  },
  {
    step: 'Review',
    title: 'Appwrite Reviews',
    description:
      'A summary, findings on the lines they concern, a verdict, and a grade on every pull request.',
    icons: [PRODUCT_REGISTRY.reviews.icon],
    current: true,
  },
  {
    step: 'Deploy',
    title: 'Sites and Functions',
    description:
      'A preview for the pull request, then production on merge, with rollbacks one click away.',
    icons: [PRODUCT_REGISTRY.sites.icon, PRODUCT_REGISTRY.functions.icon],
    href: '/products/sites',
  },
  {
    step: 'Observe',
    title: 'Appwrite Monitors',
    description:
      'Usage thresholds and endpoint checks tell you when what you merged misbehaves.',
    icons: [PRODUCT_REGISTRY.monitors.icon],
    href: '/products/monitors',
  },
  {
    step: 'Respond',
    title: 'Appwrite Beep',
    description:
      'The alert reaches your phone, and the next person in line if nobody acknowledges it.',
    icons: [PRODUCT_REGISTRY.beep.icon],
    href: '/products/beep',
  },
]

const SHARED = [
  'One Console',
  'One organization bill',
  'Same team and roles',
  'No per-seat fees',
] as const

function StageCard({ stage, index }: { stage: Stage; index: number }) {
  const t = useT()
  const body = (
    <>
      <span className="flex items-center gap-2">
        <span className="font-mono text-[10.5px] tabular-nums text-[var(--tone-ink)]">
          {String(index + 1).padStart(2, '0')}
        </span>
        <span className="text-[10.5px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
          {t(stage.step)}
        </span>
      </span>
      <span className="mt-4 flex gap-1.5">
        {stage.icons.map((Icon, iconIndex) => (
          <ProductIconTile key={iconIndex} icon={Icon} size="sm" />
        ))}
      </span>
      <span className="mt-3 flex items-center gap-1.5 text-[14px] font-medium text-foreground">
        {t(stage.title)}
        {stage.href ? (
          <ArrowRight
            className="size-3.5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-[var(--tone-ink)] rtl:-scale-x-100"
            aria-hidden
          />
        ) : null}
      </span>
      <span className="mt-1.5 block text-[12.5px] leading-5 text-muted-foreground">
        {t(stage.description)}
      </span>
    </>
  )

  const className = cn(
    'product-hero-rise group relative flex h-full flex-col rounded-xl border p-4 text-start transition-colors',
    stage.current
      ? 'border-[rgb(var(--tone-rgb)/0.55)] bg-[rgb(var(--tone-rgb)/0.07)]'
      : 'border-border bg-background/70 dark:bg-card/40',
    stage.href && 'hover:border-[rgb(var(--tone-rgb)/0.45)]',
  )

  return stage.href ? (
    <MarketingSiteLink
      href={stage.href}
      className={cn('link-unstyled', className)}
      style={riseStyle(120 + index * 110)}
    >
      {body}
    </MarketingSiteLink>
  ) : (
    <div className={className} style={riseStyle(120 + index * 110)}>
      {body}
    </div>
  )
}

export function ReviewsPlatformCatalog() {
  const t = useT()

  return (
    <div className="mx-auto max-w-6xl">
      <div className="mx-auto mb-8 flex flex-wrap items-center justify-center gap-2">
        {REVIEWS_GIT_PROVIDERS.map((provider) => (
          <span
            key={provider.id}
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground shadow-sm dark:bg-card"
          >
            <ReviewsProviderMark src={provider.icon} />
            {provider.name}
          </span>
        ))}
      </div>
      <ol className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {STAGES.map((stage, index) => (
          <li key={stage.step}>
            <StageCard stage={stage} index={index} />
          </li>
        ))}
      </ol>
      <ul className="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
        {SHARED.map((item) => (
          <li
            key={item}
            className="flex items-center gap-2 text-[12.5px] text-muted-foreground"
          >
            <span
              className="size-1.5 rounded-full bg-[var(--tone-ink)]"
              aria-hidden
            />
            {t(item)}
          </li>
        ))}
      </ul>
    </div>
  )
}
