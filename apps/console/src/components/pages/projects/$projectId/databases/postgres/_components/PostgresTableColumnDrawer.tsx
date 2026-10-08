import { useEffect, useRef, useState } from 'react'
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
  buildPostgresAddPrimaryKeySql,
  buildPostgresAddUniqueConstraintSql,
  buildPostgresAlterColumnDefaultSql,
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
  getPostgresColumnTypeDefinition,
  parsePostgresColumnTypeFromRow,
  postgresColumnTypeStatesEqual,
  validatePostgresColumnTypeState,
  type PostgresColumnTypeState,
} from '@/lib/postgres-column-types'
import {
  getSqlColumnCheckExample,
  isTextColumnComparedToNumber,
} from '@/lib/sql-column-form-hints'
import {
  getPreferredSqlColumnDefaultKind,
  parsePostgresStoredColumnDefault,
  resolveSqlColumnDefaultEmission,
  sqlColumnDefaultIsNull,
  sqlColumnDefaultsEqual,
  storedColumnDefaultForForm,
  type SqlColumnDefaultKind,
} from '@/lib/sql-column-default'
import { ColumnDefaultValueField } from '@/components/pages/projects/$projectId/databases/_components/ColumnDefaultValueField'
import {
  isPostgresPrimaryKeyColumn,
  isPostgresUniqueColumn,
  runPostgresDdlStatements,
  type PostgresTableColumnRow,
} from '@/lib/postgres-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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

