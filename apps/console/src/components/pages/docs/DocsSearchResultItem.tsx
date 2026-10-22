'use client'

import { FileText } from 'lucide-react'
import { CommandItem } from '@/components/ui/command'
import { formatDocsBreadcrumbs } from '@/lib/docs/breadcrumbs'
import type { DocsSearchEntry } from '@/lib/docs/search'

type DocsSearchResultItemProps = {
  entry: DocsSearchEntry
  onSelect: (slug: string) => void
  className?: string
}

export function DocsSearchResultItem({
  entry,
  onSelect,
  className,
}: DocsSearchResultItemProps) {
  const breadcrumbLabel = formatDocsBreadcrumbs(entry.breadcrumbs)

  return (
    <CommandItem
      value={`${entry.slug} ${entry.title}`}
      onSelect={() => onSelect(entry.slug)}
      onMouseDown={(event) => event.preventDefault()}
      className={className ?? 'items-start gap-3 py-3'}
    >
      <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        {breadcrumbLabel ? (
          <p className="truncate text-[11px] text-muted-foreground">
            {breadcrumbLabel}
          </p>
        ) : null}
        <p className="truncate text-[13px] font-medium text-foreground">
          {entry.title}
        </p>
        <p className="mt-1 line-clamp-2 text-[12px] text-muted-foreground">
          {entry.description}
        </p>
      </div>
    </CommandItem>
  )
}
