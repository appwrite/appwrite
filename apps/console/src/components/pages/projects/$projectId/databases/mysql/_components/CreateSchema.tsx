import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Create schema')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Create a MySQL schema to organize related tables.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void handleSubmit()
          }}
        >
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="schema-name" className="text-[12px] font-medium">
                {t('Schema name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="schema-name"
                value={schemaName}
                onChange={(event) => setSchemaName(event.target.value)}
                placeholder="analytics"
                disabled={executeSql.isPending}
                autoFocus
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Use letters, numbers, and underscores only.')}
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
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
      </DialogContent>
    </Dialog>
  )
}
