import type { LucideIcon } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'

type MarketingHeroSectionProps = {
  eyebrow?: string
  leading?: ReactNode
  title: string
  description: string
  align?: 'center' | 'left'
  gradientTitle?: boolean
  wideFooter?: boolean
  children?: ReactNode
  footer?: ReactNode
  className?: string
}

export function MarketingHeroSection({
  eyebrow,
  leading,
  title,
  description,
  align = 'center',
  gradientTitle = false,
  wideFooter = false,
  children,
  footer,
  className,
}: MarketingHeroSectionProps) {
  const t = useT()
  const isCenter = align === 'center'

  return (
    <section
      className={cn(
        'relative isolate overflow-hidden border-b border-border bg-background',
        className,
      )}
    >
      <HomeSoftLights variant="hero" />
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />
      <div
        className={cn(
          'relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20',
          isCenter ? 'text-center' : 'text-start',
        )}
      >
        {eyebrow ? (
          <p className="text-[11px] font-semibold uppercase tracking-[0.25em] text-muted-foreground">
            {t(eyebrow)}
            <span className="text-[var(--brand-cta)]">_</span>
          </p>
        ) : null}
        {leading ? (
          <div
            className={cn(
              'mb-6',
              eyebrow ? 'mt-4' : '',
              isCenter && 'flex justify-center',
            )}
          >
            {leading}
          </div>
        ) : null}
        <h1
          className={cn(
            'font-aeonik-pro text-balance font-normal leading-none tracking-tight',
            eyebrow || leading ? 'mt-4' : '',
            gradientTitle
              ? 'text-gradient-brand sm:text-[40px] lg:text-[48px] text-[32px]'
              : 'text-foreground sm:text-[40px] lg:text-[48px] text-[32px]',
            isCenter && 'mx-auto max-w-4xl',
            !isCenter && 'max-w-3xl',
          )}
        >
          {t(title)}
          {!gradientTitle ? <span className="text-[var(--brand-cta)]">_</span> : null}
        </h1>
        <p
          className={cn(
            'mt-5 text-[14px] leading-7 text-muted-foreground sm:text-[15px] sm:leading-7',
            isCenter && 'mx-auto max-w-2xl',
            !isCenter && 'max-w-2xl',
          )}
        >
          {t(description)}
        </p>
        {children ? (
          <div
            className={cn(
              'mt-8 flex flex-wrap gap-2',
              isCenter ? 'items-center justify-center' : 'items-center',
            )}
          >
            {children}
          </div>
        ) : null}
        {footer ? (
          <div
            className={cn(
              isCenter && 'mx-auto w-full',
              wideFooter ? 'max-w-7xl' : 'max-w-4xl',
            )}
          >
            {footer}
          </div>
        ) : null}
      </div>
    </section>
  )
}

type MarketingSplitLayoutAlign = 'start' | 'center'

/** Heading left, content right. Matches MarketingFaqSection column ratio. */
export function marketingSplitLayoutClassName(
  options?: { align?: MarketingSplitLayoutAlign; className?: string },
) {
  return cn(
    'grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] lg:gap-12',
    options?.align === 'start' && 'lg:items-start',
    options?.align === 'center' && 'lg:items-center',
    options?.className,
  )
}

export type MarketingSectionHeadingProps = {
  title: string
  description?: string
  align?: 'center' | 'left'
  size?: 'lg' | 'md' | 'sm'
  className?: string
}

export function MarketingSectionHeading({
  title,
  description,
  align = 'center',
  size = 'lg',
  className,
}: MarketingSectionHeadingProps) {
  const t = useT()
  return (
    <div
      className={cn(
        align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl text-start',
        className,
      )}
    >
      <h2
        className={cn(
          'font-aeonik-pro text-balance font-normal leading-none tracking-tight text-foreground',
          size === 'lg' && 'text-[36px] sm:text-[44px]',
          size === 'md' && 'text-[28px] sm:text-[32px]',
          size === 'sm' && 'text-[15px] font-semibold sm:text-[16px]',
        )}
      >
        {t(title)}
        <span className="text-[var(--brand-cta)]">_</span>
      </h2>
      {description ? (
        <p
          className={cn(
            'mt-4 text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7',
            align === 'center' && 'mx-auto max-w-2xl text-balance',
          )}
        >
          {t(description)}
        </p>
      ) : null}
    </div>
  )
}

export type MarketingFeatureIcon = LucideIcon | ComponentType<SVGProps<SVGSVGElement>>

export type MarketingFeatureItem = {
  title: string
  description: string
  icon: MarketingFeatureIcon
}

type MarketingFeatureGridProps = {
  items: MarketingFeatureItem[]
  columns?: 2 | 3 | 4
  className?: string
}

