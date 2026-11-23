import { useLayoutEffect, useRef, useState, type Ref } from 'react'
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

/** Space left between a connector's ends and the orbit ring or the icon tile, in px. */
const ORBIT_GAP = 6
const ICON_GAP = 8

type HubLine = { x1: number; y1: number; x2: number; y2: number }
type HubGeometry = { width: number; height: number; lines: HubLine[] }

/**
 * Position of `element` inside `container`, from layout offsets rather than
 * bounding boxes so the rise animation's transforms don't skew the result.
 */
function offsetWithin(element: HTMLElement, container: HTMLElement) {
  let x = 0
  let y = 0
  let node: HTMLElement | null = element
  while (node && node !== container) {
    x += node.offsetLeft
    y += node.offsetTop
    node = node.offsetParent as HTMLElement | null
  }
  return { x, y, width: element.offsetWidth, height: element.offsetHeight }
}

/**
 * One straight connector per icon, from the edge of the focus ring to the edge
 * of the icon tile, measured from the rendered layout so text wrapping never
 * pulls a line off its icon.
 */
function useHubGeometry(count: number) {
  const containerRef = useRef<HTMLDivElement>(null)
  const focusRef = useRef<HTMLDivElement>(null)
  const iconRefs = useRef<(HTMLSpanElement | null)[]>([])
  const [geometry, setGeometry] = useState<HubGeometry | null>(null)

  useLayoutEffect(() => {
    const container = containerRef.current
    const focus = focusRef.current
    if (!container || !focus) return

    const measure = () => {
      const ring = offsetWithin(focus, container)
      const fx = ring.x + ring.width / 2
      const fy = ring.y + ring.height / 2
      const radius = ring.width / 2

      const lines: HubLine[] = []
      for (const icon of iconRefs.current.slice(0, count)) {
        if (!icon) continue
        const tile = offsetWithin(icon, container)
        const cx = tile.x + tile.width / 2
        const cy = tile.y + tile.height / 2
        const dx = cx - fx
        const dy = cy - fy
        const length = Math.hypot(dx, dy)
        if (length === 0) continue
        const ux = dx / length
        const uy = dy / length
        // Distance from the tile's center to its border along the connector.
        const exit = Math.min(
          ux === 0 ? Number.POSITIVE_INFINITY : tile.width / 2 / Math.abs(ux),
          uy === 0 ? Number.POSITIVE_INFINITY : tile.height / 2 / Math.abs(uy),
        )
        const start = radius + ORBIT_GAP
        const end = length - exit - ICON_GAP
        if (end <= start) continue
        lines.push({ x1: fx + ux * start, y1: fy + uy * start, x2: fx + ux * end, y2: fy + uy * end })
      }

      setGeometry({ width: container.offsetWidth, height: container.offsetHeight, lines })
    }

    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(container)
    for (const icon of iconRefs.current) if (icon) observer.observe(icon)
    void document.fonts?.ready.then(measure)
    return () => observer.disconnect()
  }, [count])

  const setIconRef = (index: number) => (element: HTMLSpanElement | null) => {
    iconRefs.current[index] = element
  }

  return { containerRef, focusRef, setIconRef, geometry }
}

function HubNode({
  item,
  align,
  delayMs,
  iconRef,
}: {
  item: IntegrationHubItem
  align: 'start' | 'end'
  delayMs: number
  iconRef: Ref<HTMLSpanElement>
}) {
  const t = useT()
  const Icon = itemIcon(item.id)
  return (
    <li
      className={cn('product-hero-rise flex gap-3.5', align === 'end' && 'lg:flex-row-reverse lg:text-end')}
      style={riseStyle(delayMs)}
    >
      <span
        ref={iconRef}
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
  focus: keyof typeof PRODUCT_NAV_REGISTRY
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
  const { containerRef, focusRef, setIconRef, geometry } = useHubGeometry(items.length)

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

      <div ref={containerRef} className="relative mx-auto mt-10 min-w-0 max-w-6xl sm:mt-14">
        {geometry ? (
          <svg
            className="pointer-events-none absolute inset-0 hidden size-full lg:block"
            viewBox={`0 0 ${geometry.width} ${geometry.height}`}
            aria-hidden
          >
            {geometry.lines.map((line, index) => (
              <line
                key={index}
                x1={line.x1}
                y1={line.y1}
                x2={line.x2}
                y2={line.y2}
                className="alternative-flow stroke-[rgb(var(--tone-rgb)/0.5)]"
                strokeWidth="1.25"
                strokeDasharray="3 5"
                strokeLinecap="round"
              />
            ))}
          </svg>
        ) : null}

        <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,1fr)_14rem_minmax(0,1fr)] lg:items-center lg:gap-0">
          <ul className="order-2 grid min-w-0 gap-6 sm:grid-cols-2 sm:gap-8 lg:order-1 lg:grid-cols-1 lg:gap-14 lg:pe-16">
            {left.map((item, index) => (
              <HubNode
                key={item.title}
                item={item}
                align="end"
                delayMs={200 + index * 120}
                iconRef={setIconRef(index)}
              />
            ))}
          </ul>

          <div className="order-1 flex justify-center lg:order-2">
            <div
              ref={focusRef}
              className="product-hero-rise relative flex size-44 items-center justify-center"
              style={riseStyle(80)}
            >
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
              <HubNode
                key={item.title}
                item={item}
                align="start"
                delayMs={260 + index * 120}
                iconRef={setIconRef(left.length + index)}
              />
            ))}
          </ul>
        </div>
      </div>
    </ComparisonSection>
  )
}
