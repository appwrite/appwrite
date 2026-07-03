'use client'

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useRightPaneWidth } from '@/lib/react-query/hooks/auth'
import { clampRightPaneWidthPx } from '@/lib/right-pane/constants'
import { useIsMarketingPage } from '@/hooks/use-is-marketing-page'
import { isConsoleRightPanePath } from '@/lib/docs/docs-preview-context'
import {
  inlineEndPaneWidthFromPointer,
  isRtlElement,
  resizeHandleOnInlineStartEdgeStyle,
} from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'
import { AIChatPanelContent } from './AIChat'
import { DocsPreviewContent } from './DocsPreview'
import {
  useConsoleRightPane,
  type ConsoleRightPaneContent,
} from './ConsoleRightPaneContext'

export type { ConsoleRightPaneContent }

const AUTH_ROUTE_PATHNAMES = new Set([
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/mfa',
  '/join',
  '/sign-out',
  '/verify-email',
])

function isAssistantBlockedPath(pathname: string): boolean {
  return AUTH_ROUTE_PATHNAMES.has(pathname)
}

export function ConsoleRightPane() {
  const location = useLocation()
  const overrides = useDebugOverrides()
  const { account } = useAuth()
  const { widthPx, setWidthPx } = useRightPaneWidth(account)
  const { activeContent } = useConsoleRightPane()
  const panelRef = useRef<HTMLDivElement>(null)
  const [isResizing, setIsResizing] = useState(false)

  const isMarketingPage = useIsMarketingPage()
  const isConsolePath = useMemo(
    () => isConsoleRightPanePath(location.pathname),
    [location.pathname],
  )
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const resolvedContent =
    isMarketingPage || !isConsolePath
      ? null
      : activeContent === 'assistant' &&
          overrides.showAIAssistant &&
          !isAssistantBlocked
        ? 'assistant'
        : activeContent === 'docs'
          ? 'docs'
          : null

  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    event.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing) return

    const handleMouseMove = (event: MouseEvent) => {
      const isRtl = isRtlElement(document.documentElement)
      const newWidth = inlineEndPaneWidthFromPointer(
        event.clientX,
        window.innerWidth,
        isRtl,
      )
      setWidthPx(clampRightPaneWidthPx(newWidth))
    }

    const handleMouseUp = () => {
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isResizing, setWidthPx])

  if (!resolvedContent) return null

  return (
    <div
      ref={panelRef}
      style={{ width: `${widthPx}px` }}
      className={cn(
        'relative flex h-full shrink-0 flex-col border-s border-border bg-background',
        resolvedContent === 'assistant' &&
          '[&_button:not(:disabled)]:cursor-pointer',
      )}
    >
      <div
        onMouseDown={handleMouseDown}
        style={resizeHandleOnInlineStartEdgeStyle()}
        className={cn(
          'absolute top-0 z-10 flex h-full w-1.5 cursor-col-resize items-center justify-center transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
          isResizing && 'bg-primary/30 dark:bg-sidebar-accent/70',
        )}
        aria-hidden
      />

      {resolvedContent === 'docs' ? (
        <DocsPreviewContent />
      ) : (
        <AIChatPanelContent />
      )}
    </div>
  )
}

export { ConsoleRightPaneProvider } from './ConsoleRightPaneContext'
