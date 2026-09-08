'use client'

import { useQuery } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { docsGridQuickStarts, docsGridTwoCol } from '@/lib/docs/docs-container'
import { getDocsPage } from '@/lib/docs/content'
import { docsHrefToPreviewSlug } from '@/lib/docs/docs-href'
import { isClientQueryEnabled } from '@/lib/react-query/hooks/constants'
import { DocsMarkdown } from './DocsMarkdown'
import { DocsPreviewArticleHeader } from './DocsPreviewArticleHeader'
import {
  canShowDocsPreviewMenu,
  getDocsPreviewMenuMeta,
  type DocsPreviewView,
} from '@/lib/docs/docs-preview-menu'
import { isDocsNavGroup } from '@/lib/docs/navigation'
import { DOCS_SECTION_NAVS } from '@/lib/docs/navigation/sections'
import { QUICK_STARTS_HUB_CATEGORIES } from '@/lib/docs/quick-starts-hub'
import { getTutorialsHubCategories } from '@/lib/docs/tutorials-hub'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { docsPreviewPrimaryTitleClass } from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'
import type { RefObject } from 'react'
import { DocsRouteLink } from './DocsRouteLink'

const PREVIEW_MENU_DESCRIPTION_CLASS =
  'mt-2.5 max-w-2xl text-[13px] leading-[1.6] text-muted-foreground @[480px]:text-[14px]'

const PREVIEW_MENU_GROUP_CLASS =
  'text-[11px] font-semibold uppercase tracking-wider text-muted-foreground'

const PREVIEW_MENU_LINK_CLASS = cn(
  'block rounded-lg border border-border bg-card/45 px-3 py-2.5 text-[13px] font-medium text-foreground',
  'transition-colors hover:bg-accent/15',
)

type DocsPreviewMenuProps = {
  slug: string
  scrollContainerRef?: RefObject<HTMLElement | null>
}

function PreviewMenuHeader({
  title,
  description,
}: {
  title: string
  description?: string
}) {
  return (
    <header className="mb-6">
      <h1 className={docsPreviewPrimaryTitleClass}>
        {title}
        <span className="text-[var(--brand-cta)]">_</span>
      </h1>
      {description ? (
        <p className={PREVIEW_MENU_DESCRIPTION_CLASS}>{description}</p>
      ) : null}
      <div className="mt-6 h-px w-full bg-border" aria-hidden />
    </header>
  )
}

