import type { AriaRole, ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * Shared layout and colors for top-of-console alerts (billing, impersonation, etc.).
 * Matches {@link CloudStatusBanner}: min-h-14, padding, responsive row.
 */
export type HeaderAlertVariant = 'warning' | 'danger' | 'info'

const VARIANT_CONTAINER: Record<HeaderAlertVariant, string> = {
  warning:
    'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400 border-b border-amber-700/14 dark:border-amber-400/22',
  danger:
    'bg-red-500/10 text-red-600 dark:bg-red-500/20 dark:text-red-400 border-b border-red-600/12 dark:border-red-400/18',
  info: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400 border-b border-blue-600/12 dark:border-blue-400/18',
}

/** Outline CTA - same treatment as the Cloud status banner link chip. */
const VARIANT_OUTLINE_ACTION: Record<HeaderAlertVariant, string> = {
  warning:
    'border border-amber-500 bg-transparent text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:border-amber-400 dark:text-amber-400 dark:hover:bg-amber-500/20 dark:hover:text-amber-300',
  danger:
    'border border-red-500 bg-transparent text-red-600 hover:bg-red-500/10 hover:text-red-700 dark:border-red-400 dark:text-red-400 dark:hover:bg-red-500/20 dark:hover:text-red-300',
  info: 'border border-blue-500 bg-transparent text-blue-600 hover:bg-blue-500/10 hover:text-blue-700 dark:border-blue-400 dark:text-blue-400 dark:hover:bg-blue-500/20 dark:hover:text-blue-300',
}

export function headerAlertOutlineButtonClass(variant: HeaderAlertVariant) {
  return cn(
    'inline-flex h-8 w-fit cursor-pointer items-center justify-center gap-2 rounded-md px-3 text-[13px] font-medium transition-colors',
    VARIANT_OUTLINE_ACTION[variant],
  )
}

/** Text-style secondary CTA beside an outline header-alert button. */
const VARIANT_TEXT_ACTION: Record<HeaderAlertVariant, string> = {
  warning:
    'text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300',
  danger:
    'text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300',
  info: 'text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300',
}

export function headerAlertTextButtonClass(variant: HeaderAlertVariant) {
  return cn(
    'inline-flex h-8 w-fit cursor-pointer items-center justify-center px-1 text-[13px] font-medium underline-offset-4 hover:underline transition-colors',
    VARIANT_TEXT_ACTION[variant],
  )
}

export type HeaderAlertBarProps = {
  variant: HeaderAlertVariant
  icon: LucideIcon
  children: ReactNode
  action?: ReactNode
  className?: string
  role?: AriaRole
  'aria-label'?: string
}

export function HeaderAlertBar({
  variant,
  icon: Icon,
  children,
  action,
  className,
  role,
  'aria-label': ariaLabel,
}: HeaderAlertBarProps) {
  return (
    <div
      role={role}
      aria-label={ariaLabel}
      className={cn(
        'relative flex min-h-14 min-w-0 flex-col gap-3 px-4 py-3 transition-all duration-200 sm:flex-row sm:items-center sm:gap-4',
        VARIANT_CONTAINER[variant],
        className,
      )}
    >
      <div className="flex min-w-0 flex-1 items-start gap-3">
        <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
        <div className="min-w-0 flex-1 text-[13px] font-medium leading-snug">
          {children}
        </div>
      </div>
      {action ? (
        <div className="flex w-full shrink-0 sm:ms-auto sm:w-auto">
          {action}
        </div>
      ) : null}
    </div>
  )
}
