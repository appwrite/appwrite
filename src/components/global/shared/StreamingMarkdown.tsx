import { useMemo } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { cn } from '@/lib/utils'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'

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

function normalizeCodeLanguage(className?: string): CodeBlockLanguage {
  const language = className?.match(/language-([a-zA-Z0-9_-]+)/)?.[1]
  if (!language) return 'plaintext'

  const normalized = language.toLowerCase()
  const aliases: Record<string, CodeBlockLanguage> = {
    js: 'javascript',
    ts: 'typescript',
    shell: 'bash',
    sh: 'bash',
    zsh: 'bash',
    yml: 'markup',
    yaml: 'markup',
    html: 'markup',
    xml: 'markup',
    md: 'plaintext',
    text: 'plaintext',
  }

  const resolved = aliases[normalized] ?? normalized
  const supported = new Set<CodeBlockLanguage>([
    'javascript',
    'typescript',
    'json',
    'dart',
    'swift',
    'kotlin',
    'java',
    'bash',
    'powershell',
    'php',
    'python',
    'ruby',
    'go',
    'csharp',
    'markup',
    'plaintext',
    'env',
    'node',
    'deno',
    'bun',
    'dotnet',
  ])

  return supported.has(resolved as CodeBlockLanguage)
    ? (resolved as CodeBlockLanguage)
    : 'plaintext'
}

function formatCodeLanguageLabel(language: CodeBlockLanguage): string {
  const labels: Partial<Record<CodeBlockLanguage, string>> = {
    javascript: 'JavaScript',
    typescript: 'TypeScript',
    json: 'JSON',
    dart: 'Dart',
    swift: 'Swift',
    kotlin: 'Kotlin',
    java: 'Java',
    bash: 'Bash',
    powershell: 'PowerShell',
    php: 'PHP',
    python: 'Python',
    ruby: 'Ruby',
    go: 'Go',
    csharp: 'C#',
    markup: 'Markup',
    plaintext: 'Plain text',
    env: '.env',
    node: 'Node.js',
    deno: 'Deno',
    bun: 'Bun',
    dotnet: '.NET',
  }

  return labels[language] ?? language
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
        'text-[13px] leading-snug break-words [&_p]:my-0 [&_p+p]:mt-2.5 [&_h1]:mb-2 [&_h1]:mt-2.5 [&_h1]:text-[16px] [&_h1]:font-semibold [&_h2]:mb-2 [&_h2]:mt-2.5 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h3]:mb-1.5 [&_h3]:mt-2 [&_h3]:text-[14px] [&_h3]:font-semibold [&_ul]:my-1.5 [&_ul]:list-disc [&_ul]:pl-4 [&_ol]:my-1.5 [&_ol]:list-decimal [&_ol]:pl-4 [&_li]:my-0.5 [&_a]:text-primary [&_a]:underline [&_blockquote]:my-1.5 [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-2.5 [&_table]:my-1.5 [&_table]:w-full [&_table]:border-collapse [&_th]:border [&_th]:border-border [&_th]:px-1.5 [&_th]:py-1 [&_th]:text-left [&_td]:border [&_td]:border-border [&_td]:px-1.5 [&_td]:py-1',
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
          pre({ children }) {
            return <>{children}</>
          },
          code({ className, children, ...props }) {
            const rawCode = String(children ?? '')

            // Inline code keeps markdown-native style.
            if (!className) {
              return (
                <code
                  className={cn(
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
              const language = normalizeCodeLanguage(className)
              return (
                <div className="mt-2 mb-3.5 overflow-hidden rounded-xl border border-border bg-background">
                  <div className="flex h-10 items-center border-b border-border px-3">
                    <span className="text-[11px] font-medium text-muted-foreground">
                      {formatCodeLanguageLabel(language)}
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
                language={normalizeCodeLanguage(className)}
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
