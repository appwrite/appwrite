import { docsGridThreeCol } from '@/lib/docs/docs-container'
import type { DocsPartnersHomeCard } from '@/lib/docs/partners-home-content'
import { cn } from '@/lib/utils'
import { DocsPartnersCardIcon } from './DocsPartnersCardIcon'
import { DocsRouteLink } from '../DocsRouteLink'

const TILE_HOVER_LIGHTS = [
  'absolute -start-[28%] -top-[48%] h-[200px] w-[260px] bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.16)_0%,rgba(133,219,216,0.05)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.09)_0%,rgba(133,219,216,0.028)_42%,transparent_76%)]',
  'absolute -end-[28%] -top-[44%] h-[200px] w-[260px] bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.14)_0%,rgba(253,54,110,0.045)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.08)_0%,rgba(253,54,110,0.025)_42%,transparent_76%)]',
  'absolute start-[8%] -top-[52%] h-[210px] w-[280px] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.13)_0%,rgba(124,103,254,0.04)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.075)_0%,rgba(124,103,254,0.022)_42%,transparent_76%)]',
  'absolute -start-[32%] top-[18%] h-[190px] w-[250px] bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_14%,transparent)_0%,color-mix(in_srgb,var(--brand-cta)_4%,transparent)_42%,transparent_76%)]',
  'absolute -end-[24%] bottom-[-40%] h-[180px] w-[240px] bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.11)_0%,rgba(254,149,103,0.035)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.065)_0%,rgba(254,149,103,0.02)_42%,transparent_76%)]',
  'absolute -start-[24%] bottom-[-42%] h-[180px] w-[240px] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.1)_0%,rgba(124,103,254,0.03)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.06)_0%,rgba(124,103,254,0.018)_42%,transparent_76%)]',
] as const

function tileBorderClass(index: number, count: number): string {
  const smCols = 2
  const smCol = index % smCols
  const smRow = Math.floor(index / smCols)
  const smRows = Math.ceil(count / smCols)

  const xlCols = 3
  const xlCol = index % xlCols
  const xlRow = Math.floor(index / xlCols)
  const xlRows = Math.ceil(count / xlCols)

  return cn(
    'border-b border-border last:border-b-0',
    '@[560px]:border-b-0',
    smCol < smCols - 1 && '@[560px]:border-e @[560px]:border-border',
    smRow < smRows - 1 && '@[560px]:border-b @[560px]:border-border',
    '@[900px]:border-b-0 @[900px]:border-e-0',
    xlCol < xlCols - 1 && '@[900px]:border-e @[900px]:border-border',
    xlRow < xlRows - 1 && '@[900px]:border-b @[900px]:border-border',
  )
}

function TileHoverLight({ variant }: { variant: number }) {
  return (
    <div
      className="pointer-events-none absolute inset-0 z-0 overflow-hidden opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:transition-none motion-reduce:group-hover:opacity-0"
      aria-hidden
    >
      <div className={TILE_HOVER_LIGHTS[variant % TILE_HOVER_LIGHTS.length]} />
    </div>
  )
}

function TileIcon({ item }: { item: DocsPartnersHomeCard }) {
  return <DocsPartnersCardIcon item={item} className="size-9" iconClassName="size-4" />
}

type DocsPartnersHubBentoProps = {
  items: DocsPartnersHomeCard[]
}

export function DocsPartnersHubBento({ items }: DocsPartnersHubBentoProps) {
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45">
      <div className={cn('grid', docsGridThreeCol)}>
        {items.map((item, index) => (
            <DocsRouteLink
              key={item.href}
              href={item.href}
              className={cn(
                'group relative isolate flex flex-col p-5 transition-colors hover:bg-accent/10',
                tileBorderClass(index, items.length),
              )}
            >
              <TileHoverLight variant={index} />
              <div className="relative z-10">
                <TileIcon item={item} />
                <h3 className="mt-3 text-[13px] font-medium text-foreground">
                  {item.title}
                  {item.new ? (
                    <span className="ms-2 text-[10px] font-semibold uppercase tracking-wide text-[var(--brand-cta)]">
                      New
                    </span>
                  ) : null}
                </h3>
                <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                  {item.description}
                </p>
              </div>
            </DocsRouteLink>
          ))}
      </div>
    </div>
  )
}
