import type { Models } from '@appwrite.io/console'
import { Terminal } from '@/lib/icons'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { useT } from '@/lib/i18n/translate'
import {
  isCliSdkActivity,
  isMcpSdkActivity,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import { cn } from '@/lib/utils'

/**
 * MCP / CLI source mark for the Type column: icon + "Via MCP" / "Via CLI".
 */
export function ActivitySdkSourceBadge({
  event,
}: {
  event: Pick<Models.ActivityEvent, 'sdk' | 'userAgent'>
}) {
  const t = useT()
  const viaMcp = isMcpSdkActivity(event)
  const viaCli = !viaMcp && isCliSdkActivity(event)
  if (!viaMcp && !viaCli) return null

  const label = viaMcp ? t('Via MCP') : t('Via CLI')
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 whitespace-nowrap rounded bg-slate-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-600 dark:text-slate-400',
      )}
      aria-label={label}
      title={label}
    >
      {viaMcp ? (
        <McpIcon className="h-3.5 w-3.5 shrink-0" />
      ) : (
        <Terminal className="h-3.5 w-3.5 shrink-0" />
      )}
      <span>{label}</span>
    </span>
  )
}
