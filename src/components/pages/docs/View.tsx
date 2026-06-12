import type { DocsPageData } from '@/lib/docs/types'
import { DocsLayout } from './DocsLayout'
import { DocsMarkdown } from './DocsMarkdown'
import { DocsPageHeaderActions } from './DocsPageHeaderActions'

type ViewProps = {
  page: DocsPageData
}

export function View({ page }: ViewProps) {
  const headerActions = <DocsPageHeaderActions slug={page.meta.slug} />

  return (
    <DocsLayout
      slug={page.meta.slug}
      title={page.meta.title}
      description={page.meta.description}
      readingTimeMinutes={page.meta.readingTimeMinutes}
      toc={page.toc}
      headerActions={headerActions}
    >
      <DocsMarkdown content={page.content} />
    </DocsLayout>
  )
}
