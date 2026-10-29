import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { DatabaseRowsFullscreenToggle } from './DatabaseRowsFullscreenToggle'

type DatabaseRowsFullscreenShellProps = {
  active: boolean
  onExit: () => void
  children: ReactNode
  className?: string
}

/** Expands children to the viewport with only an exit control when active. */
export function DatabaseRowsFullscreenShell({
  active,
  onExit,
  children,
  className,
}: DatabaseRowsFullscreenShellProps) {
  return (
    <div
      className={cn(
        'flex min-h-0 flex-1 flex-col overflow-hidden',
        active &&
          'fixed inset-0 z-[150] h-[100dvh] max-h-[100dvh] bg-background',
        className,
      )}
    >
      {active ? (
        <div className="absolute end-3 top-3 z-[160]">
          <DatabaseRowsFullscreenToggle active onToggle={onExit} />
        </div>
      ) : null}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">{children}</div>
    </div>
  )
}
