'use client'

import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Loader2, X } from 'lucide-react'
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
import { DocsHome } from '@/components/pages/docs/DocsHome'
import { DocsPartnersHome } from '@/components/pages/docs/DocsPartnersHome'
import { DocsPreviewArticleHeader } from '@/components/pages/docs/DocsPreviewArticleHeader'
import { DocsPreviewMenu } from '@/components/pages/docs/DocsPreviewMenu'
import { docsHrefToPreviewSlug } from '@/lib/docs/docs-href'
import { getDocsPageBreadcrumbItems } from '@/lib/docs/breadcrumbs'
import {
  DOCS_CONTAINER,
  docsContentPaddingX,
} from '@/lib/docs/docs-container'
import {
  canShowDocsPreviewMenu,
  resolveDocsPreviewView,
  type DocsPreviewView,
} from '@/lib/docs/docs-preview-menu'
import { DocsPreviewNavigationProvider } from '@/lib/docs/docs-preview-navigation'
import { getDocsPage } from '@/lib/docs/content'
import { isAgentDocsSlug } from '@/lib/docs/agent-docs-feature'
import { isFirewallDocsSlug } from '@/lib/docs/firewall-docs-feature'
import { isPartnersDocsSlug } from '@/lib/docs/partners-docs-feature'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { isClientQueryEnabled } from '@/lib/react-query/hooks/constants'
import {
  buildConsoleUrl,
  openInNewTab,
} from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { useDocsPreview } from './DocsPreviewContext'

function getDocsPreviewUrl(slug: string): string {
  return slug ? buildConsoleUrl(`/docs/${slug}`) : buildConsoleUrl('/docs/')
}

