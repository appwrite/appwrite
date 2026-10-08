'use client'

import { useEffect, useState } from 'react'
import { FilterX } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { getChangelogEntriesPage } from '@/lib/changelog/content'
import type { ChangelogEntry, ChangelogTag } from '@/lib/changelog/types'
import { ChangelogEntryCard } from './ChangelogEntryCard'
import { TAG_LABELS } from './ChangelogFilters'

type ChangelogTimelineProps = {
  initialEntries: ChangelogEntry[]
  initialNextPage: number | null
  selectedTag?: ChangelogTag | null
  onClearFilter?: () => void
}

export function ChangelogTimeline({
  initialEntries,
  initialNextPage,
  selectedTag = null,
  onClearFilter,
}: ChangelogTimelineProps) {
  const [entries, setEntries] = useState(initialEntries)
  const [nextPage, setNextPage] = useState(initialNextPage)

  useEffect(() => {
    const { entries: pageEntries, nextPage: pageNext } =
      getChangelogEntriesPage(1, selectedTag)
    setEntries(pageEntries)
    setNextPage(pageNext)
  }, [selectedTag])

  function loadMore() {
    if (!nextPage) return

    const { entries: nextEntries, nextPage: followingPage } =
      getChangelogEntriesPage(nextPage, selectedTag)

    setEntries(nextEntries)
    setNextPage(followingPage)
  }

  return (
    <>
      {entries.length > 0 ? (
        <ol className="relative grid min-w-0 gap-20 border-s border-border ps-8 sm:ps-0 sm:[&>li]:ps-8">
          {entries.map((entry) => (
            <li key={entry.slug} className="relative min-w-0">
              <span
                className="absolute start-0 top-1 hidden size-2.5 -translate-x-1/2 rounded-full border-2 border-border bg-background sm:block"
                aria-hidden
              />
              <ChangelogEntryCard entry={entry} />
            </li>
          ))}
        </ol>
      ) : selectedTag ? (
        <EmptyState
          variant="card"
          icon={FilterX}
          iconSize="lg"
          hasFilters
          title={`No changelog entries for ${TAG_LABELS[selectedTag]}`}
          description="Try another filter, or clear your selection to browse all updates."
          className="py-14"
          action={
            onClearFilter ? (
              <Button type="button" variant="outline" onClick={onClearFilter}>
                Clear filter
              </Button>
            ) : null
          }
        />
      ) : null}

      {nextPage && entries.length > 0 ? (
        <div className="mt-20 flex justify-center">
          <Button variant="outline" className="min-w-44" onClick={loadMore}>
            Load more
          </Button>
        </div>
      ) : null}
    </>
  )
}
