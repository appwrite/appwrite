'use client'

import { useCallback, useEffect, useMemo, useState, type RefObject } from 'react'
import { X } from 'lucide-react'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandList,
  CommandItem,
} from '@/components/ui/command'
import { cn } from '@/lib/utils'
import { CommandCenterListFooter } from '@/components/global/shared/CommandCenterListFooter'
import { searchBlogPosts } from '@/lib/blog/search-client'
import { formatDate } from '@/lib/date-utils'
import { useNavigate } from '@tanstack/react-router'

type BlogSearchViewProps = {
  isMobile: boolean
  inputRef?: RefObject<HTMLInputElement | null>
  onBack: () => void
  onClose: () => void
  onKeyDown: (e: React.KeyboardEvent) => void
  onOpenShortcuts?: () => void
}

export function BlogSearchView({
  isMobile,
  inputRef,
  onBack,
  onClose,
  onKeyDown,
  onOpenShortcuts,
}: BlogSearchViewProps) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const results = useMemo(() => searchBlogPosts(query), [query])
  const hasQuery = query.trim().length > 0

  useEffect(() => {
    const id = window.requestAnimationFrame(() => {
      inputRef?.current?.focus()
    })
    return () => window.cancelAnimationFrame(id)
  }, [inputRef])

  const handleViewKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Escape' && query.trim()) {
        e.preventDefault()
        setQuery('')
        return
      }
      onKeyDown(e)
    },
    [onKeyDown, query],
  )

  const handleSelect = useCallback(
    (slug: string) => {
      onClose()
      navigate({
        to: '/blog/post/$slug',
        params: { slug },
      })
    },
    [navigate, onClose],
  )

  return (
    <Command
      className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
      onKeyDown={handleViewKeyDown}
      shouldFilter={false}
    >
      <div className="flex shrink-0 items-center border-b border-border [&_[data-slot=command-input-wrapper]]:h-14 [&_[data-slot=command-input-wrapper]]:border-transparent [&_[data-slot=command-input-wrapper]]:flex-1">
        <button
          type="button"
          onClick={onBack}
          className="ms-3 flex h-6 shrink-0 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
        >
          ← Back
        </button>
        <CommandInput
          ref={inputRef}
          placeholder="Search blog posts..."
          value={query}
          onValueChange={setQuery}
          autoFocus
          className="h-14 border-0 text-[14px] text-foreground placeholder:text-muted-foreground"
        />
        {isMobile ? (
          <button
            type="button"
            onClick={onClose}
            className="me-3 flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <X className="h-5 w-5" />
          </button>
        ) : null}
      </div>
      <CommandList
        className={cn(
          'p-2',
          isMobile ? 'max-h-none flex-1 min-h-0' : 'max-h-[min(420px,58dvh)]',
        )}
      >
        {!hasQuery ? (
          <div className="py-10 text-center">
            <p className="text-[13px] text-muted-foreground">
              Search for blog posts by title, topic, or description
            </p>
          </div>
        ) : results.length > 0 ? (
          <CommandGroup heading="Articles">
            {results.map((result) => (
              <CommandItem
                key={result.slug}
                value={result.slug}
                onSelect={() => handleSelect(result.slug)}
                className="flex cursor-pointer flex-col items-start gap-1 rounded-md px-3 py-2.5 data-[selected=true]:bg-accent"
              >
                <p className="line-clamp-2 text-[13px] font-medium text-foreground">
                  {result.title}
                </p>
                <p className="line-clamp-1 text-[11px] text-muted-foreground">
                  {formatDate(result.date)}
                  {result.timeToRead > 0 ? ` · ${result.timeToRead} min read` : ''}
                  {result.category ? ` · ${result.category}` : ''}
                </p>
              </CommandItem>
            ))}
          </CommandGroup>
        ) : (
          <CommandEmpty className="py-10 text-[13px] text-muted-foreground">
            No blog posts found. Try a different search term.
          </CommandEmpty>
        )}
      </CommandList>
      {!isMobile ? (
        <CommandCenterListFooter onOpenShortcuts={onOpenShortcuts} />
      ) : null}
    </Command>
  )
}
