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
import { useDatabaseTableOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import {
  useExecuteMysqlSql,
  useMysqlTableInfo,
} from '@/lib/react-query/hooks'
import {
  buildMysqlDropTableSql,
  buildMysqlRenameTableSql,
  buildMysqlTableCommentSql,
  formatMysqlBytes,
} from '@/lib/mysql-table-ddl'
import {
  parseMysqlTableId,
  mysqlNav,
  mysqlTableId,
} from '@/lib/mysql-database-routes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type MysqlTablePropertiesPanelProps = {
  databaseId: string
  tableId: string
}

export function MysqlTablePropertiesPanel({
  databaseId,
  tableId,
}: MysqlTablePropertiesPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { schema, table } = parseMysqlTableId(tableId)
  const { canWrite } = useDatabaseTableOperationsAccess()

  const { tableInfo, isLoading, refetch } = useMysqlTableInfo(
    projectId,
    databaseId,
    tableId,
  )
  const executeSql = useExecuteMysqlSql(projectId, databaseId)

  const [tableName, setTableName] = useState(table)
  const [comment, setComment] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const savedComment = tableInfo?.table_comment ?? ''
  const isCommentUnchanged = comment === savedComment
  const isNameUnchanged = !tableName.trim() || tableName.trim() === table

  useEffect(() => {
    setTableName(table)
  }, [table])

  useEffect(() => {
    setComment(tableInfo?.table_comment ?? '')
  }, [tableId, tableInfo?.table_comment])

  const handleUpdateName = async () => {
    const trimmed = tableName.trim()
    if (!trimmed || trimmed === table) return
    try {
      await executeSql.mutateAsync(buildMysqlRenameTableSql(tableId, trimmed))
      toast.success(t('Table renamed'))
      const nextTableId = mysqlTableId(schema, trimmed)
      navigate({
        ...mysqlNav({ projectId, databaseId })
          .table({ tableId: nextTableId })
          .settings(),
        replace: true,
      })
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to rename table'))
    }
  }

  const handleUpdateComment = async () => {
    if (comment === savedComment) return
    try {
      await executeSql.mutateAsync(buildMysqlTableCommentSql(tableId, comment))
      toast.success(t('Comment updated'))
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to update comment'))
    }
  }

  const handleDelete = async () => {
    try {
      await executeSql.mutateAsync(buildMysqlDropTableSql(tableId))
      toast.success(t('Table deleted'))
      setDeleteDialogOpen(false)
      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
        replace: true,
      })
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete table'))
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
            {t('Table properties')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Overview of this table in the {schema} schema.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 grid gap-4 sm:grid-cols-2">
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Schema')}
            </p>
            <p className="mt-1 text-[13px]">{schema}</p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Type')}
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading ? 'Loading…' : (tableInfo?.table_type ?? '-')}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Estimated rows')}
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading ? 'Loading…' : rowEstimate}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Total size')}
            </p>
            <p className="mt-1 text-[13px]">
              {isLoading
                ? 'Loading…'
                : formatMysqlBytes(tableInfo?.total_bytes)}
            </p>
          </div>
        </div>
      </div>

      {canWrite ? (
        <>
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Rename table')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Change the table name within the {schema} schema.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-3">
              <div className="space-y-2">
                <Label htmlFor="mysql-table-name">{t('Table name')}</Label>
                <Input
                  id="mysql-table-name"
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
                disabled={executeSql.isPending || isNameUnchanged}
                onClick={() => void handleUpdateName()}
              >
                {t('Update')}
              </Button>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Comment')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t('Add a description for this table.')}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <Textarea
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                rows={3}
                className="text-[13px] resize-none"
                placeholder={t('Optional table comment')}
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={executeSql.isPending || isCommentUnchanged}
                onClick={() => void handleUpdateComment()}
              >
                {t('Update')}
              </Button>
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Delete table')}
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
                {t('Delete table')}
              </Button>
            </div>
          </div>
        </>
      ) : null}

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete table')}</DialogTitle>
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
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      </div>
    </div>
  )
}
