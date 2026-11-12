import { Link, type LinkProps } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type DatabaseSidebarNavItemProps = {
  disabled?: boolean
  disabledTooltip?: string
  className?: string
  children: ReactNode
  onClick?: () => void
  to?: string
  params?: object
  search?: object
}

export function DatabaseSidebarNavItem({
  disabled = false,
  disabledTooltip,
  className,
  children,
  onClick,
  to,
  params,
  search,
}: DatabaseSidebarNavItemProps) {
  const t = useT()

  if (disabled) {
    const content = (
      <span
        className={cn(className, 'cursor-not-allowed opacity-50')}
        aria-disabled="true"
      >
        {children}
      </span>
    )
    if (!disabledTooltip) return content
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>{content}</TooltipTrigger>
          <TooltipContent side="top" className="max-w-xs">
            <p className="text-[13px]">{t(disabledTooltip)}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  if (onClick) {
    return (
      <button type="button" className={className} onClick={onClick}>
        {children}
      </button>
    )
  }

  return (
    <Link
      to={to as LinkProps['to']}
      params={params as LinkProps['params']}
      search={search as LinkProps['search']}
      className={className}
    >
      {children}
    </Link>
  )
}
