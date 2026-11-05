'use client'

import { Copy, ExternalLink } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'

type DocsPageHeaderActionsProps = {
  slug: string
  buttonClassName?: string
  /** Hide Copy/Raw when the page has an AI quick-start prompt banner. */
  showCopyPage?: boolean
}

export function DocsPageHeaderActions({
  slug,
  buttonClassName = 'h-9 text-[13px]',
  showCopyPage = true,
}: DocsPageHeaderActionsProps) {
  const [copying, setCopying] = useState(false)
  const markdownUrl = slug ? `/docs/${slug}.md` : '/docs.md'

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

  return (
    <>
      {showCopyPage ? (
        <>
          <Button
            variant="outline"
            size="sm"
            className={buttonClassName}
            onClick={handleCopyMarkdown}
            disabled={copying}
          >
            <Copy className="me-1.5 size-3.5" />
            Copy
          </Button>
          <Button variant="outline" size="sm" className={buttonClassName} asChild>
            <a href={markdownUrl} target="_blank" rel="noopener noreferrer">
              <ExternalLink className="me-1.5 size-3.5" />
              Raw
            </a>
          </Button>
        </>
      ) : null}
    </>
  )
}
