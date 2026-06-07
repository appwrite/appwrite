import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

type PricingSectionHeadingProps = {
  title: ReactNode
  description?: string
  descriptionClassName?: string
  align?: 'center' | 'left'
  size?: 'lg' | 'md'
  className?: string
}

export function PricingSectionHeading({
  title,
  description,
  descriptionClassName,
  align = 'center',
  size = 'lg',
  className,
}: PricingSectionHeadingProps) {
  return (
    <div
      className={cn(
        align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl text-left',
        className,
      )}
    >
      <h2
        className={cn(
          'font-aeonik-pro text-balance font-normal leading-none tracking-tight text-foreground',
          size === 'lg'
            ? 'text-[36px] sm:text-[44px]'
            : 'text-[28px] sm:text-[32px]',
        )}
      >
        {title}
        <span className="text-[var(--brand-cta)]">_</span>
      </h2>
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
