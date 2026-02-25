/**
 * BuildLogsCard – shared build logs viewer with search, scroll, copy, download.
 * Used by sites and functions create deploying views.
 */

import { useState, useRef, useEffect, useCallback } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import { Search, Copy, Download, ArrowUp, ArrowDown, CircleDashed } from 'lucide-react'
import { toast } from 'sonner'

export interface BuildLogsCardProps {
  buildLogs: string
  durationDisplay?: string | null
  emptyMessage?: React.ReactNode
  downloadFilename?: string
  hideWhenEmpty?: boolean
}

export function BuildLogsCard({
  buildLogs,
  durationDisplay = null,
  emptyMessage = (
    <div className="flex items-center gap-2">
      <CircleDashed className="h-3.5 w-3.5 shrink-0" />
      Waiting for build logs...
    </div>
  ),
  downloadFilename = 'build-logs.txt',
  hideWhenEmpty = false,
}: BuildLogsCardProps) {
  const logsContainerRef = useRef<HTMLDivElement>(null)
  const hasUserScrolledRef = useRef(false)
  const [logsSearch, setLogsSearch] = useState('')
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)

  useEffect(() => {
    const shouldFollow = !hasUserScrolledRef.current || isAtBottom
    if (!shouldFollow || !logsContainerRef.current) return
    const el = logsContainerRef.current
    el.scrollTop = el.scrollHeight
  }, [buildLogs, isAtBottom])

  const updateScrollPosition = useCallback(() => {
    const el = logsContainerRef.current
    if (!el) return
    hasUserScrolledRef.current = true
    const { scrollTop, scrollHeight, clientHeight } = el
    const threshold = 10
    setIsAtTop(scrollTop <= threshold)
    setIsAtBottom(scrollTop + clientHeight >= scrollHeight - threshold)
  }, [])

  useEffect(() => {
    const el = logsContainerRef.current
    if (!el) return
    updateScrollPosition()
    el.addEventListener('scroll', updateScrollPosition)
    window.addEventListener('resize', updateScrollPosition)
    return () => {
      el.removeEventListener('scroll', updateScrollPosition)
      window.removeEventListener('resize', updateScrollPosition)
    }
  }, [updateScrollPosition, buildLogs])

  const handleScrollToTop = useCallback(() => {
    logsContainerRef.current?.scrollTo({ top: 0, behavior: 'smooth' })
  }, [])

  const handleScrollToBottom = useCallback(() => {
    const el = logsContainerRef.current
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
  }, [])

  const handleCopyLogs = useCallback(async () => {
    if (!buildLogs) {
      toast.error('No logs to copy')
      return
    }
    try {
      await navigator.clipboard.writeText(buildLogs)
      toast.success('Logs copied to clipboard')
    } catch {
      toast.error('Failed to copy logs')
    }
  }, [buildLogs])

  const handleDownloadLogs = useCallback(() => {
    if (!buildLogs) {
      toast.error('No logs to download')
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
    toast.success('Logs downloaded')
  }, [buildLogs, downloadFilename])

  if (hideWhenEmpty && !buildLogs) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4 flex items-center justify-between gap-3">
        <h3 className="text-[15px] font-semibold text-foreground">Build logs</h3>
        {durationDisplay && (
          <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">
            Duration:{' '}
            <span className="font-medium text-foreground">{durationDisplay}</span>
          </span>
        )}
      </div>
      <div className="border-t border-border px-4 sm:px-6 py-3">
        <TooltipProvider>
          <div className="flex items-center gap-2">
            <div className="relative flex-1 min-w-0">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search logs..."
                value={logsSearch}
                onChange={(e) => setLogsSearch(e.target.value)}
                className="pl-9 h-9 text-[13px]"
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
                <p>Download logs</p>
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
                <p>Copy logs</p>
              </TooltipContent>
            </TooltipPrimitive.Root>
          </div>
        </TooltipProvider>
      </div>
      <div className="border-t border-border" />
      <div className="relative">
        <div
          ref={logsContainerRef}
          className="h-[400px] overflow-y-auto overflow-x-auto"
        >
          <BuildLogsView
            buildLogs={buildLogs}
            searchTerm={logsSearch}
            highlightLineOnHover
            emptyMessage={emptyMessage}
          />
        </div>
        {buildLogs && (
          <div className="absolute bottom-3 right-3 flex flex-col gap-2 z-10">
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
                  <p>Scroll to top</p>
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
                  <p>Scroll to bottom</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
            </TooltipProvider>
          </div>
        )}
      </div>
    </div>
  )
}
