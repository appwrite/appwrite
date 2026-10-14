import { Link } from '@tanstack/react-router'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { resolve, slug as anchor, type Library } from '@/lib/docs'
import { Children, isValidElement, type ReactNode } from 'react'
import { CodeBlock, type Lang } from './Code'

/** Rustdoc's intra-doc links (`` [`Text`] ``) as `rustdoc:` links the renderer resolves. */
function intraDoc(md: string) {
  return md.replace(/\[`([^`\]]+)`\](?![([:])/g, (_, target: string) => `[\`${target}\`](rustdoc:${encodeURIComponent(target)})`)
}

/** The plain text of rendered Markdown children, for heading anchors. */
function plain(children: ReactNode): string {
  return Children.toArray(children)
    .map((c) => (typeof c === 'string' || typeof c === 'number' ? String(c) : isValidElement<{ children?: ReactNode }>(c) ? plain(c.props.children) : ''))
    .join('')
}

const LANGS: Record<string, Lang> = { rust: 'rust', rs: 'rust', php: 'php', toml: 'toml', bash: 'bash', sh: 'bash', shell: 'bash' }

/** Doc text: GitHub Markdown, highlighted code, and links into this library's pages. */
export function Markdown({ text, lib, slug }: { text: string; lib?: Pick<Library, 'items' | 'modules'>; slug?: string }) {
  if (!text.trim()) return null
  return (
    <div className="grid min-w-0 gap-3 text-[14px] leading-[1.7] text-foreground/90 [overflow-wrap:anywhere]">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        urlTransform={(url) => url}
        components={{
          h1: ({ children }) => <h3 className="title mt-4 text-[22px] leading-tight text-foreground">{children}</h3>,
          h2: ({ children }) => (
            <h3 id={anchor(plain(children))} className="title mt-5 scroll-mt-20 text-[20px] leading-tight text-foreground">
              {children}
            </h3>
          ),
          h3: ({ children }) => <h4 className="mt-2 text-base text-foreground">{children}</h4>,
          h4: ({ children }) => <h4 className="mt-2 text-sm font-semibold text-foreground">{children}</h4>,
          p: ({ children }) => <p className="m-0">{children}</p>,
          ul: ({ children }) => <ul className="m-0 grid list-disc gap-1 pl-5">{children}</ul>,
          ol: ({ children }) => <ol className="m-0 grid list-decimal gap-1 pl-5">{children}</ol>,
          table: ({ children }) => (
            <div className="overflow-x-auto border-y border-foreground">
              <table className="w-full border-collapse text-[13px]">{children}</table>
            </div>
          ),
          th: ({ children }) => <th className="label border-b border-foreground px-3 py-2.5 text-left text-muted-foreground">{children}</th>,
          td: ({ children }) => <td className="border-b border-border px-3 py-2 align-top">{children}</td>,
          pre: ({ children }) => <>{children}</>,
          code: ({ className, children }) => {
            const lang = /language-(\w+)/.exec(className ?? '')?.[1]
            const text = String(children).replace(/\n$/, '')
            if (lang || text.includes('\n')) return <CodeBlock code={text} lang={LANGS[lang ?? ''] ?? 'text'} />
            return <code className="bg-muted px-1 py-px font-mono text-[0.85em] text-foreground">{children}</code>
          },
          a: ({ href, children }) => {
            if (href?.startsWith('rustdoc:')) {
              const target = lib && slug ? resolve(lib, decodeURIComponent(href.slice('rustdoc:'.length))) : null
              if (!target || !slug) return <>{children}</>
              return (
                <Link to="/$lib/$" params={{ lib: slug, _splat: target.splat }} hash={target.hash} className="text-foreground underline decoration-highlight-line decoration-1 underline-offset-[3px] transition-colors hover:text-highlight hover:decoration-highlight">
                  {children}
                </Link>
              )
            }
            const className =
              'text-foreground underline decoration-highlight-line decoration-1 underline-offset-[3px] transition-colors hover:text-highlight hover:decoration-highlight'
            if (href?.startsWith('/')) {
              return (
                <Link to={href} className={className}>
                  {children}
                </Link>
              )
            }
            if (href?.startsWith('#')) {
              return (
                <a href={href} className={className}>
                  {children}
                </a>
              )
            }
            return (
              <a href={href} target="_blank" rel="noopener" className={className}>
                {children}
              </a>
            )
          },
        }}
      >
        {intraDoc(text)}
      </ReactMarkdown>
    </div>
  )
}
