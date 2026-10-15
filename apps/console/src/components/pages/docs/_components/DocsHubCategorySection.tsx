import type { ReactNode } from 'react'
import { DocsHeadingLink } from '@/components/pages/docs/markdoc/DocsHeadingLink'
import type { DocsTocItem } from '@/lib/docs/types'
import { DOCS_PAGE_EYEBROW_CLASS } from '@/lib/docs/prose-typography'
import { slugifyHeading } from '@/lib/marketing/slugify'
import { cn } from '@/lib/utils'

type DocsHubCategorySectionProps = {
  title: string
  children: ReactNode
  className?: string
}

export function getHubCategoryTocItem(title: string): DocsTocItem {
  return {
    id: slugifyHeading(title),
    label: title,
    level: 2,
  }
}

export function DocsHubCategorySection({
  title,
  children,
  className,
}: DocsHubCategorySectionProps) {
  const sectionId = slugifyHeading(title)

  return (
    <section className={cn('scroll-mt-24 space-y-4', className)}>
      <h2
        id={sectionId}
        className={cn(
          DOCS_PAGE_EYEBROW_CLASS,
          'group font-aeonik-fono scroll-mt-24',
        )}
      >
        <DocsHeadingLink headingId={sectionId} linkIconSizeClass="size-3.5">
          {title}
        </DocsHeadingLink>
      </h2>
      {children}
    </section>
  )
}
