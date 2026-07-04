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
  buildPostgresColumnCommentSql,
  buildPostgresCreateIndexSql,
  buildPostgresIndexCommentSql,
  buildPostgresTableCommentSql,
} from '@/lib/postgres-table-ddl'
import {
  buildPostgresColumnTypeSql,
  createDefaultPostgresColumnTypeState,
  getPostgresColumnDefaultPlaceholder,
  validatePostgresColumnTypeState,
  type PostgresColumnTypeState,
} from '@/lib/postgres-column-types'
import {
  createDefaultPostgresIndexFormState,
  validatePostgresIndexFormState,
  type PostgresIndexFormState,
} from '@/lib/postgres-index-metadata'
import { postgresTableId, quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import { useExecutePostgresSql } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PostgresColumnTypeSelector } from './PostgresColumnTypeSelector'
import { PostgresIndexAlgorithmSelector } from './PostgresIndexAlgorithmSelector'
import { useT } from '@/lib/i18n/translate'

type CreateTableProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  defaultSchema?: string | null
  onSuccess: (result: { tableId: string; schema: string; table: string }) => void
}

type DraftColumn = {
  id: string
  name: string
  typeState: PostgresColumnTypeState
  nullable: boolean
  defaultValue: string
  comment: string
  primaryKey: boolean
}

type DraftIndex = {
  id: string
  formState: PostgresIndexFormState
}

const POSTGRES_IDENTIFIER_REGEX = /^[A-Za-z_][A-Za-z0-9_]*$/

function createDraftColumn(): DraftColumn {
  return {
    id: crypto.randomUUID(),
    name: '',
    typeState: createDefaultPostgresColumnTypeState(),
    nullable: true,
    defaultValue: '',
    comment: '',
    primaryKey: false,
  }
}

