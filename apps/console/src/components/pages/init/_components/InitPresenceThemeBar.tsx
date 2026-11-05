import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { resolveInitPresenceTheme, type InitPresenceTheme } from '@/lib/init/init-presence-theme'
import { useInitPresence } from '@/lib/init/init-presence-context'
import { cn } from '@/lib/utils'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from 'next-themes'

export function InitPresenceThemeBar({
  light,
  dark,
  collapsed = false,
  isMobile = false,
  reserveSpace = false,
}: {
  light: number
  dark: number
  collapsed?: boolean
  isMobile?: boolean
  /** Reserve footer height while presence theme counts are not ready yet. */
  reserveSpace?: boolean
}) {
  const { resolvedTheme, setTheme } = useTheme()
  const { syncPresenceTheme } = useInitPresence()
  const total = light + dark
  if (collapsed && !isMobile) return null
  if (total === 0 && !reserveSpace) return null

  const shellClassName = cn('shrink-0 px-3 py-3', isMobile && 'px-4')

  if (total === 0 && reserveSpace) {
    return (
      <div className={shellClassName} aria-hidden>
        <div className="pointer-events-none invisible rounded-lg border border-border bg-card/50 px-1.5 py-1.5">
          <div className="flex h-6 items-center gap-1">
            <span className="size-6 shrink-0" />
            <span className="h-2 min-w-0 flex-1 rounded-full" />
            <span className="size-6 shrink-0" />
          </div>
        </div>
      </div>
    )
  }

  const lightPercent = (light / total) * 100
  const darkPercent = 100 - lightPercent
  const scoreLabel = `${Math.round(lightPercent)}% light · ${Math.round(darkPercent)}% dark (${light} · ${dark})`
  const activeTheme = resolveInitPresenceTheme(resolvedTheme)

  const handleThemeChange = (theme: InitPresenceTheme) => {
    setTheme(theme)
    void syncPresenceTheme(theme)
  }

  return (
    <div className={shellClassName}>
      <div className="rounded-lg border border-border bg-card/50 px-1.5 py-1.5">
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              'size-6 shrink-0 rounded-md border border-border text-muted-foreground',
              activeTheme === 'light' && 'border-border bg-accent text-foreground',
            )}
            aria-label="Light theme"
            aria-pressed={activeTheme === 'light'}
            onClick={() => handleThemeChange('light')}
          >
            <Sun className="size-3" aria-hidden />
          </Button>

          <Tooltip delayDuration={200}>
            <TooltipTrigger asChild>
              <div
                className="flex h-2 min-w-0 flex-1 cursor-default overflow-hidden rounded-full border border-border/80 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                role="img"
                aria-label={scoreLabel}
                tabIndex={0}
              >
                <div
                  className="h-full bg-[#f4f4f5] transition-[width] duration-300 ease-out"
                  style={{ width: `${lightPercent}%` }}
                />
                <div className="h-full flex-1 bg-[#27272a] transition-[width] duration-300 ease-out" />
              </div>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-[12px]">
              {scoreLabel}
            </TooltipContent>
          </Tooltip>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              'size-6 shrink-0 rounded-md border border-border text-muted-foreground',
              activeTheme === 'dark' && 'border-border bg-accent text-foreground',
            )}
            aria-label="Dark theme"
            aria-pressed={activeTheme === 'dark'}
            onClick={() => handleThemeChange('dark')}
          >
            <Moon className="size-3" aria-hidden />
          </Button>
        </div>
      </div>
    </div>
  )
}
