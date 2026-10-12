import type { LucideIcon } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { formatDedicatedDatabaseRegionUnavailableDescription } from '@/lib/databases/dedicated-database-regions'
import { useT } from '@/lib/i18n/translate'

type DedicatedDatabaseRegionUnavailableCardProps = {
  icon: LucideIcon
  title?: string
}

export function DedicatedDatabaseRegionUnavailableCard({
  icon,
  title = 'Coming soon',
}: DedicatedDatabaseRegionUnavailableCardProps) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-card/50 opacity-80">
      <EmptyState
        icon={icon}
        title={t(title)}
        description={formatDedicatedDatabaseRegionUnavailableDescription(t)}
        isEmpty
        variant="centered"
        iconSize="md"
      />
    </div>
  )
}

export function DedicatedDatabaseRegionUnavailableBadge() {
  const t = useT()
  return (
    <Badge variant="inactive" className="text-[10px] shrink-0">
      {t('Coming soon')}
    </Badge>
  )
}
