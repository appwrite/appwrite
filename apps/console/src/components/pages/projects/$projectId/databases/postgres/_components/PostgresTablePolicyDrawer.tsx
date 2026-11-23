import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { X } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Badge } from '@/components/ui/badge'
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
  addPostgresPolicyFormRole,
  buildPostgresAlterPolicySql,
  buildPostgresCreatePolicySql,
  createDefaultPostgresPolicyFormState,
  formatPostgresPolicyFormRoles,
  mapPostgresPolicyRowToFormState,
  normalizePostgresTablePolicyRow,
  parsePostgresPolicyFormRoles,
  removePostgresPolicyFormRole,
  validatePostgresPolicyFormState,
  type PostgresPolicyCommand,
  type PostgresPolicyFormState,
  type PostgresPolicyPermissive,
  type PostgresTablePolicyRow,
} from '@/lib/postgres-rls'
import { isPostgresBuiltinRole } from '@/lib/postgres-roles'
import { useExecutePostgresSql, usePostgresRoles } from '@/lib/react-query/hooks'
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

const POLICY_SPECIAL_ROLES = [
  {
    value: 'public',
    descriptionKey: 'Applies to all roles.',
  },
  {
    value: 'CURRENT_USER',
    descriptionKey: 'The user executing the query.',
  },
  {
    value: 'CURRENT_ROLE',
    descriptionKey: 'The role active in the session.',
  },
] as const

type PostgresPolicyRolesFieldProps = {
  projectId: string
  databaseId: string
  value: string
  onChange: (value: string) => void
}

function PostgresPolicyRolesField({
  projectId,
  databaseId,
  value,
  onChange,
}: PostgresPolicyRolesFieldProps) {
  const t = useT()
  const { roles, isLoading } = usePostgresRoles(projectId, databaseId)
  const selectedRoles = useMemo(() => parsePostgresPolicyFormRoles(value), [value])

  const roleOptions = useMemo(() => {
    const selectedKeys = new Set(
      selectedRoles.map((role) => role.trim().toLowerCase()),
    )
    const specialOptions = POLICY_SPECIAL_ROLES.filter(
      (role) => !selectedKeys.has(role.value.toLowerCase()),
    ).map((role) => ({
      value: role.value,
      label: role.value,
      description: t(role.descriptionKey),
    }))

    const databaseOptions = roles
      .map((role) => role.role_name)
      .filter((roleName) => !selectedKeys.has(roleName.toLowerCase()))
      .sort((a, b) => a.localeCompare(b))
      .map((roleName) => {
        const row = roles.find((entry) => entry.role_name === roleName)
        return {
          value: roleName,
          label: roleName,
          description: row && isPostgresBuiltinRole(row) ? t('System') : undefined,
        }
      })

    return [...specialOptions, ...databaseOptions]
  }, [roles, selectedRoles, t])

  const addRole = (roleName: string) => {
    onChange(formatPostgresPolicyFormRoles(addPostgresPolicyFormRole(selectedRoles, roleName)))
  }

  const removeRole = (roleName: string) => {
    onChange(
      formatPostgresPolicyFormRoles(
        removePostgresPolicyFormRole(selectedRoles, roleName),
      ),
    )
  }

  return (
    <div className="space-y-2">
      <Label className="text-[12px] font-medium">{t('Roles')}</Label>
      <SearchableSelect
        value=""
        onValueChange={addRole}
        items={roleOptions}
        placeholder={t('Add role')}
        searchPlaceholder={t('Search roles...')}
        emptyMessage={isLoading ? t('Loading roles…') : t('No roles available')}
        disabled={isLoading && roles.length === 0}
      />
      {selectedRoles.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {selectedRoles.map((role) => (
            <Badge key={role} variant="secondary" className="gap-1 pr-1 text-[12px]">
              {role}
              <button
                type="button"
                className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                onClick={() => removeRole(role)}
                aria-label={t('Remove')}
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      ) : (
        <p className="text-[12px] text-muted-foreground">
          {t('Defaults to all (public) roles if none selected.')}
        </p>
      )}
    </div>
  )
}

function getPolicyTypeLabel(
  type: PostgresPolicyPermissive,
  t: (text: string) => string,
): string {
  return type === 'PERMISSIVE' ? t('Permissive') : t('Restrictive')
}

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

  const policySnapshot = useMemo(
    () => (policy ? normalizePostgresTablePolicyRow(policy) : null),
    [policy],
  )

  const [formState, setFormState] = useState<PostgresPolicyFormState>(() =>
    policy
      ? mapPostgresPolicyRowToFormState(policy)
      : createDefaultPostgresPolicyFormState(),
  )

  useEffect(() => {
    if (!open) return
    setFormState(
      policySnapshot
        ? mapPostgresPolicyRowToFormState(policySnapshot)
        : createDefaultPostgresPolicyFormState(),
    )
  }, [open, policySnapshot])

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
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent className="min-w-[var(--radix-select-trigger-width)]">
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
                  <SelectTrigger className="w-full">
                    <SelectValue>
                      {getPolicyTypeLabel(formState.permissive, t)}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent className="min-w-[var(--radix-select-trigger-width)]">
                    <SelectItem value="PERMISSIVE" className="items-start py-2">
                      <div className="flex flex-col gap-0.5">
                        <span className="font-medium">{t('Permissive')}</span>
                        <span className="text-[11px] text-muted-foreground">
                          {t(
                            'Policies are combined using the "OR" Boolean operator.',
                          )}
                        </span>
                      </div>
                    </SelectItem>
                    <SelectItem value="RESTRICTIVE" className="items-start py-2">
                      <div className="flex flex-col gap-0.5">
                        <span className="font-medium">{t('Restrictive')}</span>
                        <span className="text-[11px] text-muted-foreground">
                          {t(
                            'Policies are combined using the "AND" Boolean operator.',
                          )}
                        </span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <PostgresPolicyRolesField
              projectId={projectId}
              databaseId={databaseId}
              value={formState.roles}
              onChange={(roles) =>
                setFormState((prev) => ({
                  ...prev,
                  roles,
                }))
              }
            />

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