function QuickStartsPreviewMenu() {
  const meta = getDocsPreviewMenuMeta('quick-starts')!

  return (
    <article className="min-w-0">
      <PreviewMenuHeader title={meta.title} description={meta.description} />
      <div className="space-y-8">
        {QUICK_STARTS_HUB_CATEGORIES.map((category) => (
          <section key={category.title} className="space-y-3">
            <h2 className={PREVIEW_MENU_GROUP_CLASS}>{category.title}</h2>
            <ul className={cn('grid gap-2', docsGridQuickStarts)}>
              {category.items.map((item) => (
                <li key={item.href}>
                  <DocsRouteLink href={item.href} className={PREVIEW_MENU_LINK_CLASS}>
                    <span className="flex items-center gap-2.5">
                      <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                        <img
                          src={item.iconSrc}
                          alt=""
                          className={cn('size-3.5', PUBLIC_ICON_MUTED_CLASSES)}
                        />
                      </span>
                      {item.title}
                    </span>
                  </DocsRouteLink>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </article>
  )
}

function TutorialsPreviewMenu() {
  const categories = getTutorialsHubCategories()
  const meta = getDocsPreviewMenuMeta('tutorials')!

  return (
    <article className="min-w-0">
      <PreviewMenuHeader title={meta.title} description={meta.description} />
      <div className="space-y-8">
        {categories.map((category) => (
          <section key={category.title} className="space-y-3">
            <h2 className={PREVIEW_MENU_GROUP_CLASS}>{category.title}</h2>
            <ul className={cn('grid gap-2', docsGridTwoCol)}>
              {category.tutorials.map((tutorial) => (
                <li key={tutorial.href}>
                  {tutorial.draft ? (
                    <div
                      aria-disabled
                      className={cn(
                        PREVIEW_MENU_LINK_CLASS,
                        'cursor-default opacity-70 hover:bg-card/45',
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <span>{tutorial.framework}</span>
                        <Badge variant="inactive" className="text-[10px] shrink-0">
                          Coming soon
                        </Badge>
                      </div>
                      <p className="mt-1 text-[12px] font-normal text-muted-foreground">
                        {tutorial.title}
                      </p>
                    </div>
                  ) : (
                    <DocsRouteLink href={tutorial.href} className={PREVIEW_MENU_LINK_CLASS}>
                      <span>{tutorial.framework}</span>
                      <p className="mt-1 text-[12px] font-normal text-muted-foreground">
                        {tutorial.title}
                      </p>
                    </DocsRouteLink>
                  )}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </article>
  )
}

function sectionNavItemPreviewView(
  href: string,
  sectionPrefix: string,
): DocsPreviewView | undefined {
  const itemSlug = docsHrefToPreviewSlug(href)
  if (itemSlug === sectionPrefix) return 'article'
  return undefined
}

function SectionPreviewMenu({
  slug,
  scrollContainerRef,
}: {
  slug: string
  scrollContainerRef?: RefObject<HTMLElement | null>
}) {
  const config = DOCS_SECTION_NAVS.find((entry) => entry.prefix === slug)
  if (!config) return null

  const meta = getDocsPreviewMenuMeta(slug)

  const { data: overviewPage } = useQuery({
    queryKey: ['docs', 'page', slug],
    queryFn: () => getDocsPage(slug),
    enabled: isClientQueryEnabled && slug === config.prefix,
    staleTime: 5 * 60 * 1000,
    retry: false,
  })

  if (overviewPage) {
    return (
      <article className="min-w-0">
        <DocsPreviewArticleHeader
          title={overviewPage.meta.title}
          description={overviewPage.meta.description}
          readingTimeMinutes={overviewPage.meta.readingTimeMinutes}
          slug={slug}
          toc={overviewPage.toc}
          scrollContainerRef={scrollContainerRef}
        />
        <div className="mb-8 min-w-0">
          <DocsMarkdown content={overviewPage.content} compact />
        </div>
        <div className="mb-6 h-px w-full bg-border" aria-hidden />
        <SectionPreviewNav config={config} slug={slug} />
      </article>
    )
  }

  return (
    <article className="min-w-0">
      <PreviewMenuHeader
        title={meta?.title ?? config.parent.label}
        description={meta?.description}
      />
      <SectionPreviewNav config={config} slug={slug} />
    </article>
  )
}

function SectionPreviewNav({
  config,
  slug,
}: {
  config: (typeof DOCS_SECTION_NAVS)[number]
  slug: string
}) {
  return (
    <nav aria-label={config.parent.label} className="space-y-6">
      {config.navigation.map((entry, index) =>
        isDocsNavGroup(entry) ? (
          <section key={entry.label ?? index} className="space-y-2">
            {entry.label ? (
              <h2 className={PREVIEW_MENU_GROUP_CLASS}>{entry.label}</h2>
            ) : null}
            <ul className="space-y-1">
              {entry.items.map((item) => (
                <li key={item.href}>
                  <DocsRouteLink
                    href={item.href}
                    className={PREVIEW_MENU_LINK_CLASS}
                    previewView={sectionNavItemPreviewView(item.href, slug)}
                  >
                    {item.label}
                  </DocsRouteLink>
                </li>
              ))}
            </ul>
          </section>
        ) : (
          <ul key={entry.href} className="space-y-1">
            <li>
              <DocsRouteLink
                href={entry.href}
                className={PREVIEW_MENU_LINK_CLASS}
                previewView={sectionNavItemPreviewView(entry.href, slug)}
              >
                {entry.label}
              </DocsRouteLink>
            </li>
          </ul>
        ),
      )}
    </nav>
  )
}

export function DocsPreviewMenu({
  slug,
  scrollContainerRef,
}: DocsPreviewMenuProps) {
  if (!canShowDocsPreviewMenu(slug)) return null

  if (slug === 'quick-starts') return <QuickStartsPreviewMenu />
  if (slug === 'tutorials') return <TutorialsPreviewMenu />

  return (
    <SectionPreviewMenu slug={slug} scrollContainerRef={scrollContainerRef} />
  )
}
