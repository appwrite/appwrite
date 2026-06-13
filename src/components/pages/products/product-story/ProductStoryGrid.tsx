import { useEffect, useRef, useState, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { StoryActiveProvider } from './StoryActiveContext'

export type ProductStoryFeature = {
  id: string
  title: string
  description: string
  icon: LucideIcon
  content: ReactNode
  /** Tailwind grid placement, e.g. `lg:col-span-6` */
  className?: string
  /** Taller tile on large screens */
  tall?: boolean
}

type ProductStoryGridProps = {
  features: ProductStoryFeature[]
  className?: string
}

function StoryFeatureTile({ feature }: { feature: ProductStoryFeature }) {
  const Icon = feature.icon
  const tileRef = useRef<HTMLElement>(null)
  const [inView, setInView] = useState(false)

  useEffect(() => {
    const node = tileRef.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) setInView(true)
      },
      { threshold: 0.2, rootMargin: '0px 0px -8% 0px' },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [])

  return (
    <article
      ref={tileRef}
      className={cn(
        'group relative flex min-h-[360px] flex-col overflow-hidden rounded-xl border border-border bg-card/50 transition-colors hover:bg-accent/10 sm:min-h-[400px]',
        feature.tall && 'lg:min-h-[480px]',
        feature.className,
      )}
    >
      <div className="relative z-10 shrink-0 px-4 pt-4 pb-2.5 sm:px-5 sm:pt-5">
        <div className="flex items-center gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
            <Icon className="size-4 text-[var(--brand-cta)]" aria-hidden />
          </span>
          <h3 className="text-[15px] font-semibold text-foreground sm:text-[16px]">
            {feature.title}
          </h3>
        </div>
        <p className="mt-2 max-w-prose text-[13px] leading-5 text-muted-foreground">
          {feature.description}
        </p>
      </div>

      <div className="relative flex min-h-0 flex-1 flex-col px-3 pb-3 sm:px-4 sm:pb-4 lg:px-5 lg:pb-5">
        <div
          className={cn(
            'relative min-h-0 flex-1 overflow-hidden rounded-lg border border-border',
            feature.tall ? 'min-h-[280px] lg:min-h-[340px]' : 'min-h-[240px] lg:min-h-[280px]',
          )}
        >
          <div
            className="pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
            aria-hidden
          />
          <div className="relative z-[1] h-full overflow-auto p-3 sm:p-4">
            <StoryActiveProvider active={inView}>{feature.content}</StoryActiveProvider>
          </div>
        </div>
      </div>
    </article>
  )
}

export function ProductStoryGrid({ features, className }: ProductStoryGridProps) {
  return (
    <div
      className={cn(
        'grid auto-rows-fr grid-cols-1 gap-3 sm:gap-4 lg:grid-cols-12',
        className,
      )}
    >
      {features.map((feature) => (
        <StoryFeatureTile key={feature.id} feature={feature} />
      ))}
    </div>
  )
}
