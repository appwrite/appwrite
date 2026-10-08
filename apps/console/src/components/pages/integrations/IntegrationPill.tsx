import { Slot } from '@radix-ui/react-slot'
import type { ComponentProps } from 'react'
import { cn } from '@/lib/utils'

type IntegrationPillOptions = {
  active?: boolean
  variant?: 'default' | 'success'
}

export function integrationPillClassName({
  active = false,
  variant = 'default',
}: IntegrationPillOptions = {}) {
  const base =
    'inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-[12px] font-medium shrink-0 transition-colors [&>svg]:size-3'

  if (variant === 'success') {
    return cn(
      base,
      'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    )
  }

  return cn(
    base,
    active
      ? 'bg-foreground text-background'
      : 'bg-muted/60 text-muted-foreground hover:text-foreground',
  )
}

type IntegrationPillProps = ComponentProps<'span'> &
  IntegrationPillOptions & {
    asChild?: boolean
  }

export function IntegrationPill({
  active,
  variant = 'default',
  className,
  asChild = false,
  ...props
}: IntegrationPillProps) {
  const Comp = asChild ? Slot : 'span'

  return (
    <Comp
      className={cn(integrationPillClassName({ active, variant }), className)}
      {...props}
    />
  )
}
