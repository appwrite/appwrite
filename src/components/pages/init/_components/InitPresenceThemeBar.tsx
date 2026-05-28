import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { Moon, Sun } from 'lucide-react'

export function InitPresenceThemeBar({
  light,
  dark,
  collapsed = false,
  isMobile = false,
}: {
  light: number
  dark: number
  collapsed?: boolean
  isMobile?: boolean
}) {
  const total = light + dark
  if (collapsed && !isMobile) return null
  if (total === 0) return null

  const lightPercent = (light / total) * 100
  const darkPercent = 100 - lightPercent
  const scoreLabel = `${Math.round(lightPercent)}% light · ${Math.round(darkPercent)}% dark (${light} · ${dark})`

  return (
    <div
      className={cn(
        'shrink-0 px-3 py-3',
        isMobile && 'px-4',
      )}
    >
      <Tooltip delayDuration={200}>
        <TooltipTrigger asChild>
          <div
            className={cn(
              'cursor-default rounded-lg border border-border bg-card/50 px-2.5 py-2',
              'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            )}
            role="img"
            aria-label={scoreLabel}
            tabIndex={0}
          >
            <div className="flex items-center gap-2">
              <Sun className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <div className="flex h-2 min-w-0 flex-1 overflow-hidden rounded-full border border-border/80">
                <div
                  className="h-full bg-[#f4f4f5] transition-[width] duration-300 ease-out"
                  style={{ width: `${lightPercent}%` }}
                />
                <div className="h-full flex-1 bg-[#27272a] transition-[width] duration-300 ease-out" />
              </div>
              <Moon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
            </div>
          </div>
        </TooltipTrigger>
        <TooltipContent side="top" className="text-[12px]">
          {scoreLabel}
        </TooltipContent>
      </Tooltip>
    </div>
  )
}
