import type { DocsPageData } from '@/lib/docs/types'
import { pageHasDocsPrompt, resolveDocsPagePrompt } from '@/lib/docs/route-prompts'
import { DocsLayout } from './DocsLayout'
import { DocsMarkdown } from './DocsMarkdown'
import { DocsPageHeaderActions } from './DocsPageHeaderActions'
import { DocsPromptBanner } from './DocsPromptBanner'
import { DocsPromptProvider } from './DocsPromptContext'

type ViewProps = {
  page: DocsPageData
}

export function View({ page }: ViewProps) {
  const promptText = resolveDocsPagePrompt(page.meta.slug, page.promptPath)
  const hasPrompt = pageHasDocsPrompt(page.meta.slug, page.promptPath)
  const isPromptOnlyPage =
    page.meta.slug.startsWith('tooling/ai/quickstart-prompts/') &&
    page.meta.slug !== 'tooling/ai/quickstart-prompts'
  const headerActions = (
    <DocsPageHeaderActions slug={page.meta.slug} showCopyPage={!hasPrompt} />
  )

  return (
    <DocsPromptProvider promptText={promptText}>
      <DocsLayout
        slug={page.meta.slug}
        title={page.meta.title}
        description={page.meta.description}
        readingTimeMinutes={page.meta.readingTimeMinutes}
        toc={page.toc}
        headerActions={headerActions}
      >
        {promptText ? (
          <DocsPromptBanner
            prompt={promptText}
            showPromptPreview={!isPromptOnlyPage}
          />
        ) : null}
        <DocsMarkdown content={page.content} />
      </DocsLayout>
    </DocsPromptProvider>
  )
}
