import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import {
  buildPostgresColumnConstraintName,
  createEmptyPostgresForeignKeyState,
  getPostgresColumnCheckExpressionForEdit,
  getPostgresColumnForeignKeyReferenceForEdit,
  getPostgresForeignKeyReferenceForCompare,
  parsePostgresColumnCheckConstraints,
  parsePostgresColumnForeignKeys,
  parsePostgresForeignKeyStateFromRow,
  postgresForeignKeyStateToReference,
  validatePostgresForeignKeyState,
  type PostgresForeignKeyState,
} from '@/lib/postgres-column-metadata'
import { useExecutePostgresSql } from '@/lib/react-query/hooks'
import {
  buildPostgresAddCheckConstraintSql,
  buildPostgresAddColumnSql,
  buildPostgresAddForeignKeySql,
  buildPostgresAlterColumnNullableSql,
  buildPostgresAlterColumnTypeSql,
  buildPostgresColumnCommentSql,
  buildPostgresDropConstraintSql,
  buildPostgresRenameColumnSql,
} from '@/lib/postgres-table-ddl'
import {
  buildPostgresColumnTypeSql,
  createDefaultPostgresColumnTypeState,
  getPostgresColumnDefaultPlaceholder,
  parsePostgresColumnTypeFromRow,
  postgresColumnTypeStatesEqual,
  validatePostgresColumnTypeState,
  type PostgresColumnTypeState,
} from '@/lib/postgres-column-types'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import { PostgresColumnTypeSelector } from './PostgresColumnTypeSelector'
import { PostgresForeignKeySelector } from './PostgresForeignKeySelector'
import { useT } from '@/lib/i18n/translate'

type PostgresTableColumnDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  column: PostgresTableColumnRow | null
  onSuccess: () => void
}

function normalizeOptionalText(value: string): string {
  return value.trim()
}

