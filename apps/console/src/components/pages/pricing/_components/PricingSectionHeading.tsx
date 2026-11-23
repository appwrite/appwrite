import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

type PricingSectionHeadingProps = {
  title: ReactNode
  description?: string
  descriptionClassName?: string
  align?: 'center' | 'left'
  size?: 'lg' | 'md'
  className?: string
  as?: 'h1' | 'h2'
  showUnderscore?: boolean
}

export function PricingSectionHeading({
  title,
  description,
  descriptionClassName,
  align = 'center',
  size = 'lg',
  className,
  as: HeadingTag = 'h2',
  showUnderscore = true,
}: PricingSectionHeadingProps) {
  return (
    <div
      className={cn(
        align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl text-start',
        className,
      )}
    >
      <HeadingTag
        className={cn(
          'font-aeonik-pro text-balance font-normal leading-none tracking-tight text-foreground',
          size === 'lg'
            ? 'text-[36px] sm:text-[44px]'
            : 'text-[28px] sm:text-[32px]',
        )}
      >
        {title}
        {showUnderscore ? (
          <span className="text-[var(--brand-cta)]">_</span>
        ) : null}
      </HeadingTag>
      {description ? (
        <p
          className={cn(
            'mt-4 text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7',
            align === 'center' && cn('mx-auto text-balance', descriptionClassName ?? 'max-w-2xl'),
          )}
        >
          {description}
        </p>
      ) : null}
    </div>
  )
}
