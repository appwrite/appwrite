'use client'

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useRightPaneWidth } from '@/lib/react-query/hooks/auth'
import {
  clampRightPaneWidthPx,
  RIGHT_PANE_TRANSITION_MS,
} from '@/lib/right-pane/constants'
import { useIsMarketingPage } from '@/hooks/use-is-marketing-page'
import { isConsoleRightPanePath } from '@/lib/docs/docs-preview-context'
import { isAgentPagePath } from '@/lib/assistant/agent-paths'
import {
  inlineEndPaneWidthFromPointer,
  isRtlElement,
  resizeHandleOnInlineStartEdgeStyle,
  setBodyResizeDragActive,
} from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'
import { AgentPanelContent } from './AgentChat'
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

function isAgentBlockedPath(pathname: string): boolean {
  return AUTH_ROUTE_PATHNAMES.has(pathname) || isAgentPagePath(pathname)
}

export function ConsoleRightPane() {
  const location = useLocation()
  const { features } = useConsoleProfile()
  const { account } = useAuth()
  const { widthPx, setWidthPx } = useRightPaneWidth(account)
  const { activeContent } = useConsoleRightPane()
  const panelRef = useRef<HTMLDivElement>(null)
  const [isResizing, setIsResizing] = useState(false)
  const [displayContent, setDisplayContent] =
    useState<ConsoleRightPaneContent | null>(null)
  const [expanded, setExpanded] = useState(false)

  const isMarketingPage = useIsMarketingPage()
  const isConsolePath = useMemo(
    () => isConsoleRightPanePath(location.pathname),
    [location.pathname],
  )
  const isAgentBlocked = useMemo(
    () => isAgentBlockedPath(location.pathname),
    [location.pathname],
  )
  const resolvedContent =
    isMarketingPage || !isConsolePath
      ? null
      : activeContent === 'agent' && features.agent && !isAgentBlocked
        ? 'agent'
        : activeContent === 'docs'
          ? 'docs'
          : null

  useEffect(() => {
    if (resolvedContent) {
      setDisplayContent(resolvedContent)
      // Double rAF so the pane mounts at width 0 before expanding.
      let innerFrame = 0
      const outerFrame = window.requestAnimationFrame(() => {
        innerFrame = window.requestAnimationFrame(() => {
          setExpanded(true)
        })
      })
      return () => {
        window.cancelAnimationFrame(outerFrame)
        window.cancelAnimationFrame(innerFrame)
      }
    }

    setExpanded(false)
    const timer = window.setTimeout(() => {
      setDisplayContent(null)
    }, RIGHT_PANE_TRANSITION_MS)
    return () => window.clearTimeout(timer)
  }, [resolvedContent])

  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    event.preventDefault()
    setBodyResizeDragActive(true)
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
      setBodyResizeDragActive(false)
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      setBodyResizeDragActive(false)
    }
  }, [isResizing, setWidthPx])

  if (!displayContent) return null

  return (
    <div
      ref={panelRef}
      style={{
        width: expanded ? widthPx : 0,
        transitionDuration: isResizing ? '0ms' : `${RIGHT_PANE_TRANSITION_MS}ms`,
      }}
      className={cn(
        // Above ConsoleLayout sticky header (z-[110]) so the centered resize
        // rail hover/drag highlight is not clipped where it overlaps the header.
        'relative z-[111] h-full shrink-0 overflow-hidden',
        !isResizing &&
          'transition-[width] ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none',
      )}
      aria-hidden={!expanded}
    >
      <div
        style={{ width: widthPx }}
        className={cn(
          'relative flex h-full flex-col border-s border-border bg-background',
          displayContent === 'agent' &&
            '[&_button:not(:disabled)]:cursor-pointer',
          !expanded && 'pointer-events-none',
        )}
      >
        <div
          onMouseDown={handleMouseDown}
          style={resizeHandleOnInlineStartEdgeStyle()}
          className={cn(
            // Above the agent composer (`relative z-10`) so the border hover
            // highlight is not covered along the prompt section.
            'absolute top-0 z-20 flex h-full w-1.5 cursor-col-resize items-center justify-center transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
            isResizing && 'bg-primary/30 dark:bg-sidebar-accent/70',
          )}
          aria-hidden
        />

        {displayContent === 'docs' ? (
          <DocsPreviewContent />
        ) : (
          <AgentPanelContent />
        )}
      </div>
    </div>
  )
}

export { ConsoleRightPaneProvider } from './ConsoleRightPaneContext'
