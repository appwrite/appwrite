import { FileImage, FileText, Trash2, Upload } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const FILES = [
  { name: 'hero-banner.webp', size: '842 KB', icon: FileImage, selected: true },
  { name: 'pricing.pdf', size: '1.2 MB', icon: FileText, selected: true },
  { name: 'team-photo.jpg', size: '3.4 MB', icon: FileImage, selected: false },
] as const

export function StorageBucketsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'files', label: t('Files'), active: true },
        { id: 'settings', label: t('Settings') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">marketing-assets</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">128 {t('files')} · 2.1 GB</p>
          </div>
          <Badge variant="inactive" className="shrink-0 text-[10px]">
            {t('2 selected')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-lg border border-border">
          <div className="flex items-center gap-2 border-b border-border bg-muted/15 px-3 py-2">
            <Checkbox checked="indeterminate" disabled aria-hidden />
            <span className="text-[11px] font-medium text-foreground">{t('Name')}</span>
            <span className="ms-auto text-[11px] text-muted-foreground">{t('Size')}</span>
          </div>
          <div className="divide-y divide-border">
            {FILES.map((file) => {
              const Icon = file.icon
              return (
                <div
                  key={file.name}
                  className="flex items-center gap-2 px-3 py-2 transition-colors group-hover/visual:bg-muted/20"
                >
                  <Checkbox checked={file.selected} disabled aria-hidden />
                  <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                  <span className="min-w-0 truncate text-[12px] text-foreground">{file.name}</span>
                  <span className="ms-auto shrink-0 text-[11px] text-muted-foreground">
                    {file.size}
                  </span>
                </div>
              )
            })}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-[11px] font-medium text-foreground">
            <Upload className="size-3.5" aria-hidden />
            {t('Upload')}
          </div>
          <div className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/30 px-2.5 py-1.5 text-[11px] font-medium text-muted-foreground">
            <Trash2 className="size-3.5" aria-hidden />
            {t('Delete selected')}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
