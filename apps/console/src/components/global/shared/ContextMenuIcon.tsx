import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

interface ContextMenuIconProps {
  icon: LucideIcon
}

/** 8px gap between leading icon and label - matches menu item `gap-2`. */
export const menuItemIconGapClassName = 'gap-2'

export function ContextMenuIcon({ icon: Icon }: ContextMenuIconProps) {
  return (
    <span className="flex h-4 w-4 shrink-0 items-center justify-center [&_svg]:m-0">
      <Icon className="size-4 shrink-0" />
    </span>
  )
}

/** Alias for dropdown menus - same icon slot as context menus. */
export const MenuItemIcon = ContextMenuIcon

interface MenuItemContentProps {
  icon: LucideIcon
  children: ReactNode
  className?: string
}

/** Icon + label with consistent spacing (use inside menu items / sub-triggers). */
export function MenuItemContent({
  icon,
  children,
  className,
}: MenuItemContentProps) {
  return (
    <>
      <MenuItemIcon icon={icon} />
      <span className={cn('min-w-0 flex-1', className)}>{children}</span>
    </>
  )
}

/** For `asChild` menu rows (e.g. anchor links). */
export const menuItemRowClassName = cn(
  'flex w-full min-w-0 cursor-pointer items-center',
  menuItemIconGapClassName,
)
