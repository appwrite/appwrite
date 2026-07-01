import { ArrowRight, type LucideIcon } from 'lucide-react'
import { Children, cloneElement, isValidElement, type ReactNode } from 'react'
import { resolveMarkdocCardIcon, MARKDOC_BRAND_ICON_CLASS } from '@/lib/docs/markdoc-icons'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
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
  'absolute -end-[18%] -top-[36%] h-[170px] w-[220px]',
  'absolute -start-[16%] -top-[32%] h-[165px] w-[210px]',
  'absolute end-[8%] -top-[40%] h-[175px] w-[225px]',
  'absolute -end-[22%] top-[8%] h-[160px] w-[205px]',
  'absolute -start-[20%] bottom-[-28%] h-[170px] w-[220px]',
  'absolute -end-[14%] bottom-[-24%] h-[165px] w-[215px]',
] as const

const CARD_LINK_CLASS = 'link-unstyled block h-full'

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

  return (
    <div className="not-prose my-8 grid grid-cols-1 gap-4 @[640px]:grid-cols-2">
      {items}
    </div>
  )
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

type ResolvedCardIcon =
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }

function CardItemIcon({ resolved }: { resolved: ResolvedCardIcon }) {
  return (
    <span className="mb-3 flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
      {resolved.type === 'image' ? (
        <img
          src={resolved.src}
          alt=""
          className={cn(MARKDOC_BRAND_ICON_CLASS, PUBLIC_ICON_MUTED_CLASSES)}
        />
      ) : (
        <resolved.Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      )}
    </span>
  )
}

export function CardsItem({
  href,
  title,
  icon,
  image,
  children,
  cardIndex = 0,
  compact = false,
}: {
  href?: string
  title?: string
  icon?: string
  image?: string
  children?: ReactNode
  cardIndex?: number
  compact?: boolean
}) {
  const lightVariant = getCardLightVariant(href ?? title ?? '', cardIndex)
  const resolvedIcon = resolveMarkdocCardIcon({ icon, image, title, href })

  const content = (
    <div className="group relative isolate flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card/50 p-5">
      <CardHoverLight variant={lightVariant} />
      <div className="relative z-10 flex h-full flex-col">
        {resolvedIcon ? <CardItemIcon resolved={resolvedIcon} /> : null}
        {title ? (
          <h3
            className={cn(
              'font-medium text-foreground/90',
              compact
                ? 'text-[13px] @[480px]:text-[14px]'
                : 'text-[15px] @[640px]:text-[16px]',
            )}
          >
            {title}
          </h3>
        ) : null}
        {children ? (
          <div
            className={cn(
              'mt-2 flex-1 leading-[1.6] text-muted-foreground',
              compact
                ? 'text-[12px] @[480px]:text-[13px]'
                : 'text-[13px] @[640px]:text-[14px]',
            )}
          >
            {children}
          </div>
        ) : null}
        {href ? (
          <ArrowRight className="mt-4 size-4 text-muted-foreground" />
        ) : null}
      </div>
    </div>
  )

  if (!href) return content

  if (href.startsWith('http') || href.startsWith('//')) {
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className={CARD_LINK_CLASS}
      >
        {content}
      </a>
    )
  }

  return (
    <DocsRouteLink href={href} className={CARD_LINK_CLASS}>
      {content}
    </DocsRouteLink>
  )
}
