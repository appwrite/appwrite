'use client'

import { BookOpen } from 'lucide-react'
import { CommandGroup } from '@/components/ui/command'
import { DocsSearchResultItem } from './DocsSearchResultItem'
import {
  DOCS_SEARCH_PAGE_COUNT,
  DOCS_SEARCH_SUGGESTIONS,
  getDocsSearchPopularPages,
} from '@/lib/docs/search'

type DocsSearchIdleProps = {
  onSuggest: (query: string) => void
  onSelect: (slug: string) => void
}

export function DocsSearchIdle({ onSuggest, onSelect }: DocsSearchIdleProps) {
  const popularPages = getDocsSearchPopularPages()

  return (
    <div className="pb-1">
      <div className="flex flex-col items-center px-6 pb-5 pt-7 text-center">
        <div className="relative mb-4">
          <div
            aria-hidden
            className="absolute -inset-3 rounded-full bg-primary/10 blur-2xl"
          />
          <div className="relative flex h-14 w-14 items-center justify-center rounded-2xl border border-border bg-gradient-to-b from-muted/70 to-muted/20 shadow-sm">
            <BookOpen className="h-6 w-6 text-muted-foreground" />
          </div>
        </div>
        <p className="text-[14px] font-semibold text-foreground">
          Search documentation
        </p>
        <p className="mt-1.5 max-w-[300px] text-[12px] leading-relaxed text-muted-foreground">
          Find guides, API references, and tutorials across{' '}
          {DOCS_SEARCH_PAGE_COUNT} pages.
        </p>
      </div>

      <div className="px-4 pb-4">
        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          Try searching for
        </p>
        <div className="flex flex-wrap gap-1.5">
          {DOCS_SEARCH_SUGGESTIONS.map((suggestion) => (
            <button
              key={suggestion}
              type="button"
              onClick={() => onSuggest(suggestion)}
              className="rounded-full border border-border bg-muted/20 px-2.5 py-1 text-[12px] text-foreground transition-colors hover:border-border/80 hover:bg-accent"
            >
              {suggestion}
            </button>
          ))}
        </div>
      </div>

      {popularPages.length > 0 ? (
        <CommandGroup heading="Popular pages">
          {popularPages.map((entry) => (
            <DocsSearchResultItem
              key={entry.slug || 'docs-home'}
              entry={entry}
              onSelect={onSelect}
              className="items-start gap-3 py-2.5"
            />
          ))}
        </CommandGroup>
      ) : null}
    </div>
  )
}
