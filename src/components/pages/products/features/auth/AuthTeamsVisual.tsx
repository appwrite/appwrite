import { ShieldCheck, Users } from 'lucide-react'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import {
  MockMemberRow,
  MockPermissionChip,
} from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'

export function AuthTeamsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'users', label: t('Users') },
        { id: 'teams', label: t('Teams'), active: true },
        { id: 'security', label: t('Security') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex items-start justify-between gap-3 rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <div className="flex items-start gap-2.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-background">
              <Users className="size-4 text-muted-foreground" aria-hidden />
            </span>
            <div>
              <p className="text-[13px] font-semibold text-foreground">Acme Engineering</p>
              <p className="text-[11px] text-muted-foreground">
                {t('3 members · Owner access')}
              </p>
            </div>
          </div>
        </div>

        <div className="space-y-2">
          <MockMemberRow
            name="Walter O'Brien"
            email="walter@acme.io"
            role="Owner"
            delayMs={80}
          />
          <MockMemberRow
            name="Paige Dineen"
            email="paige@acme.io"
            role="Developer"
            delayMs={160}
          />
          <MockMemberRow
            name="Happy Quinn"
            email="happy@acme.io"
            role="Member"
            delayMs={240}
          />
        </div>

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <div className="flex items-center gap-2">
            <ShieldCheck className="size-3.5 text-muted-foreground" aria-hidden />
            <p className="text-[11px] font-semibold text-foreground">documents.reports</p>
          </div>
          <div className="mt-2.5 flex flex-wrap gap-1.5">
            <MockPermissionChip label="team:acme/read" tone="accent" />
            <MockPermissionChip label="user:walter/update" />
            <MockPermissionChip label="role:developer/read" />
          </div>
          <p className="mt-2 text-[10px] leading-5 text-muted-foreground">
            {t('Share tables, buckets, and functions without building custom RBAC.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
