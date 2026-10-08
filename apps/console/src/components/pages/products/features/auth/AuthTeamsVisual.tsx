import { ShieldCheck, Users } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { MockPermissionChip } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'

const MEMBERS = [
  { name: "Walter O'Brien", email: 'walter@acme.io', role: 'Owner', variant: 'info' },
  { name: 'Paige Dineen', email: 'paige@acme.io', role: 'Developer', variant: 'success' },
  { name: 'Happy Quinn', email: 'happy@acme.io', role: 'Member', variant: 'inactive' },
] as const

export function AuthTeamsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-4">
      <ArtPanel className="mx-auto w-fit" innerClassName="product-tone-shadow flex items-center gap-2.5 px-3.5 py-2.5" delayMs={60}>
        <ArtIconBadge icon={Users} className="size-8" />
        <div>
          <p className="text-[13px] font-semibold text-foreground">Acme Engineering</p>
          <p className="text-[11px] text-muted-foreground">{t('3 members · Owner access')}</p>
        </div>
      </ArtPanel>

      <div className="relative mx-auto h-10 w-2/3" aria-hidden>
        <span className="absolute start-1/2 top-0 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute inset-x-0 top-1/2 border-t border-dashed border-foreground/25" />
        <span className="absolute start-0 top-1/2 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute start-1/2 top-1/2 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute end-0 top-1/2 h-1/2 border-e border-dashed border-foreground/25" />
      </div>

      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        {MEMBERS.map((member, index) => (
          <ArtPanel
            key={member.email}
            delayMs={300 + index * 140}
            float
            floatDelayMs={index * 500}
            innerClassName="flex flex-col items-center px-2 py-3 text-center"
          >
            <span className="flex size-9 items-center justify-center rounded-full bg-muted text-[12px] font-semibold text-muted-foreground">
              {member.name.charAt(0)}
            </span>
            <p className="mt-2 w-full truncate text-[12px] font-medium text-foreground">{member.name}</p>
            <p className="w-full truncate text-[10px] text-muted-foreground">{member.email}</p>
            <Badge variant={member.variant} className="mt-2 text-[10px]">
              {t(member.role)}
            </Badge>
          </ArtPanel>
        ))}
      </div>

      <div className="mx-auto h-8 w-0 border-s border-dashed border-foreground/25" aria-hidden />

      <ArtPanel className="mx-auto w-fit max-w-full" innerClassName="px-3.5 py-3" delayMs={800}>
        <div className="flex items-center gap-2">
          <ShieldCheck className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <p dir="ltr" className="font-mono text-[11px] font-semibold text-foreground">documents.reports</p>
        </div>
        <div dir="ltr" className="mt-2 flex flex-wrap gap-1.5">
          {['team:acme/read', 'user:walter/update', 'role:developer/read'].map((label, index) => (
            <span key={label} className="product-hero-rise" style={riseStyle(1000 + index * 120)}>
              <MockPermissionChip label={label} tone={index === 0 ? 'accent' : 'muted'} />
            </span>
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}
