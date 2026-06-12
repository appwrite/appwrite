'use client'

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Loader2, X } from 'lucide-react'
import { useLocation } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { DocsMarkdown } from '@/components/pages/docs/DocsMarkdown'
import { docsHrefToRoute } from '@/components/pages/docs/DocsRouteLink'
import { getDocsPageBreadcrumbItems } from '@/lib/docs/breadcrumbs'
import { DocsPreviewNavigationProvider } from '@/lib/docs/docs-preview-navigation'
import { getDocsPage } from '@/lib/docs/content'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { isClientQueryEnabled } from '@/lib/react-query/hooks/constants'
import {
  buildConsoleUrl,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'

const DOCS_PREVIEW_PANEL_MIN_WIDTH_PX = 400
const DOCS_PREVIEW_PANEL_MAX_WIDTH_PX = 800
const DOCS_PREVIEW_PANEL_DEFAULT_WIDTH_PX = 640

const DOCS_PREVIEW_PAGE_TITLE_CLASS =
  'font-aeonik-pro text-balance text-[18px] font-normal leading-tight tracking-tight text-foreground sm:text-[20px]'

const DOCS_PREVIEW_PAGE_DESCRIPTION_CLASS = cn(
  'mt-2.5 max-w-2xl text-[13px] leading-[1.6] text-muted-foreground sm:text-[14px]',
)

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

function getDocsPreviewUrl(slug: string): string {
  return slug ? buildConsoleUrl(`/docs/${slug}`) : buildConsoleUrl('/docs/')
}

type DocsPreviewContextValue = {
  isOpen: boolean
  slug: string | null
  openDocsPreview: (slug: string) => void
  closeDocsPreview: () => void
}

const DocsPreviewContext = createContext<DocsPreviewContextValue | null>(null)

export function useDocsPreview() {
  const context = useContext(DocsPreviewContext)
  if (!context) {
    return {
      isOpen: false,
      slug: null,
      openDocsPreview: () => {},
      closeDocsPreview: () => {},
    }
  }
  return context
}

export function DocsPreviewProvider({ children }: { children: ReactNode }) {
  const location = useLocation()
  const isBlocked = useMemo(
    () => isDocsPreviewBlockedPath(location.pathname),
    [location.pathname],
  )
  const [isOpen, setIsOpen] = useState(false)
  const [slug, setSlug] = useState<string | null>(null)

  const openDocsPreview = useCallback(
    (nextSlug: string) => {
      if (isBlocked) return
      setSlug(nextSlug)
      setIsOpen(true)
    },
    [isBlocked],
  )

  const closeDocsPreview = useCallback(() => {
    setIsOpen(false)
    setSlug(null)
  }, [])

  useEffect(() => {
    if (isBlocked && isOpen) {
      setIsOpen(false)
      setSlug(null)
    }
  }, [isBlocked, isOpen])

  const value = useMemo(
    () => ({
      isOpen,
      slug,
      openDocsPreview,
      closeDocsPreview,
    }),
    [isOpen, slug, openDocsPreview, closeDocsPreview],
  )

  return (
    <DocsPreviewContext.Provider value={value}>
      {children}
    </DocsPreviewContext.Provider>
  )
}

export function DocsPreviewPanel() {
  const { isOpen, slug, openDocsPreview, closeDocsPreview } = useDocsPreview()
  const panelRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(DOCS_PREVIEW_PANEL_DEFAULT_WIDTH_PX)
  const [isResizing, setIsResizing] = useState(false)

  const { data: page, isLoading, isError } = useQuery({
    queryKey: ['docs', 'page', slug],
    queryFn: () => getDocsPage(slug!),
    enabled: isOpen && slug !== null && isClientQueryEnabled,
    staleTime: 5 * 60 * 1000,
    retry: false,
  })

  useEffect(() => {
    contentRef.current?.scrollTo({ top: 0, behavior: 'auto' })
  }, [slug])

  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    event.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing) return

    const handleMouseMove = (event: MouseEvent) => {
      const newWidth = window.innerWidth - event.clientX
      const clampedWidth = Math.min(
        DOCS_PREVIEW_PANEL_MAX_WIDTH_PX,
        Math.max(DOCS_PREVIEW_PANEL_MIN_WIDTH_PX, newWidth),
      )
      setWidth(clampedWidth)
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
  }, [isResizing])

  const handleOpenInNewWindow = useCallback(() => {
    if (slug === null) return
    openInNewWindow(getDocsPreviewUrl(slug))
  }, [slug])

  const handleOpenInDocs = useCallback(() => {
    if (slug === null) return
    openInNewTab(getDocsPreviewUrl(slug))
  }, [slug])

  const breadcrumbItems = useMemo(
    () => (slug === null ? [] : getDocsPageBreadcrumbItems(slug)),
    [slug],
  )

  const handleBreadcrumbSelect = useCallback(
    (itemSlug: string | null) => {
      if (itemSlug === null || itemSlug === slug) return
      if (itemSlug === '') {
        openInNewTab(buildConsoleUrl('/docs/'))
        return
      }
      openDocsPreview(itemSlug)
    },
    [openDocsPreview, slug],
  )

  const navigatePreviewSlug = useCallback(
    (nextSlug: string) => {
      if (nextSlug === '') {
        openInNewTab(buildConsoleUrl('/docs/'))
        return
      }
      openDocsPreview(nextSlug)
    },
    [openDocsPreview],
  )

  const handleContentClick = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      const target = event.target
      if (!(target instanceof Element)) return

      const anchor = target.closest('a[href]')
      if (!(anchor instanceof HTMLAnchorElement)) return

      const href = anchor.getAttribute('href')
      if (!href || href.startsWith('#')) return

      const route = docsHrefToRoute(href)
      if (!route) return

      event.preventDefault()
      if (!route.params) {
        openInNewTab(buildConsoleUrl('/docs/'))
        return
      }
      openDocsPreview(route.params._splat)
    },
    [openDocsPreview],
  )

  if (!isOpen || slug === null) return null

  return (
    <div
      ref={panelRef}
      style={{ width: `${width}px` }}
      className="relative flex h-full shrink-0 flex-col border-l border-border bg-background"
    >
      <div
        onMouseDown={handleMouseDown}
        className={cn(
          'absolute left-0 top-0 z-10 flex h-full w-1.5 cursor-col-resize items-center justify-center transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
          isResizing && 'bg-primary/30 dark:bg-sidebar-accent/70',
        )}
      />

      <div className="flex h-14 min-h-14 shrink-0 items-center justify-between gap-3 border-b border-border px-3">
        <p className="min-w-0 truncate text-[13px] font-semibold text-foreground">
          Docs
        </p>
        <div className="flex shrink-0 items-center gap-1">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 px-2 text-[12px]"
            onClick={handleOpenInNewWindow}
          >
            <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
            Open in new window
          </Button>
          <button
            type="button"
            onClick={closeDocsPreview}
            className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Close documentation preview"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      {breadcrumbItems.length > 0 ? (
        <div className="shrink-0 border-b border-border px-4 py-2.5 sm:px-6">
          <Breadcrumb>
            <BreadcrumbList className="flex-nowrap gap-1 text-[11px] sm:text-[12px]">
              {breadcrumbItems.map((item, index) => {
                const isLast = index === breadcrumbItems.length - 1
                const isClickable = !isLast && item.slug !== null

                return (
                  <span key={`${item.label}-${index}`} className="contents">
                    {index > 0 ? (
                      <BreadcrumbSeparator className="shrink-0" />
                    ) : null}
                    <BreadcrumbItem className="min-w-0">
                      {isLast ? (
                        <BreadcrumbPage className="truncate font-normal">
                          {item.label}
                        </BreadcrumbPage>
                      ) : isClickable ? (
                        <BreadcrumbLink asChild>
                          <button
                            type="button"
                            onClick={() => handleBreadcrumbSelect(item.slug)}
                            className="max-w-[9rem] truncate text-left sm:max-w-[11rem]"
                          >
                            {item.label}
                          </button>
                        </BreadcrumbLink>
                      ) : (
                        <span className="max-w-[9rem] truncate text-muted-foreground sm:max-w-[11rem]">
                          {item.label}
                        </span>
                      )}
                    </BreadcrumbItem>
                  </span>
                )
              })}
            </BreadcrumbList>
          </Breadcrumb>
        </div>
      ) : null}

      <DocsPreviewNavigationProvider navigateToSlug={navigatePreviewSlug}>
        <div
          ref={contentRef}
          onClick={handleContentClick}
          className="min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
        {isLoading ? (
          <div className="flex h-full min-h-[240px] items-center justify-center text-[13px] text-muted-foreground">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            Loading documentation...
          </div>
        ) : isError || !page ? (
          <div className="flex h-full min-h-[240px] flex-col items-center justify-center gap-3 px-4 text-center">
            <p className="text-[13px] text-muted-foreground">
              Could not load this documentation page.
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleOpenInNewWindow}
            >
              <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
              Open in new window
            </Button>
          </div>
        ) : (
          <article className="min-w-0">
            <header className="mb-6">
              <h1 className={DOCS_PREVIEW_PAGE_TITLE_CLASS}>
                {page.meta.title}
                <span className="text-[var(--brand-cta)]">_</span>
              </h1>
              {page.meta.description ? (
                <p className={DOCS_PREVIEW_PAGE_DESCRIPTION_CLASS}>
                  {page.meta.description}
                </p>
              ) : null}
              <div className="mt-6 h-px w-full bg-border" aria-hidden />
            </header>
            <DocsMarkdown content={page.content} compact />
          </article>
        )}
        </div>
      </DocsPreviewNavigationProvider>

      <div
        className="flex shrink-0 items-center border-t border-border bg-background px-4 sm:px-6"
        style={{
          height: CLI_SHELL_COLLAPSED_HEIGHT_PX,
          minHeight: CLI_SHELL_COLLAPSED_HEIGHT_PX,
          maxHeight: CLI_SHELL_COLLAPSED_HEIGHT_PX,
        }}
      >
        <Button
          type="button"
          size="sm"
          className="h-8 w-full text-[13px]"
          onClick={handleOpenInDocs}
        >
          Open in docs
        </Button>
      </div>
    </div>
  )
}
