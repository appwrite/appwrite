'use client'

import type { Components } from 'react-markdown'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { parseDocsPagePath } from '@/lib/marketing/urls'
import { cn } from '@/lib/utils'

const INLINE_CODE_CLASS =
  'rounded-sm bg-muted px-1 py-0.5 font-mono text-[0.92em] text-foreground ring-1 ring-border/60'

function isExternalDomainLink(href?: string): boolean {
  if (!href) return false

  if (
    href.startsWith('mailto:') ||
    href.startsWith('tel:') ||
    href.startsWith('#')
  ) {
    return false
  }

  if (parseDocsPagePath(href)) {
    return false
  }

  try {
    if (typeof window === 'undefined') {
      return /^https?:\/\//i.test(href) || href.startsWith('//')
    }

    const current = new URL(window.location.href)
    const resolved = new URL(href, current)

    return (
      (resolved.protocol === 'http:' || resolved.protocol === 'https:') &&
      resolved.hostname !== current.hostname
    )
  } catch {
    return false
  }
}

const METADATA_MARKDOWN_COMPONENTS: Components = {
  p: ({ children }) => <>{children}</>,
  a: ({ href, children, ...props }) => {
    if (href && parseDocsPagePath(href)) {
      return (
        <DocsRouteLink
          href={href}
          className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
        >
          {children}
        </DocsRouteLink>
      )
    }

    const openInNewWindow = isExternalDomainLink(href)

    return (
      <a
        href={href}
        target={openInNewWindow ? '_blank' : undefined}
        rel={openInNewWindow ? 'noopener noreferrer' : undefined}
        className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
        {...props}
      >
        {children}
      </a>
    )
  },
  strong: ({ children }) => (
    <strong className="font-semibold text-foreground">{children}</strong>
  ),
  em: ({ children }) => <em className="text-foreground/90">{children}</em>,
  code: ({ className, children, ...props }) => {
    if (className) {
      return (
        <code className={cn(INLINE_CODE_CLASS, className)} {...props}>
          {children}
        </code>
      )
    }

    return (
      <code className={INLINE_CODE_CLASS} {...props}>
        {children}
      </code>
    )
  },
}

type MetadataTextMarkdownProps = {
  content: string
  className?: string
}

/** Compact inline markdown for API metadata copy (auth, rate limits, etc.). */
export function MetadataTextMarkdown({
  content,
  className,
}: MetadataTextMarkdownProps) {
  return (
    <div className={className}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={METADATA_MARKDOWN_COMPONENTS}
        allowedElements={[
          'p',
          'a',
          'strong',
          'em',
          'code',
          'del',
          'br',
        ]}
        unwrapDisallowed
      >
        {content}
      </ReactMarkdown>
    </div>
  )
}
