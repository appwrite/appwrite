import { assetUrl } from '@/lib/asset-url'
import type { LaunchEvent } from '@/lib/init/types'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ChevronRight } from 'lucide-react'
import { useInitHref } from '@/lib/init/use-init-href'
import { useT } from '@/lib/i18n/translate'

type LiveBannerBarProps = {
  liveBanner: NonNullable<LaunchEvent['liveBanner']>
}

export function LiveBannerBar({ liveBanner }: LiveBannerBarProps) {
  const t = useT()
  const resolvedHref = useInitHref(liveBanner.href)
  const isStartingSoon = liveBanner.mode === 'startingSoon'

  return (
    <div className="shrink-0 border-b border-border bg-background">
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
        <div className="flex min-w-0 items-center gap-2">
          <Badge
            variant={isStartingSoon ? 'warning' : 'error'}
            className="text-[10px] shrink-0"
          >
            {isStartingSoon ? t('Starting soon') : t('Live now')}
          </Badge>
          <span className="truncate text-[13px] font-medium text-foreground">
            {t(liveBanner.title)}
          </span>
        </div>
        {resolvedHref ? (
          <Button variant="outline" size="sm" className="h-7 shrink-0 text-[12px]" asChild>
            <a
              href={assetUrl(resolvedHref.href)}
              {...(resolvedHref.external
                ? { target: '_blank', rel: 'noopener noreferrer' }
                : {})}
            >
              {t('Watch')}
              <ChevronRight className="size-3.5" />
            </a>
          </Button>
        ) : null}
      </div>
    </div>
  )
}
