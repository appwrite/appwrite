import type { ReactNode } from 'react'
import { cn } from '../lib/cn'

export function BrandEyebrow({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <p
      className={cn('font-inter-semibold uppercase', className)}
      style={{
        fontSize: 22,
        letterSpacing: '0.25em',
        color: BRAND_MUTED,
      }}
    >
      {children}
      <span style={{ color: BRAND_CTA }}>_</span>
    </p>
  )
}

export function BrandTitle({
  children,
  size = 'lg',
  className,
}: {
  children: ReactNode
  size?: 'lg' | 'xl' | 'hero'
  className?: string
}) {
  const fontSize =
    size === 'hero' ? 168 : size === 'xl' ? 96 : 84

  return (
    <h1
      className={cn('font-aeonik leading-[1.05] tracking-[-0.022em]', className)}
      style={{
        fontSize,
        color: BRAND_FOREGROUND,
      }}
    >
      {children}
    </h1>
  )
}

export function BrandTitleSuffix({
  size = 'xl',
  fontSize,
}: {
  size?: 'md' | 'lg' | 'xl' | 'hero'
  fontSize?: number
}) {
  const resolvedSize =
    fontSize ?? (size === 'hero' ? 220 : size === 'xl' ? 96 : size === 'md' ? 80 : 84)
  return (
    <span
      className="font-aeonik"
      style={{ fontSize: resolvedSize, lineHeight: 1, color: '#fd366e' }}
    >
      _
    </span>
  )
}

export function BrandGradientText({
  children,
  size = 'hero',
  className,
}: {
  children: ReactNode
  size?: 'md' | 'lg' | 'hero'
  className?: string
}) {
  const fontSize = size === 'hero' ? 220 : size === 'lg' ? 120 : 80

  return (
    <span
      className={cn('font-aeonik text-gradient-brand inline-block', className)}
      style={{
        fontSize,
        lineHeight: 1,
        letterSpacing: '-0.022em',
        paddingRight: '0.075em',
      }}
    >
      {children}
    </span>
  )
}

export function BrandSubtitle({
  children,
  className,
  centered = false,
}: {
  children: ReactNode
  className?: string
  centered?: boolean
}) {
  return (
    <p
      className={cn('font-inter max-w-2xl leading-snug', className)}
      style={{
        fontSize: 32,
        color: BRAND_MUTED,
        textAlign: centered ? 'center' : 'left',
      }}
    >
      {children}
    </p>
  )
}

const BRAND_FOREGROUND = '#fafafa'
const BRAND_MUTED = '#a8a8b3'
const BRAND_CTA = '#fd366e'
