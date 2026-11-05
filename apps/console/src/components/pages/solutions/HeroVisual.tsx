import { Database, Folder, Globe, Users } from 'lucide-react'
import { ArtChip, ArtConnector, ArtIconBadge, ArtIconMask, ArtLiveDot, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile, ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import type {
  SolutionHeroMetric,
  SolutionHeroPath,
  SolutionHeroProject,
  SolutionPageContent,
} from '@/lib/solutions/content'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CLIENT_STACK = [
  { id: 'auth', icon: Users },
  { id: 'databases', icon: Database },
  { id: 'storage', icon: Folder },
  { id: 'sites', icon: Globe },
] as const

function SolutionChipIcon({
  chip,
  tone = 'primary',
}: {
  chip: SolutionPageContent['hero']['chips'][number]
  tone?: 'primary' | 'secondary'
}) {
  if (chip.icon) {
    return <ArtIconBadge icon={chip.icon} tone={tone} />
  }

  if (!chip.iconSrc) return null

  return (
    <span
      className={cn(
        'flex size-7 shrink-0 items-center justify-center rounded-md',
        tone === 'secondary'
          ? 'bg-[rgb(var(--tone2-rgb)/0.18)] text-foreground'
          : 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]',
      )}
    >
      <ArtIconMask src={chip.iconSrc} className="size-3.5" />
    </span>
  )
}

function MetricGrid({ metrics }: { metrics: readonly SolutionHeroMetric[] }) {
  const t = useT()

  return (
    <ul className="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-foreground/10 sm:grid-cols-3">
      {metrics.map((metric, index) => (
        <li
          key={metric.title}
          className="product-hero-rise min-w-0 bg-background/90 p-3"
          style={riseStyle(180 + index * 70)}
        >
          <div className="flex items-center gap-2">
            <ProductIconTile icon={metric.icon} size="sm" />
            <p className="truncate text-[11px] font-medium text-muted-foreground">{t(metric.title)}</p>
          </div>
          <p
            dir={metric.literal || metric.code ? 'ltr' : undefined}
            className={cn(
              'mt-3 truncate leading-none tracking-tight text-foreground',
              metric.code ? 'font-mono text-[13px]' : 'font-aeonik-pro text-[20px]',
            )}
          >
            {metric.literal ? metric.value : t(metric.value)}
          </p>
          <p
            dir={metric.mono ? 'ltr' : undefined}
            className={cn(
              'mt-1.5 text-[10px] leading-4 text-muted-foreground',
              metric.mono && 'truncate font-mono',
            )}
          >
            {metric.mono ? metric.detail : t(metric.detail)}
          </p>
        </li>
      ))}
    </ul>
  )
}

function ProjectList({ projects }: { projects: readonly SolutionHeroProject[] }) {
  const t = useT()

  return (
    <ul className="mt-6 overflow-hidden rounded-2xl border border-foreground/10 bg-background/75">
      {projects.map((project, index) => (
        <li
          key={project.name}
          className={cn(
            'product-hero-rise flex items-center gap-3 px-3 py-3',
            index > 0 && 'border-t border-foreground/10',
          )}
          style={riseStyle(180 + index * 90)}
        >
          <div className="min-w-0 flex-1">
            <p dir="ltr" className="truncate font-mono text-[12px] text-foreground">
              {project.name}
            </p>
            <p dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
              {project.detail}
            </p>
          </div>
          <div className="flex shrink-0 gap-1" aria-hidden>
            {CLIENT_STACK.map((service) => {
              const Icon = service.icon
              return (
                <span
                  key={service.id}
                  className="flex size-6 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground"
                >
                  <Icon className="size-3" />
                </span>
              )
            })}
          </div>
          <p className="w-16 shrink-0 text-end text-[11px] font-medium leading-4 text-[var(--tone-ink)]">
            {t(project.status)}
          </p>
        </li>
      ))}
    </ul>
  )
}

function PlanPath({ path }: { path: SolutionHeroPath }) {
  const t = useT()
  const [from, to] = path.steps

  if (!from || !to) return null

  return (
    <div className="product-hero-rise mt-5" style={riseStyle(720)}>
      <div className="flex items-center gap-3">
        <span className="rounded-full border border-foreground/15 px-2.5 py-1 font-mono text-[11px] text-muted-foreground">
          {from.literal ? from.label : t(from.label)}
        </span>
        <ArtConnector travel travelDelayMs={500} className="min-w-8 flex-1" />
        <span className="rounded-full border border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] px-2.5 py-1 font-mono text-[11px] text-[var(--tone-ink)]">
          {to.literal ? to.label : t(to.label)}
        </span>
      </div>
      <p className="ms-28 mt-2 text-end text-[11px] text-muted-foreground">{t(path.note)}</p>
    </div>
  )
}

type HeroVisualProps = {
  hero: SolutionPageContent['hero']
}

const CHIP_PLACEMENT = [
  { className: 'end-0 top-0 sm:-end-8', delayMs: 1100, floatDelayMs: 200, tone: 'primary' },
  { className: 'bottom-0 start-0 sm:-start-8', delayMs: 1700, floatDelayMs: 900, tone: 'secondary' },
] as const

export function HeroVisual({ hero }: HeroVisualProps) {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-xl pb-10 pt-10 text-start">
      <ProductVisualAura>
        <div className="product-hero-rise flex flex-wrap items-center gap-x-3 gap-y-1 pe-28" style={riseStyle(80)}>
          <p dir="ltr" className="font-mono text-[12px] text-muted-foreground">
            {hero.orgLabel}
          </p>
          <span className="flex items-center gap-2 text-[11px] text-muted-foreground">
            <ArtLiveDot className="size-1.5" />
            {t(hero.status)}
          </span>
        </div>

        {hero.board.kind === 'metrics' ? (
          <MetricGrid metrics={hero.board.metrics} />
        ) : (
          <ProjectList projects={hero.board.projects} />
        )}
        <PlanPath path={hero.board.path} />
      </ProductVisualAura>

      {hero.chips.map((chip, index) => {
        const placement = CHIP_PLACEMENT[index]
        if (!placement) return null
        return (
          <ArtChip
            key={chip.label}
            className={placement.className}
            delayMs={placement.delayMs}
            floatDelayMs={placement.floatDelayMs}
          >
            <div className="flex items-center gap-2">
              <SolutionChipIcon chip={chip} tone={placement.tone} />
              <p className="text-[11px] font-medium leading-4 text-foreground">{t(chip.label)}</p>
            </div>
          </ArtChip>
        )
      })}
    </div>
  )
}
