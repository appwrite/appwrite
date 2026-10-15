import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getPostgresArrayElementFieldType,
  getPostgresColumnEditMeta,
  getPostgresInlineFieldType,
  isPostgresColumnRequired,
  isPostgresColumnRequiredOnCreate,
  isPostgresColumnSystemGenerated,
  parseAndValidatePostgresArrayInput,
  parseAndValidatePostgresCellInput,
  postgresColumnAutoGeneratesOnInsert,
  postgresColumnCanOmitOnCreate,
  shouldOmitPostgresColumnOnRowCreate,
  valueToPostgresEditString,
} from '@/lib/postgres-row-edits'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import type { PostgresRowIdentity } from '@/lib/postgres-row-sql'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import {
  isDateTimeInlineFieldType,
  isNumericInlineFieldType,
} from '@/lib/database-row-inline-edits'
import {
  useCreatePostgresTableRow,
  useUpdatePostgresTableRow,
} from '@/lib/react-query/hooks'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { useT } from '@/lib/i18n/translate'
import { DatabaseArrayFieldEditor } from '@/components/pages/projects/$projectId/databases/_components/DatabaseArrayFieldEditor'
import {
  databaseRowFieldOverlayClass,
  databaseRowTextareaPadding,
  DATABASE_ROW_FIELD_INLINE_COUNTER_PADDING,
  DATABASE_ROW_TEXTAREA_CLASS,
} from '@/components/pages/projects/$projectId/databases/_components/DatabaseArrayItemTextField'
import { isSpreadsheetRtlText } from '@/lib/spreadsheet-cell-formatting'

type PostgresRowEditDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  row: Record<string, unknown> | null
  identity: PostgresRowIdentity | null
  columns: PostgresTableColumnRow[]
  focusedField?: string
  canWrite?: boolean
}

/** `null` = explicit SQL NULL; string = raw edit value; array = array column draft. */
type PostgresRowFieldDraft = string | null | RowCellValue[]

function isJsonType(typeId: string): boolean {
  return typeId === 'json' || typeId === 'jsonb'
}

function isLongTextType(typeId: string): boolean {
  return typeId === 'text'
}

function PostgresRowNullOverlay({
  columnName,
  checked,
  onCheckedChange,
  className,
}: {
  columnName: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  className?: string
}) {
  const t = useT()
  const id = `postgres-row-field-${columnName}-null`
  return (
    <div
      className={cn(
        'pointer-events-none absolute flex items-center gap-2',
        className,
      )}
    >
      <div className="pointer-events-auto flex items-center gap-1.5">
        <Checkbox
          id={id}
          checked={checked}
          onCheckedChange={(value) => onCheckedChange(value === true)}
          onClick={(event) => event.stopPropagation()}
          className="h-4 w-4"
        />
        <label
          htmlFor={id}
          className="cursor-pointer select-none text-[11px] text-muted-foreground"
        >
          {t('Null')}
        </label>
      </div>
    </div>
  )
}