export function DocsPreviewContent() {
  const t = useT()
  const { isOpen, slug, view, openDocsPreview, closeDocsPreview } =
    useDocsPreview()
  const { features } = useConsoleProfile()
  const contentRef = useRef<HTMLDivElement>(null)
  const partnersDocsEnabled = features.partnersDocs
  const firewallDocsEnabled = features.firewall
  const agentDocsEnabled = features.agent

  const showMenu =
    slug !== null &&
    slug !== '' &&
    view === 'menu' &&
    canShowDocsPreviewMenu(slug) &&
    (!isPartnersDocsSlug(slug) || partnersDocsEnabled) &&
    (!isFirewallDocsSlug(slug) || firewallDocsEnabled) &&
    (!isAgentDocsSlug(slug) || agentDocsEnabled)

  const { data: page, isLoading, isError } = useQuery({
    queryKey: ['docs', 'page', slug],
    queryFn: () => getDocsPage(slug!),
    enabled:
      isOpen &&
      slug !== null &&
      slug !== '' &&
      !showMenu &&
      (!isPartnersDocsSlug(slug!) || partnersDocsEnabled) &&
      (!isFirewallDocsSlug(slug!) || firewallDocsEnabled) &&
      (!isAgentDocsSlug(slug!) || agentDocsEnabled) &&
      isClientQueryEnabled,
    staleTime: 5 * 60 * 1000,
    retry: false,
  })

  useEffect(() => {
    contentRef.current?.scrollTo({ top: 0, behavior: 'auto' })
  }, [slug, view])

  const navigatePreviewSlug = useCallback(
    (nextSlug: string, nextView: DocsPreviewView = 'article') => {
      if (isPartnersDocsSlug(nextSlug) && !partnersDocsEnabled) {
        openDocsPreview('', { view: 'article' })
        return
      }
      if (isFirewallDocsSlug(nextSlug) && !firewallDocsEnabled) {
        openDocsPreview('', { view: 'article' })
        return
      }
      if (isAgentDocsSlug(nextSlug) && !agentDocsEnabled) {
        openDocsPreview('', { view: 'article' })
        return
      }
      const resolvedView = resolveDocsPreviewView(nextSlug, nextView)
      if (nextSlug === slug && resolvedView === view) return
      openDocsPreview(nextSlug, { view: nextView })
    },
    [
      agentDocsEnabled,
      firewallDocsEnabled,
      openDocsPreview,
      partnersDocsEnabled,
      slug,
      view,
    ],
  )

  useEffect(() => {
    if (!isOpen || slug === null) return
    if (!partnersDocsEnabled && isPartnersDocsSlug(slug)) {
      openDocsPreview('', { view: 'article' })
      return
    }
    if (!firewallDocsEnabled && isFirewallDocsSlug(slug)) {
      openDocsPreview('', { view: 'article' })
      return
    }
    if (!agentDocsEnabled && isAgentDocsSlug(slug)) {
      openDocsPreview('', { view: 'article' })
    }
  }, [
    agentDocsEnabled,
    firewallDocsEnabled,
    isOpen,
    openDocsPreview,
    partnersDocsEnabled,
    slug,
  ])

  const handleOpenInNewTab = useCallback(() => {
    if (slug === null) return
    openInNewTab(getDocsPreviewUrl(slug))
  }, [slug])

  const handleOpenInDocs = useCallback(() => {
    if (slug === null) return
    openInNewTab(getDocsPreviewUrl(slug))
  }, [slug])

  const breadcrumbItems = useMemo(
    () =>
      slug === null
        ? []
        : getDocsPageBreadcrumbItems(slug, undefined, {
            previewView: showMenu ? 'menu' : 'article',
          }),
    [slug, showMenu],
  )

  const handleBreadcrumbSelect = useCallback(
    (itemSlug: string | null, itemView?: DocsPreviewView) => {
      if (itemSlug === null) return
      navigatePreviewSlug(itemSlug, itemView ?? 'article')
    },
    [navigatePreviewSlug],
  )

  const handleContentClick = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      const target = event.target
      if (!(target instanceof Element)) return

      const anchor = target.closest('a[href]')
      if (!(anchor instanceof HTMLAnchorElement)) return

      const href = anchor.getAttribute('href')
      if (!href || href.startsWith('#')) return

      const previewSlug = docsHrefToPreviewSlug(href)
      if (previewSlug === null) return

      event.preventDefault()
      navigatePreviewSlug(previewSlug, 'article')
    },
    [navigatePreviewSlug],
  )

  if (!isOpen || slug === null) return null

  if (!partnersDocsEnabled && isPartnersDocsSlug(slug)) {
    return null
  }

  if (!firewallDocsEnabled && isFirewallDocsSlug(slug)) {
    return null
  }

  const isDocsHome = slug === ''
  const isPartnersHome = slug === 'partners' && partnersDocsEnabled
  const isHubHome = isDocsHome || isPartnersHome

  return (
    <DocsPreviewNavigationProvider navigateToSlug={navigatePreviewSlug}>
      <div
        className={cn(
          DOCS_CONTAINER,
          'flex h-full min-h-0 flex-col',
        )}
      >
        <div className="flex h-14 min-h-14 shrink-0 items-center justify-between gap-3 border-b border-border px-3">
          <button
            type="button"
            onClick={() => navigatePreviewSlug('')}
            className="min-w-0 truncate text-start text-[13px] font-semibold text-foreground transition-colors hover:text-foreground/80"
          >
            {t('Docs')}
          </button>
          <div className="flex shrink-0 items-center gap-1">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 px-2 text-[12px]"
              onClick={handleOpenInNewTab}
            >
              <ExternalLink className="me-1.5 h-3.5 w-3.5" />
              {t('Open in new tab')}
            </Button>
            <button
              type="button"
              onClick={closeDocsPreview}
              className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              aria-label={t('Close documentation preview')}
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        {!isHubHome && breadcrumbItems.length > 0 ? (
          <div
            className={cn(
              'shrink-0 border-b border-border py-2.5',
              docsContentPaddingX,
            )}
          >
            <Breadcrumb>
              <BreadcrumbList className="flex-nowrap gap-1 text-[11px] @[480px]:text-[12px]">
                {breadcrumbItems.map((item, index) => {
                  const isLast = index === breadcrumbItems.length - 1
                  const isClickable =
                    !isLast &&
                    item.slug !== null &&
                    (item.slug !== slug || item.view === 'menu')

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
                              onClick={() =>
                                handleBreadcrumbSelect(item.slug, item.view)
                              }
                              className="max-w-[9rem] cursor-pointer truncate text-start @[480px]:max-w-[11rem]"
                            >
                              {item.label}
                            </button>
                          </BreadcrumbLink>
                        ) : (
                          <span className="max-w-[9rem] truncate text-muted-foreground @[480px]:max-w-[11rem]">
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

        <div
          ref={contentRef}
          onClick={handleContentClick}
          className={cn(
            'min-h-0 w-full min-w-0 flex-1 overflow-y-auto',
            isHubHome ? 'py-0' : 'py-6',
            docsContentPaddingX,
          )}
        >
          {isDocsHome ? (
            <DocsHome variant="preview" />
          ) : isPartnersHome ? (
            <DocsPartnersHome variant="preview" />
          ) : showMenu ? (
            <DocsPreviewMenu slug={slug} scrollContainerRef={contentRef} />
          ) : isLoading ? (
            <div className="flex h-full min-h-[240px] items-center justify-center text-[13px] text-muted-foreground">
              <Loader2 className="me-2 h-4 w-4 animate-spin" />
              {t('Loading documentation...')}
            </div>
          ) : isError || !page ? (
            <div className="flex h-full min-h-[240px] flex-col items-center justify-center gap-3 px-4 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t('Could not load this documentation page.')}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleOpenInNewTab}
              >
                <ExternalLink className="me-1.5 h-3.5 w-3.5" />
                {t('Open in new tab')}
              </Button>
            </div>
          ) : (
            <article className="min-w-0">
              <DocsPreviewArticleHeader
                title={page.meta.title}
                description={page.meta.description}
                readingTimeMinutes={page.meta.readingTimeMinutes}
                slug={page.meta.slug}
                toc={page.toc}
                scrollContainerRef={contentRef}
              />
              <DocsMarkdown content={page.content} compact />
            </article>
          )}
        </div>

        <div
          className={cn(
            'flex shrink-0 items-center border-t border-border bg-background',
            docsContentPaddingX,
          )}
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
            {t('Open in docs')}
          </Button>
        </div>
      </div>
    </DocsPreviewNavigationProvider>
  )
}

/** @deprecated Use {@link ConsoleRightPane} with {@link DocsPreviewContent}. */
export function DocsPreviewPanel() {
  return null
}
