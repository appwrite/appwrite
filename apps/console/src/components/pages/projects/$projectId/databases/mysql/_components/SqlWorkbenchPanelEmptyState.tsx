import { EmptyState } from '@/components/global/shared/EmptyState'
import { Play, Table2 } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

type SqlWorkbenchPanelEmptyStateProps = {
  variant: 'results' | 'query-no-rows'
}

export function SqlWorkbenchPanelEmptyState({
  variant,
}: SqlWorkbenchPanelEmptyStateProps) {
  const t = useT()
  const emptyState =
    variant === 'query-no-rows' ? (
      <EmptyState
        variant="centered"
        icon={Table2}
        iconSize="md"
        title={t('No results for query')}
        description={t('The query completed successfully but did not return any data.')}
        isEmpty
        className="w-full"
      />
    ) : (
      <EmptyState
        variant="centered"
        icon={Play}
        iconClassName="fill-current"
        iconSize="md"
        title={t('No query results yet')}
        description={t('Write SQL in the editor above and run your query. Results will appear in this panel.')}
        isEmpty
        className="w-full"
      />
    )

  return (
    <div className="flex min-h-0 flex-1 w-full items-center justify-center px-4 py-8">
      <div className="w-full max-w-sm">{emptyState}</div>
    </div>
  )
}
