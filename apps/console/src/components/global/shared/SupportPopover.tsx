import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Headphones } from 'lucide-react'
import { SupportPanel } from '@/components/global/shared/SupportPanel'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

interface SupportPopoverProps {
  orgId?: string | null
}

export function SupportPopover({ orgId }: SupportPopoverProps) {
  const t = useT()
  return (
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t('Support')}
              {...analyticsAttrs('support-open')}
            >
              <Headphones className="h-4 w-4" />
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>{t('Support')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="end" className="w-80 p-0">
        <SupportPanel orgId={orgId} />
      </PopoverContent>
    </Popover>
  )
}
