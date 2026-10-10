import { getRouteApi } from '@tanstack/react-router'
import type { PhpSymbol } from '@/lib/docs'
import { phpDescription, sourceUrl } from '@/lib/docs'
import { CodeBlock } from './Code'
import { MigrationCard } from './Migration'
import { Markdown } from './Markdown'

const root = getRouteApi('__root__')

/** The PHP method or class a Rust item ports, shown when the comparison is on. */
export function PhpCounterpart({ symbols }: { symbols: PhpSymbol[] }) {
  const index = root.useLoaderData()
  if (!symbols.length) return null
  return (
    <MigrationCard title="PHP counterpart">
      {symbols.map((p) => (
        <div key={p.symbol} className="grid min-w-0 gap-2">
          <div className="font-mono text-[12px] text-muted-foreground [overflow-wrap:anywhere]">{p.symbol}</div>
          <CodeBlock code={p.signature} lang="php" />
          {p.doc ? <Markdown text={phpDescription(p.doc)} /> : null}
          {p.file ? (
            <a href={sourceUrl(index?.branch ?? index?.commit, p.file, p.line)} target="_blank" rel="noopener" className="text-[12px] text-muted-foreground hover:text-foreground">
              {p.file}:{p.line}
            </a>
          ) : null}
        </div>
      ))}
    </MigrationCard>
  )
}
