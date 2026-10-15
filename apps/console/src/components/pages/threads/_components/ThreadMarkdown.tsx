'use client'

import { Link } from '@tanstack/react-router'
import { useMemo } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkBreaks from 'remark-breaks'
import remarkGfm from 'remark-gfm'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { resolveFenceCodeLanguage } from '@/lib/code-language'
import {
  THREAD_PROSE_DETAIL_CLASSES,
  THREAD_PROSE_WRAPPER_CLASS,
} from '@/lib/docs/prose-typography'
import {
  isDiscordEmojiImageUrl,
  prepareThreadMessageForMarkdown,
} from '@/lib/threads/markdown'
import type { ThreadMentionLookup } from '@/lib/threads/types'
import { cn } from '@/lib/utils'

type ThreadMarkdownProps = {
  content: string
  className?: string
  mentionLookup?: ThreadMentionLookup
}

function isExternalLink(href?: string): boolean {
  if (!href) return false
  if (href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#')) {
    return false
  }
  if (href.startsWith('/')) return false
  return /^https?:\/\//i.test(href) || href.startsWith('//')
}

function sanitizeUserMessageHref(href?: string): string | undefined {
  const trimmed = href?.trim()
  if (!trimmed) return undefined

  if (
    trimmed.startsWith('/') ||
    trimmed.startsWith('#') ||
    /^https?:\/\//i.test(trimmed) ||
    /^mailto:/i.test(trimmed)
  ) {
    return trimmed
  }

  return undefined
}

function ThreadMarkdownLink({
  href,
  children,
  ...props
}: {
  href?: string
  children?: React.ReactNode
}) {
  const safeHref = sanitizeUserMessageHref(href)
  if (!safeHref) {
    return <span>{children}</span>
  }

  const authorMatch = safeHref.match(/^\/threads\/authors\/([^/?#]+)$/)
  if (authorMatch) {
    return (
      <Link
        to="/threads/authors/$authorId"
        params={{ authorId: authorMatch[1] }}
        {...props}
      >
        {children}
      </Link>
    )
  }

  const threadMatch = safeHref.match(/^\/threads\/([^/?#]+)$/)
  if (threadMatch) {
    return (
      <Link
        to="/threads/$threadId"
        params={{ threadId: threadMatch[1] }}
        {...props}
      >
        {children}
      </Link>
    )
  }

  const external = isExternalLink(safeHref)
  return (
    <a
      href={safeHref}
      {...props}
      rel="nofollow ugc noopener noreferrer"
      {...(external ? { target: '_blank' } : {})}
    >
      {children}
    </a>
  )
}

export function ThreadMarkdown({
  content,
  className,
  mentionLookup,
}: ThreadMarkdownProps) {
  const preparedContent = useMemo(
    () => prepareThreadMessageForMarkdown(content, mentionLookup),
    [content, mentionLookup],
  )

  return (
    <div
      className={cn(
        THREAD_PROSE_WRAPPER_CLASS,
        ...THREAD_PROSE_DETAIL_CLASSES,
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm, remarkBreaks]}
        skipHtml
        components={{
          a: ThreadMarkdownLink,
          img({ src, alt }) {
            if (isDiscordEmojiImageUrl(src)) {
              return (
                <img
                  src={src}
                  alt={alt ?? ''}
                  className="inline-block h-5 w-5 align-text-bottom"
                  loading="lazy"
                  decoding="async"
                />
              )
            }

            return alt ? (
              <span className="text-muted-foreground">[Image: {alt}]</span>
            ) : null
          },
          pre({ children }) {
            return <>{children}</>
          },
          code({ className: codeClassName, children, ...props }) {
            if (!codeClassName) {
              return <code {...props}>{children}</code>
            }

            const rawCode = String(children ?? '').replace(/\n$/, '')
            const language = resolveFenceCodeLanguage(
              codeClassName.match(/language-([a-zA-Z0-9_-]+)/)?.[1],
            )

            return (
              <div className="not-prose my-4 w-full">
                <ConnectCodeExample code={rawCode} language={language} />
              </div>
            )
          },
        }}
      >
        {preparedContent}
      </ReactMarkdown>
    </div>
  )
}
