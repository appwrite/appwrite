import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { X } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useT } from '@/lib/i18n/translate'
import {
  buildMysqlCreateRoleSql,
  buildMysqlUpdateRoleSql,
  createDefaultMysqlRoleFormState,
  mapMysqlRoleRowToFormState,
  parseMysqlRoleMembership,
  validateMysqlRoleFormState,
  type MysqlRoleFormState,
  type MysqlRoleRow,
} from '@/lib/mysql-roles'
import { useExecuteMysqlSql } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type MysqlRoleDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
  role?: MysqlRoleRow | null
  availableRoles: MysqlRoleRow[]
  onSuccess: () => void
}

type RoleOptionSwitchProps = {
  title: string
  description: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
}

function RoleOptionSwitch({
  title,
  description,
  checked,
  onCheckedChange,
  disabled = false,
}: RoleOptionSwitchProps) {
  return (
    <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-4 py-3">
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="text-[12px] text-muted-foreground">{description}</p>
      </div>
      <Switch
        checked={checked}
        onCheckedChange={onCheckedChange}
        disabled={disabled}
        aria-label={title}
      />
    </div>
  )
}

function FormSection({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <section className="space-y-3">
      <div>
        <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
        {description ? (
          <p className="mt-1 text-[12px] text-muted-foreground">{description}</p>
        ) : null}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  )
}

