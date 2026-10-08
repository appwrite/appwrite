import { useNavigate } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { MarketingHeroSection } from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  formatThreadsTotal,
  getThreads,
} from '@/lib/threads/content'
import {
  buildThreadsRouteSearch,
  toggleThreadsTag,
} from '@/lib/threads/search'
import type { DiscordThread, ThreadsIndexLoaderData } from '@/lib/threads/types'
import { ThreadCard } from './_components/ThreadCard'
import { ThreadTagsFilter } from './_components/ThreadTagsFilter'
import { ThreadsPreFooter } from './_components/ThreadsPreFooter'

type ViewProps = ThreadsIndexLoaderData

export function View({
  threads: loaderThreads,
  hasMore: loaderHasMore,
  nextCursor: loaderNextCursor,
  total,
  q: loaderQuery = '',
  tags: loaderTags,
}: ViewProps) {
  const navigate = useNavigate()
  const [query, setQuery] = useState(loaderQuery)
  const [selectedTags, setSelectedTags] = useState(loaderTags)
  const [extraThreads, setExtraThreads] = useState<DiscordThread[]>([])
  const [hasMore, setHasMore] = useState(loaderHasMore)
  const [nextCursor, setNextCursor] = useState(loaderNextCursor)
  const [loadingMore, setLoadingMore] = useState(false)

  useEffect(() => {
    setQuery(loaderQuery)
    setSelectedTags(loaderTags)
    setExtraThreads([])
    setHasMore(loaderHasMore)
    setNextCursor(loaderNextCursor)
  }, [loaderQuery, loaderTags, loaderHasMore, loaderNextCursor])

  const threads = [...loaderThreads, ...extraThreads]

  const applyFilters = useCallback(
    (nextQuery: string, nextTags: string[]) => {
      navigate({
        to: '/threads',
        search: () => buildThreadsRouteSearch({ q: nextQuery, tags: nextTags }),
        replace: true,
      })
    },
    [navigate],
  )

  const handleSearch = useCallback(
    (nextQuery: string = query, nextTags: string[] = selectedTags) => {
      applyFilters(nextQuery, nextTags)
    },
    [query, selectedTags, applyFilters],
  )

  const handleToggleTag = useCallback(
    (tag: string) => {
      const nextTags = toggleThreadsTag(selectedTags, tag)
      setSelectedTags(nextTags)
      handleSearch(query, nextTags)
    },
    [selectedTags, query, handleSearch],
  )

  const handleLoadMore = useCallback(async () => {
    if (!hasMore || loadingMore || !nextCursor) return

    setLoadingMore(true)
    try {
      const result = await getThreads({
        q: loaderQuery || undefined,
        tags: loaderTags,
        allTags: true,
        cursor: nextCursor,
      })
      setExtraThreads((current) => [...current, ...result.threads])
      setHasMore(result.hasMore)
      setNextCursor(result.nextCursor)
    } finally {
      setLoadingMore(false)
    }
  }, [hasMore, loadingMore, nextCursor, loaderQuery, loaderTags])

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        title="Threads"
        description="Community support discussions from the Appwrite Discord. Search threads, browse topics, and find answers from developers."
        align="left"
      />

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <ThreadTagsFilter
              selectedTags={selectedTags}
              onToggleTag={handleToggleTag}
            />

            <div className="relative w-full max-w-[350px] lg:ms-auto">
              <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    handleSearch(event.currentTarget.value, selectedTags)
                  }
                }}
                placeholder="Search for threads..."
                className="ps-9"
                aria-label="Search for threads..."
              />
            </div>
          </div>

          {threads.length > 0 ? (
            <p className="mt-6 text-[13px] text-muted-foreground" aria-live="polite">
              Found {formatThreadsTotal(total)} results.
            </p>
          ) : null}

          <div className="mt-4 flex flex-col gap-4">
            {threads.length > 0 ? (
              threads.map((thread) => (
                <ThreadCard key={thread.$id} thread={thread} query={loaderQuery} />
              ))
            ) : (
              <div className="rounded-xl border border-border bg-card/50 px-6 py-12 text-center">
                <p className="text-[15px] font-medium text-foreground">
                  No support threads found
                </p>
                <p className="mt-2 text-[13px] text-muted-foreground">
                  Try adjusting your search or clearing filters.
                </p>
                <Button
                  type="button"
                  variant="outline"
                  className="mt-6"
                  onClick={() => {
                    setQuery('')
                    setSelectedTags([])
                    handleSearch('', [])
                  }}
                >
                  Clear search
                </Button>
              </div>
            )}
          </div>

          {hasMore ? (
            <div className="mt-6 flex justify-center">
              <Button
                type="button"
                variant="outline"
                disabled={loadingMore}
                onClick={() => void handleLoadMore()}
              >
                Load more
              </Button>
            </div>
          ) : null}
        </div>
      </section>

      <ThreadsPreFooter />
    </div>
  )
}
