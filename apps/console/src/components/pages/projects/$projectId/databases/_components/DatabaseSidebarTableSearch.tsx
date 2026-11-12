import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ArrowUpDown, RefreshCw, Search, X } from 'lucide-react'
import type { ReactNode } from 'react'

type DatabaseSidebarTableSearchProps = {
  value: string
  onChange: (value: string) => void
  placeholder: string
  ariaLabel?: string
  disabled?: boolean
  clearAriaLabel?: string
  isFetching?: boolean
  onRefresh?: () => void
  sortAriaLabel: string
  sortTooltip?: string
  sortDisabled?: boolean
  sortMenu: ReactNode
  className?: string
}

export function DatabaseSidebarTableSearch({
  value,
  onChange,
  placeholder,
  ariaLabel,
  disabled = false,
  clearAriaLabel,
  isFetching = false,
  onRefresh,
  sortAriaLabel,
  sortTooltip,
  sortDisabled = false,
  sortMenu,
  className,
}: DatabaseSidebarTableSearchProps) {
  const t = useT()
  const resolvedAriaLabel = ariaLabel ?? placeholder
  const resolvedClearLabel = clearAriaLabel ?? t('Clear table search')
  const resolvedSortTooltip = sortTooltip ?? sortAriaLabel
  const resolvedRefreshLabel = t('Refresh')
  const hasClearButton = value.length > 0
  const trailingActionCount = 2 + (hasClearButton ? 1 : 0)

  return (
    <div className={cn('relative min-w-0 flex-1', className)}>
      <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3 w-3 -translate-y-1/2 text-muted-foreground" />
      <Input
        type="text"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className={cn(
          'h-8 ps-8 text-[13px]',
          trailingActionCount === 3 ? 'pe-[4.5rem]' : 'pe-[3.25rem]',
        )}
        aria-label={resolvedAriaLabel}
        disabled={disabled}
      />
      <div className="absolute end-0.5 top-1/2 flex -translate-y-1/2 items-center">
        <TooltipProvider delayDuration={0}>
          {hasClearButton ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6 text-muted-foreground"
                  aria-label={resolvedClearLabel}
                  onClick={() => onChange('')}
                  disabled={disabled}
                >
                  <X className="h-3 w-3" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-xs">
                {resolvedClearLabel}
              </TooltipContent>
            </Tooltip>
          ) : null}
          <DropdownMenu>
            <Tooltip>
              <TooltipTrigger asChild>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6 text-muted-foreground"
                    aria-label={sortAriaLabel}
                    disabled={disabled || sortDisabled}
                  >
                    <ArrowUpDown className="h-3 w-3" />
                  </Button>
                </DropdownMenuTrigger>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-xs">
                {resolvedSortTooltip}
              </TooltipContent>
            </Tooltip>
            {sortMenu}
          </DropdownMenu>
          {onRefresh ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6 text-muted-foreground"
                  aria-label={resolvedRefreshLabel}
                  onClick={onRefresh}
                  disabled={disabled || isFetching}
                >
                  <RefreshCw
                    className={cn(
                      'h-3 w-3',
                      isFetching && 'animate-spin',
                    )}
                  />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-xs">
                {resolvedRefreshLabel}
              </TooltipContent>
            </Tooltip>
          ) : null}
        </TooltipProvider>
      </div>
    </div>
  )
}
