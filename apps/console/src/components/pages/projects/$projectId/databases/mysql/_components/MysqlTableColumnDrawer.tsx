import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
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
  mysqlTypeRequiresIndexKeyLength,
  mysqlTypeSupportsPrefixIndex,
} from '@/lib/mysql-table-ddl'
import {
  buildMysqlColumnTypeSql,
  createDefaultMysqlColumnTypeState,
  getMysqlColumnDefaultPlaceholder,
  getMysqlColumnTypeDefinition,
  parseMysqlColumnTypeFromRow,
  mysqlColumnTypeStatesEqual,
  validateMysqlColumnTypeState,
  type MysqlColumnTypeState,
} from '@/lib/mysql-column-types'
import {
  getSqlColumnCheckExample,
  isTextColumnComparedToNumber,
} from '@/lib/sql-column-form-hints'
import {
  getPreferredSqlColumnDefaultKind,
  parseMysqlStoredColumnDefault,
  resolveSqlColumnDefaultEmission,
  sqlColumnDefaultIsNull,
  sqlColumnDefaultsEqual,
  storedColumnDefaultForForm,
  type SqlColumnDefaultKind,
} from '@/lib/sql-column-default'
import { ColumnDefaultValueField } from '@/components/pages/projects/$projectId/databases/_components/ColumnDefaultValueField'
import {
  isMysqlPrimaryKeyColumn,
  isMysqlUniqueColumn,
  runMysqlDdlStatements,
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
  disabledTooltip,
}: {
  id: string
  label: string
  description: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
  disabledTooltip?: string
}) {
  const switchControl = (
    <Switch
      id={id}
      checked={checked}
      onCheckedChange={onCheckedChange}
      disabled={disabled}
    />
  )

  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2.5">
      <div className="min-w-0">
        <Label htmlFor={id} className="text-[12px] font-medium">
          {label}
        </Label>
        <p className="text-[11px] text-muted-foreground mt-1">{description}</p>
      </div>
      {disabled && disabledTooltip ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex shrink-0">{switchControl}</span>
          </TooltipTrigger>
          <TooltipContent className="max-w-xs">{disabledTooltip}</TooltipContent>
        </Tooltip>
      ) : (
        switchControl
      )}
    </div>
  )
}

