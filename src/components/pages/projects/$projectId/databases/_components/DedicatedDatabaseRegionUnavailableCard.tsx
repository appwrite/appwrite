import type { LucideIcon } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { getDedicatedDatabaseRegionUnavailableDescription } from '@/lib/databases/dedicated-database-regions'

type DedicatedDatabaseRegionUnavailableCardProps = {
  icon: LucideIcon
  title?: string
}

export function DedicatedDatabaseRegionUnavailableCard({
  icon,
  title = 'Coming soon',
}: DedicatedDatabaseRegionUnavailableCardProps) {
  return (
    <div className="rounded-lg border border-border bg-card/50 opacity-80">
      <EmptyState
        icon={icon}
        title={title}
        description={getDedicatedDatabaseRegionUnavailableDescription()}
        isEmpty
        variant="centered"
        iconSize="md"
      />
    </div>
  )
}

export function DedicatedDatabaseRegionUnavailableBadge() {
  return (
    <Badge variant="inactive" className="text-[10px] shrink-0">
      Coming soon
    </Badge>
  )
}