function preferredPostgresColumnDefaultKind(
  typeState: PostgresColumnTypeState,
): SqlColumnDefaultKind {
  const definition = getPostgresColumnTypeDefinition(typeState.typeId)
  return getPreferredSqlColumnDefaultKind({
    typeGroup: definition.group,
    typeId: typeState.typeId,
  })
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
  const [primaryKey, setPrimaryKey] = useState(false)
  const [unique, setUnique] = useState(false)
  const [defaultValue, setDefaultValue] = useState('')
  const [defaultKind, setDefaultKind] = useState<SqlColumnDefaultKind>('value')
  const [defaultIsNull, setDefaultIsNull] = useState(true)
  const [comment, setComment] = useState('')
  const [checkExpression, setCheckExpression] = useState('')
  const [foreignKeyState, setForeignKeyState] = useState<PostgresForeignKeyState>(
    () => createEmptyPostgresForeignKeyState(tableSchema),
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
      const isPrimary = isPostgresPrimaryKeyColumn(column)
      const nextTypeState = parsePostgresColumnTypeFromRow(column)
      setName(column.column_name)
      setTypeState(nextTypeState)
      setNullable(column.is_nullable === 'YES')
      setPrimaryKey(isPrimary)
      setUnique(isPrimary || isPostgresUniqueColumn(column))
      const parsedDefault = storedColumnDefaultForForm(
        parsePostgresStoredColumnDefault(column.column_default),
        preferredPostgresColumnDefaultKind(nextTypeState),
      )
      setDefaultValue(parsedDefault.value)
      setDefaultKind(parsedDefault.kind)
      setDefaultIsNull(sqlColumnDefaultIsNull(parsedDefault))
      setComment(column.column_comment ?? '')
      setCheckExpression(getPostgresColumnCheckExpressionForEdit(column.check_constraints))
      setForeignKeyState(
        parsePostgresForeignKeyStateFromRow(column.foreign_keys, tableSchema),
      )
    } else {
      setName('')
      setTypeState(createDefaultPostgresColumnTypeState())
      setNullable(true)
      setPrimaryKey(false)
      setUnique(false)
      setDefaultValue('')
      setDefaultKind(
        preferredPostgresColumnDefaultKind(createDefaultPostgresColumnTypeState()),
      )
      setDefaultIsNull(true)
      setComment('')
      setCheckExpression('')
      setForeignKeyState(createEmptyPostgresForeignKeyState(tableSchema))
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

    const typeError = validatePostgresColumnTypeState(typeState)
    if (typeError) {
      toast.error(typeError)
      return
    }

    const nextComment = normalizeOptionalText(comment)
    const nextCheckExpression = normalizeOptionalText(checkExpression)
    const defaultEmission = resolveSqlColumnDefaultEmission({
      isNull: defaultIsNull,
      value: defaultValue,
      kind: defaultKind,
    })

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
        const nextNullable = primaryKey ? false : nullable
        if (nextNullable !== wasNullable) {
          statements.push(
            buildPostgresAlterColumnNullableSql(
              tableId,
              trimmedName,
              nextNullable,
            ),
          )
        }

        const previousDefault = storedColumnDefaultForForm(
          parsePostgresStoredColumnDefault(column.column_default),
          preferredPostgresColumnDefaultKind(currentTypeState),
        )
        const nextParsedDefault = {
          kind: defaultKind,
          value: typeof defaultEmission === 'object' ? defaultEmission.value : '',
          isNull: defaultEmission === 'null',
        }
        if (!sqlColumnDefaultsEqual(previousDefault, nextParsedDefault)) {
          statements.push(
            buildPostgresAlterColumnDefaultSql(
              tableId,
              trimmedName,
              typeof defaultEmission === 'object' ? defaultEmission.value : null,
              defaultKind,
              defaultEmission === 'null',
            ),
          )
        }

        const wasPrimary = isPostgresPrimaryKeyColumn(column)
        const hadStandaloneUnique = isPostgresUniqueColumn(column)
        if (primaryKey !== wasPrimary) {
          if (wasPrimary && column.primary_key_constraint) {
            statements.push(
              buildPostgresDropConstraintSql(
                tableId,
                column.primary_key_constraint,
              ),
            )
          }
          if (primaryKey) {
            statements.push(
              buildPostgresAddPrimaryKeySql(
                tableId,
                trimmedName,
                buildPostgresColumnConstraintName(tableName, trimmedName, 'pkey'),
              ),
            )
          }
        }

        if (primaryKey) {
          // Primary key already enforces uniqueness.
          if (hadStandaloneUnique && column.unique_constraint) {
            statements.push(
              buildPostgresDropConstraintSql(tableId, column.unique_constraint),
            )
          }
        } else if (hadStandaloneUnique && !unique && column.unique_constraint) {
          statements.push(
            buildPostgresDropConstraintSql(tableId, column.unique_constraint),
          )
        } else if (!hadStandaloneUnique && unique) {
          statements.push(
            buildPostgresAddUniqueConstraintSql(
              tableId,
              trimmedName,
              buildPostgresColumnConstraintName(tableName, trimmedName, 'key'),
            ),
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

      await runPostgresDdlStatements(executeSql.mutateAsync, statements)
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
    isEditing && parsePostgresColumnCheckConstraints(column?.check_constraints).length > 1
  const hasMultipleForeignKeys =
    isEditing && parsePostgresColumnForeignKeys(column?.foreign_keys).length > 1
  const isExistingPrimaryKey =
    isEditing && column != null && isPostgresPrimaryKeyColumn(column)
  const typeGroup = getPostgresColumnTypeDefinition(typeState.typeId).group
  const checkExample = getSqlColumnCheckExample(name, typeGroup)
  const showTextNumberCheckHint =
    typeGroup === 'Text' && isTextColumnComparedToNumber(checkExpression)

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
              <PostgresColumnTypeSelector
                value={typeState}
                onChange={(next) => {
                  setTypeState(next)
                  if (next.isArray && defaultValue.trim()) {
                    setDefaultValue('')
                  }
                  if (defaultIsNull || !defaultValue.trim() || next.isArray) {
                    setDefaultKind(preferredPostgresColumnDefaultKind(next))
                  }
                }}
                allowSerialTypes={!isEditing}
              />
              {!typeState.isArray ? (
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
                expressionPlaceholder={getPostgresColumnDefaultPlaceholder(
                  typeState,
                )}
                expressionHint="Passed to the database as SQL, for example now() or gen_random_uuid()."
                nullDisabled={primaryKey}
              />
              ) : null}
            </section>

            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Foreign key')}
              </h4>
              <PostgresForeignKeySelector
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
                  onCheckedChange={handleNullableChange}
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
