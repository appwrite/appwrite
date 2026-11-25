import {
  Fragment,
  useEffect,
  useMemo,
  useState,
  type ClipboardEvent,
  type ReactNode,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { Info, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  LDAP_HOSTS_MAX,
  LDAP_PORT_MAX,
  LDAP_PORT_MIN,
  LDAP_TIMEOUT_MAX,
  LDAP_TIMEOUT_MIN,
  isLdapEnabled,
  ldapEncryptionFromProject,
  projectQueryOptions,
  useUpdateLDAP,
  type LdapUpdateData,
} from '@/lib/react-query/hooks'
import {
  LDAP_USER_FILTER_DEFAULT,
  isLdapUserFilterValid,
  ldapDefaultPort,
  ldapSettingsFromUrls,
  type LdapEncryption,
} from '@/lib/ldap'
import { TestLDAPConnection } from '../_components/TestLDAPConnection'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { InputTags } from '@/components/ui/input-tags'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
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

interface LDAPProps {
  projectId: string
}

type LdapForm = {
  enabled: boolean
  hosts: string[]
  /** Text, so the field can be left empty for the default port of the encryption. */
  port: string
  encryption: LdapEncryption
  verification: boolean
  certificate: string
  timeout: number
  baseDn: string
  bindDn: string
  /** Write-only: never prefilled, sent only when typed. */
  bindPassword: string
  userFilter: string
  requiredGroupDn: string
  idAttribute: string
  emailAttribute: string
  nameAttribute: string
  phoneAttribute: string
}

const INPUT_CLASS_NAME =
  'h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0'

/** Catalog placeholders are one line; a PEM placeholder should show real line breaks. */
const CERTIFICATE_PLACEHOLDER = `-----BEGIN CERTIFICATE-----
MIIDdTCCAl2gAwIBAgIVAK...
-----END CERTIFICATE-----`

const EMPTY_FORM: LdapForm = {
  enabled: false,
  hosts: [],
  port: '',
  encryption: 'ldaps',
  verification: true,
  certificate: '',
  timeout: 10,
  baseDn: '',
  bindDn: '',
  bindPassword: '',
  userFilter: LDAP_USER_FILTER_DEFAULT,
  requiredGroupDn: '',
  idAttribute: 'entryUUID',
  emailAttribute: 'mail',
  nameAttribute: 'cn',
  phoneAttribute: '',
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
    verification: project.ldapVerification ?? true,
    certificate: project.ldapCertificate ?? '',
    timeout: project.ldapTimeout || EMPTY_FORM.timeout,
    baseDn: project.ldapBaseDn ?? '',
    bindDn: project.ldapBindDn ?? '',
    bindPassword: '',
    userFilter: project.ldapUserFilter || EMPTY_FORM.userFilter,
    requiredGroupDn: project.ldapRequiredGroupDn ?? '',
    idAttribute: project.ldapIdAttribute || EMPTY_FORM.idAttribute,
    emailAttribute: project.ldapEmailAttribute || EMPTY_FORM.emailAttribute,
    nameAttribute: project.ldapNameAttribute || EMPTY_FORM.nameAttribute,
    phoneAttribute: project.ldapPhoneAttribute ?? '',
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
  'certificate',
  'baseDn',
  'bindDn',
  'requiredGroupDn',
  'idAttribute',
  'emailAttribute',
  'nameAttribute',
  'phoneAttribute',
] as const

/**
 * Changing any of these points the stored bind password at another directory or
 * trusts another certificate, so the API refuses the change unless the password
 * is sent again.
 */
const BIND_PASSWORD_GUARDED_FIELDS = [
  'hosts',
  'port',
  'encryption',
  'verification',
  'certificate',
  'bindDn',
] as const

function changesGuardedField(data: LdapUpdateData): boolean {
  return BIND_PASSWORD_GUARDED_FIELDS.some((field) => data[field] !== undefined)
}

/** Only what differs from the saved configuration is sent; a field left out keeps its stored value. */
function changedFields(form: LdapForm, saved: LdapForm): LdapUpdateData {
  const data: LdapUpdateData = {}
  if (form.enabled !== saved.enabled) data.enabled = form.enabled
  if (!sameHosts(form.hosts, saved.hosts)) data.hosts = form.hosts
  if (portOf(form) !== portOf(saved)) data.port = portOf(form)
  if (form.encryption !== saved.encryption) data.encryption = form.encryption
  if (form.verification !== saved.verification)
    data.verification = form.verification
  if (form.timeout !== saved.timeout) data.timeout = form.timeout
  for (const field of TEXT_FIELDS) {
    const value = form[field].trim()
    if (value !== saved[field].trim()) data[field] = value
  }
  // Empty stands for the default filter, which the project reports as the one in use.
  const userFilter = form.userFilter.trim()
  if ((userFilter || LDAP_USER_FILTER_DEFAULT) !== saved.userFilter)
    data.userFilter = userFilter
  // Write-only: a typed password is itself a change.
  if (form.bindPassword !== '') {
    data.bindPassword = form.bindPassword
  } else if (changesGuardedField(data) && form.bindDn.trim() === '') {
    // Anonymous search binds without a password: clear the stored one.
    data.bindPassword = ''
  }
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

/** Active Directory names the user attributes differently: the values to use, on demand. */
function ActiveDirectoryInfo() {
  const t = useT()
  const rows = [
    [t('User filter'), '(sAMAccountName={username})'],
    [t('ID attribute'), 'objectGUID'],
    [t('Name attribute'), 'displayName'],
  ]

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={t('Using Active Directory?')}
          className="inline-flex h-4 w-4 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Info className="h-3.5 w-3.5" aria-hidden />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-auto space-y-2 p-3">
        <p className="text-[13px] font-medium text-foreground">
          {t('Using Active Directory?')}
        </p>
        <dl className="grid grid-cols-[auto_auto] gap-x-4 gap-y-1 text-[12px]">
          {rows.map(([label, value]) => (
            <Fragment key={value}>
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="select-all font-mono text-foreground">{value}</dd>
            </Fragment>
          ))}
        </dl>
      </PopoverContent>
    </Popover>
  )
}

export function LDAP({ projectId }: LDAPProps) {
  const t = useT()
  const { data: project, isLoading } = useQuery(projectQueryOptions(projectId))
  const updateLDAPMutation = useUpdateLDAP(projectId)
  const [testDialogOpen, setTestDialogOpen] = useState(false)

  const saved = useMemo(
    () => (project ? formFromProject(project) : null),
    [project],
  )
  const [form, setForm] = useState<LdapForm>(() => saved ?? EMPTY_FORM)
  // A base DN from a pasted URL, offered when the field already holds another one.
  const [baseDnOffer, setBaseDnOffer] = useState('')

  // Initialize form from project data
  useEffect(() => {
    if (saved) setForm(saved)
    setBaseDnOffer('')
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

  /** A pasted ldap:// or ldaps:// URL fills hosts, encryption, port and base DN instead of becoming a host. */
  const handleHostsPaste = (event: ClipboardEvent<HTMLInputElement>) => {
    const settings = ldapSettingsFromUrls(
      event.clipboardData.getData('text'),
      form.encryption,
    )
    if (!settings) return
    event.preventDefault()
    const keepBaseDn = form.baseDn.trim() !== '' || settings.baseDn === ''
    setForm((current) => ({
      ...current,
      hosts: [
        ...current.hosts,
        ...settings.hosts.filter((host) => !current.hosts.includes(host)),
      ].slice(0, LDAP_HOSTS_MAX),
      encryption: settings.encryption,
      port: settings.port,
      baseDn: keepBaseDn ? current.baseDn : settings.baseDn,
    }))
    setBaseDnOffer(
      keepBaseDn && settings.baseDn !== form.baseDn.trim()
        ? settings.baseDn
        : '',
    )
  }

  const changes = useMemo(
    () => (saved ? changedFields(form, saved) : {}),
    [form, saved],
  )
  const hasChanges = Object.keys(changes).length > 0
  // The service account's password has to come with any change that moves or re-trusts the directory.
  const needsBindPassword =
    changesGuardedField(changes) && changes.bindPassword === undefined
  const userFilterInvalid = !isLdapUserFilterValid(form.userFilter)

  const handleUpdate = async () => {
    if (
      form.enabled &&
      (form.hosts.length === 0 || form.baseDn.trim() === '')
    ) {
      toast.error(
        t('Add at least one host and a base DN to enable LDAP sign-in.'),
      )
      return
    }
    if (
      changes.port !== undefined &&
      (changes.port < LDAP_PORT_MIN || changes.port > LDAP_PORT_MAX)
    ) {
      toast.error(t('Port must be between 1 and 65535.'))
      return
    }
    if (userFilterInvalid) return
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
    <div className="space-y-6">
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
                {t('Enable LDAP sign-in')}
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t(
                  'When enabled, users can sign in with their directory username and password.',
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
                      'Tried in order until one answers, up to 10. Pasting an LDAP URL also fills in encryption, port and base DN.',
                    )}
                    className="sm:col-span-2"
                  >
                    <InputTags
                      id="ldap-hosts"
                      value={form.hosts}
                      onChange={(hosts) =>
                        patch({ hosts: hosts.slice(0, LDAP_HOSTS_MAX) })
                      }
                      onPaste={handleHostsPaste}
                      splitOnComma
                      placeholder="ldap.example.com"
                      disabled={isBusy}
                    />
                    {baseDnOffer && baseDnOffer !== form.baseDn.trim() ? (
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-foreground">
                        <span className="min-w-0 break-all">
                          {t('Use {dn} as the base DN?').replace(
                            '{dn}',
                            () => baseDnOffer,
                          )}
                        </span>
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="h-auto p-0 text-[12px]"
                          onClick={() => {
                            patch({ baseDn: baseDnOffer })
                            setBaseDnOffer('')
                          }}
                          disabled={isBusy}
                        >
                          {t('Use')}
                        </Button>
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="h-auto p-0 text-[12px] text-muted-foreground"
                          onClick={() => setBaseDnOffer('')}
                        >
                          {t('Dismiss')}
                        </Button>
                      </div>
                    ) : null}
                  </Field>

                  <Field
                    id="ldap-port"
                    label={t('Port')}
                    hint={t(
                      'Leave empty for 636 with LDAPS, or 389 otherwise.',
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
                    hint={t('Use None only on a trusted private network.')}
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

                  {/* Without encryption there is no certificate to check; the values stay for when it comes back. */}
                  {form.encryption !== 'none' ? (
                    <>
                      {/* A switch row like Enabled, so it does not leave half a row empty. */}
                      <div className="flex items-center justify-between gap-4 sm:col-span-2">
                        <div className="flex-1">
                          <Label
                            htmlFor="ldap-verification"
                            className="text-[13px] font-medium text-foreground"
                          >
                            {t('Verify certificate')}
                          </Label>
                          <p className="text-[12px] text-muted-foreground mt-0.5">
                            {t(
                              "Check the directory's certificate before sending any password.",
                            )}
                          </p>
                        </div>
                        <Switch
                          id="ldap-verification"
                          checked={form.verification}
                          onCheckedChange={(verification) =>
                            patch({ verification })
                          }
                          disabled={isBusy}
                        />
                      </div>

                      {/* Only a verified connection uses the certificate; the value stays for when verification comes back. */}
                      {form.verification ? (
                        <Field
                          id="ldap-certificate"
                          label={t('Certificate (PEM)')}
                          hint={t(
                            'The CA or self-signed certificate to trust. Leave empty to use the system trust store.',
                          )}
                          className="sm:col-span-2"
                        >
                          <Textarea
                            id="ldap-certificate"
                            placeholder={CERTIFICATE_PLACEHOLDER}
                            value={form.certificate}
                            onChange={(e) =>
                              patch({ certificate: e.target.value })
                            }
                            disabled={isBusy}
                            spellCheck={false}
                            className="min-h-28 border-border bg-background font-mono text-[12px] text-foreground placeholder:text-muted-foreground md:text-[12px]"
                          />
                        </Field>
                      ) : null}
                    </>
                  ) : null}

                  <Field
                    id="ldap-timeout"
                    label={t('Timeout (seconds)')}
                    hint={t('How long to wait for each answer, from 1 to 30.')}
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
                      'Service account for the search, or empty for anonymous.',
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
                    required={needsBindPassword}
                    hint={t(
                      'Never shown again. Re-enter it when the connection changes.',
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
                      aria-invalid={needsBindPassword || undefined}
                      className={INPUT_CLASS_NAME}
                    />
                    {needsBindPassword ? (
                      <p className="text-[11px] text-destructive">
                        {t('Enter the bind password again to save.')}
                      </p>
                    ) : null}
                  </Field>
                </div>
              </div>
            </div>

            {/* Sign-in Rules Section */}
            <div className="space-y-4">
              <div>
                <div className="mb-3 flex items-center gap-1.5">
                  <h4 className="text-[13px] font-medium text-foreground">
                    {t('Sign-in rules')}
                  </h4>
                  <ActiveDirectoryInfo />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    id="ldap-user-filter"
                    label={t('User filter')}
                    hint={t(
                      'Finds the person signing in. {username} is replaced with what they type.',
                    )}
                    className="sm:col-span-2"
                  >
                    <Input
                      id="ldap-user-filter"
                      placeholder={LDAP_USER_FILTER_DEFAULT}
                      value={form.userFilter}
                      onChange={(e) => patch({ userFilter: e.target.value })}
                      disabled={isBusy}
                      aria-invalid={userFilterInvalid || undefined}
                      className={cn(INPUT_CLASS_NAME, 'font-mono')}
                    />
                    {userFilterInvalid ? (
                      <p className="text-[11px] text-destructive">
                        {t('The user filter must contain {username}.')}
                      </p>
                    ) : null}
                  </Field>

                  <Field
                    id="ldap-required-group-dn"
                    label={t('Required group DN')}
                    hint={t(
                      'Only its members can sign in. Leave empty to allow everyone.',
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

            {/* Attribute Mapping Section */}
            <div className="space-y-4">
              <div>
                <h4 className="text-[13px] font-medium text-foreground mb-3">
                  {t('Attribute mapping')}
                </h4>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    id="ldap-id-attribute"
                    label={t('ID attribute')}
                    hint={t('An ID that never changes, such as entryUUID.')}
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
                    hint={t('Attribute holding the display name, usually cn.')}
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
                      'Attribute holding an E.164 phone number. Optional.',
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
              disabled={
                !hasChanges || needsBindPassword || userFilterInvalid || isBusy
              }
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
