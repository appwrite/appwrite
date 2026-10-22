import { cn } from '@/lib/utils'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import {
  mysqlQuerySelectionKey,
  queryPreviewLabel,
  useMysqlSidebar,
} from './MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

export function MysqlHistorySidebarPanel() {
  const t = useT()
  const {
    recentQueries,
    selectedQueryKey,
    selectRecentQuery,
    clearRecentQueries,
  } = useMysqlSidebar()

  if (recentQueries.length === 0) {
    return (
      <p className="px-2 py-3 text-[12px] text-muted-foreground">
        {t('No query history yet. Run a query in the editor to see it here.')}
      </p>
    )
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="min-h-0 flex-1 space-y-0.5 overflow-y-auto">
        {recentQueries.map((query) => (
          <button
            key={query.id}
            type="button"
            onClick={() => selectRecentQuery(query)}
            className={cn(
              'flex w-full cursor-pointer flex-col gap-0.5 rounded-md px-3 py-2 text-start transition-colors',
              selectedQueryKey === mysqlQuerySelectionKey('recent', query.id)
                ? 'bg-background text-foreground shadow-sm'
                : 'text-muted-foreground hover:bg-background/70 hover:text-foreground',
            )}
          >
            <span className="truncate font-mono text-[12px] font-medium text-foreground">
              {queryPreviewLabel(query.sql)}
            </span>
            <DateTooltip
              date={new Date(query.ranAt).toISOString()}
              className="text-[11px] text-muted-foreground"
              disableTooltip
              live
            />
          </button>
        ))}
      </div>
      <div className="shrink-0 border-t border-border pt-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 w-full text-[12px] text-muted-foreground hover:text-foreground"
          onClick={clearRecentQueries}
        >
          {t('Clear history')}
        </Button>
      </div>
    </div>
  )
}
