'use client'

import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { getChangelogEntriesPage } from '@/lib/changelog/content'
import type { ChangelogEntry } from '@/lib/changelog/types'
import { ChangelogEntryCard } from './ChangelogEntryCard'

type ChangelogTimelineProps = {
  initialEntries: ChangelogEntry[]
  initialNextPage: number | null
}

export function ChangelogTimeline({
  initialEntries,
  initialNextPage,
}: ChangelogTimelineProps) {
  const [entries, setEntries] = useState(initialEntries)
  const [nextPage, setNextPage] = useState(initialNextPage)

  function loadMore() {
    if (!nextPage) return

    const { entries: nextEntries, nextPage: followingPage } =
      getChangelogEntriesPage(nextPage)

    setEntries(nextEntries)
    setNextPage(followingPage)
  }

  return (
    <>
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

      {nextPage ? (
        <div className="mt-20 flex justify-center">
          <Button variant="outline" className="min-w-44" onClick={loadMore}>
            Load more
          </Button>
        </div>
      ) : null}
    </>
  )
}