function featureGridCellBorderClass(
  index: number,
  itemCount: number,
  columns: 2 | 3 | 4,
): string {
  const isLastItem = index === itemCount - 1

  if (columns === 2) {
    const col = index % 2
    const row = Math.floor(index / 2)
    const totalRows = Math.ceil(itemCount / 2)

    return cn(
      !isLastItem && 'border-b border-border sm:border-b-0',
      col === 0 && 'sm:border-e sm:border-border',
      row < totalRows - 1 && 'sm:border-b sm:border-border',
    )
  }

  if (columns === 4) {
    const smCol = index % 2
    const smRow = Math.floor(index / 2)
    const smTotalRows = Math.ceil(itemCount / 2)
    const lgCol = index % 4
    const lgRow = Math.floor(index / 4)
    const lgTotalRows = Math.ceil(itemCount / 4)

    return cn(
      !isLastItem && 'border-b border-border sm:border-b-0 lg:border-b-0',
      smCol === 0 && 'sm:border-e sm:border-border',
      smRow < smTotalRows - 1 && 'sm:border-b sm:border-border',
      lgCol < 3 && 'lg:border-e lg:border-border',
      lgRow < lgTotalRows - 1 && 'lg:border-b lg:border-border',
    )
  }

  const smCol = index % 2
  const smRow = Math.floor(index / 2)
  const smTotalRows = Math.ceil(itemCount / 2)
  const lgCol = index % 3
  const lgRow = Math.floor(index / 3)
  const lgTotalRows = Math.ceil(itemCount / 3)

  return cn(
    !isLastItem && 'border-b border-border sm:border-b-0 lg:border-b-0',
    smCol === 0 && 'sm:border-e sm:border-border',
    smRow < smTotalRows - 1 && 'sm:border-b sm:border-border',
    lgCol < 2 && 'lg:border-e lg:border-border',
    lgRow < lgTotalRows - 1 && 'lg:border-b lg:border-border',
  )
}

export function MarketingFeatureGrid({
  items,
  columns = 3,
  className,
}: MarketingFeatureGridProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'grid overflow-hidden rounded-xl border border-border bg-card/45',
        columns === 2 && 'sm:grid-cols-2',
        columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
        columns === 4 && 'sm:grid-cols-2 lg:grid-cols-4',
        className,
      )}
    >
      {items.map((item, index) => {
        const Icon = item.icon
        return (
          <article
            key={item.title}
            className={cn(
              'group p-5 transition-colors hover:bg-accent/15',
              featureGridCellBorderClass(index, items.length, columns),
            )}
          >
            <div className="flex flex-col gap-3">
              <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                <Icon className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <div>
                <h3 className="text-[14px] font-semibold text-foreground">{t(item.title)}</h3>
                <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                  {t(item.description)}
                </p>
              </div>
            </div>
          </article>
        )
      })}
    </div>
  )
}

type MarketingBentoFeatureCardProps = {
  title?: string
  items: MarketingFeatureItem[]
  className?: string
}

export function MarketingBentoFeatureCard({
  title,
  items,
  className,
}: MarketingBentoFeatureCardProps) {
  const t = useT()
  return (
    <div className={cn('overflow-hidden rounded-xl border border-border bg-card/45', className)}>
      {title ? (
        <div className="border-b border-border bg-muted/15 px-4 py-3 text-center">
          <h3 className="text-[13px] font-normal text-foreground">{t(title)}</h3>
        </div>
      ) : null}
      <div className="grid grid-cols-2">
        {items.map((item, index) => {
          const Icon = item.icon
          return (
            <article
              key={item.title}
              className={cn(
                'px-4 py-4',
                index % 2 === 1 && 'border-s border-border',
                index >= 2 && 'border-t border-border',
              )}
            >
              <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                <Icon className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <h4 className="mt-3 text-[13px] font-semibold text-foreground">{t(item.title)}</h4>
              <p className="mt-1 text-[12px] leading-5 text-muted-foreground">{t(item.description)}</p>
            </article>
          )
        })}
      </div>
    </div>
  )
}

export type MarketingStatItem = {
  value: string
  label: string
}

type MarketingHeroStatsProps = {
  items: MarketingStatItem[]
  className?: string
}

