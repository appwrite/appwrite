import { cn } from '@/lib/utils'

/** Shared active background for API explorer and docs reference nav items. */
export const API_NAV_ACTIVE_BG_CLASS = 'bg-accent/60'

export function apiNavItemClassName(active: boolean) {
  return cn(
    'rounded-md px-2 py-1.5 text-[13px] font-medium leading-5 transition-colors',
    active
      ? cn(API_NAV_ACTIVE_BG_CLASS, 'text-foreground')
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

export function apiNavMethodItemClassName(active: boolean) {
  return cn(
    'flex w-full max-w-full min-w-0 cursor-pointer flex-col gap-1 rounded-md px-2 py-1.5 text-start transition-colors',
    active
      ? cn(API_NAV_ACTIVE_BG_CLASS, 'text-foreground')
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}
