import type { HTMLAttributes, ReactNode } from 'react'
import { Card } from '@/components/ui/card'
import { cn } from '@/lib/utils'

/** Shared auth flow card chrome (padding, min height, vertical center). */
export const authFlowCardContentClassName =
  'flex min-h-[480px] flex-col justify-center p-6 md:min-h-[520px] md:p-10'

/** OAuth authorize / device / outcome cards: size to content, scroll only when permissions overflow the viewport. */
export const authFlowOAuthNarrowCardContentClassName =
  'min-h-0 max-h-[min(80dvh,640px)] justify-start overflow-y-auto overscroll-contain md:min-h-0'

/** Left column for sign-in / recovery style illustration cards. */
export const authFlowIllustrationContentClassName =
  'flex min-h-[600px] flex-col justify-center p-6 md:p-10'

export const authFlowTitleClassName = 'text-2xl font-semibold tracking-tight'

export const authFlowDescriptionClassName =
  'text-sm text-muted-foreground leading-relaxed'

/** Footnotes and secondary lines under auth headings. */
export const authFlowMetaClassName = 'text-xs text-muted-foreground'

type AuthFlowTitleProps = HTMLAttributes<HTMLHeadingElement>

export function AuthFlowTitle({ className, ...props }: AuthFlowTitleProps) {
  return <h1 className={cn(authFlowTitleClassName, className)} {...props} />
}

type AuthFlowDescriptionProps = HTMLAttributes<HTMLParagraphElement>

export function AuthFlowDescription({
  className,
  ...props
}: AuthFlowDescriptionProps) {
  return (
    <p className={cn(authFlowDescriptionClassName, className)} {...props} />
  )
}

type AuthFlowNarrowCardProps = {
  children: ReactNode
  className?: string
  contentClassName?: string
}

/** Single-column auth card (invite, OAuth relay, education join, etc.). */
export function AuthFlowNarrowCard({
  children,
  className,
  contentClassName,
}: AuthFlowNarrowCardProps) {
  return (
    <Card className={cn('w-full min-w-0 overflow-hidden py-0', className)}>
      <div className={cn(authFlowCardContentClassName, contentClassName)}>
        {children}
      </div>
    </Card>
  )
}

type AuthFlowIllustrationCardProps = {
  children: ReactNode
  illustration?: ReactNode
  className?: string
  contentClassName?: string
}

/** Two-column card with optional illustration column (defaults to cover image). */
export function AuthFlowIllustrationCard({
  children,
  illustration,
  className,
  contentClassName,
}: AuthFlowIllustrationCardProps) {
  return (
    <Card className={cn('overflow-hidden py-0', className)}>
      <div className="grid md:grid-cols-2">
        <div
          className={cn(authFlowIllustrationContentClassName, contentClassName)}
        >
          {children}
        </div>
        {illustration}
      </div>
    </Card>
  )
}