export function MarketingHeroStats({ items, className }: MarketingHeroStatsProps) {
  const t = useT()
  const isWide = items.length >= 5

  return (
    <div
      className={cn(
        'mt-10 border-t border-border/60 pt-8 sm:mt-12 sm:pt-10',
        className,
      )}
    >
      <dl
        className={cn(
          'grid grid-cols-2',
          isWide ? 'sm:grid-cols-5' : 'sm:grid-cols-4',
        )}
      >
        {items.map((item, index) => (
          <div
            key={item.label}
            className={cn(
              'flex flex-col-reverse items-center gap-1.5 py-4 text-center sm:gap-2 sm:py-5',
              isWide ? 'px-2 sm:px-3' : 'px-3 sm:px-4',
              index % 2 === 1 && 'border-s border-border/60',
              index >= 2 && 'border-t border-border/60 sm:border-t-0',
              index > 0 && 'sm:border-s sm:border-border/60',
            )}
          >
            <dt
              className={cn(
                'text-[11px] font-medium leading-snug text-muted-foreground sm:text-[12px] sm:leading-5',
                isWide ? 'max-w-[9.5rem] sm:max-w-none' : 'max-w-[10rem]',
              )}
            >
              {t(item.label)}
            </dt>
            <dd
              className={cn(
                'font-aeonik-pro font-normal tabular-nums tracking-tight text-foreground',
                isWide
                  ? 'text-[22px] sm:text-[26px] lg:text-[30px]'
                  : 'text-[26px] sm:text-[30px]',
              )}
            >
              {item.value}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

type MarketingStatGridProps = {
  items: MarketingStatItem[]
  compact?: boolean
  className?: string
}

export function MarketingStatGrid({
  items,
  compact = true,
  className,
}: MarketingStatGridProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'grid grid-cols-2 gap-3 sm:grid-cols-4',
        className,
      )}
    >
      {items.map((item) => (
        <div
          key={item.label}
          className={cn(
            compact
              ? 'flex h-full min-w-0 flex-col justify-center rounded-xl border border-border/80 px-2.5 py-2.5 sm:px-3 sm:py-3'
              : 'rounded-xl border border-border bg-card/50 p-5 text-center sm:p-6',
          )}
        >
          <p
            className={cn(
              compact
                ? 'text-base font-semibold tabular-nums tracking-tight text-foreground sm:text-lg lg:text-xl'
                : 'font-aeonik-pro text-[28px] font-normal text-foreground sm:text-[32px]',
            )}
          >
            {item.value}
          </p>
          <p
            className={cn(
              'mt-0.5 text-muted-foreground',
              compact ? 'text-[10px] leading-snug sm:text-[11px]' : 'text-[13px]',
            )}
          >
            {t(item.label)}
          </p>
        </div>
      ))}
    </div>
  )
}

export type MarketingInvolvementItem = {
  title: string
  description: string
  icon: LucideIcon
  href?: string
  external?: boolean
}

type MarketingInvolvementCardsProps = {
  title?: string
  items: MarketingInvolvementItem[]
  className?: string
}

export function MarketingInvolvementCards({
  title,
  items,
  className,
}: MarketingInvolvementCardsProps) {
  const t = useT()
  return (
    <div className={className}>
      {title ? (
        <h3 className="text-[15px] font-semibold text-foreground">{t(title)}</h3>
      ) : null}
      <div
        className={cn(
          'grid gap-3',
          title && 'mt-4',
          items.length === 1 && 'max-w-xl grid-cols-1',
          items.length === 2 && 'grid-cols-1 sm:grid-cols-2',
          items.length >= 3 && 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        )}
      >
        {items.map((item) => {
          const Icon = item.icon
          const inner = (
            <>
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-5 text-muted-foreground" aria-hidden />
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-foreground">{t(item.title)}</p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">{t(item.description)}</p>
              </div>
            </>
          )

          const cardClassName =
            'group flex w-full items-center gap-3 rounded-xl border border-border bg-card/50 p-4 text-start transition-colors hover:bg-accent/50'

          if (item.href) {
            return (
              <a
                key={item.title}
                href={item.href}
                {...(item.external
                  ? { target: '_blank', rel: 'noopener noreferrer' }
                  : {})}
                className={cn(cardClassName, 'link-unstyled')}
              >
                {inner}
              </a>
            )
          }

          return (
            <div key={item.title} className={cardClassName}>
              {inner}
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function MarketingCtaSection({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  const t = useT()
  return (
    <section className="relative border-t border-border">
      <HomeSoftLights variant="testimonials" className="opacity-50" />
      <div className="relative mx-auto max-w-xl px-4 py-16 text-center sm:px-6 sm:py-20">
        <h2 className="font-aeonik-pro text-[28px] font-normal leading-tight text-foreground sm:text-[32px]">
          {t(title)}
          <span className="text-[var(--brand-cta)]">_</span>
        </h2>
        {description ? (
          <p className="mt-4 text-[14px] leading-7 text-muted-foreground">{t(description)}</p>
        ) : null}
        <div className="mt-6 flex flex-wrap items-center justify-center gap-2">{children}</div>
      </div>
    </section>
  )
}

export function MarketingCtaSignupButtons() {
  const t = useT()
  return (
    <>
      <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
        <Link
          to="/sign-up"
          search={{ redirect: '/' }}
          {...analyticsAttrs('marketing-get-started')}
        >
          {t('Get started')}
        </Link>
      </Button>
      <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
        <Link to="/pricing" {...analyticsAttrs('marketing-view-pricing')}>
          {t('View pricing')}
        </Link>
      </Button>
    </>
  )
}
