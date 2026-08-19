import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { parseMysqlTableId } from '@/lib/mysql-database-routes'
import {
  buildMysqlColumnConstraintName,
  createEmptyMysqlForeignKeyState,
  getMysqlColumnCheckExpressionForEdit,
  getMysqlColumnForeignKeyReferenceForEdit,
  getMysqlForeignKeyReferenceForCompare,
  parseMysqlColumnCheckConstraints,
  parseMysqlColumnForeignKeys,
  parseMysqlForeignKeyStateFromRow,
  mysqlForeignKeyStateToReference,
  validateMysqlForeignKeyState,
  type MysqlForeignKeyState,
} from '@/lib/mysql-column-metadata'
import { useExecuteMysqlSql } from '@/lib/react-query/hooks'
import {
  buildMysqlAddCheckConstraintSql,
  buildMysqlAddColumnSql,
  buildMysqlAddForeignKeySql,
  buildMysqlAddPrimaryKeySql,
  buildMysqlAddUniqueConstraintSql,
  buildMysqlAlterColumnDefaultSql,
  buildMysqlAlterColumnNullableSql,
  buildMysqlAlterColumnTypeSql,
  buildMysqlColumnCommentSql,
  buildMysqlDropConstraintSql,
  buildMysqlRenameColumnSql,
} from '@/lib/mysql-table-ddl'
import {
  buildMysqlColumnTypeSql,
  createDefaultMysqlColumnTypeState,
  getMysqlColumnDefaultPlaceholder,
  parseMysqlColumnTypeFromRow,
  mysqlColumnTypeStatesEqual,
  validateMysqlColumnTypeState,
  type MysqlColumnTypeState,
} from '@/lib/mysql-column-types'
import {
  buildMysqlSingleRequestDdlSql,
  isMysqlPrimaryKeyColumn,
  isMysqlUniqueColumn,
  type MysqlTableColumnRow,
} from '@/lib/mysql-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { MysqlColumnTypeSelector } from './MysqlColumnTypeSelector'
import { MysqlForeignKeySelector } from './MysqlForeignKeySelector'
import { useT } from '@/lib/i18n/translate'

type MysqlTableColumnDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  column: MysqlTableColumnRow | null
  onSuccess: () => void | Promise<unknown>
}

function normalizeOptionalText(value: string): string {
  return value.trim()
}

function ConstraintToggle({
  id,
  label,
  description,
  checked,
  onCheckedChange,
  disabled,
}: {
  id: string
  label: string
  description: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2.5">
      <div className="min-w-0">
        <Label htmlFor={id} className="text-[12px] font-medium">
          {label}
        </Label>
        <p className="text-[11px] text-muted-foreground mt-1">{description}</p>
      </div>
      <Switch
        id={id}
        checked={checked}
        onCheckedChange={onCheckedChange}
        disabled={disabled}
      />
    </div>
  )
}

