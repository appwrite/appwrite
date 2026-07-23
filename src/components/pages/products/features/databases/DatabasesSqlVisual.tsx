import { Terminal } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const SQL_LINES = [
  { tone: 'keyword', text: 'SELECT' },
  { tone: 'plain', text: ' id, email, plan' },
  { tone: 'keyword', text: '\nFROM' },
  { tone: 'plain', text: ' customers' },
  { tone: 'keyword', text: '\nWHERE' },
  { tone: 'plain', text: ' plan = ' },
  { tone: 'string', text: "'pro'" },
  { tone: 'keyword', text: '\nORDER BY' },
  { tone: 'plain', text: ' created_at DESC;' },
] as const

function SqlToken({
  tone,
  text,
}: {
  tone: (typeof SQL_LINES)[number]['tone']
  text: string
}) {
  if (tone === 'keyword') {
    return <span className="text-sky-700 dark:text-sky-300">{text}</span>
  }
  if (tone === 'string') {
    return <span className="text-amber-700 dark:text-amber-300">{text}</span>
  }
  return <span className="text-foreground/90">{text}</span>
}

export function DatabasesSqlVisual() {
  const t = useT()
  return (
    <div className="mx-auto grid max-w-4xl gap-4 sm:grid-cols-2">
      <ProductFeatureVisualFrame contentClassName="space-y-3">
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
            <PostgresElephantIcon className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-foreground">PostgreSQL</p>
            <p className="text-[11px] text-muted-foreground">{t('Extensions, roles, SQL editor')}</p>
          </div>
          <Badge variant="success" className="ms-auto text-[10px] shrink-0">
            {t('Ready')}
          </Badge>
        </div>
        <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
          <div className="flex items-center gap-2 border-b border-border px-3 py-1.5">
            <Terminal className="size-3 text-muted-foreground" aria-hidden />
            <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
              SQL
            </span>
          </div>
          <pre className="overflow-x-auto p-3 font-mono text-[11px] leading-5">
            <code>
              {SQL_LINES.map((token, index) => (
                <SqlToken key={`${token.tone}-${index}`} tone={token.tone} text={token.text} />
              ))}
            </code>
          </pre>
        </div>
      </ProductFeatureVisualFrame>

      <ProductFeatureVisualFrame contentClassName="space-y-3">
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
            <MySQLDolphinIcon className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-foreground">MySQL</p>
            <p className="text-[11px] text-muted-foreground">
              {t('Connections, schemas, and backups')}
            </p>
          </div>
          <Badge variant="success" className="ms-auto text-[10px] shrink-0">
            {t('Ready')}
          </Badge>
        </div>
        <div className="space-y-2">
          {[
            { label: 'Host', value: 'mysql.appwrite.cloud' },
            { label: 'Port', value: '3306' },
            { label: 'Database', value: 'app_production' },
            { label: 'SSL', value: 'Required' },
          ].map((row) => (
            <div
              key={row.label}
              className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2"
            >
              <span className="text-[11px] text-muted-foreground">{t(row.label)}</span>
              <span className="truncate font-mono text-[11px] text-foreground">{row.value}</span>
            </div>
          ))}
        </div>
      </ProductFeatureVisualFrame>
    </div>
  )
}
