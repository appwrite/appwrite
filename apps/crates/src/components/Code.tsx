import Prism from 'prismjs'
import 'prismjs/components/prism-rust'
import 'prismjs/components/prism-markup-templating'
import 'prismjs/components/prism-php'
import 'prismjs/components/prism-toml'
import 'prismjs/components/prism-bash'
import { Check, Copy, Maximize2, Minimize2 } from 'lucide-react'
import { useState } from 'react'
import type { Example } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

export type Lang = 'rust' | 'php' | 'toml' | 'bash' | 'text'

const LABEL: Record<Lang, string> = { rust: 'Rust', php: 'PHP', toml: 'Cargo.toml', bash: 'Shell', text: 'Text' }

function highlight(code: string, lang: Lang) {
  const grammar = lang === 'text' ? undefined : Prism.languages[lang]
  if (!grammar) return code.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c] as string)
  return Prism.highlight(code, grammar, lang)
}

function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <button
      type="button"
      onClick={() => {
        navigator.clipboard
          .writeText(text)
          .then(() => {
            setCopied(true)
            setTimeout(() => setCopied(false), 1500)
          })
          .catch(() => undefined)
      }}
      className="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs whitespace-nowrap text-zinc-400 transition-colors hover:bg-white/10 hover:text-white"
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
  migration,
}: {
  code: string
  lang: Lang
  title?: string | null
  program?: string
  note?: string
  /** A migration detail (a PHP twin): framed like the other migration cards. */
  migration?: boolean
}) {
  const [full, setFull] = useState(false)
  const runnable = program && program.trim() !== code.trim()
  const text = full && program ? program : code
  return (
    <div className={`min-w-0 overflow-hidden rounded-[14px] border bg-code ${migration ? 'border-migration-border' : 'border-code-border'}`}>
      <div className={`flex items-center gap-2 border-b py-1 pr-1 pl-3 ${migration ? 'border-migration-border bg-migration' : 'border-code-border bg-code-header'}`}>
        <span className={`text-xs font-semibold ${migration ? 'text-migration-ink' : 'text-code-header-fg'}`}>{LABEL[lang]}</span>
        {migration ? <span className="text-[10px] font-semibold tracking-wider text-migration-ink uppercase">migration</span> : null}
        {title ? <span className="truncate text-xs text-zinc-400">{title}</span> : null}
        <span className="flex-1" />
        {runnable ? (
          <button
            type="button"
            onClick={() => setFull((f) => !f)}
            className="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs whitespace-nowrap text-zinc-400 transition-colors hover:bg-white/10 hover:text-white"
            title={full ? 'Show the example' : 'Show the complete program Copy copies'}
          >
            {full ? <Minimize2 className="size-3.5" /> : <Maximize2 className="size-3.5" />}
            <span className="hidden 2xl:inline">{full ? 'Example' : 'Full program'}</span>
          </button>
        ) : null}
        <CopyButton text={program ?? code} label={program ? 'Copy program' : 'Copy'} />
      </div>
      <pre className="code m-0 overflow-x-auto px-3.5 py-3 font-mono text-[12.5px] leading-[1.6]">
        <code dangerouslySetInnerHTML={{ __html: highlight(text, lang) }} />
      </pre>
      {note ? <div className="border-t border-code-border px-3.5 py-1.5 text-[11px] text-zinc-400">{note}</div> : null}
    </div>
  )
}

/** One example: Rust, and next to it PHP when the comparison is on. */
export function ExampleView({ example }: { example: Example }) {
  const migration = useMigration()
  const showPhp = migration && example.php
  const rustOnlyNote = example.rust?.attributes.includes('no_run') ? 'Compiles in CI; needs a running service to execute.' : undefined
  return (
    <div className="grid gap-2">
      {example.title ? <div className="text-[13px] font-medium">{example.title}</div> : null}
      <div className={`grid min-w-0 gap-3 ${showPhp && example.rust ? 'md:grid-cols-2' : ''}`}>
        {example.rust ? <CodeBlock code={example.rust.shown} program={example.rust.program} lang="rust" note={rustOnlyNote} /> : null}
        {showPhp && example.php ? <CodeBlock code={example.php.shown} program={example.php.program} lang="php" migration /> : null}
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
