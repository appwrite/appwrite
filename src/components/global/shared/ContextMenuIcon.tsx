import type { LucideIcon } from 'lucide-react'

interface ContextMenuIconProps {
  icon: LucideIcon
}

export function ContextMenuIcon({ icon: Icon }: ContextMenuIconProps) {
  return (
    <span className="flex h-4 w-4 shrink-0 items-center justify-center">
      <Icon className="size-4" />
    </span>
  )
}
