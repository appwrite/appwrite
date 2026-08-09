'use client'

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useLocation } from '@tanstack/react-router'
import {
  resolveDocsPreviewView,
  type DocsPreviewView,
} from '@/lib/docs/docs-preview-menu'
import { isConsoleDocsPreviewPath } from '@/lib/docs/docs-preview-context'
import {
  isAgentDocsEnabled,
  isAgentDocsSlug,
} from '@/lib/docs/agent-docs-feature'
import {
  isFirewallDocsEnabled,
  isFirewallDocsSlug,
} from '@/lib/docs/firewall-docs-feature'
import {
  isPartnersDocsEnabled,
  isPartnersDocsSlug,
} from '@/lib/docs/partners-docs-feature'
import { useConsoleRightPane } from './ConsoleRightPaneContext'
import {
  DocsPreviewContext,
  type DocsPreviewOpenOptions,
} from './DocsPreviewContext'

const AUTH_ROUTE_PATHNAMES = new Set([
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/mfa',
  '/join',
  '/sign-out',
  '/verify-email',
])

function isDocsPreviewBlockedPath(pathname: string): boolean {
  return AUTH_ROUTE_PATHNAMES.has(pathname)
}

/**
 * Provider only — no docs content imports, so markdoc HMR does not invalidate
 * __root. Kept as a components-only module for React Fast Refresh.
 */
export function DocsPreviewProvider({ children }: { children: ReactNode }) {
  const location = useLocation()
  const { showDocs, hideRightPane } = useConsoleRightPane()
  const isAuthBlocked = useMemo(
    () => isDocsPreviewBlockedPath(location.pathname),
    [location.pathname],
  )
  const isPreviewAllowed = useMemo(
    () => !isAuthBlocked && isConsoleDocsPreviewPath(location.pathname),
    [isAuthBlocked, location.pathname],
  )
  const [isOpen, setIsOpen] = useState(false)
  const [slug, setSlug] = useState<string | null>(null)
  const [view, setView] = useState<DocsPreviewView>('article')

  const openDocsPreview = useCallback(
    (nextSlug: string, options?: DocsPreviewOpenOptions) => {
      if (!isPreviewAllowed) return
      if (isPartnersDocsSlug(nextSlug) && !isPartnersDocsEnabled()) return
      if (isFirewallDocsSlug(nextSlug) && !isFirewallDocsEnabled()) return
      if (isAgentDocsSlug(nextSlug) && !isAgentDocsEnabled()) return
      showDocs()
      setSlug(nextSlug)
      setView(resolveDocsPreviewView(nextSlug, options?.view))
      setIsOpen(true)
    },
    [isPreviewAllowed, showDocs],
  )

  const closeDocsPreview = useCallback(() => {
    setIsOpen(false)
    setSlug(null)
    setView('article')
    hideRightPane()
  }, [hideRightPane])

  useEffect(() => {
    if (!isPreviewAllowed) {
      if (isOpen) {
        setIsOpen(false)
        setSlug(null)
        setView('article')
      }
      hideRightPane()
    }
  }, [hideRightPane, isPreviewAllowed, isOpen])

  const value = useMemo(
    () => ({
      isOpen,
      slug,
      view,
      openDocsPreview,
      closeDocsPreview,
    }),
    [isOpen, slug, view, openDocsPreview, closeDocsPreview],
  )

  return (
    <DocsPreviewContext.Provider value={value}>
      {children}
    </DocsPreviewContext.Provider>
  )
}
