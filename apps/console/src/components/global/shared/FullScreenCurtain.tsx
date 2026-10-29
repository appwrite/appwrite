import type { ComponentType, ReactNode } from 'react'
import { cn } from '@/lib/utils'

type FullScreenCurtainProps = {
  icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
  /** `destructive` tints the icon badge red for blocks and security warnings. */
  tone?: 'muted' | 'destructive'
  title: ReactNode
  description?: ReactNode
  /** Primary and secondary actions, laid out in a responsive row. */
  actions?: ReactNode
  /** Extra content rendered below the actions (e.g. a ghost "Back" button). */
  footer?: ReactNode
  /** Content between the description and the actions. */
  children?: ReactNode
  /** Stacking order; defaults to `z-[120]`. */
  className?: string
  role?: 'alert' | 'dialog'
}

/**
 * Full-viewport blocking curtain with a centered icon, title, description,
 * and actions. Shared by project blocks, offline state, and account warnings.
 */
export function FullScreenCurtain({
  icon: Icon,
  tone = 'muted',
  title,
  description,
  actions,
  footer,
  children,
  className,
  role,
}: FullScreenCurtainProps) {
  return (
    <div
      role={role}
      aria-modal={role === 'dialog' ? true : undefined}
      aria-live={role === 'alert' ? 'assertive' : undefined}
      className={cn(
        'fixed inset-0 z-[120] flex h-[100dvh] max-h-[100dvh] w-full items-center justify-center overflow-y-auto bg-background/95 backdrop-blur-sm',
        className,
      )}
    >
      <div className="mx-4 flex max-w-md flex-col items-center py-6 text-center">
        <div
          className={cn(
            'mb-6 flex size-16 items-center justify-center rounded-full',
            tone === 'destructive'
              ? 'bg-destructive/10 text-destructive'
              : 'bg-muted text-muted-foreground',
          )}
        >
          <Icon className="size-9" aria-hidden />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">{title}</h1>
        {description ? (
          <div className="mt-3 text-[15px] text-muted-foreground">
            {description}
          </div>
        ) : null}
        {children}
        {actions ? (
          <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
            {actions}
          </div>
        ) : null}
        {footer}
      </div>
    </div>
  )
}
