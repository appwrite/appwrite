import { Lock } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const ENCRYPTED_FILES = [
  { name: 'contract.pdf', size: '1.2 MB' },
  { name: 'backup.zip', size: '4.8 MB' },
  { name: 'credentials.json', size: '12 KB' },
] as const

export function StorageEncryptionVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'files', label: t('Files') },
        { id: 'settings', label: t('Settings'), active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="px-4 py-3">
            <p className="text-[13px] font-semibold text-foreground">{t('Encryption')}</p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
              {t('Encrypt new uploads at rest so leaked files stay unreadable.')}
            </p>
          </div>
          <div className="border-t border-border px-4 py-3">
            <div className="flex items-center justify-between gap-3">
              <div>
                <p className="text-[12px] font-medium text-foreground">{t('Enabled')}</p>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                  {t('Applies to new files in this bucket')}
                </p>
              </div>
              <Switch
                checked
                disabled
                className="shrink-0 data-[state=checked]:bg-foreground/80"
                aria-hidden
              />
            </div>
          </div>
        </div>

        <div className="overflow-hidden rounded-lg border border-border">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2">
            <Lock className="size-3.5 text-muted-foreground" aria-hidden />
            <span className="text-[11px] font-medium text-foreground">documents</span>
            <Badge variant="success" className="ms-auto text-[10px]">
              {t('Encrypted')}
            </Badge>
          </div>
          <div className="divide-y divide-border">
            {ENCRYPTED_FILES.map((file) => (
              <div
                key={file.name}
                className="flex items-center justify-between gap-3 px-3 py-2 transition-colors group-hover/visual:bg-muted/20"
              >
                <span className="min-w-0 truncate text-[12px] text-foreground">{file.name}</span>
                <span className="shrink-0 text-[11px] text-muted-foreground">{file.size}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
