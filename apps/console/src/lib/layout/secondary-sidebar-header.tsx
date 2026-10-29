import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { ChevronLeft } from 'lucide-react'
import { cn } from '@/lib/utils'

type SecondarySidebarHeaderProps = {
  title: ReactNode
  showBackButton?: boolean
  backLabel?: string
  onBack?: () => void
  backTo?: {
    to: string
    params?: Record<string, string>
  }
  className?: string
}

const backButtonClassName =
  'flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground'

export function SecondarySidebarHeader({
  title,
  showBackButton = true,
  backLabel = 'Back',
  onBack,
  backTo,
  className,
}: SecondarySidebarHeaderProps) {
  return (
    <div
      className={cn(
        'flex shrink-0 flex-col border-b border-border bg-background',
        className,
      )}
    >
      <div className="flex h-11 items-center gap-2 px-3">
        {showBackButton ? (
          backTo ? (
            <Link
              to={backTo.to}
              params={backTo.params}
              className={backButtonClassName}
              aria-label={backLabel}
            >
              <ChevronLeft className="h-4 w-4" />
            </Link>
          ) : (
            <button
              type="button"
              onClick={onBack}
              className={backButtonClassName}
              aria-label={backLabel}
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
          )
        ) : null}
        <span className="text-[13px] font-medium text-foreground">{title}</span>
      </div>
    </div>
  )
}
