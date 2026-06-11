import { ArrowRight } from 'lucide-react'
import { Children, cloneElement, isValidElement, type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '../DocsRouteLink'

const CARD_HOVER_LIGHTS = [
  'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.14)_0%,rgba(253,54,110,0.045)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.08)_0%,rgba(253,54,110,0.025)_42%,transparent_76%)]',
  'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.13)_0%,rgba(124,103,254,0.04)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.075)_0%,rgba(124,103,254,0.022)_42%,transparent_76%)]',
  'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.16)_0%,rgba(133,219,216,0.05)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.09)_0%,rgba(133,219,216,0.028)_42%,transparent_76%)]',
  'bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.11)_0%,rgba(254,149,103,0.035)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.065)_0%,rgba(254,149,103,0.02)_42%,transparent_76%)]',
  'bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_14%,transparent)_0%,color-mix(in_srgb,var(--brand-cta)_4%,transparent)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_8%,transparent)_0%,color-mix(in_srgb,var(--brand-cta)_2.5%,transparent)_42%,transparent_76%)]',
  'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.12)_0%,rgba(133,219,216,0.038)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.07)_0%,rgba(133,219,216,0.02)_42%,transparent_76%)]',
] as const

const CARD_HOVER_LIGHT_POSITIONS = [
  'absolute -right-[18%] -top-[36%] h-[170px] w-[220px]',
  'absolute -left-[16%] -top-[32%] h-[165px] w-[210px]',
  'absolute right-[8%] -top-[40%] h-[175px] w-[225px]',
  'absolute -right-[22%] top-[8%] h-[160px] w-[205px]',
  'absolute -left-[20%] bottom-[-28%] h-[170px] w-[220px]',
  'absolute -right-[14%] bottom-[-24%] h-[165px] w-[215px]',
] as const

function getCardLightVariant(seed: string, fallbackIndex: number): number {
  if (!seed) return fallbackIndex % CARD_HOVER_LIGHTS.length

  let hash = 0
  for (let i = 0; i < seed.length; i += 1) {
    hash = (hash * 31 + seed.charCodeAt(i)) >>> 0
  }

  return hash % CARD_HOVER_LIGHTS.length
}

export function Cards({ children }: { children: ReactNode }) {
  const items = Children.map(children, (child, index) => {
    if (!isValidElement(child)) return child
    return cloneElement(child, { cardIndex: index } as { cardIndex: number })
  })

  return <div className="not-prose my-8 grid grid-cols-1 gap-4 md:grid-cols-2">{items}</div>
}

function CardHoverLight({ variant }: { variant: number }) {
  const lightIndex = variant % CARD_HOVER_LIGHTS.length
  const positionIndex = variant % CARD_HOVER_LIGHT_POSITIONS.length

  return (
    <div
      className="pointer-events-none absolute inset-0 z-0 overflow-hidden rounded-[inherit] opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:transition-none motion-reduce:group-hover:opacity-0"
      aria-hidden
    >
      <div
        className={cn(
          CARD_HOVER_LIGHT_POSITIONS[positionIndex],
          CARD_HOVER_LIGHTS[lightIndex],
        )}
      />
    </div>
  )
}

export function CardsItem({
  href,
  title,
  children,
  cardIndex = 0,
}: {
  href?: string
  title?: string
  children?: ReactNode
  cardIndex?: number
}) {
  const lightVariant = getCardLightVariant(href ?? title ?? '', cardIndex)

  const content = (
    <div className="group relative isolate flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card/50 p-5">
      <CardHoverLight variant={lightVariant} />
      <div className="relative z-10 flex h-full flex-col">
        {title ? (
          <h3 className="text-[17px] font-semibold text-foreground/90 sm:text-[18px]">{title}</h3>
        ) : null}
        {children ? (
          <div className="mt-2 flex-1 text-[13px] leading-[1.6] text-muted-foreground sm:text-[14px]">
            {children}
          </div>
        ) : null}
        {href ? (
          <ArrowRight className="mt-4 size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-[var(--brand-cta)]" />
        ) : null}
      </div>
    </div>
  )

  if (!href) return content

  if (href.startsWith('http') || href.startsWith('//')) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer">
        {content}
      </a>
    )
  }

  return <DocsRouteLink href={href}>{content}</DocsRouteLink>
}
