import type { Models } from '@appwrite.io/console'
import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { getRuleStatusBadgeConfig } from '@/lib/utils/status-badge'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'

interface RuleStatusBadgeProps {
  rule: Models.ProxyRule
}

/**
 * Status badge of a proxy rule. A verified rule shows the state of its
 * certificate, and its tooltip says what to do about a problem, or when a
 * healthy certificate expires.
 */
export function RuleStatusBadge({ rule }: RuleStatusBadgeProps) {
  const t = useT()
  const { formatDateShort } = useLocalizedDateFormat()
  const config = getRuleStatusBadgeConfig(rule)
  const hint = config.hint
    ? t(config.hint)
    : config.expireAt
      ? t('Certificate expires on {date}.').replace(
          '{date}',
          formatDateShort(config.expireAt),
        )
      : undefined

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge
          variant={config.variant}
          className="text-[10px] shrink-0 gap-1.5"
          tabIndex={hint ? 0 : undefined}
          title={
            rule.status === 'verifying'
              ? t(
                  'SSL certificate is being issued. This usually takes a couple of minutes.',
                )
              : undefined
          }
        >
          {rule.status === 'verifying' && (
            <Loader2 className="h-3 w-3 animate-spin" />
          )}
          {t(config.label)}
        </Badge>
      </TooltipTrigger>
      {hint ? (
        <TooltipContent className="max-w-xs text-xs">{hint}</TooltipContent>
      ) : null}
    </Tooltip>
  )
}
