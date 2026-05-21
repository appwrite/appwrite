import { Fragment, useMemo, type ReactNode } from 'react'
import { matchesSettingsSearch, type SettingsSearchFields } from '@/lib/settings-search'
import { cn } from '@/lib/utils'
import { useSettingsSearch } from './SettingsSearchContext'

export type SettingsCardItem = {
  id: string
  search: SettingsSearchFields
  node: ReactNode
}

export function SettingsCardsList({
  cards,
  className,
  emptyMessage = 'No matching settings',
}: {
  cards: SettingsCardItem[]
  className?: string
  emptyMessage?: string
}) {
  const { query } = useSettingsSearch()

  const visible = useMemo(() => {
    const q = query.trim()
    if (!q) return cards
    return cards.filter((card) => matchesSettingsSearch(q, card.search))
  }, [cards, query])

  if (visible.length === 0 && query.trim()) {
    return (
      <p className="py-8 text-center text-[13px] text-muted-foreground">
        {emptyMessage}
      </p>
    )
  }

  return (
    <div className={cn('space-y-6', className)}>
      {visible.map((card) => (
        <Fragment key={card.id}>{card.node}</Fragment>
      ))}
    </div>
  )
}
