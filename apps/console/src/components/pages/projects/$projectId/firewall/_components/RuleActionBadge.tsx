import { Badge } from '@/components/ui/badge'
import {
  getFirewallActionBadgeClass,
  getFirewallActionLabel,
} from '@/lib/firewall/actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export function RuleActionBadge({
  action,
  className,
}: {
  action: string
  className?: string
}) {
  const t = useT()
  const label = t(getFirewallActionLabel(action))

  // Colors come from the shared firewall action palette so the badge matches
  // the traffic chart and dropdown dots (see FIREWALL_ACTION_COLORS).
  return (
    <Badge
      className={cn(
        getFirewallActionBadgeClass(action),
        'text-[10px] shrink-0',
        className,
      )}
    >
      {label}
    </Badge>
  )
}
