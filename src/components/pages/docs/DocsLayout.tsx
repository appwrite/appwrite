import type { ReactNode } from 'react'
import { DOCS_CONTAINER } from '@/lib/docs/docs-container'
import { getDocsSectionNav } from '@/lib/docs/navigation'
import { cn } from '@/lib/utils'
import type { DocsTocItem } from '@/lib/docs/types'
import { DocsArticleHeader } from './DocsArticleHeader'
import { DocsFeedback } from './DocsFeedback'
import { DocsSectionSubnavMobile } from './DocsSectionSubnav'
import { DocsToc } from './DocsToc'

type DocsLayoutProps = {
  slug: string
  title: string
  description?: string
  readingTimeMinutes?: number
  toc?: DocsTocItem[]
  headerActions?: ReactNode
  /** When true, hides the TOC column and lets content use the full docs container width. */
  wideContent?: boolean
  children: ReactNode
}

export function DocsLayout({
  slug,
  title,
  description,
  readingTimeMinutes,
  toc = [],
  headerActions,
  wideContent = false,
  children,
}: DocsLayoutProps) {
  const { parent, navigation: sectionNav } = getDocsSectionNav(slug)
  const hasSectionNav = sectionNav && sectionNav.length > 0

  return (
    <div className="flex w-full min-w-0 flex-col overflow-visible">
      {hasSectionNav && sectionNav ? (
        <DocsSectionSubnavMobile navigation={sectionNav} parent={parent} />
      ) : null}

      <div className="min-w-0 flex-1 overflow-visible">
        <div
          data-docs-page-shell
          className={cn(
            DOCS_CONTAINER,
            'mx-auto w-full max-w-7xl overflow-visible pb-8 ps-8 pe-4 pt-8 @[480px]:ps-10 @[480px]:pe-6 @[480px]:pt-10 @[900px]:ps-12',
          )}
        >
          <div
            className={cn(
              'grid items-start gap-8 overflow-visible',
              wideContent
                ? 'grid-cols-1'
                : '@[900px]:grid-cols-[minmax(0,52rem)_1px_minmax(192px,208px)] @[900px]:gap-x-12 @[1080px]:gap-x-16',
            )}
          >
            <article className="min-w-0">
              <DocsArticleHeader
                title={title}
                description={description}
                readingTimeMinutes={readingTimeMinutes}
                parent={parent}
                actions={headerActions}
              />
              <div className="min-w-0 overflow-x-clip">
                {children}
                {!wideContent ? <DocsFeedback /> : null}
              </div>
            </article>
            {!wideContent ? (
              <>
                <div
                  aria-hidden
                  className="hidden w-px self-stretch bg-border @[900px]:block"
                />
                <DocsToc items={toc} />
              </>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