export function PostgresTableColumnDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  tableId,
  column,
  onSuccess,
}: PostgresTableColumnDrawerProps) {
  const t = useT()
  const isEditing = !!column
  const executeSql = useExecutePostgresSql(projectId, databaseId)
  const { schema: tableSchema, table: tableName } = parsePostgresTableId(tableId)

  const [name, setName] = useState('')
  const [typeState, setTypeState] = useState<PostgresColumnTypeState>(
    createDefaultPostgresColumnTypeState(),
  )
  const [nullable, setNullable] = useState(true)
  const [defaultValue, setDefaultValue] = useState('')
  const [comment, setComment] = useState('')
  const [checkExpression, setCheckExpression] = useState('')
  const [foreignKeyState, setForeignKeyState] = useState<PostgresForeignKeyState>(
    () => createEmptyPostgresForeignKeyState(tableSchema),
  )

  useEffect(() => {
    if (!open) return
    if (column) {
      setName(column.column_name)
      setTypeState(parsePostgresColumnTypeFromRow(column))
      setNullable(column.is_nullable === 'YES')
      setDefaultValue(column.column_default ?? '')
      setComment(column.column_comment ?? '')
      setCheckExpression(getPostgresColumnCheckExpressionForEdit(column.check_constraints))
      setForeignKeyState(
        parsePostgresForeignKeyStateFromRow(column.foreign_keys, tableSchema),
      )
    } else {
      setName('')
      setTypeState(createDefaultPostgresColumnTypeState())
      setNullable(true)
      setDefaultValue('')
      setComment('')
      setCheckExpression('')
      setForeignKeyState(createEmptyPostgresForeignKeyState(tableSchema))
    }
  }, [open, column, tableSchema])

  const handleSubmit = async () => {
    const trimmedName = name.trim()
    if (!trimmedName) {
      toast.error(t('Column name is required'))
      return
    }

    const typeError = validatePostgresColumnTypeState(typeState)
    if (typeError) {
      toast.error(typeError)
      return
    }

    const nextComment = normalizeOptionalText(comment)
    const nextCheckExpression = normalizeOptionalText(checkExpression)

    const foreignKeyError = validatePostgresForeignKeyState(foreignKeyState)
    if (foreignKeyError) {
      toast.error(foreignKeyError)
      return
    }

    const nextForeignKeyReference = getPostgresForeignKeyReferenceForCompare(
      foreignKeyState,
    )

    const dataType = buildPostgresColumnTypeSql(typeState)
    const statements: string[] = []

    try {
      if (!isEditing) {
        statements.push(
          buildPostgresAddColumnSql(tableId, trimmedName, dataType, {
            nullable,
            defaultValue: defaultValue.trim() || undefined,
          }),
        )
      } else {
        if (trimmedName !== column.column_name) {
          statements.push(
            buildPostgresRenameColumnSql(
              tableId,
              column.column_name,
              trimmedName,
            ),
          )
        }
        const currentTypeState = parsePostgresColumnTypeFromRow(column)
        if (!postgresColumnTypeStatesEqual(typeState, currentTypeState)) {
          statements.push(
            buildPostgresAlterColumnTypeSql(tableId, trimmedName, dataType),
          )
        }
        const wasNullable = column.is_nullable === 'YES'
        if (nullable !== wasNullable) {
          statements.push(
            buildPostgresAlterColumnNullableSql(tableId, trimmedName, nullable),
          )
        }
      }

      const previousComment = normalizeOptionalText(column?.column_comment ?? '')
      if (nextComment !== previousComment) {
        statements.push(
          buildPostgresColumnCommentSql(
            tableId,
            trimmedName,
            nextComment || null,
          ),
        )
      }

      const previousCheckExpression = normalizeOptionalText(
        getPostgresColumnCheckExpressionForEdit(column?.check_constraints ?? null),
      )
      if (nextCheckExpression !== previousCheckExpression) {
        for (const check of parsePostgresColumnCheckConstraints(
          column?.check_constraints ?? null,
        )) {
          if (check.name) {
            statements.push(buildPostgresDropConstraintSql(tableId, check.name))
          }
        }
        if (nextCheckExpression) {
          statements.push(
            buildPostgresAddCheckConstraintSql(
              tableId,
              buildPostgresColumnConstraintName(tableName, trimmedName, 'check'),
              nextCheckExpression,
            ),
          )
        }
      }

      const previousForeignKeyReference = normalizeOptionalText(
        getPostgresColumnForeignKeyReferenceForEdit(column?.foreign_keys ?? null),
      )
      if (nextForeignKeyReference !== previousForeignKeyReference) {
        for (const foreignKey of parsePostgresColumnForeignKeys(
          column?.foreign_keys ?? null,
        )) {
          if (foreignKey.name) {
            statements.push(
              buildPostgresDropConstraintSql(tableId, foreignKey.name),
            )
          }
        }
        if (nextForeignKeyReference) {
          const parsedReference = postgresForeignKeyStateToReference(foreignKeyState)
          if (parsedReference) {
            statements.push(
              buildPostgresAddForeignKeySql(
                tableId,
                trimmedName,
                buildPostgresColumnConstraintName(tableName, trimmedName, 'fkey'),
                parsedReference,
              ),
            )
          }
        }
      }

      if (statements.length === 0) {
        onOpenChange(false)
        return
      }

      await executeSql.mutateAsync(statements.join(';\n'))
      toast.success(isEditing ? t('Column updated') : t('Column created'))
      onOpenChange(false)
      onSuccess()
    } catch (error) {
      toast.error(
        getErrorMessage(error) ??
          t(
            isEditing ? 'Failed to update column' : 'Failed to create column',
          ),
      )
    }
  }

  const hasMultipleChecks =
    isEditing && parsePostgresColumnCheckConstraints(column?.check_constraints).length > 1
  const hasMultipleForeignKeys =
    isEditing && parsePostgresColumnForeignKeys(column?.foreign_keys).length > 1

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEditing ? t('Update column') : t('Create column')}
      description={
        isEditing
          ? t('Update the column definition, constraints, and metadata.')
          : t('Add a new column with optional constraints and metadata.')
      }
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
              <Label htmlFor="column-name" className="text-[12px] font-medium">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="column-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                disabled={
                  isEditing &&
                  (column.is_primary_key === true ||
                    column.is_primary_key === 'true')
                }
              />
            </div>
            <PostgresColumnTypeSelector
              value={typeState}
              onChange={setTypeState}
              allowSerialTypes={!isEditing}
            />
            <div className="flex items-center justify-between gap-3">
              <div>
                <Label htmlFor="column-nullable" className="text-[12px] font-medium">
                  {t('Nullable')}
                </Label>
                <p className="text-[11px] text-muted-foreground mt-1">
                  {t('Allow NULL values in this column.')}
                </p>
              </div>
              <Switch
                id="column-nullable"
                checked={nullable}
                onCheckedChange={setNullable}
              />
            </div>
            {!isEditing ? (
              <div className="space-y-2">
                <Label htmlFor="column-default" className="text-[12px] font-medium">
                  {t('Default value')}
                </Label>
                <Input
                  id="column-default"
                  value={defaultValue}
                  onChange={(event) => setDefaultValue(event.target.value)}
                  className="font-mono"
                  placeholder={t(
                    getPostgresColumnDefaultPlaceholder(typeState.typeId),
                  )}
                />
              </div>
            ) : null}
            <div className="space-y-2">
              <Label htmlFor="column-comment" className="text-[12px] font-medium">
                {t('Comment')}
              </Label>
              <Textarea
                id="column-comment"
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                rows={2}
                className="min-h-[80px] resize-y"
                placeholder={t('Describe what this column stores')}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="column-check" className="text-[12px] font-medium">
                Check
              </Label>
              <p className="text-[11px] text-muted-foreground">
                SQL expression inside CHECK (...), for example{' '}
                <code className="font-mono text-[11px]">amount &gt; 0</code>.
              </p>
              {hasMultipleChecks ? (
                <p className="text-[11px] text-muted-foreground">
                  {t('This column has multiple check constraints. Saving replaces them with a single check.')}
                </p>
              ) : null}
              <Textarea
                id="column-check"
                value={checkExpression}
                onChange={(event) => setCheckExpression(event.target.value)}
                rows={2}
                className="min-h-[80px] resize-y font-mono"
                placeholder="amount > 0"
              />
            </div>
            <PostgresForeignKeySelector
              value={foreignKeyState}
              onChange={setForeignKeyState}
              projectId={projectId}
              databaseId={databaseId}
              defaultSchema={tableSchema}
              active={open}
              hasMultipleForeignKeys={hasMultipleForeignKeys}
            />
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={executeSql.isPending || !name.trim()}>
              {isEditing ? t('Update') : t('Create')}
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
