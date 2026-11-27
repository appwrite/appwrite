import Prism from '@/lib/prism'
import 'prismjs/components/prism-rust'
import 'prismjs/components/prism-markup-templating'
import 'prismjs/components/prism-php'
import 'prismjs/components/prism-toml'
import 'prismjs/components/prism-bash'
import { Check, Copy, Maximize2, Minimize2 } from 'lucide-react'
import { useState } from 'react'
import type { Example } from '@/lib/docs'
import { trackDocsEvent } from '@/lib/analytics'

export type Lang = 'rust' | 'php' | 'toml' | 'bash' | 'text'

const LABEL: Record<Lang, string> = { rust: 'Rust', php: 'PHP', toml: 'Cargo.toml', bash: 'Shell', text: 'Text' }

function highlight(code: string, lang: Lang) {
  const grammar = lang === 'text' ? undefined : Prism.languages[lang]
  if (!grammar) return code.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c] as string)
  return Prism.highlight(code, grammar, lang)
}

export function CopyButton({ text, label = 'Copy', kind = 'snippet' }: { text: string; label?: string; kind?: 'snippet' | 'program' | 'install' }) {
  const [copied, setCopied] = useState(false)
  return (
    <button
      type="button"
      onClick={() => {
        navigator.clipboard
          .writeText(text)
          .then(() => {
            setCopied(true)
            trackDocsEvent('code_copied', { kind })
            setTimeout(() => setCopied(false), 1500)
          })
          .catch(() => undefined)
      }}
      className="inline-flex h-7 items-center gap-1.5 px-2 font-mono text-[11px] whitespace-nowrap text-zinc-500 transition-colors hover:bg-white/5 hover:text-zinc-100"
      aria-label={label}
      title={label}
    >
      {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
      <span className={copied ? '' : 'hidden 2xl:inline'}>{copied ? 'Copied' : label}</span>
    </button>
  )
}

/** A code panel: language, optional title, copy. `program` is what Copy copies (a complete program). */
export function CodeBlock({
  code,
  lang,
  title,
  program,
  note,
}: {
  code: string
  lang: Lang
  title?: string | null
  program?: string
  note?: string
}) {
  const [full, setFull] = useState(false)
  const runnable = program && program.trim() !== code.trim()
  const text = full && program ? program : code
  return (
    <div className="min-w-0 overflow-hidden border border-code-border bg-code">
      <div className="flex items-center gap-2 border-b border-code-border bg-code-header py-1 pr-1 pl-3">
        <span className="flex items-center gap-2 font-mono text-[10px] font-medium tracking-[0.12em] text-code-header-fg uppercase">
          <span className="marker" aria-hidden />
          {LABEL[lang]}
        </span>
        {title ? <span className="truncate border-l border-code-border pl-2 font-mono text-[11px] text-zinc-500">{title}</span> : null}
        <span className="flex-1" />
        {runnable ? (
          <button
            type="button"
            onClick={() => setFull((f) => !f)}
            className="inline-flex h-7 items-center gap-1.5 px-2 font-mono text-[11px] whitespace-nowrap text-zinc-500 transition-colors hover:bg-white/5 hover:text-zinc-100"
            title={full ? 'Show the example' : 'Show the complete program Copy copies'}
          >
            {full ? <Minimize2 className="size-3.5" /> : <Maximize2 className="size-3.5" />}
            <span className="hidden 2xl:inline">{full ? 'Example' : 'Full program'}</span>
          </button>
        ) : null}
        <CopyButton text={program ?? code} label={program ? 'Copy program' : 'Copy'} kind={program ? 'program' : 'snippet'} />
      </div>
      <pre className="code m-0 overflow-x-auto px-5 py-4 font-mono text-[12.5px] leading-[1.75]">
        <code dangerouslySetInnerHTML={{ __html: highlight(text, lang) }} />
      </pre>
      {note ? <div className="border-t border-code-border px-5 py-2 font-mono text-[11px] text-zinc-500">{note}</div> : null}
    </div>
  )
}

/** One example: Rust, and next to it the same in PHP when the docs give it. */
export function ExampleView({ example }: { example: Example }) {
  const rustOnlyNote = example.rust?.attributes.includes('no_run') ? 'Compiles in CI; needs a running service to execute.' : undefined
  return (
    <div className="grid gap-2">
      {example.title ? <div className="text-[13.5px] font-medium">{example.title}</div> : null}
      <div className={`grid min-w-0 gap-3 ${example.php && example.rust ? 'md:grid-cols-2' : ''}`}>
        {example.rust ? <CodeBlock code={example.rust.shown} program={example.rust.program} lang="rust" note={rustOnlyNote} /> : null}
        {example.php ? <CodeBlock code={example.php.shown} program={example.php.program} lang="php" /> : null}
      </div>
    </div>
  )
}

export function Examples({ examples }: { examples: Example[] }) {
  if (!examples.length) return null
  return (
    <div className="grid gap-4">
      {examples.map((e, i) => (
        <ExampleView key={i} example={e} />
      ))}
    </div>
  )
}
