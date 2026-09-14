'use client'

import { FileText } from 'lucide-react'
import { CommandItem } from '@/components/ui/command'
import { formatDate } from '@/lib/date-utils'
import { getPostCategoryLabel } from '@/lib/blog/content'
import type { BlogPostMeta } from '@/lib/blog/types'

type BlogSearchResultItemProps = {
  post: BlogPostMeta
  onSelect: (slug: string) => void
  className?: string
}

export function BlogSearchResultItem({
  post,
  onSelect,
  className,
}: BlogSearchResultItemProps) {
  const categoryLabel = getPostCategoryLabel(post)

  return (
    <CommandItem
      value={`${post.slug} ${post.title}`}
      onSelect={() => onSelect(post.slug)}
      onMouseDown={(event) => event.preventDefault()}
      className={
        className ??
        'flex cursor-pointer flex-col items-start gap-1 rounded-md px-3 py-2.5 data-[selected=true]:bg-accent'
      }
    >
      <div className="flex w-full min-w-0 items-start gap-3">
        <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
        <div className="min-w-0 flex-1">
          <p className="line-clamp-2 text-[13px] font-medium text-foreground">
            {post.title}
          </p>
          <p className="line-clamp-1 text-[11px] text-muted-foreground">
            {formatDate(post.date)}
            {post.timeToRead > 0 ? ` · ${post.timeToRead} min read` : ''}
            {categoryLabel ? ` · ${categoryLabel}` : ''}
          </p>
        </div>
      </div>
    </CommandItem>
  )
}
