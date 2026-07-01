import { useEffect, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  useExecutePostgresSql,
  usePostgresTableInfo,
} from '@/lib/react-query/hooks'
import {
  buildPostgresDropTableSql,
  buildPostgresRenameTableSql,
  buildPostgresTableCommentSql,
  formatPostgresBytes,
} from '@/lib/postgres-table-ddl'
import {
  parsePostgresTableId,
  postgresNav,
  postgresTableId,
} from '@/lib/postgres-database-routes'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type PostgresTablePropertiesPanelProps = {
  databaseId: string
  tableId: string
}

export function PostgresTablePropertiesPanel({
  databaseId,
  tableId,
}: PostgresTablePropertiesPanelProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { schema, table } = parsePostgresTableId(tableId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  const { tableInfo, isLoading, refetch } = usePostgresTableInfo(
    projectId,
    databaseId,
    tableId,
  )
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [tableName, setTableName] = useState(table)
  const [comment, setComment] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  useEffect(() => {
    setTableName(table)
  }, [table])

  useEffect(() => {
    if (tableInfo?.table_comment != null) {
      setComment(tableInfo.table_comment)
    }
  }, [tableInfo?.table_comment])

  const handleUpdateName = async () => {
    const trimmed = tableName.trim()
    if (!trimmed || trimmed === table) return
    try {
      await executeSql.mutateAsync(buildPostgresRenameTableSql(tableId, trimmed))
      toast.success('Table renamed')
      const nextTableId = postgresTableId(schema, trimmed)
      navigate({
        ...postgresNav({ projectId, databaseId })
          .table({ tableId: nextTableId })
          .settings(),
        replace: true,
      })
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to rename table')
    }
  }

  const handleUpdateComment = async () => {
    try {
      await executeSql.mutateAsync(buildPostgresTableCommentSql(tableId, comment))
      toast.success('Comment updated')
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to update comment')
    }
  }

  const handleDelete = async () => {
    try {
      await executeSql.mutateAsync(buildPostgresDropTableSql(tableId))
      toast.success('Table deleted')
      setDeleteDialogOpen(false)
      navigate({
        ...postgresNav({ projectId, databaseId }).sql(),
        replace: true,
      })
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to delete table')
    }
  }

  const estimatedRows = tableInfo?.estimated_rows
  const rowEstimate =
    estimatedRows != null && estimatedRows !== ''
      ? Number.parseInt(String(estimatedRows), 10).toLocaleString()
      : '-'

  return (
    <div className="w-full flex-1 overflow-y-auto px-4 py-4 sm:px-6">
      <div className="mx-auto w-full max-w-7xl space-y-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Table properties
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Overview of this table in the {schema} schema.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 grid gap-4 sm:grid-cols-2">
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              Schema
            </p>
            <p className="mt-1 text-[13px]">{schema}</p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              Type
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading ? 'Loading…' : (tableInfo?.table_type ?? '-')}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              Estimated rows
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading ? 'Loading…' : rowEstimate}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              Total size
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading
                ? 'Loading…'
                : formatPostgresBytes(tableInfo?.total_bytes)}
            </p>
          </div>
        </div>
      </div>

      {canWrite ? (
        <>
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Rename table
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Change the table name within the {schema} schema.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-3">
              <div className="space-y-2">
                <Label htmlFor="postgres-table-name">Table name</Label>
                <Input
                  id="postgres-table-name"
                  value={tableName}
                  onChange={(event) => setTableName(event.target.value)}
                  className="h-9 text-[13px]"
                />
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  executeSql.isPending ||
                  !tableName.trim() ||
                  tableName.trim() === table
                }
                onClick={() => void handleUpdateName()}
              >
                Update
              </Button>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Comment
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Add a description for this table.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <Textarea
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                rows={3}
                className="text-[13px] resize-none"
                placeholder="Optional table comment"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={executeSql.isPending}
                onClick={() => void handleUpdateComment()}
              >
                Update
              </Button>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Delete table
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Permanently delete {schema}.{table} and all of its data. This
                action cannot be undone.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 bg-muted/30">
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setDeleteDialogOpen(true)}
              >
                Delete table
              </Button>
            </div>
          </div>
        </>
      ) : null}

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete table</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>
                {schema}.{table}
              </strong>
              ? All rows and data will be permanently removed. This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      </div>
    </div>
  )
}
