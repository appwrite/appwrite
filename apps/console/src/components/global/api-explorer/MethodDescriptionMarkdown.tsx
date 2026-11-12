'use client'

import { useMemo } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { resolveFenceCodeLanguage } from '@/lib/code-language'
import {
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_PROSE_WRAPPER_CLASS,
} from '@/lib/docs/prose-typography'
import { parseDocsPagePath } from '@/lib/marketing/urls'
import { cn } from '@/lib/utils'

function normalizeApiDescriptionMarkdown(content: string): string {
  return content.replace(/\r\n/g, '\n').trim()
}

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

type MethodDescriptionMarkdownProps = {
  content: string
  className?: string
  /** `docs` renders like a docs article; `card` is the bordered explorer panel (default). */
  variant?: 'card' | 'docs'
}

const LINK_CLASS = 'link-neutral'

const CARD_WRAPPER_CLASS = cn(
  'rounded-lg border border-border bg-muted/25 px-4 py-3.5',
  'text-[14px] leading-[1.65] tracking-[0.01em] text-foreground/88',
  '[&_p]:my-0 [&_p+p]:mt-3',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_em]:text-foreground/90',
  'prose-links-neutral',
  '[&_code]:rounded-sm [&_code]:bg-background/80 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[12px] [&_code]:text-foreground [&_code]:ring-1 [&_code]:ring-border/60',
  '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5',
  '[&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-5',
  '[&_li]:text-foreground/88',
)

const DOCS_WRAPPER_CLASS = cn(
  DOCS_PROSE_WRAPPER_CLASS,
  'prose-links-neutral min-w-0',
  ...DOCS_PROSE_DETAIL_CLASSES,
)

export function MethodDescriptionMarkdown({
  content,
  className,
  variant = 'card',
}: MethodDescriptionMarkdownProps) {
  const normalized = useMemo(
    () => normalizeApiDescriptionMarkdown(content),
    [content],
  )

  return (
    <div
      className={cn(
        variant === 'docs' ? DOCS_WRAPPER_CLASS : CARD_WRAPPER_CLASS,
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a({ href, children, ...props }) {
            if (href && parseDocsPagePath(href)) {
              return (
                <DocsRouteLink href={href} className={LINK_CLASS}>
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
                className={LINK_CLASS}
                {...props}
              >
                {children}
              </a>
            )
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
              <div className="not-prose my-3 w-full">
                <ConnectCodeExample code={rawCode} language={language} />
              </div>
            )
          },
        }}
      >
        {normalized}
      </ReactMarkdown>
    </div>
  )
}
