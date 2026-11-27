import {
  Building2,
  CheckCircle2,
  ChevronDown,
  Globe,
  Link2,
} from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { zeroClaimRows } from '@/lib/zero/marketing-content'

/** The claim screen: pick an organization, keep everything the project already has. */
export function ZeroClaimVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[460px] pt-6">
      <ArtChip className="-top-1 end-2 sm:-end-6" delayMs={700}>
        <span className="flex items-center gap-1.5 text-[11px] text-foreground">
          <Link2 className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          {t('Claim link')}
        </span>
      </ArtChip>

      <ArtPanel innerClassName="p-4">
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Building2} />
          <p className="text-[13px] font-semibold text-foreground">
            {t('Claim project')}
          </p>
          <Badge variant="active" className="ms-auto text-[10px]">
            {t('Active')}
          </Badge>
        </div>

        <p className="mt-4 text-[11px] text-muted-foreground">
          {t('Organization')}
        </p>
        <div className="mt-1.5 flex h-9 items-center justify-between rounded-md border border-border bg-muted/30 px-3 text-[13px] text-foreground">
          {t('Personal projects')}
          <ChevronDown className="size-4 text-muted-foreground" aria-hidden />
        </div>

        <ul className="mt-4 divide-y divide-border rounded-lg border border-border">
          {zeroClaimRows.map((row, index) => (
            <li
              key={row.label}
              className="product-hero-rise flex min-w-0 items-center gap-3 px-3 py-2.5"
              style={riseStyle(250 + index * 150)}
            >
              <span className="w-20 shrink-0 text-[11px] text-muted-foreground">
                {t(row.label)}
              </span>
              <code
                dir="ltr"
                className="min-w-0 flex-1 truncate text-start font-mono text-[11px] text-foreground"
              >
                {row.value}
              </code>
              {row.label === 'Domains' ? (
                <Badge variant="success" className="gap-1 text-[10px]">
                  <Globe className="size-3" aria-hidden />
                  {t('Public')}
                </Badge>
              ) : (
                <CheckCircle2
                  className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                  aria-hidden
                />
              )}
            </li>
          ))}
        </ul>

        <div
          className="mt-4 flex h-9 items-center justify-center rounded-md bg-[var(--brand-cta)] text-[13px] font-medium text-white"
          aria-hidden
        >
          {t('Claim project')}
        </div>
      </ArtPanel>
    </div>
  )
}
