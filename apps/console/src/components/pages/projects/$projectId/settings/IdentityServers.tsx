import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  LDAP_HOSTS_MAX,
  LDAP_PORT_MAX,
  LDAP_PORT_MIN,
  LDAP_TIMEOUT_MAX,
  LDAP_TIMEOUT_MIN,
  isLdapEnabled,
  ldapDefaultPort,
  ldapEncryptionFromProject,
  projectQueryOptions,
  useUpdateLDAP,
  type LdapEncryption,
  type LdapUpdateData,
} from '@/lib/react-query/hooks'
import { TestLDAPConnection } from './_components/TestLDAPConnection'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { InputTags } from '@/components/ui/input-tags'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

interface IdentityServersProps {
  projectId: string
}

type LdapForm = {
  enabled: boolean
  hosts: string[]
  /** Text, so the field can be left empty for the default port of the encryption. */
  port: string
  encryption: LdapEncryption
  tls: boolean
  caCertificate: string
  timeout: number
  baseDn: string
  bindDn: string
  /** Write-only: never prefilled, sent only when typed. */
  bindPassword: string
  usernameAttribute: string
  userFilter: string
  idAttribute: string
  emailAttribute: string
  nameAttribute: string
  phoneAttribute: string
  requiredGroupDn: string
}

const INPUT_CLASS_NAME =
  'h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0'

/** Catalog placeholders are one line; a PEM placeholder should show real line breaks. */
const CA_CERTIFICATE_PLACEHOLDER = `-----BEGIN CERTIFICATE-----
MIIDdTCCAl2gAwIBAgIVAK...
-----END CERTIFICATE-----`

const EMPTY_FORM: LdapForm = {
  enabled: false,
  hosts: [],
  port: '',
  encryption: 'ldaps',
  tls: true,
  caCertificate: '',
  timeout: 10,
  baseDn: '',
  bindDn: '',
  bindPassword: '',
  usernameAttribute: 'uid',
  userFilter: '',
  idAttribute: 'entryUUID',
  emailAttribute: 'mail',
  nameAttribute: 'cn',
  phoneAttribute: '',
  requiredGroupDn: '',
}

function formFromProject(project: Models.Project): LdapForm {
  const encryption = ldapEncryptionFromProject(project.ldapEncryption)
  const port = project.ldapPort || 0
  return {
    enabled: isLdapEnabled(project),
    hosts: project.ldapHosts ?? [],
    // The project reports the port a sign-in uses; the field shows it only when it deviates from the default.
    port: port && port !== ldapDefaultPort(encryption) ? String(port) : '',
    encryption,
    tls: project.ldapTls ?? true,
    caCertificate: project.ldapCaCertificate ?? '',
    timeout: project.ldapTimeout || EMPTY_FORM.timeout,
    baseDn: project.ldapBaseDn ?? '',
    bindDn: project.ldapBindDn ?? '',
    bindPassword: '',
    usernameAttribute:
      project.ldapUsernameAttribute || EMPTY_FORM.usernameAttribute,
    userFilter: project.ldapUserFilter ?? '',
    idAttribute: project.ldapIdAttribute || EMPTY_FORM.idAttribute,
    emailAttribute: project.ldapEmailAttribute || EMPTY_FORM.emailAttribute,
    nameAttribute: project.ldapNameAttribute || EMPTY_FORM.nameAttribute,
    phoneAttribute: project.ldapPhoneAttribute ?? '',
    requiredGroupDn: project.ldapRequiredGroupDn ?? '',
  }
}

function sameHosts(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((host, index) => host === b[index])
}

/** The port the form stands for: the typed one, or the default of the chosen encryption. */
function portOf(form: LdapForm): number {
  return form.port.trim() === ''
    ? ldapDefaultPort(form.encryption)
    : Number(form.port)
}

const TEXT_FIELDS = [
  'caCertificate',
  'baseDn',
  'bindDn',
  'usernameAttribute',
  'userFilter',
  'idAttribute',
  'emailAttribute',
  'nameAttribute',
  'phoneAttribute',
  'requiredGroupDn',
] as const

/** Only what differs from the saved configuration is sent; a field left out keeps its stored value. */
function changedFields(form: LdapForm, saved: LdapForm): LdapUpdateData {
  const data: LdapUpdateData = {}
  if (form.enabled !== saved.enabled) data.enabled = form.enabled
  if (!sameHosts(form.hosts, saved.hosts)) data.hosts = form.hosts
  if (portOf(form) !== portOf(saved)) data.port = portOf(form)
  if (form.encryption !== saved.encryption) data.encryption = form.encryption
  if (form.tls !== saved.tls) data.tls = form.tls
  if (form.timeout !== saved.timeout) data.timeout = form.timeout
  for (const field of TEXT_FIELDS) {
    const value = form[field].trim()
    if (value !== saved[field]) data[field] = value
  }
  // Write-only: a typed password is itself a change.
  if (form.bindPassword !== '') data.bindPassword = form.bindPassword
  return data
}

