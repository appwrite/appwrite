import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  buildPostgresCreateEnumSql,
  buildPostgresSingleRequestDdlSql,
} from '@/lib/postgres-enum-ddl'
import {
  buildPostgresEnumUpdateStatements,
  createDefaultPostgresEnumFormState,
  createPostgresEnumFormStateFromRow,
  normalizePostgresEnumFormValues,
  validatePostgresEnumCreateForm,
  validatePostgresEnumUpdateForm,
  type PostgresEnumFormState,
} from '@/lib/postgres-enum-metadata'
import { useExecutePostgresSql } from '@/lib/react-query/hooks'
import type { PostgresSchemaEnumRow } from '@/lib/postgres-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PostgresEnumValuesEditor } from './PostgresEnumValuesEditor'
import { useT } from '@/lib/i18n/translate'

type PostgresSchemaEnumDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  schema: string
  enumRow?: PostgresSchemaEnumRow | null
  onSuccess: () => void
}

function EnumFormSection({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: React.ReactNode
}) {
  return (
    <div className="rounded-xl border border-border bg-muted/20 overflow-hidden">
      <div className="px-4 py-3">
        <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
        {description ? (
          <p className="mt-1 text-[11px] text-muted-foreground">{description}</p>
        ) : null}
      </div>
      <div className="border-t border-border px-4 py-4">{children}</div>
    </div>
  )
}

export function PostgresSchemaEnumDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  schema,
  enumRow = null,
  onSuccess,
}: PostgresSchemaEnumDrawerProps) {
  const t = useT()
  const isEdit = enumRow != null
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [formState, setFormState] = useState<PostgresEnumFormState>(
    createDefaultPostgresEnumFormState(),
  )

  useEffect(() => {
    if (!open) return
    setFormState(
      enumRow
        ? createPostgresEnumFormStateFromRow(enumRow)
        : createDefaultPostgresEnumFormState(),
    )
  }, [open, enumRow])

  const handleSubmit = async () => {
    if (isEdit && enumRow) {
      const validationError = validatePostgresEnumUpdateForm({
        state: formState,
        original: enumRow,
      })
      if (validationError) {
        toast.error(t(validationError))
        return
      }

      const statements = buildPostgresEnumUpdateStatements(
        schema,
        enumRow,
        formState,
      )

      try {
        await executeSql.mutateAsync(
          buildPostgresSingleRequestDdlSql(statements, 'Update enum type'),
        )
        toast.success(t('Enum updated'))
        onOpenChange(false)
        onSuccess()
      } catch (error) {
        toast.error(getErrorMessage(error) ?? t('Failed to update enum'))
      }
      return
    }

    const validationError = validatePostgresEnumCreateForm(formState)
    if (validationError) {
      toast.error(t(validationError))
      return
    }

    const trimmedName = formState.name.trim()
    const values = normalizePostgresEnumFormValues(formState.entries)

    try {
      await executeSql.mutateAsync(
        buildPostgresCreateEnumSql(schema, trimmedName, values, {
          comment: formState.comment.trim() || undefined,
        }),
      )
      toast.success(t('Enum created'))
      onOpenChange(false)
      onSuccess()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to create enum'))
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? t('Update enum') : t('Create enum')}
      description={
        isEdit
          ? t('Update the enum name, values, and description for this schema.')
          : t('Define a named list of allowed values for columns in this schema.')
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
          className="flex min-h-0 flex-1 flex-col"
        >
          <div className="flex-1 space-y-4 overflow-y-auto px-6 pb-4 pt-4">
            <EnumFormSection
              title={t('General')}
              description={t('Basic details for this enum type.')}
            >
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="enum-name" className="text-[12px] font-medium">
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="enum-name"
                    value={formState.name}
                    onChange={(event) =>
                      setFormState((current) => ({
                        ...current,
                        name: event.target.value,
                      }))
                    }
                    placeholder={t('e.g. status')}
                    className="h-9 font-mono text-[13px]"
                    autoComplete="off"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="enum-comment" className="text-[12px] font-medium">
                    {t('Description')}
                  </Label>
                  <Textarea
                    id="enum-comment"
                    value={formState.comment}
                    onChange={(event) =>
                      setFormState((current) => ({
                        ...current,
                        comment: event.target.value,
                      }))
                    }
                    placeholder={t('Describe what this enum represents')}
                    className="min-h-[72px] resize-y text-[13px]"
                  />
                </div>
              </div>
            </EnumFormSection>

            <EnumFormSection
              title={t('Values')}
              description={
                isEdit
                  ? t('Existing values can be renamed. New values can be added and positioned.')
                  : t('Add every allowed value and drag to set the sort order.')
              }
            >
              <PostgresEnumValuesEditor
                entries={formState.entries}
                onChange={(entries) =>
                  setFormState((current) => ({ ...current, entries }))
                }
                mode={isEdit ? 'update' : 'create'}
              />
            </EnumFormSection>
          </div>

          <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={executeSql.isPending}>
              {isEdit ? t('Update') : t('Create')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
