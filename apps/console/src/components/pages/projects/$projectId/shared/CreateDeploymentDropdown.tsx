/**
 * Create deployment dropdown – single primary button that opens a menu with
 * Git (recommended), CLI, and Manual options. Used in Functions and Sites
 * deployment headers and empty states.
 */

import { Plus, ChevronDown, GitBranch, Terminal, Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
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
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

export interface CreateDeploymentDropdownProps {
  onSelectGit: () => void
  onSelectCli: () => void
  onSelectManual: () => void
  disabled?: boolean
  /** When provided, show tooltip on disabled (e.g. plan limit) */
  disabledTooltip?: string
  className?: string
}

export function CreateDeploymentDropdown({
  onSelectGit,
  onSelectCli,
  onSelectManual,
  disabled = false,
  disabledTooltip,
  className,
}: CreateDeploymentDropdownProps) {
  const t = useT()
  const trigger = (
    <Button
      variant="brandCta"
      size="sm"
      disabled={disabled}
      className={`h-9 gap-2 text-[13px] font-medium disabled:opacity-50 disabled:cursor-not-allowed ${className ?? ''}`}
      {...analyticsAttrs('create-deployment')}
    >
      <Plus className="h-4 w-4" />
      {t('Create deployment')}
      <ChevronDown className="h-4 w-4" />
    </Button>
  )

  const content = (
    <DropdownMenu>
      <DropdownMenuTrigger asChild disabled={disabled}>
        {disabled && disabledTooltip ? (
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <div className="inline-flex">{trigger}</div>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                <p>{disabledTooltip}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ) : (
          trigger
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[220px]">
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onSelectGit)}
          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
        >
          <GitBranch className="h-4 w-4" />
          <span>Git</span>
          <span className="ms-auto text-[11px] text-muted-foreground">
            {t('Recommended')}
          </span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onSelectCli)}
          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
        >
          <Terminal className="h-4 w-4" />
          <span>CLI</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onSelectManual)}
          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
        >
          <Upload className="h-4 w-4" />
          <span>{t('Manual')}</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )

  return content
}
