'use client'

import { useCallback, useEffect, useMemo, useState, type RefObject } from 'react'
import { X } from 'lucide-react'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandList,
} from '@/components/ui/command'
import { cn } from '@/lib/utils'
import { CommandCenterListFooter } from '@/components/global/shared/CommandCenterListFooter'
import { DocsSearchIdle } from './DocsSearchIdle'
import { DocsSearchResultItem } from './DocsSearchResultItem'
import { searchDocs } from '@/lib/docs/search'

type DocsSearchViewProps = {
  isMobile: boolean
  inputRef?: RefObject<HTMLInputElement | null>
  onBack: () => void
  onClose: () => void
  onKeyDown: (e: React.KeyboardEvent) => void
  onSelect: (slug: string) => void
  onOpenShortcuts?: () => void
}

export function DocsSearchView({
  isMobile,
  inputRef,
  onBack,
  onClose,
  onKeyDown,
  onSelect,
  onOpenShortcuts,
}: DocsSearchViewProps) {
  const [query, setQuery] = useState('')
  const results = useMemo(() => searchDocs(query), [query])
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
          placeholder="Search documentation..."
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
          <DocsSearchIdle onSuggest={setQuery} onSelect={onSelect} />
        ) : results.length > 0 ? (
          <CommandGroup heading="Pages">
            {results.map((result) => (
              <DocsSearchResultItem
                key={result.slug || 'docs-home'}
                entry={result}
                onSelect={onSelect}
              />
            ))}
          </CommandGroup>
        ) : (
          <CommandEmpty className="py-10 text-[13px] text-muted-foreground">
            No documentation pages found. Try a different search term.
          </CommandEmpty>
        )}
      </CommandList>
      {!isMobile ? (
        <CommandCenterListFooter onOpenShortcuts={onOpenShortcuts} />
      ) : null}
    </Command>
  )
}
