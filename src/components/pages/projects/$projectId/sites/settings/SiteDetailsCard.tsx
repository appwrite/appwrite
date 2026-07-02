import type { Models } from '@appwrite.io/console'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'

interface SiteDetailsCardProps {
  site: Models.Site
}

export function SiteDetailsCard({ site }: SiteDetailsCardProps) {
  const t = useT()
  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Details')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Identifiers and timestamps for this site.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-1">
        <p className="text-[13px] text-muted-foreground">
          {t('Site ID:')}{' '}
          <span className="ms-1.5">
            <CopyableId id={site.$id} size="sm" />
          </span>
        </p>
        <p className="text-[13px] text-muted-foreground">
          {t('Created:')}{' '}
          <DateTooltip
            date={new Date(site.$createdAt)}
            showFormattedDate
            className="text-foreground"
          />
        </p>
        <p className="text-[13px] text-muted-foreground">
          {t('Last updated:')}{' '}
          <DateTooltip
            date={new Date(site.$updatedAt || site.$createdAt)}
            showFormattedDate
            className="text-foreground"
          />
        </p>
      </div>
    </div>
  )
}
