import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getMysqlColumnEditMeta,
  getMysqlInlineFieldType,
  isMysqlColumnRequired,
  isMysqlColumnRequiredOnCreate,
  isMysqlColumnSystemGenerated,
  parseAndValidateMysqlCellInput,
  mysqlColumnAutoGeneratesOnInsert,
  mysqlColumnCanOmitOnCreate,
  shouldOmitMysqlColumnOnRowCreate,
  valueToMysqlEditString,
} from '@/lib/mysql-row-edits'
import type { MysqlTableColumnRow } from '@/lib/mysql-sql'
import type { MysqlRowIdentity } from '@/lib/mysql-row-sql'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import {
  isDateTimeInlineFieldType,
  isNumericInlineFieldType,
} from '@/lib/database-row-inline-edits'
import {
  useCreateMysqlTableRow,
  useUpdateMysqlTableRow,
} from '@/lib/react-query/hooks'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'

type MysqlRowEditDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  row: Record<string, unknown> | null
  identity: MysqlRowIdentity | null
  columns: MysqlTableColumnRow[]
  focusedField?: string
  canWrite?: boolean
}

/** `null` = explicit SQL NULL; string = raw edit value. */
type MysqlRowFieldDraft = string | null

function isJsonType(typeId: string): boolean {
  return typeId === 'json' || typeId === 'jsonb'
}

function isLongTextType(typeId: string): boolean {
  return typeId === 'text'
}

