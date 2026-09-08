import { Copy, Server } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const COMPATIBLE_TOOLS = [
  { id: 'rclone', name: 'rclone', iconSrc: '/icons/rclone.svg' },
  { id: 'terraform', name: 'Terraform', iconSrc: '/icons/terraform.svg' },
  { id: 'aws-cli', name: 'AWS CLI', iconSrc: '/icons/amazon.svg' },
  { id: 'cyberduck', name: 'Cyberduck', iconSrc: '/icons/cyberduck.svg' },
] as const

function MockCredentialRow({
  label,
  value,
  masked = false,
}: {
  label: string
  value: string
  masked?: boolean
}) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t(label)}
      </p>
      <div className="mt-1.5 flex items-center gap-2">
        <p className="min-w-0 flex-1 truncate font-mono text-[11px] text-foreground">
          {masked ? value.replace(/./g, '•') : value}
        </p>
        <Copy className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      </div>
    </div>
  )
}

export function StorageS3Visual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'app', label: t('App') },
        { id: 'server', label: t('Server') },
        { id: 's3', label: 'S3', active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-start gap-2.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-background">
              <Server className="size-4 text-muted-foreground" aria-hidden />
            </span>
            <div>
              <p className="text-[13px] font-semibold text-foreground">{t('S3-compatible access')}</p>
              <p className="mt-0.5 text-[12px] text-muted-foreground">
                {t('Project-scoped endpoint with SigV4 signing.')}
              </p>
            </div>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            SigV4
          </Badge>
        </div>

        <div className="space-y-2">
          <MockCredentialRow
            label={t('Endpoint')}
            value="https://cloud.appwrite.io/v1/s3"
          />
          <MockCredentialRow label={t('Access key')} value="AKIA4EXAMPLEKEY" masked />
          <MockCredentialRow label={t('Secret key')} value="wJalrXUtnFEMIEXAMPLE" masked />
        </div>

        <div>
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Works with')}
          </p>
          <div className="grid grid-cols-4 gap-1.5">
            {COMPATIBLE_TOOLS.map((tool) => (
              <div
                key={tool.id}
                className="flex min-w-0 items-center gap-1.5 rounded-lg border border-border bg-background/80 px-2 py-1.5 transition-colors group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/40"
              >
                <span className="flex size-6 shrink-0 items-center justify-center rounded-md border border-border bg-muted/30">
                  <ProductFeaturePublicIcon src={tool.iconSrc} className="size-3.5" />
                </span>
                <span className="min-w-0 truncate text-[10px] font-medium text-foreground">
                  {tool.name}
                </span>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Attach Storage to existing object-storage pipelines without rebuilding upload flows.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
