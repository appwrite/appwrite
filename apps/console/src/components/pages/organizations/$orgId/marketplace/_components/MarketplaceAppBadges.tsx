import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { MarketplaceApp } from '@/lib/marketplace/types'
import { cn } from '@/lib/utils'
import { BadgeCheck } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

type MarketplaceAppBadgesProps = {
  app: Pick<MarketplaceApp, 'isVerified'>
}

function IconBadge({
  label,
  variant,
  children,
}: {
  label: string
  variant: 'info' | 'success' | 'inactive'
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge
          variant={variant}
          className={cn(
            'inline-flex h-5 w-5 shrink-0 items-center justify-center p-0',
          )}
          aria-label={label}
        >
          {children}
        </Badge>
      </TooltipTrigger>
      <TooltipContent side="top" className="text-[12px]">
        {label}
      </TooltipContent>
    </Tooltip>
  )
}

export function MarketplaceAppBadges({ app }: MarketplaceAppBadgesProps) {
  const t = useT()
  if (!app.isVerified) return null

  return (
    <TooltipProvider delayDuration={300}>
      <div className="flex shrink-0 items-center gap-1">
        <IconBadge label={t('Verified')} variant="success">
          <BadgeCheck className="h-3 w-3" aria-hidden />
        </IconBadge>
      </div>
    </TooltipProvider>
  )
}
