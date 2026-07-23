import { Badge } from '@/components/ui/badge'
import { WafRuleAction } from '@appwrite.io/console'
import { getFirewallActionLabel } from '@/lib/firewall/actions'
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

  const variant =
    action === WafRuleAction.Deny
      ? 'error'
      : action === WafRuleAction.Bypass
        ? 'processing'
        : action === WafRuleAction.RateLimit
          ? 'warning'
          : action === WafRuleAction.Redirect
            ? 'info'
            : 'info'

  return (
    <Badge variant={variant} className={cn('text-[10px] shrink-0', className)}>
      {label}
    </Badge>
  )
}
