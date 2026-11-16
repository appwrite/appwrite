import { ShoppingCart } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canBuyOrTransferOrgDomain } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks'
import { useOrganizationDomainsPlanLimit } from '@/components/pages/organizations/$orgId/domains/_components/useOrganizationDomainsPlanLimit'
import {
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from './service-header-container'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const buyButtonClassName = cn(
  serviceHeaderIconOnlyButton,
  'text-[13px] font-medium',
)

export function BuyDomainHeaderButton({
  orgId,
  onClick,
}: {
  orgId?: string
  onClick: () => void
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(orgId)
  const { isAtLimit, limit } = useOrganizationDomainsPlanLimit(orgId)

  if (!canBuyOrTransferOrgDomain(access, features)) {
    return null
  }

  const label = t('Buy domain')
  const disabledTooltip = isAtLimit
    ? `${t('Your current plan includes up to')} ${limit} ${t('domains')}.`
    : undefined

  const button = (
    <Button
      variant="outline"
      onClick={onClick}
      disabled={!!disabledTooltip}
      className={buyButtonClassName}
      aria-label={label}
      {...analyticsAttrs('buy-domain')}
    >
      <ShoppingCart className="h-4 w-4 shrink-0" />
      <span className={serviceHeaderShowLabel}>{label}</span>
      <span className="sr-only @[640px]:hidden">{label}</span>
    </Button>
  )

  if (!disabledTooltip) {
    return button
  }

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{button}</span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="max-w-xs">
          <p>{disabledTooltip}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
