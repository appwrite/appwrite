import type { LucideIcon } from 'lucide-react'
import type { ReactElement } from 'react'
import { cn } from '@/lib/utils'

export type AuthFlowHeaderIconComponent =
  | LucideIcon
  | ((props: { className?: string }) => ReactElement)

/** Header icon tile above auth narrow-card titles (invite, sites preview, OAuth device, etc.). */
export const authFlowHeaderIconBoxClassName =
  'flex size-14 shrink-0 items-center justify-center rounded-xl'

export const authFlowHeaderIconClassName = 'size-6 shrink-0'

type AuthFlowHeaderIconProps = {
  icon: AuthFlowHeaderIconComponent
  variant?: 'neutral' | 'destructive'
  className?: string
  iconClassName?: string
}

export function AuthFlowHeaderIcon({
  icon: Icon,
  variant = 'neutral',
  className,
  iconClassName,
}: AuthFlowHeaderIconProps) {
  return (
    <div
      className={cn(
        authFlowHeaderIconBoxClassName,
        variant === 'destructive'
          ? 'bg-destructive/10'
          : 'bg-muted text-muted-foreground ring-1 ring-border/50',
        className,
      )}
    >
      <Icon
        className={cn(
          authFlowHeaderIconClassName,
          variant === 'destructive' && 'text-destructive',
          iconClassName,
        )}
      />
    </div>
  )
}
