import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getPostgresColumnEditMeta,
  getPostgresInlineFieldType,
  isPostgresColumnRequired,
  isPostgresGeneratedColumn,
  parseAndValidatePostgresCellInput,
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

function isJsonType(typeId: string): boolean {
  return typeId === 'json' || typeId === 'jsonb'
}

function isLongTextType(typeId: string): boolean {
  return typeId === 'text'
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
  const isCreate = row == null
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

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
        if (isCreate) return !isPostgresGeneratedColumn(column)
        return true
      }),
    [columns, isCreate],
  )

  useEffect(() => {
    if (!open) return
    const nextDraft: Record<string, string> = {}
    for (const column of editableColumns) {
      const meta = getPostgresColumnEditMeta(column)
      const value = row?.[column.column_name] as RowCellValue
      nextDraft[column.column_name] = valueToPostgresEditString(value ?? null, meta)
    }
    setDraft(nextDraft)
    setErrors({})
  }, [editableColumns, open, row])

  const handleFieldChange = (columnName: string, value: string) => {
    setDraft((prev) => ({ ...prev, [columnName]: value }))
    setErrors((prev) => {
      if (!prev[columnName]) return prev
      const next = { ...prev }
      delete next[columnName]
      return next
    })
  }

  const handleSave = async () => {
    if (!canWrite) return

    const values: Record<string, RowCellValue> = {}
    const nextErrors: Record<string, string> = {}

    for (const column of editableColumns) {
      if (!isCreate && isPostgresGeneratedColumn(column)) continue
      const raw = draft[column.column_name] ?? ''
      const result = parseAndValidatePostgresCellInput(raw, column)
      if (!result.ok) {
        nextErrors[column.column_name] = result.error
        continue
      }
      values[column.column_name] = result.value
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors)
      toast.error('Fix validation errors before saving.')
      return
    }

    try {
      if (isCreate) {
        await createMutation.mutateAsync(values)
        toast.success('Row created')
      } else if (identity) {
        const changes: Record<string, RowCellValue> = {}
        for (const column of editableColumns) {
          if (isPostgresGeneratedColumn(column)) continue
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
        toast.success('Row updated')
      }
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to save row')
    }
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isCreate ? 'Create row' : 'Update row'}
      maxWidth="sm:max-w-xl"
      disableAutoFocus
    >
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4 space-y-4">
          {editableColumns.map((column) => {
            const meta = getPostgresColumnEditMeta(column)
            const fieldType = getPostgresInlineFieldType(meta)
            const required = isPostgresColumnRequired(column)
            const value = draft[column.column_name] ?? ''
            const error = errors[column.column_name]
            const inputId = `postgres-row-field-${column.column_name}`
            const readOnly =
              !canWrite ||
              (!isCreate && isPostgresGeneratedColumn(column)) ||
              (meta.isPrimaryKey && !isCreate)

            return (
              <div key={column.column_name} className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                  <Label htmlFor={inputId} className="text-[13px] font-medium">
                    {column.column_name}
                  </Label>
                  <span className="text-[11px] text-muted-foreground">
                    {meta.dataType}
                    {required ? '' : ' · nullable'}
                  </span>
                </div>

                {readOnly ? (
                  <Input
                    id={inputId}
                    value={value}
                    readOnly
                    className="h-9 text-[13px] bg-muted/30"
                  />
                ) : fieldType === 'boolean' ? (
                  <Switch
                    id={inputId}
                    checked={value === 'true'}
                    onCheckedChange={(checked) =>
                      handleFieldChange(
                        column.column_name,
                        checked ? 'true' : required ? 'false' : '',
                      )
                    }
                  />
                ) : isDateTimeInlineFieldType(fieldType) ? (
                  <DateTimePicker
                    value={value || null}
                    onChange={(next) =>
                      handleFieldChange(column.column_name, next ?? '')
                    }
                    className="w-full"
                  />
                ) : isJsonType(meta.typeId) || isLongTextType(meta.typeId) ? (
                  <Textarea
                    id={inputId}
                    value={value}
                    onChange={(event) =>
                      handleFieldChange(column.column_name, event.target.value)
                    }
                    rows={isJsonType(meta.typeId) ? 6 : 4}
                    className="text-[13px] font-mono"
                    aria-invalid={error ? true : undefined}
                    autoFocus={focusedField === column.column_name}
                  />
                ) : (
                  <Input
                    id={inputId}
                    type={isNumericInlineFieldType(fieldType) ? 'number' : 'text'}
                    value={value}
                    onChange={(event) =>
                      handleFieldChange(column.column_name, event.target.value)
                    }
                    className="h-9 text-[13px]"
                    aria-invalid={error ? true : undefined}
                    autoFocus={focusedField === column.column_name}
                  />
                )}

                {error ? (
                  <p className="text-[12px] text-destructive">{error}</p>
                ) : null}
              </div>
            )
          })}
        </div>

        <div className="shrink-0 border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSaving}
          >
            Cancel
          </Button>
          <Button onClick={() => void handleSave()} disabled={!canWrite || isSaving}>
            {isCreate ? 'Create' : 'Update'}
          </Button>
        </div>
      </div>
    </BaseDrawer>
  )
}