function Field({
  id,
  label,
  hint,
  required = false,
  className,
  children,
}: {
  id: string
  label: string
  hint: string
  required?: boolean
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor={id} className="text-[12px] font-medium">
        {label}
        {required ? (
          <>
            {' '}
            <span className="text-destructive">*</span>
          </>
        ) : null}
      </Label>
      {children}
      <p className="text-[11px] text-muted-foreground">{hint}</p>
    </div>
  )
}

export function IdentityServers({ projectId }: IdentityServersProps) {
  const t = useT()
  const { data: project, isLoading } = useQuery(projectQueryOptions(projectId))
  const updateLDAPMutation = useUpdateLDAP(projectId)
  const [testDialogOpen, setTestDialogOpen] = useState(false)

  const saved = useMemo(
    () => (project ? formFromProject(project) : null),
    [project],
  )
  const [form, setForm] = useState<LdapForm>(() => saved ?? EMPTY_FORM)

  // Initialize form from project data
  useEffect(() => {
    if (saved) setForm(saved)
  }, [saved])

  const patch = (changes: Partial<LdapForm>) =>
    setForm((current) => ({ ...current, ...changes }))

  const handleEncryptionChange = (encryption: LdapEncryption) =>
    setForm((current) => ({
      ...current,
      encryption,
      // A port left at the default follows the new encryption.
      port:
        current.port.trim() === '' ||
        Number(current.port) === ldapDefaultPort(current.encryption)
          ? ''
          : current.port,
    }))

  const changes = useMemo(
    () => (saved ? changedFields(form, saved) : {}),
    [form, saved],
  )
  const hasChanges = Object.keys(changes).length > 0

  const handleUpdate = async () => {
    if (
      changes.port !== undefined &&
      (changes.port < LDAP_PORT_MIN || changes.port > LDAP_PORT_MAX)
    ) {
      toast.error(t('Port must be between 1 and 65535.'))
      return
    }
    try {
      await updateLDAPMutation.mutateAsync(changes)
      toast.success(
        changes.enabled === undefined
          ? t('LDAP settings have been updated.')
          : changes.enabled
            ? t('LDAP sign-in has been enabled.')
            : t('LDAP sign-in has been disabled.'),
      )
    } catch (error: unknown) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update LDAP settings'),
      )
    }
  }

  const savedEnabled = saved?.enabled ?? false
  const canTest = Boolean(saved && saved.hosts.length > 0 && saved.baseDn)
  const testDisabledReason = canTest
    ? undefined
    : t(
        'Update the configuration with at least one host and a base DN before testing the connection.',
      )
  const isBusy = updateLDAPMutation.isPending

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-4 sm:px-6">
      <p className="text-[13px] text-muted-foreground">
        {t(
          'Connect the identity systems your organization already runs so people can sign in with the accounts they have.',
        )}
      </p>

      {/* LDAP Configuration Card */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        {/* Header */}
        <div className="px-6 py-4">
          <div className="flex items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('LDAP')}
            </h3>
            <Badge
              variant={savedEnabled ? 'success' : 'inactive'}
              className="text-[10px] shrink-0"
            >
              {savedEnabled ? t('Enabled') : t('Disabled')}
            </Badge>
          </div>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Let users sign in with the credentials of your LDAP directory, such as Active Directory or OpenLDAP.',
            )}
          </p>
        </div>

        {/* Separator */}
        <div className="border-t border-border" />

        <div className="px-6 py-4">
          {/* Enable/Disable Toggle */}
          <div className="flex items-center justify-between mb-6">
            <div className="flex-1">
              <Label
                htmlFor="ldap-enabled"
                className="text-[13px] font-medium text-foreground"
              >
                {t('Enabled')}
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t(
                  'Turn on LDAP sign-in for this project. Enabling connects to the directory to validate the configuration, so add at least one host and a base DN first.',
                )}
              </p>
            </div>
            <Switch
              id="ldap-enabled"
              checked={form.enabled}
              onCheckedChange={(enabled) => patch({ enabled })}
              disabled={isBusy}
            />
          </div>

          <div className="space-y-6">
            {/* Connection Section */}
            <div className="space-y-4">
              <div>
                <h4 className="text-[13px] font-medium text-foreground mb-3">
                  {t('Connection')}
                </h4>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    id="ldap-hosts"
                    label={t('Hosts')}
                    required
                    hint={t(
                      'Hostnames or IP addresses of the directory, tried in order until one answers. Press Enter after each host, up to 10.',
                    )}
                    className="sm:col-span-2"
                  >
                    <InputTags
                      id="ldap-hosts"
                      value={form.hosts}
                      onChange={(hosts) =>
                        patch({ hosts: hosts.slice(0, LDAP_HOSTS_MAX) })
                      }
                      splitOnComma
                      placeholder="ldap.example.com"
                      disabled={isBusy}
                    />
                  </Field>

                  <Field
                    id="ldap-port"
                    label={t('Port')}
                    hint={t(
                      'Leave empty for the default: 636 for LDAPS, 389 otherwise.',
                    )}
                  >
                    <Input
                      id="ldap-port"
                      type="number"
                      inputMode="numeric"
                      min={LDAP_PORT_MIN}
                      max={LDAP_PORT_MAX}
                      placeholder={String(ldapDefaultPort(form.encryption))}
                      value={form.port}
                      onChange={(e) =>
                        patch({ port: e.target.value.replace(/\D/g, '') })
                      }
                      disabled={isBusy}
                      className={INPUT_CLASS_NAME}
                    />
                  </Field>

                  <Field
                    id="ldap-encryption"
                    label={t('Encryption')}
                    hint={t(
                      'LDAPS encrypts from the first byte, StartTLS upgrades a plain connection, None is for a trusted network only.',
                    )}
                  >
                    <Select
                      value={form.encryption}
                      onValueChange={(value) =>
                        handleEncryptionChange(value as LdapEncryption)
                      }
                      disabled={isBusy}
                    >
                      <SelectTrigger
                        id="ldap-encryption"
                        className="h-9 text-[13px]"
                      >
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">{t('None')}</SelectItem>
                        <SelectItem value="ldaps">LDAPS</SelectItem>
                        <SelectItem value="starttls">StartTLS</SelectItem>
                      </SelectContent>
                    </Select>
                  </Field>

                  <Field
                    id="ldap-tls"
                    label={t('Verify certificate')}
                    hint={t(
                      'Check the certificate the directory presents against the CA certificate below or the system trust store.',
                    )}
                  >
                    <div className="flex h-9 items-center">
                      <Switch
                        id="ldap-tls"
                        checked={form.tls}
                        onCheckedChange={(tls) => patch({ tls })}
                        disabled={isBusy}
                      />
                    </div>
                  </Field>

                  <Field
                    id="ldap-timeout"
                    label={t('Timeout (seconds)')}
                    hint={t(
                      'Seconds to wait for the directory to connect and answer each operation, from 1 to 30.',
                    )}
                  >
                    <NumberInput
                      id="ldap-timeout"
                      min={LDAP_TIMEOUT_MIN}
                      max={LDAP_TIMEOUT_MAX}
                      value={form.timeout}
                      onValueChange={(timeout) => patch({ timeout })}
                      disabled={isBusy}
                      className={INPUT_CLASS_NAME}
                    />
                  </Field>

                  <Field
                    id="ldap-ca-certificate"
                    label={t('CA certificate (PEM)')}
                    hint={t(
                      'Certificate of the authority that signed the directory certificate. Leave empty to use the system trust store.',
                    )}
                    className="sm:col-span-2"
                  >
                    <Textarea
                      id="ldap-ca-certificate"
                      placeholder={CA_CERTIFICATE_PLACEHOLDER}
                      value={form.caCertificate}
                      onChange={(e) => patch({ caCertificate: e.target.value })}
                      disabled={isBusy}
                      spellCheck={false}
                      className="min-h-28 border-border bg-background font-mono text-[12px] text-foreground placeholder:text-muted-foreground md:text-[12px]"
                    />
                  </Field>
                </div>
              </div>
            </div>

            {/* Directory Section */}
            <div className="space-y-4">
              <div>
                <h4 className="text-[13px] font-medium text-foreground mb-3">
                  {t('Directory')}
                </h4>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    id="ldap-base-dn"
                    label={t('Base DN')}
                    required
                    hint={t(
                      'Subtree the user search starts from, for example ou=people,dc=example,dc=com.',
                    )}
                    className="sm:col-span-2"
                  >
                    <Input
                      id="ldap-base-dn"
                      placeholder="ou=people,dc=example,dc=com"
                      value={form.baseDn}
                      onChange={(e) => patch({ baseDn: e.target.value })}
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-bind-dn"
                    label={t('Bind DN')}
                    hint={t(
                      'Service account used to search for users. Leave empty if the directory allows anonymous search.',
                    )}
                  >
                    <Input
                      id="ldap-bind-dn"
                      placeholder="cn=appwrite,ou=services,dc=example,dc=com"
                      value={form.bindDn}
                      onChange={(e) => patch({ bindDn: e.target.value })}
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-bind-password"
                    label={t('Bind password')}
                    hint={t(
                      'Stored securely. Required again when hosts, port, encryption or bind DN change.',
                    )}
                  >
                    <Input
                      id="ldap-bind-password"
                      type="password"
                      placeholder={t('Enter password')}
                      value={form.bindPassword}
                      onChange={(e) => patch({ bindPassword: e.target.value })}
                      disabled={isBusy}
                      autoComplete="new-password"
                      className={INPUT_CLASS_NAME}
                    />
                  </Field>

                  <Field
                    id="ldap-username-attribute"
                    label={t('Username attribute')}
                    hint={t(
                      'Attribute the username is matched against: uid, or sAMAccountName on Active Directory.',
                    )}
                  >
                    <Input
                      id="ldap-username-attribute"
                      placeholder="uid"
                      value={form.usernameAttribute}
                      onChange={(e) =>
                        patch({ usernameAttribute: e.target.value })
                      }
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-user-filter"
                    label={t('User filter')}
                    hint={t(
                      'Optional LDAP filter every user must also match, for example (objectClass=inetOrgPerson).',
                    )}
                  >
                    <Input
                      id="ldap-user-filter"
                      placeholder="(objectClass=inetOrgPerson)"
                      value={form.userFilter}
                      onChange={(e) => patch({ userFilter: e.target.value })}
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-id-attribute"
                    label={t('ID attribute')}
                    hint={t(
                      'Attribute with the identifier that stays the same when an entry is renamed or moved: entryUUID, or objectGUID on Active Directory.',
                    )}
                  >
                    <Input
                      id="ldap-id-attribute"
                      placeholder="entryUUID"
                      value={form.idAttribute}
                      onChange={(e) => patch({ idAttribute: e.target.value })}
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-email-attribute"
                    label={t('Email attribute')}
                    hint={t(
                      'Attribute holding the email address, usually mail.',
                    )}
                  >
                    <Input
                      id="ldap-email-attribute"
                      placeholder="mail"
                      value={form.emailAttribute}
                      onChange={(e) =>
                        patch({ emailAttribute: e.target.value })
                      }
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-name-attribute"
                    label={t('Name attribute')}
                    hint={t(
                      'Attribute holding the display name: cn, or displayName on Active Directory.',
                    )}
                  >
                    <Input
                      id="ldap-name-attribute"
                      placeholder="cn"
                      value={form.nameAttribute}
                      onChange={(e) => patch({ nameAttribute: e.target.value })}
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-phone-attribute"
                    label={t('Phone attribute')}
                    hint={t(
                      'Attribute holding the phone number in E.164 format, for example mobile. Leave empty to skip phone numbers.',
                    )}
                  >
                    <Input
                      id="ldap-phone-attribute"
                      placeholder="mobile"
                      value={form.phoneAttribute}
                      onChange={(e) =>
                        patch({ phoneAttribute: e.target.value })
                      }
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>

                  <Field
                    id="ldap-required-group-dn"
                    label={t('Required group DN')}
                    hint={t(
                      'Group a user must belong to in order to sign in, checked on every sign-in. Leave empty to allow every user the directory authenticates.',
                    )}
                    className="sm:col-span-2"
                  >
                    <Input
                      id="ldap-required-group-dn"
                      placeholder="cn=staff,ou=groups,dc=example,dc=com"
                      value={form.requiredGroupDn}
                      onChange={(e) =>
                        patch({ requiredGroupDn: e.target.value })
                      }
                      disabled={isBusy}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                  </Field>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleUpdate}
              disabled={!hasChanges || isBusy}
            >
              {t('Update')}
            </Button>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setTestDialogOpen(true)}
                    disabled={!canTest || isBusy}
                  >
                    {t('Test connection')}
                  </Button>
                </span>
              </TooltipTrigger>
              {testDisabledReason ? (
                <TooltipContent className="max-w-xs text-[13px]">
                  {testDisabledReason}
                </TooltipContent>
              ) : null}
            </Tooltip>
          </div>
        </div>
      </div>

      <TestLDAPConnection
        open={testDialogOpen}
        onOpenChange={setTestDialogOpen}
        projectId={projectId}
        hasUnsavedChanges={hasChanges}
      />
    </div>
  )
}