function preferredMysqlColumnDefaultKind(
  typeState: MysqlColumnTypeState,
): SqlColumnDefaultKind {
  const definition = getMysqlColumnTypeDefinition(typeState.typeId)
  return getPreferredSqlColumnDefaultKind({
    typeGroup: definition.group,
    typeId: typeState.typeId,
  })
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
  const [defaultKind, setDefaultKind] = useState<SqlColumnDefaultKind>('value')
  const [defaultIsNull, setDefaultIsNull] = useState(true)
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
      const nextTypeState = parseMysqlColumnTypeFromRow(column)
      setName(column.column_name)
      setTypeState(nextTypeState)
      setNullable(column.is_nullable === 'YES')
      setPrimaryKey(isPrimary)
      setUnique(isPrimary || isMysqlUniqueColumn(column))
      const parsedDefault = storedColumnDefaultForForm(
        parseMysqlStoredColumnDefault(column.column_default),
        preferredMysqlColumnDefaultKind(nextTypeState),
      )
      setDefaultValue(parsedDefault.value)
      setDefaultKind(parsedDefault.kind)
      setDefaultIsNull(sqlColumnDefaultIsNull(parsedDefault))
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
      setDefaultKind(
        preferredMysqlColumnDefaultKind(createDefaultMysqlColumnTypeState()),
      )
      setDefaultIsNull(true)
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
      setDefaultIsNull(false)
    }
  }

  const handleNullableChange = (checked: boolean) => {
    setNullable(checked)
    if (!checked) setDefaultIsNull(false)
  }

  const handleDefaultNullChange = (isNull: boolean) => {
    setDefaultIsNull(isNull)
    if (isNull) {
      setDefaultValue('')
      setNullable(true)
      setPrimaryKey(false)
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
    const defaultEmission = resolveSqlColumnDefaultEmission({
      isNull: defaultIsNull,
      value: defaultValue,
      kind: defaultKind,
    })

    const foreignKeyError = validateMysqlForeignKeyState(foreignKeyState)
    if (foreignKeyError) {
      toast.error(foreignKeyError)
      return
    }

    const nextForeignKeyReference = getMysqlForeignKeyReferenceForCompare(
      foreignKeyState,
    )

    const dataType = buildMysqlColumnTypeSql(typeState)
    if (
      (primaryKey || unique) &&
      mysqlTypeRequiresIndexKeyLength(dataType) &&
      !mysqlTypeSupportsPrefixIndex(dataType)
    ) {
      toast.error(
        t(
          'JSON columns cannot be uniquely indexed. Use VARCHAR, or store a unique key in a separate column.',
        ),
      )
      return
    }
    const statements: string[] = []

    try {
      if (!isEditing) {
        statements.push(
          buildMysqlAddColumnSql(tableId, trimmedName, dataType, {
            nullable: primaryKey ? false : nullable,
            defaultIsNull: defaultEmission === 'null',
            defaultValue:
              typeof defaultEmission === 'object'
                ? defaultEmission.value
                : undefined,
            defaultKind:
              typeof defaultEmission === 'object'
                ? defaultEmission.kind
                : defaultKind,
            primaryKey,
            unique: unique && !primaryKey,
            comment: nextComment || undefined,
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

        const previousDefault = storedColumnDefaultForForm(
          parseMysqlStoredColumnDefault(column.column_default),
          preferredMysqlColumnDefaultKind(currentTypeState),
        )
        const nextParsedDefault = {
          kind: defaultKind,
          value: typeof defaultEmission === 'object' ? defaultEmission.value : '',
          isNull: defaultEmission === 'null',
        }
        if (!sqlColumnDefaultsEqual(previousDefault, nextParsedDefault)) {
          statements.push(
            buildMysqlAlterColumnDefaultSql(
              tableId,
              trimmedName,
              typeof defaultEmission === 'object' ? defaultEmission.value : null,
              dataType,
              defaultKind,
              defaultEmission === 'null',
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
                dataType,
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
              dataType,
            ),
          )
        }
      }

      const previousComment = normalizeOptionalText(column?.column_comment ?? '')
      if (isEditing && nextComment !== previousComment) {
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

      await runMysqlDdlStatements(executeSql.mutateAsync, statements)
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
  const dataTypeSql = buildMysqlColumnTypeSql(typeState)
  const typeGroup = getMysqlColumnTypeDefinition(typeState.typeId).group
  const checkExample = getSqlColumnCheckExample(name, typeGroup)
  const showTextNumberCheckHint =
    typeGroup === 'Text' && isTextColumnComparedToNumber(checkExpression)
  const uniqueNeedsPrefix = mysqlTypeSupportsPrefixIndex(dataTypeSql)
  const uniqueUnsupported =
    mysqlTypeRequiresIndexKeyLength(dataTypeSql) && !uniqueNeedsPrefix
  const uniqueDisabledReason = uniqueUnsupported
    ? t(
        'JSON columns cannot be uniquely indexed. Use VARCHAR, or store a unique key in a separate column.',
      )
    : undefined

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
                onChange={(next) => {
                  setTypeState(next)
                  const nextType = buildMysqlColumnTypeSql(next)
                  if (
                    mysqlTypeRequiresIndexKeyLength(nextType) &&
                    !mysqlTypeSupportsPrefixIndex(nextType)
                  ) {
                    setPrimaryKey(false)
                    setUnique(false)
                  }
                  if (defaultIsNull || !defaultValue.trim()) {
                    setDefaultKind(preferredMysqlColumnDefaultKind(next))
                  }
                }}
                allowSerialTypes={!isEditing}
                existing={isEditing}
              />
              <ColumnDefaultValueField
                id="column-default"
                kind={defaultKind}
                value={defaultValue}
                isNull={defaultIsNull}
                onKindChange={setDefaultKind}
                onValueChange={(next) => {
                  if (next) setDefaultIsNull(false)
                  setDefaultValue(next)
                }}
                onNullChange={handleDefaultNullChange}
                expressionPlaceholder={getMysqlColumnDefaultPlaceholder(
                  typeState.typeId,
                )}
                expressionHint="Passed to the database as SQL, for example CURRENT_TIMESTAMP."
                nullDisabled={primaryKey}
              />
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
                  description={
                    uniqueNeedsPrefix
                      ? t(
                          'MySQL indexes on TEXT and BLOB use the first 255 characters.',
                        )
                      : t(
                          'Use this column as a unique identifier for rows in the table.',
                        )
                  }
                  checked={primaryKey}
                  onCheckedChange={handlePrimaryKeyChange}
                  disabled={uniqueUnsupported}
                  disabledTooltip={uniqueDisabledReason}
                />
                <ConstraintToggle
                  id="column-nullable"
                  label={t('Allow nullable')}
                  description={t(
                    'Allow the column to be NULL when no value is provided.',
                  )}
                  checked={primaryKey ? false : nullable}
                  onCheckedChange={handleNullableChange}
                  disabled={primaryKey}
                />
                <ConstraintToggle
                  id="column-unique"
                  label={t('Unique')}
                  description={
                    uniqueNeedsPrefix
                      ? t(
                          'MySQL indexes on TEXT and BLOB use the first 255 characters.',
                        )
                      : t(
                          'Require values in this column to be unique across rows.',
                        )
                  }
                  checked={primaryKey ? true : unique}
                  onCheckedChange={setUnique}
                  disabled={primaryKey || uniqueUnsupported}
                  disabledTooltip={uniqueDisabledReason}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="column-check" className="text-[12px] font-medium">
                  {t('Check constraint')}
                </Label>
                <p className="text-[11px] text-muted-foreground">
                  {t('Must be valid SQL for this column type, for example')}{' '}
                  <code className="font-mono text-[11px]">{checkExample}</code>
                  .
                </p>
                {showTextNumberCheckHint ? (
                  <p className="text-[11px] text-muted-foreground">
                    {t('This check compares text to a number.')}
                  </p>
                ) : null}
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
                  placeholder={checkExample}
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