function MysqlRowNullOverlay({
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
  const id = `mysql-row-field-${columnName}-null`
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

export function MysqlRowEditDrawer({
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
}: MysqlRowEditDrawerProps) {
  const t = useT()
  const isCreate = row == null
  const [draft, setDraft] = useState<Record<string, MysqlRowFieldDraft>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createMutation = useCreateMysqlTableRow(
    projectId,
    databaseId,
    tableId,
  )
  const updateMutation = useUpdateMysqlTableRow(
    projectId,
    databaseId,
    tableId,
  )

  const editableColumns = useMemo(
    () =>
      columns.filter((column) => {
        if (isCreate) return !shouldOmitMysqlColumnOnRowCreate(column)
        return true
      }),
    [columns, isCreate],
  )

  useEffect(() => {
    if (!open) return
    const nextDraft: Record<string, MysqlRowFieldDraft> = {}
    for (const column of editableColumns) {
      const meta = getMysqlColumnEditMeta(column)
      const fieldType = getMysqlInlineFieldType(meta)
      const value = row?.[column.column_name] as RowCellValue | undefined
      if (value === null) {
        nextDraft[column.column_name] = null
      } else if (value === undefined) {
        nextDraft[column.column_name] =
          fieldType === 'boolean' ? 'false' : ''
      } else {
        nextDraft[column.column_name] = valueToMysqlEditString(value, meta)
      }
    }
    setDraft(nextDraft)
    setErrors({})
  }, [editableColumns, open, row])

  const handleFieldChange = (columnName: string, value: MysqlRowFieldDraft) => {
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
    handleFieldChange(columnName, '')
  }

  const handleSave = async () => {
    if (!canWrite) return

    const values: Record<string, RowCellValue> = {}
    const nextErrors: Record<string, string> = {}

    for (const column of editableColumns) {
      if (isCreate && shouldOmitMysqlColumnOnRowCreate(column)) continue
      if (!isCreate && isMysqlColumnSystemGenerated(column)) continue

      const rawDraft = draft[column.column_name]
      if (rawDraft === null) {
        const required = isCreate
          ? isMysqlColumnRequiredOnCreate(column)
          : isMysqlColumnRequired(column)
        if (required) {
          nextErrors[column.column_name] = 'This field is required.'
          continue
        }
        values[column.column_name] = null
        continue
      }

      // Empty create fields: omit only when Mysql will assign a value
      // (auto-generate or non-PK default). Primary keys without auto-generation
      // are required so we never insert a colliding default.
      if (isCreate && !(rawDraft ?? '').trim()) {
        if (mysqlColumnCanOmitOnCreate(column)) continue
        if (column.is_nullable === 'YES') {
          values[column.column_name] = null
          continue
        }
        nextErrors[column.column_name] = 'This field is required.'
        continue
      }

      const result = parseAndValidateMysqlCellInput(rawDraft ?? '', column)
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
          if (isMysqlColumnSystemGenerated(column)) continue
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
                  const meta = getMysqlColumnEditMeta(column)
                  const fieldType = getMysqlInlineFieldType(meta)
                  const required = isCreate
                    ? isMysqlColumnRequiredOnCreate(column)
                    : isMysqlColumnRequired(column)
                  const autoGenerates =
                    isCreate && mysqlColumnAutoGeneratesOnInsert(column)
                  const canOmitOnCreate =
                    isCreate && mysqlColumnCanOmitOnCreate(column)
                  const emptyPlaceholder = autoGenerates
                    ? t('Leave blank to auto-generate')
                    : column.is_nullable === 'YES'
                      ? 'NULL'
                      : undefined
                  const draftValue = draft[column.column_name]
                  const isNull = draftValue === null
                  const stringValue = isNull ? '' : (draftValue ?? '')
                  const error = errors[column.column_name]
                  const inputId = `mysql-row-field-${column.column_name}`
                  const readOnly =
                    !canWrite ||
                    (!isCreate && isMysqlColumnSystemGenerated(column))
                  const showNullToggle =
                    !readOnly && column.is_nullable === 'YES'
                  const useTextarea =
                    isJsonType(meta.typeId) || isLongTextType(meta.typeId)

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
                          className="h-9 bg-muted/30 text-[13px]"
                        />
                      ) : fieldType === 'boolean' ? (
                        <div className="flex items-center gap-2">
                          <Switch
                            id={inputId}
                            checked={stringValue === 'true'}
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
                      ) : fieldType === 'enum' && (meta.enumValues?.length ?? 0) > 0 ? (
                        <Select
                          value={isNull ? undefined : stringValue || undefined}
                          onValueChange={(next) =>
                            handleFieldChange(column.column_name, next)
                          }
                          disabled={isNull}
                        >
                          <SelectTrigger
                            id={inputId}
                            className="h-9 w-full text-[13px]"
                            aria-invalid={error ? true : undefined}
                          >
                            <SelectValue
                              placeholder={
                                required ? t('Select a value') : emptyPlaceholder
                              }
                            />
                          </SelectTrigger>
                          <SelectContent>
                            {meta.enumValues!.map((option) => (
                              <SelectItem key={option} value={option}>
                                {option}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
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
                          autoFocus={focusedField === column.column_name}
                        />
                      ) : isNumericInlineFieldType(fieldType) ? (
                        <div className="space-y-1.5">
                          <Input
                            id={inputId}
                            type="number"
                            inputMode="numeric"
                            value={stringValue}
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
                        <div className="relative">
                          <Textarea
                            id={inputId}
                            value={stringValue}
                            disabled={isNull}
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
                              'min-h-[36px] max-h-[600px] resize-none text-[13px] font-mono',
                              isNull && 'cursor-not-allowed opacity-50',
                              showNullToggle ? 'pb-8 pe-28' : 'pb-2',
                            )}
                            aria-invalid={error ? true : undefined}
                            autoFocus={focusedField === column.column_name}
                          />
                          {showNullToggle ? (
                            <MysqlRowNullOverlay
                              columnName={column.column_name}
                              checked={isNull}
                              onCheckedChange={(checked) =>
                                handleNullToggle(column.column_name, checked)
                              }
                              className="bottom-2 end-2"
                            />
                          ) : null}
                        </div>
                      ) : (
                        <div className="relative">
                          <Input
                            id={inputId}
                            type="text"
                            value={stringValue}
                            disabled={isNull}
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
                              'h-9 text-[13px]',
                              isNull && 'cursor-not-allowed opacity-50',
                              showNullToggle && 'pe-28',
                            )}
                            aria-invalid={error ? true : undefined}
                            autoFocus={focusedField === column.column_name}
                          />
                          {showNullToggle ? (
                            <MysqlRowNullOverlay
                              columnName={column.column_name}
                              checked={isNull}
                              onCheckedChange={(checked) =>
                                handleNullToggle(column.column_name, checked)
                              }
                              className="top-1/2 end-2 -translate-y-1/2"
                            />
                          ) : null}
                        </div>
                      )}

                      {error ? (
                        <p className="text-[12px] text-destructive">{t(error)}</p>
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
