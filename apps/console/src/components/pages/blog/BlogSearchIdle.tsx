'use client'

import { Newspaper } from 'lucide-react'
import { CommandGroup } from '@/components/ui/command'
import { BlogSearchResultItem } from './BlogSearchResultItem'
import {
  BLOG_SEARCH_POST_COUNT,
  BLOG_SEARCH_SUGGESTIONS,
  getBlogSearchPopularPosts,
} from '@/lib/blog/search-client'

type BlogSearchIdleProps = {
  onSuggest: (query: string) => void
  onSelect: (slug: string) => void
}

export function BlogSearchIdle({ onSuggest, onSelect }: BlogSearchIdleProps) {
  const popularPosts = getBlogSearchPopularPosts()

  return (
    <div className="pb-1">
      <div className="flex flex-col items-center px-6 pb-5 pt-7 text-center">
        <div className="relative mb-4">
          <div
            aria-hidden
            className="absolute -inset-3 rounded-full bg-primary/10 blur-2xl"
          />
          <div className="relative flex h-14 w-14 items-center justify-center rounded-2xl border border-border bg-gradient-to-b from-muted/70 to-muted/20 shadow-sm">
            <Newspaper className="h-6 w-6 text-muted-foreground" />
          </div>
        </div>
        <p className="text-[14px] font-semibold text-foreground">
          Search blog posts
        </p>
        <p className="mt-1.5 max-w-[300px] text-[12px] leading-relaxed text-muted-foreground">
          Find articles by title, topic, or description across{' '}
          {BLOG_SEARCH_POST_COUNT} posts.
        </p>
      </div>

      <div className="px-4 pb-4">
        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          Try searching for
        </p>
        <div className="flex flex-wrap gap-1.5">
          {BLOG_SEARCH_SUGGESTIONS.map((suggestion) => (
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

      {popularPosts.length > 0 ? (
        <CommandGroup heading="Recent articles">
          {popularPosts.map((post) => (
            <BlogSearchResultItem
              key={post.slug}
              post={post}
              onSelect={onSelect}
              className="items-start gap-3 py-2.5"
            />
          ))}
        </CommandGroup>
      ) : null}
    </div>
  )
}
