import { Globe, Lock } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

function MockHeaderRow({ name, value }: { name: string; value: string }) {
  return (
    <div className="flex items-start gap-2 border-b border-border/70 px-3 py-2 last:border-b-0">
      <span className="w-28 shrink-0 font-mono text-[10px] text-muted-foreground">{name}</span>
      <span className="min-w-0 flex-1 break-all font-mono text-[10px] text-foreground">{value}</span>
    </div>
  )
}

export function FunctionsHttpVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'domains', label: t('Domains'), active: true },
        { id: 'settings', label: t('Settings') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-start gap-2.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-background">
              <Globe className="size-4 text-muted-foreground" aria-hidden />
            </span>
            <div>
              <p className="text-[13px] font-semibold text-foreground">{t('Function domain')}</p>
              <p className="mt-0.5 text-[12px] text-muted-foreground">
                {t('Sync HTTP for APIs and webhooks.')}
              </p>
            </div>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            GET · POST
          </Badge>
        </div>

        <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Generated domain')}
          </p>
          <p className="mt-1 truncate font-mono text-[11px] text-foreground">
            https://65f1a2b3.appwrite.run
          </p>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between gap-2">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Request')}
            </p>
            <span className="rounded-md bg-muted/50 px-2 py-0.5 font-mono text-[10px] text-foreground">
              POST /
            </span>
          </div>
          <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
            <MockHeaderRow name="Content-Type" value="application/json" />
            <MockHeaderRow
              name="x-appwrite-user-jwt"
              value="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…"
            />
          </div>
        </div>

        <div className="flex items-start gap-2 rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <Lock className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Pass a user JWT so Server SDKs inside the function respect Auth permissions.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
