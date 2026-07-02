import { ShieldCheck } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { MockPermissionChip } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'

export function StoragePermissionsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'files', label: t('Files') },
        { id: 'security', label: t('Security'), active: true },
        { id: 'settings', label: t('Settings') },
      ]}
    >
      <div className="space-y-4">
        <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[13px] font-semibold text-foreground">avatars</p>
              <p className="text-[11px] text-muted-foreground">{t('Bucket permissions apply to all files')}</p>
            </div>
            <ShieldCheck className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          </div>
          <div className="mt-2.5 flex flex-wrap gap-1.5">
            <MockPermissionChip label="team:design/read" tone="accent" />
            <MockPermissionChip label="team:design/create" />
            <MockPermissionChip label="role:developer/update" />
          </div>
        </div>

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-[12px] font-medium text-foreground">{t('File security')}</p>
              <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">
                {t('Enable per-file permissions on individual uploads.')}
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

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <p className="text-[11px] font-semibold text-foreground">profile-128.webp</p>
          <div className="mt-2.5 flex flex-wrap gap-1.5">
            <MockPermissionChip label="user:paige/read" tone="accent" />
            <MockPermissionChip label="user:toby/read" />
          </div>
          <p className="mt-2 text-[10px] leading-5 text-muted-foreground">
            {t('File-level rules override bucket defaults for sensitive assets.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
