import { ShieldCheck } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { MockPermissionChip } from '@/components/pages/products/features/_components/ProductFeatureMockParts'

export function StoragePermissionsVisual() {
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'files', label: 'Files' },
        { id: 'security', label: 'Security', active: true },
        { id: 'settings', label: 'Settings' },
      ]}
    >
      <div className="space-y-4">
        <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[13px] font-semibold text-foreground">avatars</p>
              <p className="text-[11px] text-muted-foreground">Bucket permissions apply to all files</p>
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
              <p className="text-[12px] font-medium text-foreground">File security</p>
              <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">
                Enable per-file permissions on individual uploads.
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
            <MockPermissionChip label="user:sarah/read" tone="accent" />
            <MockPermissionChip label="user:alex/read" />
          </div>
          <p className="mt-2 text-[10px] leading-5 text-muted-foreground">
            File-level rules override bucket defaults for sensitive assets.
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
