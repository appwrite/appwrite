import type { DocsPageData } from '@/lib/docs/types'
import { getDocsPage } from '@/lib/docs/content'
import { DOCS_CONTENT_HMR_EVENT } from '@/lib/docs/docs-content-hmr-runtime'
import { pageHasDocsPrompt, resolveDocsPagePrompt } from '@/lib/docs/route-prompts'
import { useEffect, useRef, useState } from 'react'
import { DocsLayout } from './DocsLayout'
import { DocsMarkdown } from './DocsMarkdown'
import { DocsPageHeaderActions } from './DocsPageHeaderActions'
import { DocsPromptBanner } from './DocsPromptBanner'
import { DocsPromptProvider } from './DocsPromptContext'

type ViewProps = {
  page: DocsPageData
}

function readDocsScrollTop(): number {
  const main = document.getElementById('main-content')
  if (main) return main.scrollTop
  return window.scrollY
}

function writeDocsScrollTop(scrollTop: number) {
  const main = document.getElementById('main-content')
  if (main) {
    main.scrollTop = scrollTop
    return
  }
  window.scrollTo({ top: scrollTop, behavior: 'auto' })
}

function isSameDocsPage(a: DocsPageData, b: DocsPageData): boolean {
  return (
    a.meta.slug === b.meta.slug &&
    a.content === b.content &&
    a.rawContent === b.rawContent &&
    a.meta.title === b.meta.title &&
    a.meta.description === b.meta.description
  )
}

export function View({ page: initialPage }: ViewProps) {
  const [page, setPage] = useState(initialPage)
  const pageRef = useRef(page)
  pageRef.current = page

  useEffect(() => {
    if (isSameDocsPage(pageRef.current, initialPage)) return
    setPage(initialPage)
  }, [initialPage])

  useEffect(() => {
    if (!import.meta.env.DEV) return

    let cancelled = false

    const refresh = () => {
      const scrollTop = readDocsScrollTop()
      void getDocsPage(initialPage.meta.slug).then((next) => {
        if (cancelled || !next) return
        if (isSameDocsPage(pageRef.current, next)) return
        setPage(next)
        // Restore scroll after layout so content swap does not jump to the bottom.
        requestAnimationFrame(() => {
          writeDocsScrollTop(scrollTop)
          requestAnimationFrame(() => writeDocsScrollTop(scrollTop))
        })
      })
    }

    window.addEventListener(DOCS_CONTENT_HMR_EVENT, refresh)
    return () => {
      cancelled = true
      window.removeEventListener(DOCS_CONTENT_HMR_EVENT, refresh)
    }
  }, [initialPage.meta.slug])

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
