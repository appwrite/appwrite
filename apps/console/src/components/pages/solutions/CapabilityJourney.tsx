import { ArrowRight, Ban, Timer } from 'lucide-react'
import { ArtConnector, ArtIconBadge, ArtLiveDot, ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile, ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import type { ProductIcon, ProductNavItemId } from '@/lib/products/types'
import type { SolutionCapabilityGroup } from '@/lib/solutions/content'
import { useT } from '@/lib/i18n/translate'

const CAPABILITY_PRODUCT: Record<string, ProductNavItemId> = {
  Auth: 'auth',
  Databases: 'databases',
  Storage: 'storage',
  Functions: 'functions',
  Messaging: 'messaging',
  Realtime: 'realtime',
  Sites: 'sites',
  Domains: 'domains',
  Firewall: 'firewall',
}

const FIREWALL_ACTIONS = [
  { label: 'Deny', icon: Ban },
  { label: 'Rate limit', icon: Timer },
  { label: 'Redirect', icon: ArrowRight },
] as const

export function capabilityIcon(title: string): ProductIcon | null {
  const id = CAPABILITY_PRODUCT[title]
  if (!id) return null
  return PRODUCT_NAV_REGISTRY[id].icon
}

export function StageMark({ index, group }: { index: string; group: SolutionCapabilityGroup }) {
  const t = useT()
  const Icon = group.icon

  return (
    <div className="flex items-center gap-2.5">
      <span className="font-mono text-[11px] tabular-nums text-[var(--tone-ink)]">{index}</span>
      <Icon className="size-4 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
      <h3 className="text-[15px] font-medium text-foreground">{t(group.title)}</h3>
    </div>
  )
}

function BuildBoard({ group }: { group: SolutionCapabilityGroup }) {
  const t = useT()

  return (
    <div
      className="product-hero-rise product-tone-shadow overflow-hidden rounded-2xl border border-border bg-foreground/[0.08]"
      style={riseStyle(80)}
    >
      <div className="product-tone-hairline h-px" aria-hidden />
      <div className="flex items-center border-b border-foreground/10 bg-background/90 px-4 py-3.5 dark:bg-card/90">
        <StageMark index="01" group={group} />
      </div>
      <ul className="grid gap-px sm:grid-cols-2 lg:grid-cols-3">
        {group.items.map((item, index) => {
          const Icon = capabilityIcon(item.title)
          return (
            <li
              key={item.title}
              className="product-hero-rise flex gap-3 bg-background/95 p-4 dark:bg-card/95"
              style={riseStyle(160 + index * 50)}
            >
              {Icon ? <ProductIconTile icon={Icon} size="sm" /> : null}
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground">{t(item.title)}</p>
                <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(item.description)}</p>
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** Page thumbnail inside the site window. The words live in the rows under it. */
function SitePreview() {
  return (
    <div className="overflow-hidden rounded-lg border border-foreground/10 bg-muted/20" aria-hidden>
      <div className="flex items-center justify-between border-b border-foreground/10 px-3 py-2.5">
        <span className="h-2 w-14 rounded-full bg-foreground/20" />
        <span className="flex gap-1.5">
          <span className="size-1.5 rounded-full bg-foreground/15" />
          <span className="size-1.5 rounded-full bg-foreground/15" />
          <span className="size-1.5 rounded-full bg-foreground/15" />
        </span>
      </div>
      <div className="bg-[radial-gradient(ellipse_at_top,rgb(var(--tone-rgb)/0.16),transparent_70%)] px-3 py-4">
        <span className="block h-2.5 w-3/5 max-w-40 rounded-full bg-foreground/25" />
        <span className="mt-2 block h-2 w-2/5 max-w-28 rounded-full bg-foreground/10" />
        <span className="mt-4 block h-7 w-20 rounded-md bg-[var(--tone-ink)] shadow-[0_8px_20px_-10px_rgb(var(--tone-rgb))]" />
      </div>
      <div className="grid grid-cols-3 gap-2 px-3 pb-3">
        <span className="h-9 rounded-md border border-foreground/10 bg-background/80" />
        <span className="h-9 rounded-md border border-foreground/10 bg-background/80" />
        <span className="h-9 rounded-md border border-foreground/10 bg-background/60" />
      </div>
    </div>
  )
}

function DeployWindow({ group }: { group: SolutionCapabilityGroup }) {
  const t = useT()

  return (
    <div className="product-hero-rise" style={riseStyle(520)}>
      <ArtWindow
        bodyClassName="flex flex-col gap-4"
        title={
          <span dir="ltr" className="font-mono">
            app.example.com
          </span>
        }
        trailing={
          <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <ArtLiveDot className="size-1.5" />
            {t('Live')}
          </span>
        }
      >
        <StageMark index="02" group={group} />
        <SitePreview />
        <ul className="grid gap-4 sm:grid-cols-2">
          {group.items.map((item) => {
            const Icon = capabilityIcon(item.title)
            return (
              <li key={item.title} className="flex gap-3">
                {Icon ? <ProductIconTile icon={Icon} size="sm" /> : null}
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-foreground">{t(item.title)}</p>
                  <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(item.description)}</p>
                </div>
              </li>
            )
          })}
        </ul>
      </ArtWindow>
    </div>
  )
}

function ProtectPanel({ group }: { group: SolutionCapabilityGroup }) {
  const t = useT()
  const item = group.items[0]
  const Icon = item ? capabilityIcon(item.title) : null

  return (
    <div
      className="product-hero-rise product-tone-shadow flex flex-col rounded-2xl border border-border bg-background p-4 dark:bg-card"
      style={riseStyle(680)}
    >
      <div className="product-tone-hairline -mx-4 -mt-4 mb-4 h-px" aria-hidden />
      <StageMark index="03" group={group} />
      <div className="mt-5 flex items-center gap-4">
        <span className="relative flex size-16 shrink-0 items-center justify-center">
          <span
            className="absolute inset-0 rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.28),transparent_68%)]"
            aria-hidden
          />
          {Icon ? <ProductIconTile icon={Icon} size="lg" /> : null}
        </span>
        {item ? (
          <div className="min-w-0">
            <p className="text-[14px] font-medium text-foreground">{t(item.title)}</p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(item.description)}</p>
          </div>
        ) : null}
      </div>
      {item?.title === 'Firewall' ? (
        <ul className="mt-5 grid gap-2 border-t border-foreground/10 pt-3 sm:grid-cols-3 lg:grid-cols-1">
          {FIREWALL_ACTIONS.map((action) => (
            <li key={action.label} className="flex items-center gap-2.5">
              <span className={action.label === 'Redirect' ? 'rtl:-scale-x-100' : undefined}>
                <ArtIconBadge icon={action.icon} />
              </span>
              <span className="text-[13px] font-medium text-foreground">{t(action.label)}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

function StageBridge() {
  return (
    <div className="flex justify-center py-1" aria-hidden>
      <span className="h-8 border-s border-dashed border-[rgb(var(--tone-rgb)/0.55)]" />
    </div>
  )
}

/** Build fills the first row. Deploy and Protect share the next, wide enough for what they hold. */
export function CapabilityJourney({ groups }: { groups: readonly SolutionCapabilityGroup[] }) {
  const [lead, ...rest] = groups
  const wide = rest.find((group) => group.items.length > 1) ?? rest[0]
  const narrow = rest.find((group) => group !== wide)

  if (!lead || !wide) return null

  return (
    <ProductVisualAura>
      <BuildBoard group={lead} />
      <StageBridge />
      <div className="flex flex-col lg:flex-row lg:items-center">
        <div className="min-w-0 lg:flex-[2]">
          <DeployWindow group={wide} />
        </div>
        <div className="flex justify-center py-1 lg:hidden" aria-hidden>
          <span className="h-8 border-s border-dashed border-[rgb(var(--tone-rgb)/0.55)]" />
        </div>
        <div className="hidden items-center lg:flex lg:w-16 lg:px-3" aria-hidden>
          <ArtConnector travel travelDelayMs={600} />
        </div>
        {narrow ? (
          <div className="min-w-0 lg:flex-1">
            <ProtectPanel group={narrow} />
          </div>
        ) : null}
      </div>
    </ProductVisualAura>
  )
}
