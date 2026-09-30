'use client'

import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type { ChangelogTag } from '@/lib/changelog/types'
import { cn } from '@/lib/utils'

type ChangelogFiltersProps = {
  selectedTag: ChangelogTag | null
  onTagSelect: (tag: ChangelogTag) => void
  onClearFilters: () => void
}

const TAG_LABELS: Record<ChangelogTag, string> = {
  // Products
  auth: 'Auth',
  databases: 'Databases',
  storage: 'Storage',
  functions: 'Functions',
  messaging: 'Messaging',
  sites: 'Sites',
  domains: 'Domains',
  realtime: 'Realtime',
  firewall: 'Firewall',
  // Developer Tools
  console: 'Console',
  mcp: 'MCP',
  cli: 'CLI',
  sdk: 'SDKs',
  api: 'API',
  // More
  performance: 'Performance',
  security: 'Security',
  infrastructure: 'Infrastructure',
  integrations: 'Integrations',
  programs: 'Programs',
}

const FILTER_GROUPS: {
  title: string
  tags: ChangelogTag[]
}[] = [
  {
    title: 'Products',
    tags: ['auth', 'databases', 'storage', 'functions', 'messaging', 'sites', 'domains', 'realtime', 'firewall'],
  },
  {
    title: 'Tools',
    tags: ['console', 'mcp', 'integrations', 'cli', 'sdk', 'api'],
  },
  {
    title: 'More',
    tags: ['performance', 'security', 'infrastructure', 'programs'],
  },
]

export { TAG_LABELS }

export function ChangelogFilters({
  selectedTag,
  onTagSelect,
  onClearFilters,
}: ChangelogFiltersProps) {
  const hasSelectedTag = selectedTag !== null

  return (
    <div className="space-y-8">
      <div className="flex h-8 items-center justify-between gap-3">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Filters
        </h2>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onClearFilters}
          aria-hidden={!hasSelectedTag}
          tabIndex={hasSelectedTag ? 0 : -1}
          disabled={!hasSelectedTag}
          className={cn(
            'h-8 shrink-0 gap-1.5 px-2 text-xs font-normal text-muted-foreground transition-opacity',
            !hasSelectedTag && 'invisible pointer-events-none',
          )}
        >
          <X className="size-3.5" />
          Clear filter
        </Button>
      </div>

      {FILTER_GROUPS.map((group) => {
        return (
          <div key={group.title} className="space-y-3">
            <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              {group.title}
            </h3>
            <div className="flex flex-wrap gap-2">
              {group.tags.map((tag) => {
                const isSelected = selectedTag === tag
                return (
                  <Button
                    key={tag}
                    type="button"
                    variant={isSelected ? 'brandCta' : 'outline'}
                    size="sm"
                    aria-pressed={isSelected}
                    onClick={() => onTagSelect(tag)}
                    className={cn(
                      'h-8 rounded-full px-3 text-xs font-normal',
                      !isSelected &&
                        'border-border bg-transparent text-muted-foreground hover:border-foreground/20 hover:bg-muted/50 hover:text-foreground',
                    )}
                  >
                    {TAG_LABELS[tag]}
                  </Button>
                )
              })}
            </div>
          </div>
        )
      })}
    </div>
  )
}
