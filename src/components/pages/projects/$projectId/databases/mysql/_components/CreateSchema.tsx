import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'
import { quoteMysqlIdentifier } from '@/lib/mysql-database-routes'
import { useExecuteMysqlSql } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type CreateSchemaProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  onSuccess: (schema: string) => void
}

const MYSQL_IDENTIFIER_REGEX = /^[A-Za-z_][A-Za-z0-9_]*$/

export function CreateSchema({
  open,
  onOpenChange,
  projectId,
  databaseId,
  onSuccess,
}: CreateSchemaProps) {
  const t = useT()
  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const [schemaName, setSchemaName] = useState('')

  useEffect(() => {
    if (!open) return
    setSchemaName('')
  }, [open])

  const handleSubmit = async () => {
    const normalizedSchemaName = schemaName.trim()

    if (!normalizedSchemaName) {
      toast.error(t('Schema name is required'))
      return
    }

    if (!MYSQL_IDENTIFIER_REGEX.test(normalizedSchemaName)) {
      toast.error(t('Schema name must use letters, numbers, and underscores only.'))
      return
    }

    try {
      await executeSql.mutateAsync(
        `CREATE SCHEMA ${quoteMysqlIdentifier(normalizedSchemaName)}`,
      )
      toast.success(t('Schema created'))
      onOpenChange(false)
      onSuccess(normalizedSchemaName)
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to create schema'))
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Create schema')}
      description={t('Create a MySQL schema to organize related tables.')}
      maxWidth="sm:max-w-md"
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
            <div className="space-y-2">
              <Label htmlFor="schema-name" className="text-[12px] font-medium">
                {t('Schema name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="schema-name"
                value={schemaName}
                onChange={(event) => setSchemaName(event.target.value)}
                placeholder="analytics"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Use letters, numbers, and underscores only.')}
              </p>
            </div>
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
