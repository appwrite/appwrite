import { useState } from 'react'
import { ChevronLeft, ChevronRight, History, Search } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useKeyboardShortcutsContext } from '@/components/global/providers/KeyboardShortcuts'
import { useNavigationHistorySafe } from '@/components/global/providers/NavigationHistoryProvider'
import { useNavigate } from '@tanstack/react-router'
import { useI18n } from '@/lib/i18n'

/** Width reserved for OS window controls (e.g. traffic lights on macOS) in a native shell */
const OS_CONTROLS_WIDTH = 72

/**
 * App bar shown above the main header when "Show native app bar" debug option is enabled.
 * Intended for future use in a native OS app: reserved space for window controls, back/forward, centered search.
 */
export function NativeAppBar() {
  const { openCommandCenter } = useKeyboardShortcutsContext()
  const navigationHistory = useNavigationHistorySafe()
  const navigate = useNavigate()
  const { catalog } = useI18n()
  const nativeAppBarCopy = catalog.app.nativeAppBar
  const [historyOpen, setHistoryOpen] = useState(false)

  const canGoBack = navigationHistory?.hasInternalHistory() ?? false
  const canGoForward = navigationHistory?.hasForwardHistory() ?? false
  const backStack = navigationHistory?.getBackStack() ?? []
  const hasHistory = backStack.length > 0

  const handleBack = () => {
    const path = navigationHistory?.popHistory()
    if (path) {
      navigationHistory?.skipNextPush()
      navigate({ to: path })
    }
  }

  const handleForward = () => {
    const path = navigationHistory?.popForward()
    if (path) {
      navigationHistory?.skipNextPush()
      navigate({ to: path })
    }
  }

  const handleHistorySelect = (path: string) => {
    const target = navigationHistory?.popUntil(path)
    if (target) {
      navigationHistory?.skipNextPush()
      navigate({ to: target })
      setHistoryOpen(false)
    }
  }

  return (
    <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-3 py-1.5 sm:px-4 @[1000px]:px-6">
      {/* Reserved for OS-level window controls (traffic lights / min-max-close) in native app */}
      <div
        className="flex shrink-0 items-center"
        style={{ width: OS_CONTROLS_WIDTH }}
        aria-hidden
        data-native-app-bar-os-controls
      />

      <TooltipProvider delayDuration={0}>
        <div className="flex items-center gap-0.5">
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={handleBack}
                disabled={!canGoBack}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40 disabled:pointer-events-none"
                aria-label={nativeAppBarCopy.back}
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              <p>{nativeAppBarCopy.back}</p>
            </TooltipContent>
          </Tooltip>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={handleForward}
                disabled={!canGoForward}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40 disabled:pointer-events-none"
                aria-label={nativeAppBarCopy.forward}
              >
                <ChevronRight className="h-4 w-4" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              <p>{nativeAppBarCopy.forward}</p>
            </TooltipContent>
          </Tooltip>
          <Popover open={historyOpen} onOpenChange={setHistoryOpen}>
            <Tooltip>
              <TooltipTrigger asChild>
                <PopoverTrigger asChild>
                  <button
                    type="button"
                    disabled={!hasHistory}
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40 disabled:pointer-events-none"
                    aria-label={nativeAppBarCopy.history}
                  >
                    <History className="h-4 w-4" />
                  </button>
                </PopoverTrigger>
              </TooltipTrigger>
              <TooltipContent>
                <p>{nativeAppBarCopy.history}</p>
              </TooltipContent>
            </Tooltip>
            <PopoverContent align="start" className="w-72 p-0" sideOffset={4}>
              <div className="border-b border-border px-3 py-2">
                <p className="text-[12px] font-semibold text-foreground">
                  {nativeAppBarCopy.recentPages}
                </p>
              </div>
              <div className="max-h-[240px] overflow-y-auto py-1">
                {hasHistory ? (
                  [...backStack].reverse().map((entry) => (
                    <button
                      key={entry.path}
                      type="button"
                      onClick={() => handleHistorySelect(entry.path)}
                      className="flex w-full items-center px-3 py-2 text-start text-[13px] text-foreground transition-colors hover:bg-accent"
                    >
                      <span className="min-w-0 truncate">
                        {entry.title || entry.path || '/'}
                      </span>
                    </button>
                  ))
                ) : (
                  <p className="px-3 py-4 text-[13px] text-muted-foreground">
                    {nativeAppBarCopy.noRecentPages}
                  </p>
                )}
              </div>
            </PopoverContent>
          </Popover>
        </div>
      </TooltipProvider>

      <div className="flex min-w-0 flex-1 justify-center">
        <button
          type="button"
          onClick={openCommandCenter}
          className="flex h-8 w-full max-w-[280px] items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent"
        >
          <Search className="h-4 w-4 shrink-0" />
          <span className="min-w-0 flex-1 truncate text-start">
            {nativeAppBarCopy.searchPlaceholder}
          </span>
          <span className="ms-auto shrink-0">
            <kbd
              dir="ltr"
              className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
            >
              ⌘K
            </kbd>
          </span>
        </button>
      </div>

      {/* Balance the left OS controls + nav group */}
      <div
        className="shrink-0"
        style={{ width: OS_CONTROLS_WIDTH + 100 }}
        aria-hidden
      />
    </div>
  )
}
