import type { ReviewStatusCounts } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type ReviewStatusTab = keyof ReviewStatusCounts

const TABS: { id: ReviewStatusTab; label: string }[] = [
  { id: 'all', label: 'All' },
  { id: 'inProgress', label: 'In progress' },
  { id: 'completed', label: 'Completed' },
  { id: 'failed', label: 'Failed' },
  { id: 'skipped', label: 'Skipped' },
]

/**
 * The reviews list's status tabs, each with how many reviews it holds within
 * the other filters. A tab is a shortcut for the status filter, so `active`
 * is null while the filters hold a status filter of another shape. Styled
 * like the service header's tabs, with each label lined up over the table's
 * first column.
 */
export function ReviewStatusTabs({
  counts,
  active,
  onChange,
}: {
  counts: ReviewStatusCounts | undefined
  active: ReviewStatusTab | null
  onChange: (tab: ReviewStatusTab) => void
}) {
  const t = useT()
  return (
    <div
      role="tablist"
      aria-label={t('Status')}
      className="flex overflow-x-auto border-b border-border px-1"
    >
      {TABS.map((tab) => {
        const selected = active === tab.id
        const count = counts?.[tab.id]
        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(tab.id)}
            className={cn(
              'relative flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-sm px-3 py-2.5 text-[13px] font-medium transition-colors',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
              selected
                ? 'text-foreground'
                : 'text-muted-foreground hover:text-foreground/80',
            )}
          >
            {t(tab.label)}
            {count !== undefined && (
              <span
                className={cn(
                  'rounded-full px-1.5 py-0.5 text-[10px]',
                  selected
                    ? 'bg-accent text-foreground'
                    : 'bg-muted text-muted-foreground',
                )}
              >
                {count.toLocaleString()}
              </span>
            )}
            {selected && (
              <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
            )}
          </button>
        )
      })}
    </div>
  )
}
