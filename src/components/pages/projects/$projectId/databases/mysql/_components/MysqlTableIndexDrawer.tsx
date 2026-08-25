import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  createDefaultMysqlIndexFormState,
  validateMysqlIndexFormState,
  type MysqlIndexFormState,
} from '@/lib/mysql-index-metadata'
import {
  buildMysqlCreateIndexSql,
  buildMysqlIndexCommentSql,
} from '@/lib/mysql-table-ddl'
import { buildMysqlSingleRequestDdlSql } from '@/lib/mysql-sql'
import {
  useExecuteMysqlSql,
  useMysqlTableColumns,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { MysqlIndexAlgorithmSelector } from './MysqlIndexAlgorithmSelector'
import { useT } from '@/lib/i18n/translate'

type MysqlTableIndexDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  onSuccess: () => void
}

function toggleOrderedColumn(
  columns: string[],
  columnName: string,
  checked: boolean,
): string[] {
  if (checked) {
    if (columns.includes(columnName)) return columns
    return [...columns, columnName]
  }
  return columns.filter((column) => column !== columnName)
}

function toggleIncludeColumn(
  includeColumns: string[],
  columnName: string,
  checked: boolean,
): string[] {
  if (checked) {
    if (includeColumns.includes(columnName)) return includeColumns
    return [...includeColumns, columnName]
  }
  return includeColumns.filter((column) => column !== columnName)
}

