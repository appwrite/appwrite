import { useState } from 'react'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import {
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ZERO_CLI_OUTPUT,
  ZERO_MCP_OUTPUT,
  zeroEntries,
  zeroResponse,
  type ZeroEntry,
} from '@/lib/zero/marketing-content'

function ZeroResponse() {
  const t = useT()
  return (
    <code className="block">
      <span className="text-muted-foreground">{'{'}</span>
      {zeroResponse.map((field, index) => (
        <span key={field.key} className="block truncate ps-4">
          <span className="text-[var(--tone-ink)]">"{field.key}"</span>
          <span className="text-muted-foreground">: </span>
          <span>"{field.value}"</span>
          {index < zeroResponse.length - 1 ? (
            <span className="text-muted-foreground">,</span>
          ) : null}
          {field.note ? (
            <span className="ms-3 font-sans text-muted-foreground">
              {'// '}
              <bdi>{t(field.note)}</bdi>
            </span>
          ) : null}
        </span>
      ))}
      <span className="text-muted-foreground">{'}'}</span>
    </code>
  )
}

function ZeroOutput({ entry }: { entry: ZeroEntry['id'] }) {
  if (entry === 'http') return <ZeroResponse />
  const output = entry === 'cli' ? ZERO_CLI_OUTPUT : ZERO_MCP_OUTPUT
  return (
    <code className="block">
      <span className="block text-muted-foreground">
        {entry === 'cli'
          ? '$ npx appwrite zero'
          : '→ appwrite_create_zero_project'}
      </span>
      {output.split('\n').map((line, index) => (
        <span
          key={index}
          className={cn(
            'block min-h-[1.7em]',
            entry === 'cli'
              ? 'overflow-hidden text-ellipsis whitespace-pre'
              : 'whitespace-pre-wrap [overflow-wrap:anywhere]',
            index === 0 &&
              entry === 'cli' &&
              'text-emerald-600 dark:text-emerald-400',
          )}
        >
          {line}
        </span>
      ))}
    </code>
  )
}

/** Pick CLI, HTTP or MCP: what you send, and the same project coming back. */
export function ZeroWaysInVisual() {
  const t = useT()
  const [selected, setSelected] = useState<ZeroEntry['id']>('cli')
  const entry =
    zeroEntries.find((item) => item.id === selected) ?? zeroEntries[0]

  return (
    <div className="relative mx-auto grid w-full max-w-[560px] gap-4">
      <ArtPanel innerClassName="p-3.5">
        <div
          role="tablist"
          aria-label={t('Three ways in')}
          className="inline-flex rounded-lg border border-border bg-muted/40 p-0.5"
        >
          {zeroEntries.map((item) => (
            <button
              key={item.id}
              id={`zero-entry-${item.id}`}
              type="button"
              role="tab"
              aria-selected={item.id === selected}
              aria-controls="zero-entry-panel"
              className={cn(
                'h-7 rounded-md px-3 text-[12px] font-medium transition-colors',
                item.id === selected
                  ? 'bg-background text-foreground shadow-sm dark:bg-card'
                  : 'text-muted-foreground hover:text-foreground',
              )}
              onClick={() => setSelected(item.id)}
            >
              {item.label}
            </button>
          ))}
        </div>

        <div
          id="zero-entry-panel"
          role="tabpanel"
          aria-labelledby={`zero-entry-${selected}`}
        >
          <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
            {t(entry.summary)}
          </p>
          <div
            dir="ltr"
            className="mt-3 flex items-start gap-2 rounded-md border border-border bg-muted/20 py-1 ps-3 pe-1"
          >
            <pre className="min-w-0 flex-1 overflow-x-auto py-1.5 text-start font-mono text-[12px] leading-5 text-foreground">
              <code>{entry.call}</code>
            </pre>
            <div
              {...analyticsAttrs(
                entry.id === 'cli' ? 'zero-copy-command' : 'zero-copy-entry',
              )}
            >
              <CodeSnippetCopyButton content={entry.call} />
            </div>
          </div>
        </div>
      </ArtPanel>

      <ArtWindow
        key={selected}
        title={selected === 'http' ? t('Response') : t('Output')}
        className="product-hero-rise sm:ms-10"
        style={riseStyle(150)}
        bodyClassName="p-3.5"
      >
        <pre
          dir="ltr"
          className="overflow-hidden text-start font-mono text-[11px] leading-[1.7] text-foreground"
        >
          <ZeroOutput entry={selected} />
        </pre>
      </ArtWindow>
    </div>
  )
}
