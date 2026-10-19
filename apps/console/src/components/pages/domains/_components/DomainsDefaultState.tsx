import { ArrowUpRight, Globe, Layers, Lock, Search, type LucideIcon } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const EXAMPLE_SEARCHES = [
  'bramblecode.dev',
  'quillstack.app',
  'driftmesh.io',
  'cobaltnest.ai',
  'emberloop.studio',
] as const

const FEATURES: { icon: LucideIcon; title: string; description: string }[] = [
  {
    icon: Search,
    title: '160+ TLDs',
    description: 'Live pricing and availability as you type.',
  },
  {
    icon: Layers,
    title: 'Managed with your backend',
    description:
      'Buy and manage the domain in the same Console as Auth, Databases, Sites, and Functions.',
  },
  {
    icon: Globe,
    title: 'Appwrite DNS',
    description: 'Manage records in the Console, next to your projects.',
  },
  {
    icon: Lock,
    title: 'Automatic TLS',
    description: 'Connect Sites, Functions, or API domains with certificates issued for you.',
  },
]

type DomainsDefaultStateProps = {
  onExampleSearch?: (value: string) => void
  /** Tighter spacing for shorter surfaces such as the buy-domain wizard. */
  compact?: boolean
}

export function DomainsExampleSearches({
  onExampleSearch,
}: {
  onExampleSearch: (value: string) => void
}) {
  const t = useT()

  return (
    <div
      className="product-hero-rise mx-auto mt-5 flex max-w-2xl flex-wrap items-center justify-center gap-2"
      style={riseStyle(120)}
    >
      <span className="me-1 text-[12px] text-muted-foreground">{t('Popular searches')}</span>
      {EXAMPLE_SEARCHES.map((example) => (
        <button
          key={example}
          type="button"
          dir="ltr"
          onClick={() => onExampleSearch(example)}
          className="rounded-full border border-border bg-background/70 px-3 py-1 font-mono text-[12px] text-foreground/80 backdrop-blur-sm transition-colors hover:border-[var(--brand-cta)]/40 hover:text-foreground dark:bg-card/50"
        >
          {example}
        </button>
      ))}
    </div>
  )
}

export function DomainsDefaultState({
  onExampleSearch,
  compact = false,
}: DomainsDefaultStateProps) {
  const t = useT()

  return (
    <div className="mx-auto w-full max-w-5xl">
      {onExampleSearch ? <DomainsExampleSearches onExampleSearch={onExampleSearch} /> : null}

      <ul
        className={cn(
          'grid gap-x-8 sm:grid-cols-2 lg:grid-cols-4',
          compact
            ? 'mt-8 gap-y-6 border-t border-border/70 pt-7 sm:mt-10'
            : 'mt-16 gap-y-8 border-t border-border pt-10 sm:mt-20',
        )}
      >
        {FEATURES.map((feature, index) => (
          <li
            key={feature.title}
            className="product-hero-rise flex gap-3.5 text-start"
            style={riseStyle(220 + index * 90)}
          >
            <ProductIconTile icon={feature.icon} size="sm" />
            <div className="min-w-0">
              <h2 className="text-[14px] font-medium text-foreground">{t(feature.title)}</h2>
              <p className="mt-1 text-[13px] leading-5 text-muted-foreground">{t(feature.description)}</p>
            </div>
          </li>
        ))}
      </ul>

      <div className={cn('text-center', compact ? 'mt-7' : 'mt-10')}>
        <DocsRouteLink
          href="/docs/products/domains"
          className="group/docs inline-flex items-center gap-1.5 text-[13px] font-medium text-foreground/80 transition-colors hover:text-foreground"
        >
          {t('Appwrite Domains docs')}
          <ArrowUpRight
            className="size-3.5 transition-transform group-hover/docs:-translate-y-0.5 group-hover/docs:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover/docs:-translate-x-0.5"
            aria-hidden
          />
        </DocsRouteLink>
      </div>
    </div>
  )
}