export function PostgresRowEditDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  tableId,
  row,
  identity,
  columns,
  focusedField,
  canWrite = true,
}: PostgresRowEditDrawerProps) {
  const t = useT()
  const isCreate = row == null
  const [draft, setDraft] = useState<Record<string, PostgresRowFieldDraft>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const fieldRefs = useRef<
    Record<
      string,
      | HTMLInputElement
      | HTMLTextAreaElement
      | HTMLButtonElement
      | null
    >
  >({})
  const lastFocusedSessionRef = useRef<string | null>(null)

  const createMutation = useCreatePostgresTableRow(
    projectId,
    databaseId,
    tableId,
  )
  const updateMutation = useUpdatePostgresTableRow(
    projectId,
    databaseId,
    tableId,
  )

  const editableColumns = useMemo(
    () =>
      columns.filter((column) => {
        if (isCreate) return !shouldOmitPostgresColumnOnRowCreate(column)
        return true
      }),
    [columns, isCreate],
  )

  const rowSessionKey = useMemo(() => {
    if (!identity) return 'create'
    if (identity.ctid) return `ctid:${identity.ctid}`
    if (identity.primaryKeyValues) {
      return JSON.stringify(identity.primaryKeyValues)
    }
    return 'edit'
  }, [identity])

  useEffect(() => {
    if (!open) return
    const nextDraft: Record<string, PostgresRowFieldDraft> = {}
    for (const column of editableColumns) {
      const meta = getPostgresColumnEditMeta(column)
      const value = row?.[column.column_name] as RowCellValue | undefined
      if (meta.isArray) {
        if (value === null) {
          nextDraft[column.column_name] = null
        } else if (Array.isArray(value)) {
          nextDraft[column.column_name] = value as RowCellValue[]
        } else {
          nextDraft[column.column_name] = []
        }
        continue
      }

      const fieldType = getPostgresInlineFieldType(meta)
      if (value === null) {
        nextDraft[column.column_name] = null
      } else if (value === undefined) {
        nextDraft[column.column_name] =
          fieldType === 'boolean' ? 'false' : ''
      } else {
        const editValue = valueToPostgresEditString(value, meta)
        nextDraft[column.column_name] =
          typeof editValue === 'string' ? editValue : []
      }
    }
    setDraft(nextDraft)
    setErrors({})
  }, [editableColumns, open, row])

  // Focus the clicked cell's field after open. BaseDrawer disableAutoFocus
  // blurs on open, so React autoFocus alone is not enough (same as TablesDB).
  useEffect(() => {
    if (!open || !focusedField) return
    const sessionKey = `${rowSessionKey}::${focusedField}`
    if (lastFocusedSessionRef.current === sessionKey) return

    let cancelled = false
    let attempt = 0
    const MAX_ATTEMPTS = 6

    const tryPlaceCaret = () => {
      if (cancelled) return
      const el = fieldRefs.current[focusedField]
      if (!el) {
        if (attempt++ < MAX_ATTEMPTS) requestAnimationFrame(tryPlaceCaret)
        return
      }
      el.focus({ preventScroll: true })
      if (
        el instanceof HTMLInputElement ||
        el instanceof HTMLTextAreaElement
      ) {
        const len = el.value.length
        if (len === 0 && attempt++ < MAX_ATTEMPTS) {
          requestAnimationFrame(tryPlaceCaret)
          return
        }
        if (len > 0) {
          try {
            el.setSelectionRange(len, len)
          } catch {
            // Number/email/etc. may reject selection APIs; ignore.
          }
        }
      }
      lastFocusedSessionRef.current = sessionKey
    }

    const rafId = requestAnimationFrame(() => {
      requestAnimationFrame(tryPlaceCaret)
    })
    return () => {
      cancelled = true
      cancelAnimationFrame(rafId)
    }
  }, [open, focusedField, rowSessionKey])

  useEffect(() => {
    if (!open) lastFocusedSessionRef.current = null
  }, [open])

  const handleFieldChange = (columnName: string, value: PostgresRowFieldDraft) => {
    setDraft((prev) => ({ ...prev, [columnName]: value }))
    setErrors((prev) => {
      if (!prev[columnName]) return prev
      const next = { ...prev }
      delete next[columnName]
      return next
    })
  }

  const handleNullToggle = (columnName: string, isNull: boolean) => {
    if (isNull) {
      handleFieldChange(columnName, null)
      return
    }
    const column = editableColumns.find(
      (entry) => entry.column_name === columnName,
    )
    const isArrayColumn = column
      ? getPostgresColumnEditMeta(column).isArray
      : false
    handleFieldChange(columnName, isArrayColumn ? [] : '')
  }

  const handleSave = async () => {
    if (!canWrite) return

    const values: Record<string, RowCellValue> = {}
    const nextErrors: Record<string, string> = {}

    for (const column of editableColumns) {
      if (isCreate && shouldOmitPostgresColumnOnRowCreate(column)) continue
      if (!isCreate && isPostgresColumnSystemGenerated(column)) continue

      const rawDraft = draft[column.column_name]
      const meta = getPostgresColumnEditMeta(column)

      if (meta.isArray) {
        if (rawDraft === null) {
          const required = isCreate
            ? isPostgresColumnRequiredOnCreate(column)
            : isPostgresColumnRequired(column)
          if (required) {
            nextErrors[column.column_name] = 'This field is required.'
            continue
          }
          values[column.column_name] = null
          continue
        }

        if (!Array.isArray(rawDraft)) continue

        if (isCreate && rawDraft.length === 0) {
          if (postgresColumnCanOmitOnCreate(column)) continue
          if (column.is_nullable === 'YES') {
            values[column.column_name] = []
            continue
          }
          nextErrors[column.column_name] = 'This field is required.'
          continue
        }

        const result = parseAndValidatePostgresArrayInput(rawDraft, column)
        if (!result.ok) {
          nextErrors[column.column_name] = result.error
          continue
        }
        values[column.column_name] = result.value
        continue
      }

      if (rawDraft === null) {
        const required = isCreate
          ? isPostgresColumnRequiredOnCreate(column)
          : isPostgresColumnRequired(column)
        if (required) {
          nextErrors[column.column_name] = 'This field is required.'
          continue
        }
        values[column.column_name] = null
        continue
      }

      // Empty create fields: omit only when Postgres will assign a value
      // (auto-generate or non-PK default). Primary keys without auto-generation
      // are required so we never insert a colliding default.
      if (isCreate && typeof rawDraft === 'string' && !rawDraft.trim()) {
        if (postgresColumnCanOmitOnCreate(column)) continue
        if (column.is_nullable === 'YES') {
          values[column.column_name] = null
          continue
        }
        nextErrors[column.column_name] = 'This field is required.'
        continue
      }

      const scalarDraft = typeof rawDraft === 'string' ? rawDraft : ''
      const result = parseAndValidatePostgresCellInput(scalarDraft, column)
      if (!result.ok) {
        nextErrors[column.column_name] = result.error
        continue
      }
      values[column.column_name] = result.value
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors)
      toast.error(t('Fix validation errors before saving.'))
      return
    }

    try {
      if (isCreate) {
        await createMutation.mutateAsync(values)
        toast.success(t('Row created'))
      } else if (identity) {
        const changes: Record<string, RowCellValue> = {}
        for (const column of editableColumns) {
          if (isPostgresColumnSystemGenerated(column)) continue
          const original = row?.[column.column_name] as RowCellValue
          const next = values[column.column_name]
          if (JSON.stringify(original) !== JSON.stringify(next)) {
            changes[column.column_name] = next
          }
        }
        if (Object.keys(changes).length === 0) {
          onOpenChange(false)
          return
        }
        await updateMutation.mutateAsync({ identity, changes })
        toast.success(t('Row updated'))
      }
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to save row'))
    }
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isCreate ? t('Create row') : t('Update row')}
      description={
        isCreate
          ? t('Add a new row. Nullable columns can be left empty or set to null.')
          : t('Update row values. Nullable columns can be cleared or set to null.')
      }
      maxWidth="sm:max-w-xl"
      disableAutoFocus
    >
      <>
        <div className="border-t border-border" />
        <div className="flex min-h-0 flex-1 flex-col">
          <div className="flex-1 overflow-y-auto px-6 pb-4 pt-4 space-y-4">
            <div className="space-y-3">
              <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                {t('Row data')}
              </h4>
              <div className="space-y-4">
                {editableColumns.map((column) => {
                  const meta = getPostgresColumnEditMeta(column)
                  const fieldType = getPostgresInlineFieldType(meta)
                  const required = isCreate
                    ? isPostgresColumnRequiredOnCreate(column)
                    : isPostgresColumnRequired(column)
                  const autoGenerates =
                    isCreate && postgresColumnAutoGeneratesOnInsert(column)
                  const canOmitOnCreate =
                    isCreate && postgresColumnCanOmitOnCreate(column)
                  const emptyPlaceholder = autoGenerates
                    ? t('Leave blank to auto-generate')
                    : column.is_nullable === 'YES'
                      ? 'NULL'
                      : undefined
                  const draftValue = draft[column.column_name]
                  const isNull = draftValue === null
                  const stringValue = isNull ? '' : Array.isArray(draftValue) ? '' : (draftValue ?? '')
                  const arrayItems = Array.isArray(draftValue) ? draftValue : []
                  const error = errors[column.column_name]
                  const inputId = `postgres-row-field-${column.column_name}`
                  const readOnly =
                    !canWrite ||
                    (!isCreate && isPostgresColumnSystemGenerated(column))
                  const showNullToggle =
                    !readOnly && column.is_nullable === 'YES'
                  const useTextarea =
                    isJsonType(meta.typeId) || isLongTextType(meta.typeId)
                  const isRTLContent = isSpreadsheetRtlText(stringValue)
                  const textareaPadding = databaseRowTextareaPadding({
                    showNullCheckbox: showNullToggle,
                  })
                  const inputSidePadding = showNullToggle
                    ? DATABASE_ROW_FIELD_INLINE_COUNTER_PADDING
                    : ''

                  return (
                    <div key={column.column_name} className="space-y-1.5">
                      <Label
                        htmlFor={inputId}
                        className="flex items-center gap-1.5 text-[12px] font-medium text-foreground"
                      >
                        <span>{column.column_name}</span>
                        {required ? (
                          <span
                            className="ms-0.5 text-[12px] font-semibold text-destructive"
                            aria-label={t('Required field')}
                          >
                            *
                          </span>
                        ) : null}
                      </Label>

                      {readOnly ? (
                        <Input
                          id={inputId}
                          value={stringValue}
                          readOnly
                          ref={(el) => {
                            fieldRefs.current[column.column_name] = el
                          }}
                          autoFocus={focusedField === column.column_name}
                          className="h-9 bg-muted/30 text-[13px]"
                        />
                      ) : fieldType === 'array' ? (
                        <div className="space-y-2">
                          {showNullToggle ? (
                            <div className="flex items-center gap-1.5">
                              <Checkbox
                                id={`${inputId}-null`}
                                checked={isNull}
                                onCheckedChange={(checked) =>
                                  handleNullToggle(
                                    column.column_name,
                                    checked === true,
                                  )
                                }
                                className="h-4 w-4"
                              />
                              <label
                                htmlFor={`${inputId}-null`}
                                className="cursor-pointer select-none text-[11px] text-muted-foreground"
                              >
                                {t('Null')}
                              </label>
                            </div>
                          ) : null}
                          <DatabaseArrayFieldEditor
                            idPrefix={inputId}
                            items={arrayItems}
                            onChange={(items) =>
                              handleFieldChange(column.column_name, items)
                            }
                            elementType={getPostgresArrayElementFieldType(meta.typeId)}
                            required={required}
                            maxLength={meta.length}
                            disabled={readOnly || isNull}
                            focusRef={(el) => {
                              fieldRefs.current[column.column_name] = el
                            }}
                            autoFocus={focusedField === column.column_name}
                          />
                        </div>
                      ) : fieldType === 'boolean' ? (
                        <div className="flex items-center gap-2">
                          <Switch
                            id={inputId}
                            checked={stringValue === 'true'}
                            ref={(el) => {
                              fieldRefs.current[column.column_name] = el
                            }}
                            autoFocus={focusedField === column.column_name}
                            onCheckedChange={(checked) =>
                              handleFieldChange(
                                column.column_name,
                                checked ? 'true' : 'false',
                              )
                            }
                          />
                          <span className="text-[12px] text-muted-foreground">
                            {stringValue === 'true' ? t('True') : t('False')}
                          </span>
                        </div>
                      ) : isDateTimeInlineFieldType(fieldType) ? (
                        <DateTimePicker
                          id={inputId}
                          value={isNull ? null : stringValue || null}
                          onChange={(next) =>
                            handleFieldChange(
                              column.column_name,
                              // '' omits the column on create (use DB default);
                              // null is an explicit SQL NULL for nullable columns.
                              next ?? (canOmitOnCreate ? '' : null),
                            )
                          }
                          clearable={!required}
                          placeholder={
                            required
                              ? 'Select date & time'
                              : emptyPlaceholder
                          }
                          className="w-full"
                          triggerRef={(el) => {
                            fieldRefs.current[column.column_name] = el
                          }}
                          autoFocus={focusedField === column.column_name}
                        />
                      ) : isNumericInlineFieldType(fieldType) ? (
                        <div className="space-y-1.5">
                          <Input
                            id={inputId}
                            type="number"
                            inputMode="numeric"
                            value={stringValue}
                            ref={(el) => {
                              fieldRefs.current[column.column_name] = el
                            }}
                            onChange={(event) => {
                              const next = event.target.value
                              handleFieldChange(
                                column.column_name,
                                // Keep '' for create defaults (omit from INSERT);
                                // only use null for explicit nullable clears.
                                next === '' && !canOmitOnCreate ? null : next,
                              )
                            }}
                            placeholder={
                              required ? undefined : emptyPlaceholder
                            }
                            className="h-9 text-[13px]"
                            aria-invalid={error ? true : undefined}
                            autoFocus={focusedField === column.column_name}
                          />
                          {!required && isNull ? (
                            <p className="text-[11px] text-muted-foreground">
                              NULL
                            </p>
                          ) : null}
                        </div>
                      ) : useTextarea ? (
                        <div
                          className="relative"
                          dir={isRTLContent ? 'rtl' : 'ltr'}
                        >
                          <Textarea
                            id={inputId}
                            value={stringValue}
                            disabled={isNull}
                            dir={isRTLContent ? 'rtl' : 'ltr'}
                            ref={(el) => {
                              fieldRefs.current[column.column_name] = el
                            }}
                            onChange={(event) =>
                              handleFieldChange(
                                column.column_name,
                                event.target.value,
                              )
                            }
                            rows={isJsonType(meta.typeId) ? 6 : 4}
                            placeholder={
                              required ? undefined : emptyPlaceholder
                            }
                            className={cn(
                              DATABASE_ROW_TEXTAREA_CLASS,
                              isJsonType(meta.typeId) && 'font-mono',
                              isNull && 'cursor-not-allowed opacity-50',
                              textareaPadding,
                            )}
                            aria-invalid={error ? true : undefined}
                            autoFocus={focusedField === column.column_name}
                          />
                          {showNullToggle ? (
                            <PostgresRowNullOverlay
                              columnName={column.column_name}
                              checked={isNull}
                              onCheckedChange={(checked) =>
                                handleNullToggle(
                                  column.column_name,
                                  checked === true,
                                )
                              }
                              className={databaseRowFieldOverlayClass('textarea')}
                            />
                          ) : null}
                        </div>
                      ) : (
                        <div
                          className="relative"
                          dir={isRTLContent ? 'rtl' : 'ltr'}
                        >
                          <Input
                            id={inputId}
                            type="text"
                            value={stringValue}
                            disabled={isNull}
                            dir={isRTLContent ? 'rtl' : 'ltr'}
                            ref={(el) => {
                              fieldRefs.current[column.column_name] = el
                            }}
                            onChange={(event) =>
                              handleFieldChange(
                                column.column_name,
                                event.target.value,
                              )
                            }
                            placeholder={
                              required ? undefined : emptyPlaceholder
                            }
                            className={cn(
                              'field-sizing-fixed h-9 w-full text-[13px] text-start',
                              isNull && 'cursor-not-allowed opacity-50',
                              inputSidePadding,
                            )}
                            aria-invalid={error ? true : undefined}
                            autoFocus={focusedField === column.column_name}
                          />
                          {showNullToggle ? (
                            <PostgresRowNullOverlay
                              columnName={column.column_name}
                              checked={isNull}
                              onCheckedChange={(checked) =>
                                handleNullToggle(
                                  column.column_name,
                                  checked === true,
                                )
                              }
                              className={databaseRowFieldOverlayClass('input')}
                            />
                          ) : null}
                        </div>
                      )}

                      {error ? (
                        <p className="text-[12px] text-destructive">{error}</p>
                      ) : null}
                    </div>
                  )
                })}
              </div>
            </div>
          </div>

          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button
              onClick={() => void handleSave()}
              disabled={!canWrite || isSaving}
            >
              {isCreate ? t('Create') : t('Update')}
            </Button>
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSaving}
            >
              {t('Cancel')}
            </Button>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
