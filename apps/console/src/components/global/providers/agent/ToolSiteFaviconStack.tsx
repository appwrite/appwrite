import { useState } from 'react'
import { Globe } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { sdk } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import type { ToolSite } from '@/lib/assistant/tool-sites'

const VISIBLE_COLLAPSED = 5

function FaviconImage({ site }: { site: ToolSite }) {
  const [failed, setFailed] = useState(false)
  const src = sdk.forConsole.avatars.getFavicon({ url: site.url })

  return (
    <span
      className={cn(
        'relative flex h-5 w-5 items-center justify-center overflow-hidden rounded-full',
        'border border-border bg-muted text-muted-foreground shadow-sm',
      )}
    >
      {failed ? (
        <Globe className="h-3 w-3" aria-hidden />
      ) : (
        <img
          src={src}
          alt=""
          width={20}
          height={20}
          className="h-full w-full object-cover"
          loading="lazy"
          decoding="async"
          onError={() => setFailed(true)}
        />
      )}
    </span>
  )
}

export function ToolSiteFaviconStack({
  sites,
  className,
}: {
  sites: ToolSite[]
  className?: string
}) {
  if (sites.length === 0) return null

  const overflow = Math.max(0, sites.length - VISIBLE_COLLAPSED)
  const visible = sites.slice(0, VISIBLE_COLLAPSED)

  return (
    <TooltipProvider delayDuration={200}>
      <div className={cn('group/stack flex shrink-0 items-center', className)}>
        <div className="flex items-center">
          {visible.map((site, index) => (
            <Tooltip key={site.url}>
              <TooltipTrigger asChild>
                <a
                  href={site.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={cn(
                    'relative block transition-all duration-200 ease-out',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                    index > 0 &&
                      '-ms-2 group-hover/stack:ms-1 group-focus-within/stack:ms-1',
                  )}
                  style={{ zIndex: visible.length - index }}
                  aria-label={site.hostname}
                >
                  <FaviconImage site={site} />
                </a>
              </TooltipTrigger>
              <TooltipContent side="top" className="max-w-[220px] text-[11px]">
                <span className="font-medium">{site.hostname}</span>
                {site.label && site.label !== site.url ? (
                  <span className="mt-0.5 block truncate text-muted-foreground">
                    {site.label}
                  </span>
                ) : null}
              </TooltipContent>
            </Tooltip>
          ))}
          {overflow > 0 ? (
            <span
              className={cn(
                'relative z-0 flex h-5 min-w-5 items-center justify-center rounded-full',
                'border border-border bg-muted px-1 text-[9px] font-medium text-muted-foreground',
                '-ms-2 transition-all duration-200 group-hover/stack:ms-1 group-focus-within/stack:ms-1',
              )}
            >
              +{overflow}
            </span>
          ) : null}
        </div>
      </div>
    </TooltipProvider>
  )
}
