import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { Check, ChevronsUpDown, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { videosQueryOptions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatVideoDuration } from '@/lib/utils/video-format'
import { VideoThumb } from './VideoThumb'

const SELECTOR_LIMIT = 15

/** Searchable dropdown to jump between videos without leaving the detail view. */
export function VideoSelector({
  projectId,
  video,
  onSelect,
  className,
}: {
  projectId: string
  video: Models.Video
  onSelect: (videoId: string) => void
  className?: string
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebouncedSearch(search.trim()),
      300,
    )
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data, isFetching } = useQuery({
    ...videosQueryOptions(
      projectId,
      0,
      SELECTOR_LIMIT,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })
  const videos = data?.videos ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          aria-label={t('Switch video')}
          className={cn(
            'h-9 w-full min-w-0 justify-between gap-1.5 px-2.5 text-[13px] font-normal',
            className,
          )}
        >
          <span
            className="min-w-0 truncate font-medium text-foreground"
            title={video.name}
          >
            {video.name || t('Untitled video')}
          </span>
          <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className="w-[var(--radix-popover-trigger-width)] min-w-[280px] max-w-[340px] p-0"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder={t('Search videos...')}
              value={search}
              onValueChange={setSearch}
              className={cn('h-9', isFetching && 'pe-8')}
            />
            <div
              aria-hidden
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList className="max-h-[300px] min-h-[180px]">
            {videos.length === 0 ? (
              <CommandEmpty>
                {isFetching ? '' : t('No videos found')}
              </CommandEmpty>
            ) : null}
            <CommandGroup>
              {videos.map((item) => (
                <CommandItem
                  key={item.$id}
                  value={item.$id}
                  onSelect={() => {
                    setOpen(false)
                    if (item.$id !== video.$id) onSelect(item.$id)
                  }}
                  className="gap-2.5 py-1.5"
                >
                  <VideoThumb
                    projectId={projectId}
                    video={item}
                    width={120}
                    uniformSize
                    fetchTimeline={false}
                    className="w-12 shrink-0 rounded border border-border/60 [&_svg]:h-3 [&_svg]:w-3"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] text-foreground">
                      {item.name || t('Untitled video')}
                    </span>
                    {item.duration > 0 ? (
                      <span className="block font-mono text-[11px] tabular-nums text-muted-foreground">
                        {formatVideoDuration(item.duration)}
                      </span>
                    ) : null}
                  </span>
                  <Check
                    className={cn(
                      'h-3.5 w-3.5 shrink-0',
                      item.$id === video.$id ? 'opacity-100' : 'opacity-0',
                    )}
                  />
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
