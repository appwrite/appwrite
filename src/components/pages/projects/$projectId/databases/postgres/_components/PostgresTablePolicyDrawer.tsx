import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import {
  buildPostgresAlterPolicySql,
  buildPostgresCreatePolicySql,
  createDefaultPostgresPolicyFormState,
  mapPostgresPolicyRowToFormState,
  validatePostgresPolicyFormState,
  type PostgresPolicyCommand,
  type PostgresPolicyFormState,
  type PostgresPolicyPermissive,
  type PostgresTablePolicyRow,
} from '@/lib/postgres-rls'
import { useExecutePostgresSql } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type PostgresTablePolicyDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  tableId: string
  policy?: PostgresTablePolicyRow | null
  onSuccess: () => void
}

const POLICY_COMMANDS: PostgresPolicyCommand[] = [
  'ALL',
  'SELECT',
  'INSERT',
  'UPDATE',
  'DELETE',
]

const POLICY_TYPES: PostgresPolicyPermissive[] = ['PERMISSIVE', 'RESTRICTIVE']

export function PostgresTablePolicyDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  tableId,
  policy,
  onSuccess,
}: PostgresTablePolicyDrawerProps) {
  const t = useT()
  const executeSql = useExecutePostgresSql(projectId, databaseId)
  const isEdit = Boolean(policy)

  const [formState, setFormState] = useState<PostgresPolicyFormState>(
    createDefaultPostgresPolicyFormState(),
  )

  useEffect(() => {
    if (!open) return
    setFormState(
      policy
        ? mapPostgresPolicyRowToFormState(policy)
        : createDefaultPostgresPolicyFormState(),
    )
  }, [open, policy])

  const title = useMemo(
    () => (isEdit ? t('Update policy') : t('Create policy')),
    [isEdit, t],
  )

  const handleSubmit = async () => {
    const validationError = validatePostgresPolicyFormState(formState, {
      isEdit,
    })
    if (validationError) {
      toast.error(t(validationError))
      return
    }

    try {
      const sql = isEdit
        ? buildPostgresAlterPolicySql(tableId, policy!.policyname, formState)
        : buildPostgresCreatePolicySql(tableId, formState)

      await executeSql.mutateAsync(sql)
      toast.success(isEdit ? t('Policy updated') : t('Policy created'))
      onOpenChange(false)
      onSuccess()
    } catch (error) {
      toast.error(
        getErrorMessage(error) ??
          (isEdit ? t('Failed to update policy') : t('Failed to create policy')),
      )
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      description={
        isEdit
          ? t('Update the roles and expressions for this row level security policy.')
          : t('Create a row level security policy for this table.')
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
            <div className="space-y-2">
              <Label htmlFor="policy-name" className="text-[12px] font-medium">
                {t('Policy Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="policy-name"
                value={formState.name}
                onChange={(event) =>
                  setFormState((prev) => ({ ...prev, name: event.target.value }))
                }
                placeholder={t('Enter policy name')}
                disabled={isEdit}
              />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label className="text-[12px] font-medium">{t('Command')}</Label>
                <Select
                  value={formState.command}
                  onValueChange={(value) =>
                    setFormState((prev) => ({
                      ...prev,
                      command: value as PostgresPolicyCommand,
                    }))
                  }
                  disabled={isEdit}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {POLICY_COMMANDS.map((command) => (
                      <SelectItem key={command} value={command}>
                        {command}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label className="text-[12px] font-medium">{t('Policy type')}</Label>
                <Select
                  value={formState.permissive}
                  onValueChange={(value) =>
                    setFormState((prev) => ({
                      ...prev,
                      permissive: value as PostgresPolicyPermissive,
                    }))
                  }
                  disabled={isEdit}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {POLICY_TYPES.map((type) => (
                      <SelectItem key={type} value={type}>
                        {type === 'PERMISSIVE' ? t('Permissive') : t('Restrictive')}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="policy-roles" className="text-[12px] font-medium">
                {t('Roles')}
              </Label>
              <Input
                id="policy-roles"
                value={formState.roles}
                onChange={(event) =>
                  setFormState((prev) => ({ ...prev, roles: event.target.value }))
                }
                placeholder={t('public, authenticated')}
              />
              <p className="text-[11px] text-muted-foreground">
                {t('Comma-separated role names. Use public for all roles.')}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="policy-using" className="text-[12px] font-medium">
                {t('Using expression')}
              </Label>
              <Textarea
                id="policy-using"
                value={formState.usingExpression}
                onChange={(event) =>
                  setFormState((prev) => ({
                    ...prev,
                    usingExpression: event.target.value,
                  }))
                }
                placeholder="user_id = current_user"
                className="min-h-[96px] font-mono text-[12px]"
              />
              <p className="text-[11px] text-muted-foreground">
                {t(
                  'SQL expression that determines which rows are visible or can be modified.',
                )}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="policy-with-check" className="text-[12px] font-medium">
                {t('With check expression')}
              </Label>
              <Textarea
                id="policy-with-check"
                value={formState.withCheckExpression}
                onChange={(event) =>
                  setFormState((prev) => ({
                    ...prev,
                    withCheckExpression: event.target.value,
                  }))
                }
                placeholder="user_id = current_user"
                className="min-h-[96px] font-mono text-[12px]"
              />
              <p className="text-[11px] text-muted-foreground">
                {t('SQL expression checked on INSERT and UPDATE operations.')}
              </p>
            </div>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={executeSql.isPending || !formState.name.trim()}>
              {isEdit ? t('Update') : t('Create')}
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
