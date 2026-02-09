import { useState } from 'react'
import { useProjectMigrations, useProject } from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  getMigrationStatusVariant,
  type MigrationStatus,
} from '@/lib/utils/status-badge'
import { cn } from '@/lib/utils'
import { Loader2, ArrowRightLeft } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type { Models } from '@appwrite.io/console'
import { MigrationDetailsDialog } from './migrations/MigrationDetails'
import { ExportDataDialog } from './migrations/ExportData'

interface MigrationsProps {
  projectId: string
}

export function Migrations({ projectId }: MigrationsProps) {
  const { project } = useProject(projectId)
  const region = project?.region

  const [selectedMigration, setSelectedMigration] =
    useState<Models.Migration | null>(null)
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [exportDialogOpen, setExportDialogOpen] = useState(false)

  const { migrations, isLoading } = useProjectMigrations(projectId, region)

  const getStatusBadge = (status: string) => {
    const variant = getMigrationStatusVariant(status as MigrationStatus)

    const statusConfig: Record<
      string,
      { label: string; icon?: typeof Loader2; spin?: boolean }
    > = {
      completed: { label: 'Complete' },
      processing: { label: 'Processing', icon: Loader2, spin: true },
      failed: { label: 'Failed' },
      pending: { label: 'Pending' },
    }

    const config = statusConfig[status] || { label: 'Waiting' }
    const Icon = config.icon

    return (
      <Badge variant={variant} className="gap-1.5">
        {Icon && (
          <Icon className={cn('h-3 w-3', config.spin && 'animate-spin')} />
        )}
        {config.label}
      </Badge>
    )
  }

  const handleViewDetails = (migration: Models.Migration) => {
    setSelectedMigration(migration)
    setDetailsOpen(true)
  }

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : migrations.length === 0 ? (
        <EmptyState
          icon={ArrowRightLeft}
          title="No migrations yet"
          description="Import data from another platform or export your project data"
          isEmpty={true}
          variant="card"
        />
      ) : (
        <div className="rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Date
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Source
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Status
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {migrations.map((migration) => (
                <TableRow key={migration.$id}>
                  <TableCell className="px-4 py-3">
                    <DateTooltip date={migration.$createdAt} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {migration.source}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {getStatusBadge(migration.status)}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleViewDetails(migration)}
                    >
                      Details
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {/* Dialogs */}
      {selectedMigration && (
        <MigrationDetailsDialog
          open={detailsOpen}
          onOpenChange={setDetailsOpen}
          migration={selectedMigration}
        />
      )}

      <ExportDataDialog
        open={exportDialogOpen}
        onOpenChange={setExportDialogOpen}
        projectId={projectId}
      />
    </div>
  )
}