export function MysqlTableColumnDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  tableId,
  column,
  onSuccess,
}: MysqlTableColumnDrawerProps) {
  const t = useT()
  const isEditing = !!column
  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const { schema: tableSchema, table: tableName } = parseMysqlTableId(tableId)

  const [name, setName] = useState('')
  const [typeState, setTypeState] = useState<MysqlColumnTypeState>(
    createDefaultMysqlColumnTypeState(),
  )
  const [nullable, setNullable] = useState(true)
  const [primaryKey, setPrimaryKey] = useState(false)
  const [unique, setUnique] = useState(false)
  const [defaultValue, setDefaultValue] = useState('')
  const [comment, setComment] = useState('')
  const [checkExpression, setCheckExpression] = useState('')
  const [foreignKeyState, setForeignKeyState] = useState<MysqlForeignKeyState>(
    () => createEmptyMysqlForeignKeyState(tableSchema),
  )
  const nameInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!open || column) return
    const timeout = window.setTimeout(() => {
      nameInputRef.current?.focus()
    }, 0)
    return () => window.clearTimeout(timeout)
  }, [open, column])

  useEffect(() => {
    if (!open) return
    if (column) {
      const isPrimary = isMysqlPrimaryKeyColumn(column)
      setName(column.column_name)
      setTypeState(parseMysqlColumnTypeFromRow(column))
      setNullable(column.is_nullable === 'YES')
      setPrimaryKey(isPrimary)
      setUnique(isPrimary || isMysqlUniqueColumn(column))
      setDefaultValue(column.column_default ?? '')
      setComment(column.column_comment ?? '')
      setCheckExpression(getMysqlColumnCheckExpressionForEdit(column.check_constraints))
      setForeignKeyState(
        parseMysqlForeignKeyStateFromRow(column.foreign_keys, tableSchema),
      )
    } else {
      setName('')
      setTypeState(createDefaultMysqlColumnTypeState())
      setNullable(true)
      setPrimaryKey(false)
      setUnique(false)
      setDefaultValue('')
      setComment('')
      setCheckExpression('')
      setForeignKeyState(createEmptyMysqlForeignKeyState(tableSchema))
    }
  }, [open, column, tableSchema])

  const handlePrimaryKeyChange = (checked: boolean) => {
    setPrimaryKey(checked)
    if (checked) {
      setNullable(false)
      setUnique(true)
    }
  }

  const handleSubmit = async () => {
    const trimmedName = name.trim()
    if (!trimmedName) {
      toast.error(t('Column name is required'))
      return
    }

    const typeError = validateMysqlColumnTypeState(typeState)
    if (typeError) {
      toast.error(t(typeError))
      return
    }

    const nextComment = normalizeOptionalText(comment)
    const nextCheckExpression = normalizeOptionalText(checkExpression)
    const nextDefault = defaultValue.trim()

    const foreignKeyError = validateMysqlForeignKeyState(foreignKeyState)
    if (foreignKeyError) {
      toast.error(foreignKeyError)
      return
    }

    const nextForeignKeyReference = getMysqlForeignKeyReferenceForCompare(
      foreignKeyState,
    )

    const dataType = buildMysqlColumnTypeSql(typeState)
    const statements: string[] = []

    try {
      if (!isEditing) {
        statements.push(
          buildMysqlAddColumnSql(tableId, trimmedName, dataType, {
            nullable: primaryKey ? false : nullable,
            defaultValue: nextDefault || undefined,
            primaryKey,
            unique: unique && !primaryKey,
          }),
        )
      } else {
        if (trimmedName !== column.column_name) {
          statements.push(
            buildMysqlRenameColumnSql(
              tableId,
              column.column_name,
              trimmedName,
            ),
          )
        }
        const currentTypeState = parseMysqlColumnTypeFromRow(column)
        if (!mysqlColumnTypeStatesEqual(typeState, currentTypeState)) {
          statements.push(
            buildMysqlAlterColumnTypeSql(tableId, trimmedName, dataType),
          )
        }
        const wasNullable = column.is_nullable === 'YES'
        const nextNullable = primaryKey ? false : nullable
        if (nextNullable !== wasNullable) {
          statements.push(
            buildMysqlAlterColumnNullableSql(
              tableId,
              trimmedName,
              nextNullable,
              dataType,
            ),
          )
        }

        const previousDefault = (column.column_default ?? '').trim()
        if (nextDefault !== previousDefault) {
          statements.push(
            buildMysqlAlterColumnDefaultSql(
              tableId,
              trimmedName,
              nextDefault || null,
            ),
          )
        }

        const wasPrimary = isMysqlPrimaryKeyColumn(column)
        const hadStandaloneUnique = isMysqlUniqueColumn(column)
        if (primaryKey !== wasPrimary) {
          if (wasPrimary && column.primary_key_constraint) {
            statements.push(
              buildMysqlDropConstraintSql(
                tableId,
                column.primary_key_constraint,
              ),
            )
          }
          if (primaryKey) {
            statements.push(
              buildMysqlAddPrimaryKeySql(
                tableId,
                trimmedName,
                buildMysqlColumnConstraintName(tableName, trimmedName, 'pkey'),
              ),
            )
          }
        }

        if (primaryKey) {
          // Primary key already enforces uniqueness.
          if (hadStandaloneUnique && column.unique_constraint) {
            statements.push(
              buildMysqlDropConstraintSql(tableId, column.unique_constraint),
            )
          }
        } else if (hadStandaloneUnique && !unique && column.unique_constraint) {
          statements.push(
            buildMysqlDropConstraintSql(tableId, column.unique_constraint),
          )
        } else if (!hadStandaloneUnique && unique) {
          statements.push(
            buildMysqlAddUniqueConstraintSql(
              tableId,
              trimmedName,
              buildMysqlColumnConstraintName(tableName, trimmedName, 'key'),
            ),
          )
        }
      }

      const previousComment = normalizeOptionalText(column?.column_comment ?? '')
      if (nextComment !== previousComment) {
        statements.push(
          buildMysqlColumnCommentSql(
            tableId,
            trimmedName,
            nextComment || null,
            dataType,
          ),
        )
      }

      const previousCheckExpression = normalizeOptionalText(
        getMysqlColumnCheckExpressionForEdit(column?.check_constraints ?? null),
      )
      if (nextCheckExpression !== previousCheckExpression) {
        for (const check of parseMysqlColumnCheckConstraints(
          column?.check_constraints ?? null,
        )) {
          if (check.name) {
            statements.push(buildMysqlDropConstraintSql(tableId, check.name))
          }
        }
        if (nextCheckExpression) {
          statements.push(
            buildMysqlAddCheckConstraintSql(
              tableId,
              buildMysqlColumnConstraintName(tableName, trimmedName, 'check'),
              nextCheckExpression,
            ),
          )
        }
      }

      const previousForeignKeyReference = normalizeOptionalText(
        getMysqlColumnForeignKeyReferenceForEdit(column?.foreign_keys ?? null),
      )
      if (nextForeignKeyReference !== previousForeignKeyReference) {
        for (const foreignKey of parseMysqlColumnForeignKeys(
          column?.foreign_keys ?? null,
        )) {
          if (foreignKey.name) {
            statements.push(
              buildMysqlDropConstraintSql(tableId, foreignKey.name),
            )
          }
        }
        if (nextForeignKeyReference) {
          const parsedReference = mysqlForeignKeyStateToReference(foreignKeyState)
          if (parsedReference) {
            statements.push(
              buildMysqlAddForeignKeySql(
                tableId,
                trimmedName,
                buildMysqlColumnConstraintName(tableName, trimmedName, 'fkey'),
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

      await executeSql.mutateAsync(
        buildMysqlSingleRequestDdlSql(
          statements,
          isEditing ? 'Update table column' : 'Add table column',
        ),
      )
      toast.success(isEditing ? t('Column updated') : t('Column created'))
      onOpenChange(false)
      await onSuccess()
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
    isEditing && parseMysqlColumnCheckConstraints(column?.check_constraints).length > 1
  const hasMultipleForeignKeys =
    isEditing && parseMysqlColumnForeignKeys(column?.foreign_keys).length > 1
  const isExistingPrimaryKey =
    isEditing && column != null && isMysqlPrimaryKeyColumn(column)

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
          <div className="flex-1 overflow-y-auto px-6 pb-4 pt-4 space-y-5">
            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('General')}
              </h4>
              <div className="space-y-2">
                <Label htmlFor="column-name" className="text-[12px] font-medium">
                  {t('Name')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  ref={nameInputRef}
                  id="column-name"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  placeholder="column_name"
                  disabled={isExistingPrimaryKey}
                  autoFocus={!isEditing}
                />
                <p className="text-[11px] text-muted-foreground">
                  {t(
                    'Use lowercase letters and underscores, for example column_name.',
                  )}
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="column-comment" className="text-[12px] font-medium">
                  {t('Description')}
                </Label>
                <Textarea
                  id="column-comment"
                  value={comment}
                  onChange={(event) => setComment(event.target.value)}
                  rows={2}
                  className="min-h-[72px] resize-y"
                  placeholder={t('Optional')}
                />
              </div>
            </section>

            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Data type')}
              </h4>
              <MysqlColumnTypeSelector
                value={typeState}
                onChange={setTypeState}
                allowSerialTypes={!isEditing}
                existing={isEditing}
              />
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
                    getMysqlColumnDefaultPlaceholder(typeState.typeId),
                  )}
                />
                <p className="text-[11px] text-muted-foreground">
                  {t(
                    'A literal or SQL expression, for example now() or gen_random_uuid().',
                  )}
                </p>
              </div>
            </section>

            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Foreign key')}
              </h4>
              <MysqlForeignKeySelector
                value={foreignKeyState}
                onChange={setForeignKeyState}
                projectId={projectId}
                databaseId={databaseId}
                defaultSchema={tableSchema}
                active={open}
                hasMultipleForeignKeys={hasMultipleForeignKeys}
              />
            </section>

            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Constraints')}
              </h4>
              <div className="space-y-2">
                <ConstraintToggle
                  id="column-primary-key"
                  label={t('Primary key')}
                  description={t(
                    'Use this column as a unique identifier for rows in the table.',
                  )}
                  checked={primaryKey}
                  onCheckedChange={handlePrimaryKeyChange}
                />
                <ConstraintToggle
                  id="column-nullable"
                  label={t('Allow nullable')}
                  description={t(
                    'Allow the column to be NULL when no value is provided.',
                  )}
                  checked={primaryKey ? false : nullable}
                  onCheckedChange={setNullable}
                  disabled={primaryKey}
                />
                <ConstraintToggle
                  id="column-unique"
                  label={t('Unique')}
                  description={t(
                    'Require values in this column to be unique across rows.',
                  )}
                  checked={primaryKey ? true : unique}
                  onCheckedChange={setUnique}
                  disabled={primaryKey}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="column-check" className="text-[12px] font-medium">
                  {t('Check constraint')}
                </Label>
                <p className="text-[11px] text-muted-foreground">
                  {t('Optional SQL expression, for example')}{' '}
                  <code className="font-mono text-[11px]">
                    length(column_name) &lt; 500
                  </code>
                  .
                </p>
                {hasMultipleChecks ? (
                  <p className="text-[11px] text-muted-foreground">
                    {t(
                      'This column has multiple check constraints. Saving replaces them with a single check.',
                    )}
                  </p>
                ) : null}
                <Textarea
                  id="column-check"
                  value={checkExpression}
                  onChange={(event) => setCheckExpression(event.target.value)}
                  rows={2}
                  className="min-h-[72px] resize-y font-mono"
                  placeholder="length(column_name) < 500"
                />
              </div>
            </section>
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
