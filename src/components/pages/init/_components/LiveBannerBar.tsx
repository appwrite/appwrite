import type { LaunchEvent } from '@/lib/init/types'
import { isExternalInitHref } from '@/lib/init/links'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ChevronRight } from 'lucide-react'

type LiveBannerBarProps = {
  liveBanner: NonNullable<LaunchEvent['liveBanner']>
}

export function LiveBannerBar({ liveBanner }: LiveBannerBarProps) {
  return (
    <div className="shrink-0 border-b border-border bg-background">
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
        <div className="flex min-w-0 items-center gap-2">
          <Badge variant="error" className="text-[10px] shrink-0">
            Live now
          </Badge>
          <span className="truncate text-[13px] font-medium text-foreground">
            {liveBanner.title}
          </span>
        </div>
        {liveBanner.href ? (
          <Button variant="outline" size="sm" className="h-7 shrink-0 text-[12px]" asChild>
            <a
              href={liveBanner.href}
              {...(isExternalInitHref(liveBanner.href)
                ? { target: '_blank', rel: 'noopener noreferrer' }
                : {})}
            >
              Watch
              <ChevronRight className="size-3.5" />
            </a>
          </Button>
        ) : null}
      </div>
    </div>
  )
}
