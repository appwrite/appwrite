'use client'

import { Search } from 'lucide-react'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { useCommandCenter } from '@/hooks/use-command-center'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { openCommandCenterViaBridge } from '@/lib/command-center/opener-bridge'

export function BlogSearchSection() {
  const { openBlogSearch } = useCommandCenter()
  const { modKey: searchModKey } = usePlatform()

  const handleOpenSearch = () => {
    if (!openCommandCenterViaBridge('blog')) {
      openBlogSearch()
    }
  }

  return (
    <section className="border-b border-border py-8 sm:py-10">
      <div className="mx-auto flex max-w-7xl justify-center px-4 sm:px-6">
        <button
          type="button"
          {...analyticsAttrs('command-center')}
          onClick={handleOpenSearch}
          className="flex h-11 w-full max-w-[25rem] cursor-pointer items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent"
        >
          <Search className="h-4 w-4 shrink-0" />
          <span className="min-w-0 flex-1 truncate text-start">
            Search articles...
          </span>
          {searchModKey ? (
            <span className="ms-auto flex shrink-0 items-center gap-1">
              <span dir="ltr" className="flex items-center gap-1">
                <kbd className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85">
                  {searchModKey}
                </kbd>
                <kbd className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85">
                  K
                </kbd>
              </span>
            </span>
          ) : null}
        </button>
      </div>
    </section>
  )
}