export function MysqlRoleDrawer({
  open,
  onOpenChange,
  projectId,
  databaseId,
  role,
  availableRoles,
  onSuccess,
}: MysqlRoleDrawerProps) {
  const t = useT()
  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const isEdit = Boolean(role)

  const [formState, setFormState] = useState<MysqlRoleFormState>(
    createDefaultMysqlRoleFormState(),
  )

  useEffect(() => {
    if (!open) return
    setFormState(
      role
        ? mapMysqlRoleRowToFormState(role)
        : createDefaultMysqlRoleFormState(),
    )
  }, [open, role])

  const membershipOptions = useMemo(() => {
    const currentRoleName = role?.role_name
    return availableRoles
      .map((entry) => entry.role_name)
      .filter((name) => name !== currentRoleName)
      .sort((a, b) => a.localeCompare(b))
      .map((name) => ({ value: name, label: name }))
  }, [availableRoles, role?.role_name])

  const updateForm = (patch: Partial<MysqlRoleFormState>) => {
    setFormState((current) => ({ ...current, ...patch }))
  }

  const handleSubmit = async () => {
    const validationError = validateMysqlRoleFormState(formState, { isEdit })
    if (validationError) {
      toast.error(t(validationError))
      return
    }

    try {
      if (isEdit && role) {
        await executeSql.mutateAsync(
          buildMysqlUpdateRoleSql(
            formState,
            parseMysqlRoleMembership(role.member_of),
          ),
        )
        toast.success(t('Role updated'))
      } else {
        await executeSql.mutateAsync(buildMysqlCreateRoleSql(formState))
        toast.success(t('Role created'))
      }
      onOpenChange(false)
      onSuccess()
    } catch (error) {
      toast.error(
        getErrorMessage(error) ??
          t(isEdit ? 'Failed to update role' : 'Failed to create role'),
      )
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? t('Update role') : t('Create role')}
      description={
        isEdit
          ? t('Update MySQL role attributes, limits, and membership.')
          : t('Create a MySQL role for RLS policies and database access.')
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
          <div className="flex-1 space-y-6 overflow-y-auto px-6 pb-4 pt-4">
            <FormSection
              title={t('General')}
              description={t('Identity and authentication settings for this role.')}
            >
              {!isEdit ? (
                <div className="space-y-2">
                  <Label htmlFor="role-name" className="text-[12px] font-medium">
                    {t('Role name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="role-name"
                    value={formState.roleName}
                    onChange={(event) =>
                      updateForm({ roleName: event.target.value })
                    }
                    placeholder="app_reader"
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t('Use letters, numbers, and underscores only.')}
                  </p>
                </div>
              ) : (
                <div className="rounded-lg border border-border bg-muted/20 px-4 py-3">
                  <p className="text-[12px] text-muted-foreground">{t('Role name')}</p>
                  <p className="text-[13px] font-medium text-foreground">
                    {formState.roleName}
                  </p>
                </div>
              )}

              <RoleOptionSwitch
                title={t('Can login')}
                description={t('Allow this role to sign in to the database.')}
                checked={formState.canLogin}
                onCheckedChange={(checked) => updateForm({ canLogin: checked })}
              />

              {formState.canLogin ? (
                <div className="space-y-2">
                  <Label htmlFor="role-password" className="text-[12px] font-medium">
                    {isEdit ? t('New password') : t('Password')}{' '}
                    {!isEdit ? <span className="text-destructive">*</span> : null}
                  </Label>
                  <Input
                    id="role-password"
                    type="password"
                    value={formState.password}
                    onChange={(event) =>
                      updateForm({ password: event.target.value })
                    }
                    autoComplete="new-password"
                  />
                  {isEdit ? (
                    <p className="text-[12px] text-muted-foreground">
                      {t('Leave blank to keep the current password.')}
                    </p>
                  ) : null}
                </div>
              ) : null}

              <RoleOptionSwitch
                title={t('No expiry')}
                description={t('Keep this role valid indefinitely.')}
                checked={formState.noExpiry}
                onCheckedChange={(checked) => updateForm({ noExpiry: checked })}
              />

              {!formState.noExpiry ? (
                <div className="space-y-2">
                  <Label htmlFor="role-valid-until" className="text-[12px] font-medium">
                    {t('Valid until')} <span className="text-destructive">*</span>
                  </Label>
                  <DateTimePicker
                    id="role-valid-until"
                    value={formState.validUntil || null}
                    onChange={(value) =>
                      updateForm({ validUntil: value ?? '' })
                    }
                    clearable={false}
                  />
                </div>
              ) : null}
            </FormSection>

            <div className="border-t border-border" />

            <FormSection
              title={t('Privileges')}
              description={t('Cluster-level capabilities granted to this role.')}
            >
              <RoleOptionSwitch
                title={t('Superuser')}
                description={t('Grant unrestricted access across the entire cluster.')}
                checked={formState.isSuperuser}
                onCheckedChange={(checked) => updateForm({ isSuperuser: checked })}
              />
              <RoleOptionSwitch
                title={t('Can create roles')}
                description={t('Allow this role to create, alter, and drop other roles.')}
                checked={formState.canCreateRole}
                onCheckedChange={(checked) => updateForm({ canCreateRole: checked })}
              />
              <RoleOptionSwitch
                title={t('Can create databases')}
                description={t('Allow this role to create databases.')}
                checked={formState.canCreateDb}
                onCheckedChange={(checked) => updateForm({ canCreateDb: checked })}
              />
              <RoleOptionSwitch
                title={t('Replication')}
                description={t('Allow this role to connect in replication mode.')}
                checked={formState.canReplicate}
                onCheckedChange={(checked) => updateForm({ canReplicate: checked })}
              />
              <RoleOptionSwitch
                title={t('Inherit privileges')}
                description={t(
                  'Allow this role to use privileges granted to roles it is a member of.',
                )}
                checked={formState.inherit}
                onCheckedChange={(checked) => updateForm({ inherit: checked })}
              />
              <RoleOptionSwitch
                title={t('Bypass row level security')}
                description={t(
                  'Allow this role to read and write rows without matching RLS policies.',
                )}
                checked={formState.bypassRls}
                onCheckedChange={(checked) => updateForm({ bypassRls: checked })}
              />
            </FormSection>

            <div className="border-t border-border" />

            <FormSection
              title={t('Limits')}
              description={t('Connection limits for roles that can sign in.')}
            >
              <RoleOptionSwitch
                title={t('Unlimited connections')}
                description={t('Allow any number of concurrent connections.')}
                checked={formState.unlimitedConnections}
                onCheckedChange={(checked) =>
                  updateForm({ unlimitedConnections: checked })
                }
              />
              {!formState.unlimitedConnections ? (
                <div className="space-y-2">
                  <Label
                    htmlFor="role-connection-limit"
                    className="text-[12px] font-medium"
                  >
                    {t('Connection limit')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="role-connection-limit"
                    type="number"
                    min={0}
                    value={formState.connectionLimit}
                    onChange={(event) =>
                      updateForm({ connectionLimit: event.target.value })
                    }
                    placeholder="10"
                  />
                </div>
              ) : null}
            </FormSection>

            <div className="border-t border-border" />

            <FormSection
              title={t('Membership')}
              description={t(
                'Grant membership in other roles so this role inherits their privileges.',
              )}
            >
              <div className="space-y-2">
                <Label className="text-[12px] font-medium">{t('Member of')}</Label>
                <SearchableSelect
                  value=""
                  onValueChange={(value) => {
                    if (!value || formState.memberOf.includes(value)) return
                    updateForm({ memberOf: [...formState.memberOf, value] })
                  }}
                  items={membershipOptions.filter(
                    (option) => !formState.memberOf.includes(option.value),
                  )}
                  placeholder={t('Add parent role')}
                  searchPlaceholder={t('Search roles...')}
                  emptyMessage={t('No roles available')}
                  disabled={membershipOptions.length === 0}
                />
                {formState.memberOf.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {formState.memberOf.map((member) => (
                      <Badge
                        key={member}
                        variant="secondary"
                        className="gap-1 pr-1 text-[12px]"
                      >
                        {member}
                        <button
                          type="button"
                          className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                          onClick={() =>
                            updateForm({
                              memberOf: formState.memberOf.filter(
                                (entry) => entry !== member,
                              ),
                            })
                          }
                          aria-label={t('Remove')}
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-[12px] text-muted-foreground">
                    {t('This role is not a member of any other roles.')}
                  </p>
                )}
              </div>
            </FormSection>
          </div>

          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={executeSql.isPending}>
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