function createDraftIndex(): DraftIndex {
  return {
    id: crypto.randomUUID(),
    formState: createDefaultPostgresIndexFormState(),
  }
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

export function CreateTable({
  open,
  onOpenChange,
  projectId,
  databaseId,
  defaultSchema,
  onSuccess,
}: CreateTableProps) {
  const t = useT()
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [schemaName, setSchemaName] = useState('public')
  const [tableName, setTableName] = useState('')
  const [tableComment, setTableComment] = useState('')
  const [columns, setColumns] = useState<DraftColumn[]>([createDraftColumn()])
  const [indexes, setIndexes] = useState<DraftIndex[]>([])

  useEffect(() => {
    if (!open) return
    setSchemaName((defaultSchema ?? 'public').trim() || 'public')
    setTableName('')
    setTableComment('')
    setColumns([createDraftColumn()])
    setIndexes([])
  }, [defaultSchema, open])

  const normalizedColumnNames = useMemo(
    () =>
      columns
        .map((column) => column.name.trim())
        .filter((name) => POSTGRES_IDENTIFIER_REGEX.test(name)),
    [columns],
  )

  const handleSubmit = async () => {
    const normalizedSchema = schemaName.trim()
    const normalizedTableName = tableName.trim()

    if (!normalizedSchema) {
      toast.error(t('Schema name is required'))
      return
    }
    if (!POSTGRES_IDENTIFIER_REGEX.test(normalizedSchema)) {
      toast.error(t('Schema name must use letters, numbers, and underscores only.'))
      return
    }
    if (!normalizedTableName) {
      toast.error(t('Table name is required'))
      return
    }
    if (!POSTGRES_IDENTIFIER_REGEX.test(normalizedTableName)) {
      toast.error(t('Table name must use letters, numbers, and underscores only.'))
      return
    }
    if (columns.length === 0) {
      toast.error(t('At least one column is required.'))
      return
    }

    try {
      const seenColumnNames = new Set<string>()
      const normalizedColumns = columns.map((column, index) => {
        const normalizedColumnName = column.name.trim()
        if (!normalizedColumnName) {
          throw new Error(`${t('Column')} ${index + 1}: ${t('Name is required')}.`)
        }
        if (!POSTGRES_IDENTIFIER_REGEX.test(normalizedColumnName)) {
          throw new Error(
            t('Column names must use letters, numbers, and underscores only.'),
          )
        }
        if (seenColumnNames.has(normalizedColumnName)) {
          throw new Error(t('Column names must be unique.'))
        }
        seenColumnNames.add(normalizedColumnName)

        const typeError = validatePostgresColumnTypeState(column.typeState)
        if (typeError) {
          throw new Error(t('One or more columns have an invalid type configuration.'))
        }

        return {
          ...column,
          name: normalizedColumnName,
          nullable: column.primaryKey ? false : column.nullable,
          defaultValue: column.defaultValue.trim(),
          comment: column.comment.trim(),
        }
      })

      const seenIndexNames = new Set<string>()
      const normalizedIndexes = indexes.map((index) => {
        const validationError = validatePostgresIndexFormState(index.formState)
        if (validationError) {
          throw new Error(validationError)
        }

        const normalizedIndexName = index.formState.name.trim()
        if (!POSTGRES_IDENTIFIER_REGEX.test(normalizedIndexName)) {
          throw new Error(
            t('Index names must use letters, numbers, and underscores only.'),
          )
        }
        if (seenIndexNames.has(normalizedIndexName)) {
          throw new Error(t('Index names must be unique.'))
        }
        seenIndexNames.add(normalizedIndexName)

        const missingColumn = index.formState.columns.find(
          (columnName) => !seenColumnNames.has(columnName),
        )
        if (missingColumn) {
          throw new Error(t('One or more indexes reference unknown columns.'))
        }

        return {
          ...index.formState,
          name: normalizedIndexName,
          condition: index.formState.condition.trim(),
          comment: index.formState.comment.trim(),
        }
      })

      const tableId = postgresTableId(normalizedSchema, normalizedTableName)
      const primaryKeyColumns = normalizedColumns
        .filter((column) => column.primaryKey)
        .map((column) => column.name)

      const columnDefinitions = normalizedColumns.map((column) => {
        const pieces = [
          `${quotePostgresIdentifier(column.name)} ${buildPostgresColumnTypeSql(column.typeState)}`,
        ]
        if (!column.nullable) {
          pieces.push('NOT NULL')
        }
        if (column.defaultValue) {
          pieces.push(`DEFAULT ${column.defaultValue}`)
        }
        return pieces.join(' ')
      })

      if (primaryKeyColumns.length > 0) {
        columnDefinitions.push(
          `PRIMARY KEY (${primaryKeyColumns.map((column) => quotePostgresIdentifier(column)).join(', ')})`,
        )
      }

      const qualifiedTableName = `${quotePostgresIdentifier(normalizedSchema)}.${quotePostgresIdentifier(normalizedTableName)}`

      const statements: string[] = [
        `CREATE TABLE ${qualifiedTableName} (\n  ${columnDefinitions.join(',\n  ')}\n)`,
      ]

      if (tableComment.trim()) {
        statements.push(buildPostgresTableCommentSql(tableId, tableComment.trim()))
      }

      for (const column of normalizedColumns) {
        if (column.comment) {
          statements.push(
            buildPostgresColumnCommentSql(tableId, column.name, column.comment),
          )
        }
      }

      for (const index of normalizedIndexes) {
        statements.push(
          buildPostgresCreateIndexSql(tableId, index.name, index.columns, {
            unique: index.unique,
            algorithm: index.algorithm,
            condition: index.condition || undefined,
            includeColumns: index.includeColumns,
          }),
        )
        if (index.comment) {
          statements.push(
            buildPostgresIndexCommentSql(normalizedSchema, index.name, index.comment),
          )
        }
      }

      await executeSql.mutateAsync(statements.join(';\n'))
      toast.success(t('Table created'))
      onOpenChange(false)
      onSuccess({
        tableId,
        schema: normalizedSchema,
        table: normalizedTableName,
      })
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to create table'))
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Create table')}
      description={t(
        'Create a table with columns, indexes, and key settings in a single flow.',
      )}
      maxWidth="sm:max-w-2xl"
    >
      <>
        <div className="border-t border-border" />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void handleSubmit()
          }}
          className="flex min-h-0 flex-1 flex-col"
        >
          <div className="flex-1 space-y-5 overflow-y-auto px-6 pb-4 pt-4">
            <section className="space-y-3">
              <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Table settings')}
              </h4>
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="table-schema" className="text-[12px] font-medium">
                    {t('Schema')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="table-schema"
                    value={schemaName}
                    onChange={(event) => setSchemaName(event.target.value)}
                    placeholder="public"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="table-name" className="text-[12px] font-medium">
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="table-name"
                    value={tableName}
                    onChange={(event) => setTableName(event.target.value)}
                    placeholder="users"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label htmlFor="table-comment" className="text-[12px] font-medium">
                  {t('Comment')}
                </Label>
                <Textarea
                  id="table-comment"
                  value={tableComment}
                  onChange={(event) => setTableComment(event.target.value)}
                  rows={2}
                  className="min-h-[80px] resize-y"
                  placeholder={t('Describe what this table stores')}
                />
              </div>
            </section>

            <section className="space-y-3">
              <div className="flex items-center justify-between gap-3">
                <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Columns')}
                </h4>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() =>
                    setColumns((current) => [...current, createDraftColumn()])
                  }
                >
                  {t('Add column')}
                </Button>
              </div>
              <div className="space-y-3">
                {columns.map((column, index) => (
                  <div
                    key={column.id}
                    className="rounded-lg border border-border bg-card/50 p-3 space-y-3"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-[12px] font-medium text-foreground">
                        {t('Column')} {index + 1}
                      </span>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-[11px]"
                        onClick={() =>
                          setColumns((current) =>
                            current.filter((entry) => entry.id !== column.id),
                          )
                        }
                        disabled={columns.length <= 1}
                      >
                        {t('Remove')}
                      </Button>
                    </div>
                    <div className="space-y-2">
                      <Label
                        htmlFor={`column-name-${column.id}`}
                        className="text-[12px] font-medium"
                      >
                        {t('Name')} <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id={`column-name-${column.id}`}
                        value={column.name}
                        onChange={(event) =>
                          setColumns((current) =>
                            current.map((entry) =>
                              entry.id === column.id
                                ? { ...entry, name: event.target.value }
                                : entry,
                            ),
                          )
                        }
                        placeholder="id"
                      />
                    </div>
                    <PostgresColumnTypeSelector
                      value={column.typeState}
                      onChange={(typeState) =>
                        setColumns((current) =>
                          current.map((entry) =>
                            entry.id === column.id ? { ...entry, typeState } : entry,
                          ),
                        )
                      }
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                      <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2">
                        <div>
                          <Label className="text-[12px] font-medium">
                            {t('Nullable')}
                          </Label>
                        </div>
                        <Switch
                          checked={column.nullable}
                          onCheckedChange={(nullable) =>
                            setColumns((current) =>
                              current.map((entry) =>
                                entry.id === column.id
                                  ? { ...entry, nullable }
                                  : entry,
                              ),
                            )
                          }
                          disabled={column.primaryKey}
                        />
                      </div>
                      <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2">
                        <div>
                          <Label className="text-[12px] font-medium">
                            {t('Primary key')}
                          </Label>
                        </div>
                        <Switch
                          checked={column.primaryKey}
                          onCheckedChange={(primaryKey) =>
                            setColumns((current) =>
                              current.map((entry) =>
                                entry.id === column.id
                                  ? {
                                      ...entry,
                                      primaryKey,
                                      nullable: primaryKey ? false : entry.nullable,
                                    }
                                  : entry,
                              ),
                            )
                          }
                        />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label
                        htmlFor={`column-default-${column.id}`}
                        className="text-[12px] font-medium"
                      >
                        {t('Default value')}
                      </Label>
                      <Input
                        id={`column-default-${column.id}`}
                        value={column.defaultValue}
                        onChange={(event) =>
                          setColumns((current) =>
                            current.map((entry) =>
                              entry.id === column.id
                                ? { ...entry, defaultValue: event.target.value }
                                : entry,
                            ),
                          )
                        }
                        className="font-mono"
                        placeholder={t(
                          getPostgresColumnDefaultPlaceholder(column.typeState.typeId),
                        )}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label
                        htmlFor={`column-comment-${column.id}`}
                        className="text-[12px] font-medium"
                      >
                        {t('Comment')}
                      </Label>
                      <Textarea
                        id={`column-comment-${column.id}`}
                        value={column.comment}
                        onChange={(event) =>
                          setColumns((current) =>
                            current.map((entry) =>
                              entry.id === column.id
                                ? { ...entry, comment: event.target.value }
                                : entry,
                            ),
                          )
                        }
                        rows={2}
                        className="min-h-[70px] resize-y"
                        placeholder={t('Describe what this column stores')}
                      />
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="space-y-3">
              <div className="flex items-center justify-between gap-3">
                <h4 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Indexes')}
                </h4>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() =>
                    setIndexes((current) => [...current, createDraftIndex()])
                  }
                  disabled={normalizedColumnNames.length === 0}
                >
                  {t('Add index')}
                </Button>
              </div>
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'Indexes are optional, but adding the most common ones now can improve query performance immediately.',
                )}
              </p>
              <div className="space-y-3">
                {indexes.map((index, indexPosition) => {
                  const keyColumns = index.formState.columns
                  const includeCandidates = normalizedColumnNames.filter(
                    (columnName) => !keyColumns.includes(columnName),
                  )

                  return (
                    <div
                      key={index.id}
                      className="rounded-lg border border-border bg-card/50 p-3 space-y-3"
                    >
                      <div className="flex items-center justify-between gap-3">
                        <span className="text-[12px] font-medium text-foreground">
                          {t('Index')} {indexPosition + 1}
                        </span>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-7 text-[11px]"
                          onClick={() =>
                            setIndexes((current) =>
                              current.filter((entry) => entry.id !== index.id),
                            )
                          }
                        >
                          {t('Remove')}
                        </Button>
                      </div>
                      <div className="space-y-2">
                        <Label
                          htmlFor={`index-name-${index.id}`}
                          className="text-[12px] font-medium"
                        >
                          {t('Name')} <span className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`index-name-${index.id}`}
                          value={index.formState.name}
                          onChange={(event) =>
                            setIndexes((current) =>
                              current.map((entry) =>
                                entry.id === index.id
                                  ? {
                                      ...entry,
                                      formState: {
                                        ...entry.formState,
                                        name: event.target.value,
                                      },
                                    }
                                  : entry,
                              ),
                            )
                          }
                          placeholder="idx_users_email"
                        />
                      </div>
                      <PostgresIndexAlgorithmSelector
                        value={index.formState.algorithm}
                        onChange={(algorithm) =>
                          setIndexes((current) =>
                            current.map((entry) =>
                              entry.id === index.id
                                ? {
                                    ...entry,
                                    formState: {
                                      ...entry.formState,
                                      algorithm,
                                      columns:
                                        algorithm === 'hash' &&
                                        entry.formState.columns.length > 1
                                          ? entry.formState.columns.slice(0, 1)
                                          : entry.formState.columns,
                                    },
                                  }
                                : entry,
                            ),
                          )
                        }
                      />
                      <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2">
                        <div>
                          <Label className="text-[12px] font-medium">
                            {t('Unique')}
                          </Label>
                        </div>
                        <Switch
                          checked={index.formState.unique}
                          onCheckedChange={(unique) =>
                            setIndexes((current) =>
                              current.map((entry) =>
                                entry.id === index.id
                                  ? {
                                      ...entry,
                                      formState: { ...entry.formState, unique },
                                    }
                                  : entry,
                              ),
                            )
                          }
                        />
                      </div>
                      <div className="space-y-2">
                        <Label className="text-[12px] font-medium">
                          {t('Key columns')} <span className="text-destructive">*</span>
                        </Label>
                        {normalizedColumnNames.length === 0 ? (
                          <p className="text-[12px] text-muted-foreground">
                            {t('Add columns before selecting index keys.')}
                          </p>
                        ) : (
                          <div className="rounded-lg border border-border divide-y divide-border">
                            {normalizedColumnNames.map((columnName) => {
                              const selectedOrder =
                                index.formState.columns.indexOf(columnName)
                              const isSelected = selectedOrder >= 0
                              const disableAdd =
                                index.formState.algorithm === 'hash' &&
                                index.formState.columns.length >= 1 &&
                                !isSelected

                              return (
                                <label
                                  key={columnName}
                                  className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-muted/40"
                                >
                                  <Checkbox
                                    checked={isSelected}
                                    disabled={disableAdd}
                                    onCheckedChange={(checked) =>
                                      setIndexes((current) =>
                                        current.map((entry) =>
                                          entry.id === index.id
                                            ? {
                                                ...entry,
                                                formState: {
                                                  ...entry.formState,
                                                  columns: toggleOrderedColumn(
                                                    entry.formState.columns,
                                                    columnName,
                                                    checked === true,
                                                  ),
                                                  includeColumns:
                                                    entry.formState.includeColumns.filter(
                                                      (name) => name !== columnName,
                                                    ),
                                                },
                                              }
                                            : entry,
                                        ),
                                      )
                                    }
                                  />
                                  <span className="flex-1 text-[12px]">
                                    {columnName}
                                  </span>
                                  {isSelected ? (
                                    <span className="text-[11px] tabular-nums text-muted-foreground">
                                      #{selectedOrder + 1}
                                    </span>
                                  ) : null}
                                </label>
                              )
                            })}
                          </div>
                        )}
                      </div>
                      <div className="space-y-2">
                        <Label
                          htmlFor={`index-condition-${index.id}`}
                          className="text-[12px] font-medium"
                        >
                          {t('Condition')}
                        </Label>
                        <Textarea
                          id={`index-condition-${index.id}`}
                          value={index.formState.condition}
                          onChange={(event) =>
                            setIndexes((current) =>
                              current.map((entry) =>
                                entry.id === index.id
                                  ? {
                                      ...entry,
                                      formState: {
                                        ...entry.formState,
                                        condition: event.target.value,
                                      },
                                    }
                                  : entry,
                              ),
                            )
                          }
                          rows={2}
                          className="min-h-[80px] resize-y font-mono"
                          placeholder="deleted_at IS NULL"
                        />
                      </div>
                      <div className="space-y-2">
                        <Label className="text-[12px] font-medium">
                          {t('Include columns')}
                        </Label>
                        {includeCandidates.length === 0 ? (
                          <p className="text-[12px] text-muted-foreground">
                            {t('Select key columns first.')}
                          </p>
                        ) : (
                          <div className="rounded-lg border border-border divide-y divide-border">
                            {includeCandidates.map((columnName) => (
                              <label
                                key={columnName}
                                className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-muted/40"
                              >
                                <Checkbox
                                  checked={index.formState.includeColumns.includes(
                                    columnName,
                                  )}
                                  onCheckedChange={(checked) =>
                                    setIndexes((current) =>
                                      current.map((entry) =>
                                        entry.id === index.id
                                          ? {
                                              ...entry,
                                              formState: {
                                                ...entry.formState,
                                                includeColumns: toggleIncludeColumn(
                                                  entry.formState.includeColumns,
                                                  columnName,
                                                  checked === true,
                                                ),
                                              },
                                            }
                                          : entry,
                                      ),
                                    )
                                  }
                                />
                                <span className="text-[12px]">{columnName}</span>
                              </label>
                            ))}
                          </div>
                        )}
                      </div>
                      <div className="space-y-2">
                        <Label
                          htmlFor={`index-comment-${index.id}`}
                          className="text-[12px] font-medium"
                        >
                          {t('Comment')}
                        </Label>
                        <Textarea
                          id={`index-comment-${index.id}`}
                          value={index.formState.comment}
                          onChange={(event) =>
                            setIndexes((current) =>
                              current.map((entry) =>
                                entry.id === index.id
                                  ? {
                                      ...entry,
                                      formState: {
                                        ...entry.formState,
                                        comment: event.target.value,
                                      },
                                    }
                                  : entry,
                              ),
                            )
                          }
                          rows={2}
                          className="min-h-[80px] resize-y"
                          placeholder={t('Describe what this index is for')}
                        />
                      </div>
                    </div>
                  )
                })}
              </div>
            </section>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={executeSql.isPending}>
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
