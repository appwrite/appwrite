import type { Models } from '@appwrite.io/console'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

function getSiteFramework(site: Models.Site) {
  return (
    (site as unknown as { buildFramework?: string }).buildFramework ||
    (site as unknown as { buildFrameworkId?: string }).buildFrameworkId ||
    (site as unknown as { framework?: string }).framework
  )
}

interface SitesListPreviewCellProps {
  site: Models.Site
  screenshotUrl: string | null
  screenshotKey: string
  isLoaded: boolean
  onLoad: () => void
}

export function SitesListPreviewCell({
  site,
  screenshotUrl,
  screenshotKey,
  isLoaded,
  onLoad,
}: SitesListPreviewCellProps) {
  const t = useT()
  const preview = (
    <div className="relative h-[52px] w-[92px] shrink-0 overflow-hidden rounded-lg border border-border bg-muted">
      {screenshotUrl ? (
        <>
          {!isLoaded ? (
            <div
              className="absolute inset-0 animate-pulse bg-muted"
              aria-hidden
            />
          ) : null}
          <img
            key={screenshotKey}
            src={screenshotUrl}
            alt={`${site.name || t('Site')} ${t('preview')}`}
            onLoad={onLoad}
            className={cn(
              'h-full w-full object-cover object-top transition-opacity duration-300',
              isLoaded ? 'opacity-100' : 'opacity-0',
            )}
          />
        </>
      ) : (
        <div className="flex h-full w-full items-center justify-center bg-muted/80">
          <FrameworkIcon
            framework={getSiteFramework(site)}
            size="sm"
            className="text-muted-foreground/70"
          />
        </div>
      )}
    </div>
  )

  if (!screenshotUrl) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>{preview}</TooltipTrigger>
          <TooltipContent side="right" className="text-[12px]">
            {t('Preview not available')}
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return preview
}
