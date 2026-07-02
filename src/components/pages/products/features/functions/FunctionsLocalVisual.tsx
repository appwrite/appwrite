import { RefreshCw, Terminal } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const TERMINAL_LINES = [
  { text: '$ appwrite run functions --function-id stripe-webhook', tone: 'command' as const },
  { text: 'Building function using Docker…', tone: 'muted' as const },
  { text: 'Starting function using Docker…', tone: 'muted' as const },
  { text: 'Visit http://localhost:3000/ to execute your function.', tone: 'success' as const },
] as const

function TerminalLine({ text, tone }: (typeof TERMINAL_LINES)[number]) {
  return (
    <p
      className={
        tone === 'command'
          ? 'text-foreground'
          : tone === 'success'
            ? 'text-emerald-600 dark:text-emerald-400'
            : 'text-muted-foreground'
      }
    >
      {text}
    </p>
  )
}

export function FunctionsLocalVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame eyebrow={t('Local dev')} title="Appwrite CLI"> {/* pragma: allowlist secret */}
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Run on localhost')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Same Docker runtime as production, on your machine.')}
            </p>
          </div>
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('Hot reload')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-muted/20 font-mono text-[10px] leading-relaxed sm:text-[11px]">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-1.5">
            <Terminal className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
            <span className="text-[10px] text-muted-foreground">{t('Terminal')}</span>
          </div>
          <div className="space-y-1.5 p-3">
            {TERMINAL_LINES.map((line) => (
              <TerminalLine key={line.text} {...line} />
            ))}
          </div>
        </div>

        <div className="grid gap-2 sm:grid-cols-2">
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Config')}
            </p>
            <p className="mt-1 font-mono text-[11px] text-foreground">appwrite.config.json</p>
          </div>
          <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Impersonate user')}
            </p>
            <p className="mt-1 font-mono text-[11px] text-foreground">--user-id &lt;id&gt;</p>
          </div>
        </div>

        <div className="flex items-start gap-2 rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <RefreshCw className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Edit your code and the function restarts automatically. Deploy when you are ready.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
