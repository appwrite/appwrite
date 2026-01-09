import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
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
import { getMigrationStatusVariant, type MigrationStatus } from '@/lib/utils/status-badge'
import { cn } from '@/lib/utils'
import { Loader2, ArrowRightLeft } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type { Models } from '@appwrite.io/console'
import { MigrationDetailsDialog } from './migrations/MigrationDetails'
import { ExportDataDialog } from './migrations/ExportData'

interface MigrationsProps {
  projectId: string
}

export function Migrations({ projectId }: MigrationsProps) {
  const { project } = useProject(projectId)
  const region = project?.region

  const [selectedMigration, setSelectedMigration] = useState<Models.Migration | null>(null)
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [exportDialogOpen, setExportDialogOpen] = useState(false)

  const { migrations, isLoading } = useProjectMigrations(projectId, region)

  const getStatusBadge = (status: string) => {
    const variant = getMigrationStatusVariant(status as MigrationStatus)
    
    const statusConfig: Record<string, { label: string; icon?: typeof Loader2; spin?: boolean }> = {
      completed: { label: 'Complete' },
      processing: { label: 'Processing', icon: Loader2, spin: true },
      failed: { label: 'Failed' },
      pending: { label: 'Pending' },
    }

    const config = statusConfig[status] || { label: 'Waiting' }
    const Icon = config.icon

    return (
      <Badge variant={variant} className="gap-1.5">
        {Icon && <Icon className={cn('h-3 w-3', config.spin && 'animate-spin')} />}
        {config.label}
      </Badge>
    )
  }

  const handleViewDetails = (migration: Models.Migration) => {
    setSelectedMigration(migration)
    setDetailsOpen(true)
  }


  return (
    <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : migrations.length === 0 ? (
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
            <ArrowRightLeft className="h-5 w-5 text-muted-foreground" />
          </div>
          <p className="mb-1 text-[14px] font-medium text-foreground">
            No migrations yet
          </p>
          <p className="text-[13px] text-muted-foreground">
            Import data from another platform or export your project data
          </p>
        </div>
      ) : (
        <div className="rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>Source</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-[100px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {migrations.map((migration) => (
                <TableRow key={migration.$id}>
                  <TableCell>
                    <DateTooltip date={migration.$createdAt} />
                  </TableCell>
                  <TableCell>{migration.source}</TableCell>
                  <TableCell>{getStatusBadge(migration.status)}</TableCell>
                  <TableCell>
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
          projectId={projectId}
          region={region}
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

