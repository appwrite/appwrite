import type { DatabaseRouteKind } from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'

const PERMISSIONS_COPY: Record<DatabaseRouteKind, string> = {
  tablesdb:
    'Permissions are configured at the table or row level. You can select the permission model for each table in its settings. When Row Level Security (RLS) is enabled, you can also modify permissions per row when updating individual rows.',
  documentsdb:
    'Permissions are configured at the collection or document level. You can select the permission model for each collection in its settings. When document level security is enabled, you can also modify permissions per document when updating individual documents.',
  vectorsdb:
    'Permissions are configured at the table or row level. You can select the permission model for each table in its settings. When Row Level Security (RLS) is enabled, you can also modify permissions per row when updating individual rows.',
}

type DatabasePermissionsCardProps = {
  dbKind: DatabaseRouteKind
}

export function DatabasePermissionsCard({
  dbKind,
}: DatabasePermissionsCardProps) {
  const t = useT()

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Permissions')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(PERMISSIONS_COPY[dbKind])}
        </p>
      </div>
    </div>
  )
}
