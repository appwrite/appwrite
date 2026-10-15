'use client'

import { Link2 } from 'lucide-react'
import type { MouseEvent, ReactNode } from 'react'
import { DOCS_HEADING_LINK_TEXT_CLASS } from '@/lib/docs/prose-link'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'

type DocsHeadingLinkProps = {
  headingId: string
  linkIconSizeClass: string
  children: ReactNode
}

function buildHeadingUrl(headingId: string) {
  return `${window.location.origin}${window.location.pathname}${window.location.search}#${headingId}`
}

export function DocsHeadingLink({
  headingId,
  linkIconSizeClass,
  children,
}: DocsHeadingLinkProps) {
  const handleCopyLink = async (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    await copyToClipboard('Link', buildHeadingUrl(headingId))
  }

  return (
    <span className="inline-flex max-w-full items-center gap-2">
      <a
        href={`#${headingId}`}
        className={cn(
          DOCS_HEADING_LINK_TEXT_CLASS,
          'focus:outline-none focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        )}
      >
        {children}
      </a>
      <button
        type="button"
        onClick={handleCopyLink}
        className={cn(
          linkIconSizeClass,
          'inline-flex shrink-0 cursor-pointer items-center justify-center rounded-sm text-muted-foreground opacity-0 transition-opacity duration-150 hover:text-foreground focus:outline-none focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background group-hover:opacity-100 group-focus-within:opacity-100',
        )}
        aria-label="Copy link"
      >
        <Link2 className="size-full -rotate-45" aria-hidden />
      </button>
    </span>
  )
}
