import type { ReactNode } from 'react'
import { getDocsSectionNav } from '@/lib/docs/navigation'
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
  children: ReactNode
}

export function DocsLayout({
  slug,
  title,
  description,
  readingTimeMinutes,
  toc = [],
  headerActions,
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
          className="mx-auto w-full max-w-7xl overflow-visible pb-8 pl-8 pr-4 pt-8 sm:pl-10 sm:pr-6 sm:pt-10 lg:pl-12"
        >
          <div className="grid items-start gap-8 overflow-visible lg:grid-cols-[minmax(0,52rem)_1px_minmax(192px,208px)] lg:gap-x-12 xl:gap-x-16">
            <article className="min-w-0">
              <DocsArticleHeader
                title={title}
                description={description}
                readingTimeMinutes={readingTimeMinutes}
                parent={parent}
                actions={headerActions}
              />
              <div className="min-w-0 overflow-x-hidden">
                {children}
                <DocsFeedback />
              </div>
            </article>
            <div
              aria-hidden
              className="hidden w-px self-stretch bg-border lg:block"
            />
            <DocsToc items={toc} />
          </div>
        </div>
      </div>
    </div>
  )
}