export function MysqlTableIndexDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  tableId,
  onSuccess,
}: MysqlTableIndexDrawerProps) {
  const t = useT()
  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const { columns } = useMysqlTableColumns(projectId, databaseId, tableId)

  const [formState, setFormState] = useState<MysqlIndexFormState>(
    createDefaultMysqlIndexFormState(),
  )

  useEffect(() => {
    if (!open) return
    setFormState(createDefaultMysqlIndexFormState())
  }, [open])

  const availableIncludeColumns = useMemo(
    () =>
      columns
        .map((column) => column.column_name)
        .filter((columnName) => !formState.columns.includes(columnName)),
    [columns, formState.columns],
  )

  const handleSubmit = async () => {
    const validationError = validateMysqlIndexFormState(formState)
    if (validationError) {
      toast.error(validationError)
      return
    }

    const trimmedName = formState.name.trim()

    try {
      const statements = [
        buildMysqlCreateIndexSql(tableId, trimmedName, formState.columns, {
          unique: formState.unique,
          algorithm: formState.algorithm,
          condition: formState.condition.trim() || undefined,
          includeColumns: formState.includeColumns,
        }),
      ]

      if (formState.comment.trim()) {
        statements.push(
          buildMysqlIndexCommentSql(tableId, trimmedName, formState.comment.trim()),
        )
      }

      await executeSql.mutateAsync(
        buildMysqlSingleRequestDdlSql(statements, 'Create table index'),
      )
      toast.success(t('Index created'))
      onOpenChange(false)
      onSuccess()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to create index'))
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Create index')}
      description={t('Define the index algorithm, key columns, and optional partial or covering options.')}
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border" />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void handleSubmit()
          }}
          className="flex flex-col flex-1 min-h-0"
        >
          <div className="flex-1 overflow-y-auto px-6 pb-4 pt-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="index-name" className="text-[12px] font-medium">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="index-name"
                value={formState.name}
                onChange={(event) =>
                  setFormState((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
                placeholder="idx_users_email"
              />
            </div>

            <MysqlIndexAlgorithmSelector
              value={formState.algorithm}
              onChange={(algorithm) =>
                setFormState((current) => ({
                  ...current,
                  algorithm,
                  columns:
                    algorithm === 'hash' && current.columns.length > 1
                      ? current.columns.slice(0, 1)
                      : current.columns,
                }))
              }
            />

            <div className="flex items-center justify-between gap-3">
              <div>
                <Label htmlFor="index-unique" className="text-[12px] font-medium">
                  {t('Unique')}
                </Label>
                <p className="text-[11px] text-muted-foreground mt-1">
                  {t('Enforce unique values across indexed columns.')}
                </p>
              </div>
              <Switch
                id="index-unique"
                checked={formState.unique}
                onCheckedChange={(unique) =>
                  setFormState((current) => ({ ...current, unique }))
                }
              />
            </div>

            <div className="space-y-2">
              <Label className="text-[12px] font-medium">
                {t('Key columns')} <span className="text-destructive">*</span>
              </Label>
              <p className="text-[11px] text-muted-foreground">
                {t('Select one or more columns in index order.')}
                {formState.algorithm === 'hash'
                  ? ` ${t('Hash indexes support one key column.')}`
                  : null}
              </p>
              {columns.length === 0 ? (
                <p className="text-[12px] text-muted-foreground">
                  {t('No columns available.')}
                </p>
              ) : (
                <div className="rounded-lg border border-border divide-y divide-border">
                  {columns.map((column) => {
                    const order = formState.columns.indexOf(column.column_name)
                    const isSelected = order >= 0
                    const disableAdd =
                      formState.algorithm === 'hash' &&
                      formState.columns.length >= 1 &&
                      !isSelected

                    return (
                      <label
                        key={column.column_name}
                        className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-muted/40"
                      >
                        <Checkbox
                          checked={isSelected}
                          disabled={disableAdd}
                          onCheckedChange={(checked) =>
                            setFormState((current) => ({
                              ...current,
                              columns: toggleOrderedColumn(
                                current.columns,
                                column.column_name,
                                checked === true,
                              ),
                              includeColumns: current.includeColumns.filter(
                                (name) => name !== column.column_name,
                              ),
                            }))
                          }
                        />
                        <span className="flex-1 text-[12px]">
                          {column.column_name}
                        </span>
                        {isSelected ? (
                          <span className="text-[11px] tabular-nums text-muted-foreground">
                            #{order + 1}
                          </span>
                        ) : null}
                      </label>
                    )
                  })}
                </div>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="index-condition" className="text-[12px] font-medium">
                {t('Condition')}
              </Label>
              <p className="text-[11px] text-muted-foreground">
                Partial index predicate inside WHERE (...), for example{' '}
                <code className="font-mono text-[11px]">deleted_at IS NULL</code>.
              </p>
              <Textarea
                id="index-condition"
                value={formState.condition}
                onChange={(event) =>
                  setFormState((current) => ({
                    ...current,
                    condition: event.target.value,
                  }))
                }
                rows={2}
                className="min-h-[80px] resize-y font-mono"
                placeholder="deleted_at IS NULL"
              />
            </div>

            <div className="space-y-2">
              <Label className="text-[12px] font-medium">{t('Include columns')}</Label>
              <p className="text-[11px] text-muted-foreground">
                {t('Covering index columns stored in the index but not used for lookups.')}
              </p>
              {availableIncludeColumns.length === 0 ? (
                <p className="text-[12px] text-muted-foreground">
                  {formState.columns.length === columns.length
                    ? t('All table columns are already key columns.')
                    : t('Select key columns first.')}
                </p>
              ) : (
                <div className="rounded-lg border border-border divide-y divide-border">
                  {availableIncludeColumns.map((columnName) => (
                    <label
                      key={columnName}
                      className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-muted/40"
                    >
                      <Checkbox
                        checked={formState.includeColumns.includes(columnName)}
                        onCheckedChange={(checked) =>
                          setFormState((current) => ({
                            ...current,
                            includeColumns: toggleIncludeColumn(
                              current.includeColumns,
                              columnName,
                              checked === true,
                            ),
                          }))
                        }
                      />
                      <span className="text-[12px]">{columnName}</span>
                    </label>
                  ))}
                </div>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="index-comment" className="text-[12px] font-medium">
                {t('Comment')}
              </Label>
              <Textarea
                id="index-comment"
                value={formState.comment}
                onChange={(event) =>
                  setFormState((current) => ({
                    ...current,
                    comment: event.target.value,
                  }))
                }
                rows={2}
                className="min-h-[80px] resize-y"
                placeholder={t('Describe what this index is for')}
              />
            </div>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button
              type="submit"
              disabled={
                executeSql.isPending ||
                !formState.name.trim() ||
                formState.columns.length === 0
              }
            >
              {t('Create')}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={executeSql.isPending}
            >
              {t('Cancel')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
