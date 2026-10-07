import { Clapperboard } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import type { PlatformProductId } from '@/lib/alternatives/platform'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import type { ProductIcon } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import { ComparisonHeading, ComparisonSection } from './ComparisonParts'

export type IntegrationHubItem = {
  id: PlatformProductId | 'videos'
  title: string
  description: string
  soon?: boolean
}

function itemIcon(id: IntegrationHubItem['id']): ProductIcon {
  return id === 'videos' ? Clapperboard : PRODUCT_NAV_REGISTRY[id].icon
}

/** Where each item's connector meets its column, as a percentage of the hub height. */
const ROW_Y = [16.7, 50, 83.3] as const

function HubNode({
  item,
  align,
  delayMs,
}: {
  item: IntegrationHubItem
  align: 'start' | 'end'
  delayMs: number
}) {
  const t = useT()
  const Icon = itemIcon(item.id)
  return (
    <li
      className={cn('product-hero-rise flex gap-3.5', align === 'end' && 'lg:flex-row-reverse lg:text-end')}
      style={riseStyle(delayMs)}
    >
      <span
        className={cn(
          'flex size-10 shrink-0 items-center justify-center rounded-xl border bg-background shadow-sm dark:bg-card',
          item.soon ? 'border-dashed border-foreground/25 text-muted-foreground' : 'border-border text-[var(--tone-ink)]',
        )}
      >
        <Icon className="size-[18px]" strokeWidth={1.75} aria-hidden />
      </span>
      <span className="min-w-0">
        <span
          className={cn(
            'flex flex-wrap items-center gap-2 text-[14px] font-medium text-foreground',
            align === 'end' && 'lg:justify-end',
          )}
        >
          {t(item.title)}
          {item.soon ? (
            <span className="rounded-full bg-[rgb(var(--tone-rgb)/0.14)] px-2 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]">
              {t('Coming soon')}
            </span>
          ) : null}
        </span>
        <span className="mt-1 block text-[13px] leading-5 text-muted-foreground">{t(item.description)}</span>
      </span>
    </li>
  )
}

/**
 * The focus product in the middle and the Appwrite products it plugs into around it.
 * Open art: connectors and nodes float on the section with no frame.
 */
export function IntegrationHubSection({
  focus,
  focusLabel,
  eyebrow = 'Better together',
  title,
  description,
  items,
}: {
  focus: PlatformProductId
  focusLabel: string
  eyebrow?: string
  title: string
  description: string
  items: [
    IntegrationHubItem,
    IntegrationHubItem,
    IntegrationHubItem,
    IntegrationHubItem,
    IntegrationHubItem,
    IntegrationHubItem,
  ]
}) {
  const t = useT()
  const FocusIcon = PRODUCT_NAV_REGISTRY[focus].icon
  const left = items.slice(0, 3)
  const right = items.slice(3)

  return (
    <ComparisonSection
      backdrop={
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
          <div className="product-tone-glow absolute left-1/2 top-[58%] h-[640px] w-[min(1100px,140%)] -translate-x-1/2 -translate-y-1/2" />
        </div>
      }
    >
      <ComparisonHeading align="center" eyebrow={eyebrow} title={title} description={description} />

      <div className="relative mx-auto mt-10 min-w-0 max-w-6xl sm:mt-14">
        <svg
          className="pointer-events-none absolute inset-0 hidden size-full lg:block"
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
          aria-hidden
        >
          {ROW_Y.flatMap((y) => [
            { x: 33, y },
            { x: 67, y },
          ]).map((end) => (
            <line
              key={`${end.x}-${end.y}`}
              x1="50"
              y1="50"
              x2={end.x}
              y2={end.y}
              className="alternative-flow stroke-[rgb(var(--tone-rgb)/0.5)]"
              strokeWidth="1.25"
              strokeDasharray="3 5"
              vectorEffect="non-scaling-stroke"
            />
          ))}
        </svg>

        <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,1fr)_14rem_minmax(0,1fr)] lg:items-center lg:gap-0">
          <ul className="order-2 grid min-w-0 gap-6 sm:grid-cols-2 sm:gap-8 lg:order-1 lg:grid-cols-1 lg:gap-14 lg:pe-16">
            {left.map((item, index) => (
              <HubNode key={item.title} item={item} align="end" delayMs={200 + index * 120} />
            ))}
          </ul>

          <div className="order-1 flex justify-center lg:order-2">
            <div className="product-hero-rise relative flex size-44 items-center justify-center" style={riseStyle(80)}>
              <span
                className="product-hero-orbit absolute inset-0 rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]"
                aria-hidden
              />
              <span
                className="absolute inset-5 rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.3),transparent_70%)]"
                aria-hidden
              />
              <span className="relative flex flex-col items-center gap-2.5">
                <span className="flex size-16 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] shadow-[0_18px_40px_-16px_rgb(var(--tone-rgb)/0.7)] dark:bg-card">
                  <FocusIcon className="size-7" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="text-[13px] font-medium text-foreground">{t(focusLabel)}</span>
              </span>
            </div>
          </div>

          <ul className="order-3 grid min-w-0 gap-6 sm:grid-cols-2 sm:gap-8 lg:grid-cols-1 lg:gap-14 lg:ps-16">
            {right.map((item, index) => (
              <HubNode key={item.title} item={item} align="start" delayMs={260 + index * 120} />
            ))}
          </ul>
        </div>
      </div>
    </ComparisonSection>
  )
}
