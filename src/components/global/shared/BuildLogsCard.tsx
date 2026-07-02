/**
 * BuildLogsCard – shared build logs viewer with search, scroll, copy, download.
 * Used by sites and functions create deploying views.
 */

import { useState, useRef, useEffect, useLayoutEffect, useCallback } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import {
  Search,
  Copy,
  Download,
  ArrowUp,
  ArrowDown,
  CircleDashed,
} from 'lucide-react'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export interface BuildLogsCardProps {
  buildLogs: string
  durationDisplay?: string | null
  emptyMessage?: React.ReactNode
  downloadFilename?: string
  hideWhenEmpty?: boolean
  /** Custom title (default: "Build logs"). Set to empty or use hideTitle to hide the header. */
  title?: string
  /** Hide the title header (e.g. when used inside a modal with its own title) */
  hideTitle?: boolean
}

export function BuildLogsCard({
  buildLogs,
  durationDisplay = null,
  emptyMessage,
  downloadFilename = 'build-logs.txt',
  hideWhenEmpty = false,
  title = 'Build logs',
  hideTitle = false,
}: BuildLogsCardProps) {
  const t = useT()
  const resolvedEmptyMessage = emptyMessage ?? (
    <div className="flex items-center gap-2">
      <CircleDashed className="h-3.5 w-3.5 shrink-0" />
      {t('Waiting for build logs...')}
    </div>
  )
  const logsContainerRef = useRef<HTMLDivElement>(null)
  const hasUserScrolledRef = useRef(false)
  const isFollowingRef = useRef(false)
  const [logsSearch, setLogsSearch] = useState('')
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)

  const readScrollPosition = useCallback(() => {
    const el = logsContainerRef.current
    if (!el) return
    const { scrollTop, scrollHeight, clientHeight } = el
    const threshold = 10
    const atTop = scrollTop <= threshold
    const atBottom = scrollTop + clientHeight >= scrollHeight - threshold
    setIsAtTop((prev) => (prev === atTop ? prev : atTop))
    setIsAtBottom((prev) => (prev === atBottom ? prev : atBottom))
  }, [])

  const handleUserScroll = useCallback(() => {
    if (isFollowingRef.current) {
      readScrollPosition()
      return
    }
    hasUserScrolledRef.current = true
    readScrollPosition()
  }, [readScrollPosition])

  useEffect(() => {
    const el = logsContainerRef.current
    if (!el) return
    readScrollPosition()
    el.addEventListener('scroll', handleUserScroll, { passive: true })
    window.addEventListener('resize', readScrollPosition)
    return () => {
      el.removeEventListener('scroll', handleUserScroll)
      window.removeEventListener('resize', readScrollPosition)
    }
  }, [handleUserScroll, readScrollPosition])

  useLayoutEffect(() => {
    const el = logsContainerRef.current
    if (!el) return
    const shouldFollow = !hasUserScrolledRef.current || isAtBottom
    if (shouldFollow) {
      isFollowingRef.current = true
      el.scrollTop = el.scrollHeight
      readScrollPosition()
      requestAnimationFrame(() => {
        isFollowingRef.current = false
      })
      return
    }
    readScrollPosition()
  }, [buildLogs, isAtBottom, readScrollPosition])

  const handleScrollToTop = useCallback(() => {
    logsContainerRef.current?.scrollTo({ top: 0, behavior: 'smooth' })
  }, [])

  const handleScrollToBottom = useCallback(() => {
    const el = logsContainerRef.current
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
  }, [])

  const handleCopyLogs = useCallback(async () => {
    if (!buildLogs) {
      toast.error(t('No logs to copy'))
      return
    }
    try {
      await navigator.clipboard.writeText(buildLogs)
      toast.success(t('Logs copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy logs'))
    }
  }, [buildLogs, t])

  const handleDownloadLogs = useCallback(() => {
    if (!buildLogs) {
      toast.error(t('No logs to download'))
      return
    }
    const blob = new Blob([buildLogs], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = downloadFilename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast.success(t('Logs downloaded'))
  }, [buildLogs, downloadFilename, t])

  if (hideWhenEmpty && !buildLogs) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {!hideTitle && (
        <div className="px-6 py-4 flex items-center justify-between gap-3">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t(title)}
          </h3>
          {durationDisplay && (
            <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">
              {t('Duration:')}{' '}
              <span className="font-medium text-foreground">
                {durationDisplay}
              </span>
            </span>
          )}
        </div>
      )}
      <div
        className={`px-4 sm:px-6 py-3 ${!hideTitle ? 'border-t border-border' : ''}`}
      >
        <TooltipProvider>
          <div className="flex items-center gap-2">
            <div className="relative flex-1 min-w-0">
              <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder={t('Search logs...')}
                value={logsSearch}
                onChange={(e) => setLogsSearch(e.target.value)}
                className="ps-9 h-9 text-[13px]"
              />
            </div>
            <TooltipPrimitive.Root>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleDownloadLogs}
                  disabled={!buildLogs}
                  className="h-9 w-9 p-0 shrink-0"
                >
                  <Download className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{t('Download logs')}</p>
              </TooltipContent>
            </TooltipPrimitive.Root>
            <TooltipPrimitive.Root>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleCopyLogs}
                  disabled={!buildLogs}
                  className="h-9 w-9 p-0 shrink-0"
                >
                  <Copy className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{t('Copy logs')}</p>
              </TooltipContent>
            </TooltipPrimitive.Root>
          </div>
        </TooltipProvider>
      </div>
      <div className="border-t border-border" />
      <div className="relative">
        <div
          ref={logsContainerRef}
          className="h-[400px] overflow-y-scroll overflow-x-hidden pe-16 [scrollbar-gutter:stable]"
        >
          <BuildLogsView
            buildLogs={buildLogs}
            searchTerm={logsSearch}
            highlightLineOnHover
            emptyMessage={resolvedEmptyMessage}
          />
        </div>
        {buildLogs && (
          <div className="absolute bottom-3 end-3 flex flex-col gap-2 z-10">
            <TooltipProvider>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleScrollToTop}
                    disabled={isAtTop}
                    className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                  >
                    <ArrowUp className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="left">
                  <p>{t('Scroll to top')}</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
              <TooltipPrimitive.Root>
                <TooltipTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleScrollToBottom}
                    disabled={isAtBottom}
                    className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                  >
                    <ArrowDown className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="left">
                  <p>{t('Scroll to bottom')}</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
            </TooltipProvider>
          </div>
        )}
      </div>
    </div>
  )
}
