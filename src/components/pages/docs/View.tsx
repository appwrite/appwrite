import { Copy, ExternalLink } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import type { DocsPageData } from '@/lib/docs/types'
import { DocsLayout } from './DocsLayout'
import { DocsMarkdown } from './DocsMarkdown'

type ViewProps = {
  page: DocsPageData
}

export function View({ page }: ViewProps) {
  const [copying, setCopying] = useState(false)
  const markdownUrl = page.meta.slug ? `/docs/${page.meta.slug}.md` : '/docs.md'

  const handleCopyMarkdown = async () => {
    setCopying(true)
    try {
      const response = await fetch(markdownUrl)
      const text = await response.text()
      await navigator.clipboard.writeText(text)
      toast.success('Copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    } finally {
      setCopying(false)
    }
  }

  const headerActions = (
    <>
      <Button
        variant="outline"
        size="sm"
        className="h-9 text-[13px]"
        onClick={handleCopyMarkdown}
        disabled={copying}
      >
        <Copy className="mr-1.5 size-3.5" />
        Copy
      </Button>
      <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
        <a href={markdownUrl} target="_blank" rel="noopener noreferrer">
          <ExternalLink className="mr-1.5 size-3.5" />
          Raw
        </a>
      </Button>
    </>
  )

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
