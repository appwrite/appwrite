import { Building2, Code, Server } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ArtConnector, ArtIconBadge, ArtLiveDot, ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile, ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { capabilityIcon, StageMark } from '@/components/pages/solutions/CapabilityJourney'
import type { SolutionCapabilityGroup } from '@/lib/solutions/content'
import { useT } from '@/lib/i18n/translate'

const KEEP_ICONS: Record<string, LucideIcon> = {
  'Enterprise plan': Building2,
  'Self-hosted edition': Server,
  'Same SDKs': Code,
}

function PilotBoard({ group }: { group: SolutionCapabilityGroup }) {
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
      <ul className="grid gap-px sm:grid-cols-2 lg:grid-cols-4">
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

/** Page thumbnail inside the demo window. The words live in the rows under it. */
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

function DemoWindow({ group }: { group: SolutionCapabilityGroup }) {
  const t = useT()

  return (
    <div className="product-hero-rise" style={riseStyle(480)}>
      <ArtWindow
        bodyClassName="flex flex-col gap-4"
        title={
          <span dir="ltr" className="font-mono">
            pilot.example.com
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
        <ul className="grid gap-4 sm:grid-cols-3">
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

function KeepCard({ group }: { group: SolutionCapabilityGroup }) {
  const t = useT()

  return (
    <div
      className="product-hero-rise product-tone-shadow flex flex-col rounded-2xl border border-border bg-background p-4 dark:bg-card"
      style={riseStyle(640)}
    >
      <div className="product-tone-hairline -mx-4 -mt-4 mb-4 h-px" aria-hidden />
      <StageMark index="03" group={group} />
      <div className="mt-5 flex items-center gap-3">
        <span className="rounded-full border border-foreground/15 px-2.5 py-1 font-mono text-[11px] text-muted-foreground">
          Cloud
        </span>
        <ArtConnector travel travelDelayMs={700} className="min-w-6 flex-1" />
        <span className="rounded-full border border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] px-2.5 py-1 font-mono text-[11px] text-[var(--tone-ink)]">
          Enterprise
        </span>
      </div>
      <ul className="mt-5 space-y-4 border-t border-foreground/10 pt-4">
        {group.items.map((item) => {
          const Icon = KEEP_ICONS[item.title]
          return (
            <li key={item.title} className="flex gap-3">
              {Icon ? <ArtIconBadge icon={Icon} /> : null}
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

function StageBridge() {
  return (
    <div className="flex justify-center py-1" aria-hidden>
      <span className="h-8 border-s border-dashed border-[rgb(var(--tone-rgb)/0.55)]" />
    </div>
  )
}

/** A pilot, the demo people can open, then the same APIs on Enterprise. */
export function CapabilityPath({ groups }: { groups: readonly SolutionCapabilityGroup[] }) {
  const [pilot, show, keep] = groups

  if (!pilot || !show || !keep) return null

  return (
    <ProductVisualAura>
      <PilotBoard group={pilot} />
      <StageBridge />
      <div className="flex flex-col lg:flex-row lg:items-center">
        <div className="min-w-0 lg:flex-[1.35]">
          <DemoWindow group={show} />
        </div>
        <div className="flex justify-center py-1 lg:hidden" aria-hidden>
          <span className="h-8 border-s border-dashed border-[rgb(var(--tone-rgb)/0.55)]" />
        </div>
        <div className="hidden items-center lg:flex lg:w-16 lg:px-3" aria-hidden>
          <ArtConnector travel travelDelayMs={400} />
        </div>
        <div className="min-w-0 lg:flex-1">
          <KeepCard group={keep} />
        </div>
      </div>
    </ProductVisualAura>
  )
}
