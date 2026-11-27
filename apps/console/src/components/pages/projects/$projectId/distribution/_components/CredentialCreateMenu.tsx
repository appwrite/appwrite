import { ChevronDown, Plus } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Button, buttonVariants } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from '@/components/pages/projects/$projectId/shared/service-header-container'
import { cn } from '@/lib/utils'

export type CredentialCreateOption = {
  id: string
  label: string
  icon: LucideIcon
  onSelect: () => void
}

interface CredentialCreateMenuProps {
  label: string
  options: CredentialCreateOption[]
  disabledTooltip?: string
  layout?: 'toolbar' | 'inline'
}

export function CredentialCreateMenu({
  label,
  options,
  disabledTooltip,
  layout = 'toolbar',
}: CredentialCreateMenuProps) {
  const toolbar = layout === 'toolbar'
  const buttonClassName = cn(
    toolbar ? serviceHeaderIconOnlyButton : 'h-9 gap-1.5 px-4',
    'text-[13px] font-medium',
    toolbar && '@[640px]:gap-1.5',
  )
  const content = (
    <>
      {toolbar ? <Plus className="h-4 w-4 shrink-0" /> : null}
      <span className={toolbar ? serviceHeaderShowLabel : undefined}>
        {label}
      </span>
      {toolbar ? (
        <span className="sr-only @[640px]:hidden">{label}</span>
      ) : null}
      <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-70" />
    </>
  )

  if (disabledTooltip) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="cursor-not-allowed">
              <span
                className={cn(
                  buttonVariants({ variant: 'brandCta' }),
                  buttonClassName,
                  'pointer-events-none inline-flex items-center justify-center opacity-50',
                )}
                aria-disabled
                aria-label={label}
                data-testid="credential-create-menu"
              >
                {content}
              </span>
            </div>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>{disabledTooltip}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="brandCta"
          className={buttonClassName}
          aria-label={label}
          data-testid="credential-create-menu"
        >
          {content}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        {options.map((option) => (
          <DropdownMenuItem key={option.id} onSelect={option.onSelect}>
            <MenuItemContent icon={option.icon}>{option.label}</MenuItemContent>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
