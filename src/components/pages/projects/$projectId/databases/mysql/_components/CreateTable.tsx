import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, GripVertical, Plus, Settings2, X } from 'lucide-react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  buildMysqlCreateIndexSql,
  buildMysqlIndexCommentSql,
  buildMysqlTableCommentSql,
} from '@/lib/mysql-table-ddl'
import {
  buildMysqlColumnTypeSql,
  createDefaultMysqlColumnTypeState,
  getMysqlColumnDefaultPlaceholder,
  validateMysqlColumnTypeState,
  type MysqlColumnTypeState,
} from '@/lib/mysql-column-types'
import {
  createDefaultMysqlIndexFormState,
  validateMysqlIndexFormState,
  type MysqlIndexFormState,
} from '@/lib/mysql-index-metadata'
import { mysqlTableId, quoteMysqlIdentifier } from '@/lib/mysql-database-routes'
import { quoteMysqlStringLiteral } from '@/lib/mysql-sql'
import { useExecuteMysqlSql } from '@/lib/react-query/hooks'
import { useMysqlSidebarSchemas } from '@/lib/react-query/hooks/mysql-databases'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { MysqlColumnTypeSelector } from './MysqlColumnTypeSelector'
import { MysqlIndexAlgorithmSelector } from './MysqlIndexAlgorithmSelector'
import { MysqlSchemaSelector } from './MysqlSchemaSelector'
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
  typeState: MysqlColumnTypeState
  nullable: boolean
  defaultValue: string
  comment: string
  primaryKey: boolean
}

type DraftIndex = {
  id: string
  formState: MysqlIndexFormState
}

/** Unquoted MySQL identifiers (table/column/index names created in the UI). */
const MYSQL_IDENTIFIER_REGEX = /^[A-Za-z_][A-Za-z0-9_]*$/
/**
 * Existing managed schemas are often the database $id and may start with a digit.
 * CREATE TABLE always quotes schema names, so leading digits are valid.
 */
const MYSQL_SCHEMA_IDENTIFIER_REGEX = /^[A-Za-z0-9_]+$/

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
}

type ColumnDropPosition = 'before' | 'after'

type ColumnDropIndicator = {
  index: number
  position: ColumnDropPosition
}

function reorderWithDropIndicator<T>(
  list: T[],
  dragIndex: number,
  indicator: ColumnDropIndicator,
): T[] {
  let insertIndex =
    indicator.position === 'before' ? indicator.index : indicator.index + 1
  if (dragIndex < insertIndex) insertIndex -= 1
  if (dragIndex === insertIndex) return list
  return reorderList(list, dragIndex, insertIndex)
}

function resolveDropIndicatorFromPointer(
  container: HTMLElement,
  clientY: number,
): ColumnDropIndicator | null {
  const rows = container.querySelectorAll<HTMLElement>('[data-column-row]')
  if (!rows.length) return null

  const firstRect = rows[0].getBoundingClientRect()
  if (clientY < firstRect.top) {
    return { index: 0, position: 'before' }
  }

  for (let i = 0; i < rows.length; i++) {
    const rect = rows[i].getBoundingClientRect()
    if (clientY > rect.bottom) continue
    const position: ColumnDropPosition =
      clientY - rect.top < rect.height / 2 ? 'before' : 'after'
    return { index: i, position }
  }

  return { index: rows.length - 1, position: 'after' }
}

function isNoOpColumnDrop(
  dragIndex: number,
  indicator: ColumnDropIndicator,
): boolean {
  let insertIndex =
    indicator.position === 'before' ? indicator.index : indicator.index + 1
  if (dragIndex < insertIndex) insertIndex -= 1
  return dragIndex === insertIndex
}

function createDraftColumn(overrides?: Partial<DraftColumn>): DraftColumn {
  return {
    id: crypto.randomUUID(),
    name: '',
    typeState: createDefaultMysqlColumnTypeState(),
    nullable: true,
    defaultValue: '',
    comment: '',
    primaryKey: false,
    ...overrides,
  }
}

