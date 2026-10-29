import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function policySidebarLinkClassName(active: boolean) {
  return cn(
    'block min-w-0 truncate rounded-md px-2 py-1.5 text-[13px] font-medium leading-5 transition-colors',
    active
      ? 'bg-accent text-foreground'
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

type PolicySidebarSectionProps = {
  title: string
  ariaLabel?: string
  children: ReactNode
  className?: string
}

export function PolicySidebarSection({
  title,
  ariaLabel,
  children,
  className,
}: PolicySidebarSectionProps) {
  return (
    <nav className={className} aria-label={ariaLabel ?? title}>
      <p className="pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </p>
      {children}
    </nav>
  )
}
