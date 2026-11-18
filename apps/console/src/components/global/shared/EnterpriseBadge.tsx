import { cn } from '@/lib/utils'
import { getPlanBadgeColor } from '@/lib/utils/plan-badge'

/**
 * Plan badge for Enterprise, in the purple custom-plan colors. Leads the
 * in-console notes that point at Enterprise.
 */
export function EnterpriseBadge({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        'me-1.5 inline-flex shrink-0 rounded px-1.5 py-0.5 align-[1px] text-[10px] font-medium leading-none',
        getPlanBadgeColor('custom'),
        className,
      )}
    >
      Enterprise
    </span>
  )
}