function createDefaultTableColumns(): DraftColumn[] {
  return [
    createDraftColumn({
      name: 'id',
      typeState: createDefaultMysqlColumnTypeState('bigserial'),
      nullable: false,
      primaryKey: true,
    }),
    createDraftColumn({
      name: 'created_at',
      typeState: createDefaultMysqlColumnTypeState('timestamp with time zone'),
      nullable: false,
      defaultValue: 'CURRENT_TIMESTAMP',
    }),
  ]
}

function createDraftIndex(): DraftIndex {
  return {
    id: crypto.randomUUID(),
    formState: createDefaultMysqlIndexFormState(),
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

type CreateTableColumnSettingsProps = {
  column: DraftColumn
  onChange: (patch: Partial<DraftColumn>) => void
  onTypeChange: (typeState: MysqlColumnTypeState) => void
}

function CreateTableColumnSettings({
  column,
  onChange,
  onTypeChange,
}: CreateTableColumnSettingsProps) {
  const t = useT()
  const [open, setOpen] = useState(false)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
          aria-label={t('Column settings')}
        >
          <Settings2 className="h-3.5 w-3.5" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-80 space-y-4 p-4" align="end">
        <div className="space-y-1">
          <p className="text-[13px] font-semibold text-foreground">
            {column.name.trim() || t('Column settings')}
          </p>
          <p className="text-[12px] text-muted-foreground">
            {t('Advanced column options')}
          </p>
        </div>
        <div className="flex items-center justify-between gap-3">
          <Label className="text-[12px] font-medium">{t('Nullable')}</Label>
          <Switch
            checked={column.nullable}
            onCheckedChange={(nullable) => onChange({ nullable })}
            disabled={column.primaryKey}
          />
        </div>
        <MysqlColumnTypeSelector
          value={column.typeState}
          onChange={onTypeChange}
          showTypePicker={false}
          showArrayOption
          showTypeOptions
        />
        <div className="space-y-2">
          <Label htmlFor={`column-comment-${column.id}`} className="text-[12px] font-medium">
            {t('Comment')}
          </Label>
          <Textarea
            id={`column-comment-${column.id}`}
            value={column.comment}
            onChange={(event) => onChange({ comment: event.target.value })}
            rows={2}
            className="min-h-[70px] resize-y text-[13px]"
            placeholder={t('Describe what this column stores')}
          />
        </div>
      </PopoverContent>
    </Popover>
  )
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
  const executeSql = useExecuteMysqlSql(projectId, databaseId)

  const [schemaName, setSchemaName] = useState('public')
  const [tableName, setTableName] = useState('')
  const [tableComment, setTableComment] = useState('')
  const [columns, setColumns] = useState<DraftColumn[]>(createDefaultTableColumns)
  const [focusColumnId, setFocusColumnId] = useState<string | null>(null)
  const [draggingColumnIndex, setDraggingColumnIndex] = useState<number | null>(
    null,
  )
  const [columnDropIndicator, setColumnDropIndicator] =
    useState<ColumnDropIndicator | null>(null)
  const columnListRef = useRef<HTMLDivElement>(null)
  const [indexes, setIndexes] = useState<DraftIndex[]>([])
  const [indexesOpen, setIndexesOpen] = useState(false)
  const [schemaPickerOpen, setSchemaPickerOpen] = useState(false)
  const [schemaPickerSearch, setSchemaPickerSearch] = useState('')
  const [debouncedSchemaPickerSearch, setDebouncedSchemaPickerSearch] =
    useState('')

  useEffect(() => {
    const timeout = window.setTimeout(
      () => setDebouncedSchemaPickerSearch(schemaPickerSearch.trim()),
      300,
    )
    return () => window.clearTimeout(timeout)
  }, [schemaPickerSearch])

  useEffect(() => {
    if (!open) return
    setSchemaName((defaultSchema ?? 'public').trim() || 'public')
    setTableName('')
    setTableComment('')
    setColumns(createDefaultTableColumns())
    setFocusColumnId(null)
    setDraggingColumnIndex(null)
    setColumnDropIndicator(null)
    setIndexes([])
    setIndexesOpen(false)
    setSchemaPickerOpen(false)
    setSchemaPickerSearch('')
    setDebouncedSchemaPickerSearch('')
  }, [defaultSchema, open])

  const {
    schemas: loadedSchemas,
    total: schemasTotal,
    isLoading: schemasLoading,
    isFetching: schemasFetching,
    isFetchingNextPage: isFetchingMoreSchemas,
    hasNextPage: hasMoreSchemas,
    fetchNextPage: fetchNextSchemaPage,
  } = useMysqlSidebarSchemas(
    projectId,
    databaseId,
    open && schemaPickerOpen ? debouncedSchemaPickerSearch : '',
  )

  const handleSchemaSearchChange = useCallback((search: string) => {
    setSchemaPickerSearch(search)
  }, [])

  const normalizedColumnNames = useMemo(
    () =>
      columns
        .map((column) => column.name.trim())
        .filter((name) => MYSQL_IDENTIFIER_REGEX.test(name)),
    [columns],
  )

  const updateColumn = useCallback((columnId: string, patch: Partial<DraftColumn>) => {
    setColumns((current) =>
      current.map((entry) =>
        entry.id === columnId ? { ...entry, ...patch } : entry,
      ),
    )
  }, [])

  const handleAddColumn = () => {
    const next = createDraftColumn()
    setColumns((current) => [...current, next])
    setFocusColumnId(next.id)
  }

  useLayoutEffect(() => {
    if (!focusColumnId) return
    const input = columnListRef.current?.querySelector<HTMLInputElement>(
      `input[data-column-name-id="${focusColumnId}"]`,
    )
    if (!input) return
    input.focus()
    input.scrollIntoView({ block: 'nearest' })
    setFocusColumnId(null)
  }, [columns, focusColumnId])

  const clearColumnDragState = useCallback(() => {
    setDraggingColumnIndex(null)
    setColumnDropIndicator(null)
  }, [])

  const updateColumnDropIndicatorFromPointer = useCallback((clientY: number) => {
    const container = columnListRef.current
    if (!container || draggingColumnIndex == null) return
    const next = resolveDropIndicatorFromPointer(container, clientY)
    if (!next || isNoOpColumnDrop(draggingColumnIndex, next)) {
      setColumnDropIndicator(null)
      return
    }
    setColumnDropIndicator(next)
  }, [draggingColumnIndex])

  const handleColumnDragStart = useCallback(
    (event: React.DragEvent, index: number) => {
      if (columns.length <= 1) {
        event.preventDefault()
        return
      }
      setDraggingColumnIndex(index)
      setColumnDropIndicator(null)
      event.dataTransfer.setData('application/json', JSON.stringify({ index }))
      event.dataTransfer.effectAllowed = 'move'
      event.dataTransfer.dropEffect = 'move'
      const row = (event.currentTarget as HTMLElement).closest('[data-column-row]')
      if (row instanceof HTMLElement) {
        event.dataTransfer.setDragImage(row, 24, 20)
      }
    },
    [columns.length],
  )

  const handleColumnListDragOver = useCallback(
    (event: React.DragEvent) => {
      if (draggingColumnIndex == null) return
      event.preventDefault()
      event.dataTransfer.dropEffect = 'move'
      updateColumnDropIndicatorFromPointer(event.clientY)
    },
    [draggingColumnIndex, updateColumnDropIndicatorFromPointer],
  )

  const handleColumnListDrop = useCallback(
    (event: React.DragEvent) => {
      event.preventDefault()
      const dragIndex = draggingColumnIndex
      const indicator = columnDropIndicator
      clearColumnDragState()
      if (dragIndex == null || !indicator) return

      setColumns((current) =>
        reorderWithDropIndicator(current, dragIndex, indicator),
      )
    },
    [clearColumnDragState, columnDropIndicator, draggingColumnIndex],
  )

  const handleSubmit = async () => {
    const normalizedSchema = schemaName.trim()
    const normalizedTableName = tableName.trim()

    if (!normalizedSchema) {
      toast.error(t('Schema name is required'))
      return
    }
    if (!MYSQL_SCHEMA_IDENTIFIER_REGEX.test(normalizedSchema)) {
      toast.error(t('Schema name must use letters, numbers, and underscores only.'))
      return
    }
    if (!normalizedTableName) {
      toast.error(t('Table name is required'))
      return
    }
    if (!MYSQL_IDENTIFIER_REGEX.test(normalizedTableName)) {
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
        if (!MYSQL_IDENTIFIER_REGEX.test(normalizedColumnName)) {
          throw new Error(
            t('Column names must use letters, numbers, and underscores only.'),
          )
        }
        if (seenColumnNames.has(normalizedColumnName)) {
          throw new Error(t('Column names must be unique.'))
        }
        seenColumnNames.add(normalizedColumnName)

        const typeError = validateMysqlColumnTypeState(column.typeState)
        if (typeError) {
          throw new Error(
            `${t('Column')} ${index + 1}: ${t(typeError)}`,
          )
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
        const validationError = validateMysqlIndexFormState(index.formState)
        if (validationError) {
          throw new Error(validationError)
        }

        const normalizedIndexName = index.formState.name.trim()
        if (!MYSQL_IDENTIFIER_REGEX.test(normalizedIndexName)) {
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

      const tableId = mysqlTableId(normalizedSchema, normalizedTableName)
      const primaryKeyColumns = normalizedColumns
        .filter((column) => column.primaryKey)
        .map((column) => column.name)

      const columnDefinitions = normalizedColumns.map((column) => {
        const pieces = [
          `${quoteMysqlIdentifier(column.name)} ${buildMysqlColumnTypeSql(column.typeState)}`,
        ]
        if (!column.nullable) {
          pieces.push('NOT NULL')
        }
        if (column.defaultValue) {
          pieces.push(`DEFAULT ${column.defaultValue}`)
        }
        if (column.comment) {
          pieces.push(
            `COMMENT ${quoteMysqlStringLiteral(column.comment)}`,
          )
        }
        return pieces.join(' ')
      })

      if (primaryKeyColumns.length > 0) {
        columnDefinitions.push(
          `PRIMARY KEY (${primaryKeyColumns.map((column) => quoteMysqlIdentifier(column)).join(', ')})`,
        )
      }

      const qualifiedTableName = `${quoteMysqlIdentifier(normalizedSchema)}.${quoteMysqlIdentifier(normalizedTableName)}`

      const createTableSql = `CREATE TABLE ${qualifiedTableName} (\n  ${columnDefinitions.join(',\n  ')}\n)`
      await executeSql.mutateAsync(createTableSql)

      const followUpStatements: string[] = []

      if (tableComment.trim()) {
        followUpStatements.push(
          buildMysqlTableCommentSql(tableId, tableComment.trim()),
        )
      }

      for (const index of normalizedIndexes) {
        followUpStatements.push(
          buildMysqlCreateIndexSql(tableId, index.name, index.columns, {
            unique: index.unique,
            algorithm: index.algorithm,
            condition: index.condition || undefined,
            includeColumns: index.includeColumns,
          }),
        )
        if (index.comment) {
          followUpStatements.push(
            buildMysqlIndexCommentSql(tableId, index.name, index.comment),
          )
        }
      }

      for (const statement of followUpStatements) {
        await executeSql.mutateAsync(statement)
      }

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
      title="Create table"
      description="Define columns and create the table."
      maxWidth="sm:max-w-3xl"
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
              <div className="flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground">
                <span>{t('Create a new table in')}</span>
                <MysqlSchemaSelector
                  value={schemaName}
                  schemas={loadedSchemas}
                  total={schemasTotal}
                  isLoading={schemasLoading}
                  isFetching={schemasFetching}
                  isFetchingNextPage={isFetchingMoreSchemas}
                  hasNextPage={hasMoreSchemas}
                  onSelect={setSchemaName}
                  onSearchChange={handleSchemaSearchChange}
                  onLoadMore={() => void fetchNextSchemaPage()}
                  onOpenChange={setSchemaPickerOpen}
                  compact
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="table-name" className="text-[13px]">
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="table-name"
                    value={tableName}
                    onChange={(event) => setTableName(event.target.value)}
                    placeholder="users"
                    className="h-8 text-[13px]"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="table-comment" className="text-[13px]">
                    {t('Description')}
                  </Label>
                  <Input
                    id="table-comment"
                    value={tableComment}
                    onChange={(event) => setTableComment(event.target.value)}
                    placeholder={t('Optional')}
                    className="h-8 text-[13px]"
                  />
                </div>
              </div>
            </section>

            <section className="space-y-2">
              <h4 className="text-[13px] font-semibold text-foreground">
                {t('Columns')}
              </h4>
              <div className="overflow-x-auto rounded-lg border border-border">
                <div className="min-w-[668px]">
                  <div className="grid grid-cols-[28px_minmax(140px,1.2fr)_minmax(140px,1fr)_minmax(140px,1fr)_80px_72px] items-center gap-2 border-b border-border bg-muted/30 px-3 py-2">
                    <span aria-hidden="true" />
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {t('Name')}
                    </span>
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {t('Type')}
                    </span>
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {t('Default value')}
                    </span>
                    <span className="whitespace-nowrap text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {t('Primary key')}
                    </span>
                    <span aria-hidden="true" />
                  </div>
                  <div
                    ref={columnListRef}
                    onDragOver={handleColumnListDragOver}
                    onDrop={handleColumnListDrop}
                    onDragLeave={(event) => {
                      const related = event.relatedTarget as Node | null
                      if (related && event.currentTarget.contains(related)) return
                      setColumnDropIndicator(null)
                    }}
                  >
                    {columns.map((column, columnIndex) => {
                      const canReorderColumns = columns.length > 1
                      const isDragging = draggingColumnIndex === columnIndex
                      const showInsertBefore =
                        columnDropIndicator?.index === columnIndex &&
                        columnDropIndicator.position === 'before'
                      const showInsertAfter =
                        columnDropIndicator?.index === columnIndex &&
                        columnDropIndicator.position === 'after'
                      const isDropTarget =
                        draggingColumnIndex != null &&
                        !isDragging &&
                        (showInsertBefore || showInsertAfter)

                      return (
                        <Fragment key={column.id}>
                          {showInsertBefore ? (
                            <div
                              className="mx-3 h-1 shrink-0 rounded-full bg-primary shadow-[0_0_0_1px_hsl(var(--primary)/0.35)]"
                              role="presentation"
                              aria-hidden
                            />
                          ) : null}
                          <div
                            data-column-row
                            className={cn(
                              'grid grid-cols-[28px_minmax(140px,1.2fr)_minmax(140px,1fr)_minmax(140px,1fr)_80px_72px] items-center gap-2 border-b border-border px-3 py-2 transition-[opacity,background-color,box-shadow]',
                              isDragging && 'opacity-35',
                              isDropTarget && 'bg-primary/5 shadow-[inset_0_0_0_1px_hsl(var(--primary)/0.25)]',
                            )}
                          >
                            <div
                              draggable={canReorderColumns}
                              onDragStart={(event) =>
                                handleColumnDragStart(event, columnIndex)
                              }
                              onDragEnd={clearColumnDragState}
                              className={cn(
                                'flex h-8 w-7 items-center justify-center rounded-md text-muted-foreground',
                                canReorderColumns
                                  ? 'cursor-grab active:cursor-grabbing hover:bg-muted/60 hover:text-foreground'
                                  : 'cursor-default opacity-40',
                              )}
                              aria-label={
                                canReorderColumns ? t('Drag to reorder') : undefined
                              }
                              aria-hidden={!canReorderColumns}
                            >
                              <GripVertical className="h-3.5 w-3.5 shrink-0" />
                            </div>
                        <Input
                          value={column.name}
                          onChange={(event) =>
                            updateColumn(column.id, { name: event.target.value })
                          }
                          placeholder="column_name"
                          className="h-8 text-[13px]"
                          aria-label={t('Name')}
                          data-column-name-id={column.id}
                          autoFocus={column.id === focusColumnId}
                        />
                        <MysqlColumnTypeSelector
                          value={column.typeState}
                          onChange={(typeState) =>
                            updateColumn(column.id, { typeState })
                          }
                          compact
                        />
                        <Input
                          value={column.defaultValue}
                          onChange={(event) =>
                            updateColumn(column.id, {
                              defaultValue: event.target.value,
                            })
                          }
                          className="h-8 font-mono text-[12px]"
                          placeholder={t(
                            getMysqlColumnDefaultPlaceholder(column.typeState.typeId),
                          )}
                          aria-label={t('Default value')}
                        />
                        <div className="flex justify-center">
                          <Checkbox
                            checked={column.primaryKey}
                            onCheckedChange={(primaryKey) =>
                              updateColumn(column.id, {
                                primaryKey: primaryKey === true,
                                nullable: primaryKey === true ? false : column.nullable,
                              })
                            }
                            aria-label={t('Primary key')}
                          />
                        </div>
                        <div className="flex items-center justify-end gap-0.5">
                          <CreateTableColumnSettings
                            column={column}
                            onChange={(patch) => updateColumn(column.id, patch)}
                            onTypeChange={(typeState) =>
                              updateColumn(column.id, { typeState })
                            }
                          />
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
                            onClick={() =>
                              setColumns((current) =>
                                current.filter((entry) => entry.id !== column.id),
                              )
                            }
                            disabled={columns.length <= 1}
                            aria-label={t('Remove column')}
                          >
                            <X className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                          </div>
                          {showInsertAfter ? (
                            <div
                              className="mx-3 h-1 shrink-0 rounded-full bg-primary shadow-[0_0_0_1px_hsl(var(--primary)/0.35)]"
                              role="presentation"
                              aria-hidden
                            />
                          ) : null}
                        </Fragment>
                      )
                    })}
                  </div>
                </div>
              </div>
              <Button
                type="button"
                variant="outline"
                className="h-9 w-full border-dashed text-[13px] text-muted-foreground hover:text-foreground"
                onClick={handleAddColumn}
              >
                <Plus className="me-1.5 h-3.5 w-3.5" />
                {t('Add column')}
              </Button>
            </section>

            <Collapsible open={indexesOpen} onOpenChange={setIndexesOpen}>
              <div className="flex items-center justify-between gap-3">
                <CollapsibleTrigger asChild>
                  <button
                    type="button"
                    className="flex cursor-pointer items-center gap-1.5 text-[13px] font-semibold text-foreground hover:text-foreground/80"
                  >
                    <ChevronDown
                      className={cn(
                        'h-4 w-4 text-muted-foreground transition-transform',
                        indexesOpen && 'rotate-180',
                      )}
                    />
                    {t('Indexes')}
                    {indexes.length > 0 ? (
                      <Badge variant="info" className="text-[10px] shrink-0">
                        {indexes.length}
                      </Badge>
                    ) : (
                      <span className="text-[12px] font-normal text-muted-foreground">
                        ({t('Optional')})
                      </span>
                    )}
                  </button>
                </CollapsibleTrigger>
                {indexesOpen ? (
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
                ) : null}
              </div>
              <CollapsibleContent className="mt-3 space-y-3">
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Indexes are optional, but adding the most common ones now can improve query performance immediately.',
                  )}
                </p>
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
                      <MysqlIndexAlgorithmSelector
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
              </CollapsibleContent>
            </Collapsible>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={executeSql.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={executeSql.isPending}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
