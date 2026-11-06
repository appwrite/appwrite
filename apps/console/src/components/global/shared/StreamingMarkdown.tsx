import { useMemo } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { cn } from '@/lib/utils'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import {
  resolveFenceCodeLanguage,
  resolveFenceCodeLabel,
} from '@/lib/code-language'
import { DOCS_TABLE_CELL_TEXT_CLASS } from '@/lib/docs/prose-typography'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

/** Matches docs Markdoc table headers (`MarkdocTableHead`). */
const MARKDOWN_TABLE_HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider whitespace-normal'

interface StreamingMarkdownProps {
  content: string
  className?: string
  deferCodeBlocks?: boolean
}

function normalizeStreamingMarkdown(content: string): string {
  const normalized = content.replace(/\r\n/g, '\n')

  // Streaming responses frequently arrive with an open code fence.
  // We auto-close it for stable rendering while tokens are still arriving.
  const fenceCount = (normalized.match(/```/g) ?? []).length
  if (fenceCount % 2 === 1) {
    return `${normalized}\n\`\`\``
  }

  return normalized
}

function resolveMarkdownCodeLanguage(className?: string): ReturnType<
  typeof resolveFenceCodeLanguage
> {
  const language = className?.match(/language-([a-zA-Z0-9_-]+)/)?.[1]
  return resolveFenceCodeLanguage(language)
}

function isExternalDomainLink(href?: string): boolean {
  if (!href) return false

  // Keep non-web protocols in the same context.
  if (
    href.startsWith('mailto:') ||
    href.startsWith('tel:') ||
    href.startsWith('#')
  ) {
    return false
  }

  try {
    if (typeof window === 'undefined') {
      // Without a browser origin, treat absolute web URLs as external.
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

export function StreamingMarkdown({
  content,
  className,
  deferCodeBlocks = false,
}: StreamingMarkdownProps) {
  const safeContent = useMemo(
    () => normalizeStreamingMarkdown(content || ''),
    [content],
  )

  return (
    <div
      className={cn(
        'text-[13px] leading-relaxed break-words [&_p]:my-0 [&_p+p]:mt-3 [&_h1]:mb-2.5 [&_h1]:mt-3 [&_h1]:text-[16px] [&_h1]:font-semibold [&_h2]:mb-2.5 [&_h2]:mt-3 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h3]:mb-2 [&_h3]:mt-2.5 [&_h3]:text-[14px] [&_h3]:font-semibold [&_ul]:my-2 [&_ul]:list-disc [&_ul]:ps-4 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:ps-4 [&_li]:my-1 prose-links-neutral [&_blockquote]:my-2 [&_blockquote]:border-s-2 [&_blockquote]:border-border [&_blockquote]:ps-3',
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a({ href, children, ...props }) {
            const openInNewWindow = isExternalDomainLink(href)

            return (
              <a
                href={href}
                target={openInNewWindow ? '_blank' : undefined}
                rel={openInNewWindow ? 'noopener noreferrer' : undefined}
                {...props}
              >
                {children}
              </a>
            )
          },
          table({ children }) {
            return (
              <div className="not-prose my-3 w-full overflow-hidden rounded-lg border border-border bg-card/50">
                <Table withScrollContainer>{children}</Table>
              </div>
            )
          },
          thead({ children }) {
            return (
              <TableHeader className="[&_tr]:border-b [&_tr]:border-border [&_tr]:hover:bg-transparent">
                {children}
              </TableHeader>
            )
          },
          tbody({ children }) {
            return <TableBody>{children}</TableBody>
          },
          tr({ children }) {
            return (
              <TableRow className="border-b border-border hover:bg-muted/30">
                {children}
              </TableRow>
            )
          },
          th({ children }) {
            return (
              <TableHead className={MARKDOWN_TABLE_HEAD_CLASS}>
                {children}
              </TableHead>
            )
          },
          td({ children }) {
            return (
              <TableCell
                className={cn(
                  'px-4 py-3 align-top whitespace-normal',
                  DOCS_TABLE_CELL_TEXT_CLASS,
                  '[&_strong]:font-semibold [&_strong]:text-foreground',
                )}
              >
                {children}
              </TableCell>
            )
          },
          hr() {
            return (
              <div
                className="my-3 text-center text-[11px] tracking-[0.35em] text-muted-foreground/80"
                aria-hidden="true"
              >
                •••
              </div>
            )
          },
          img({ src, alt }) {
            if (!src) return null
            return (
              <img
                src={src}
                alt={alt || ''}
                className="my-2 h-auto max-h-64 w-auto max-w-full rounded-md border border-border"
                loading="lazy"
              />
            )
          },
          pre({ children }) {
            return <>{children}</>
          },
          code({ className, children, ...props }) {
            const rawCode = String(children ?? '')

            // Inline code keeps markdown-native style.
            if (!className) {
              return (
                <code
                  dir="ltr"
                  className={cn(
                    FORCE_LTR_CLASS,
                    'rounded-sm bg-muted/60 px-1 py-0.5 text-[12px]',
                    className,
                  )}
                  {...props}
                >
                  {children}
                </code>
              )
            }

            if (deferCodeBlocks) {
              const language = resolveMarkdownCodeLanguage(className)
              return (
                <div
                  dir="ltr"
                  data-code-example
                  className={cn(
                    FORCE_LTR_CLASS,
                    'mt-2 mb-3.5 overflow-hidden rounded-xl border border-border bg-background',
                  )}
                >
                  <div className="flex h-10 items-center border-b border-border px-3">
                    <span className="text-[11px] font-medium text-muted-foreground">
                      {resolveFenceCodeLabel(
                        className?.match(/language-([a-zA-Z0-9_-]+)/)?.[1],
                      )}
                    </span>
                  </div>
                  <pre className="overflow-x-auto p-4 text-[12px] font-mono leading-relaxed">
                    <code>{rawCode.replace(/\n$/, '')}</code>
                  </pre>
                </div>
              )
            }

            return (
              <CodeBlock
                code={rawCode.replace(/\n$/, '')}
                language={resolveMarkdownCodeLanguage(className)}
                copyInside
                showCopy
                showFullscreen
                transparentBackground
                className="mt-2 mb-3.5"
              />
            )
          },
        }}
      >
        {safeContent}
      </ReactMarkdown>
    </div>
  )
}
