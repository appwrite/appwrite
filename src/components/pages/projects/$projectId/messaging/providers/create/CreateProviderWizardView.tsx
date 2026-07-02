/**
 * Fullscreen create-provider wizard for messaging.
 * Stage 1: pick a provider (Email / SMS / Push).
 * Stage 2: configure the provider's specific fields.
 */

import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ID, SmtpEncryption } from '@appwrite.io/console'
import { ChevronRight, Mail, Phone, Bell } from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'

type ProviderType = 'email' | 'sms' | 'push'

type FieldType =
  | 'text'
  | 'password'
  | 'email'
  | 'number'
  | 'select'
  | 'switch'
  | 'textarea'
  | 'json'

type SelectOption = { value: string; label: string }

interface ProviderField {
  key: string
  label: string
  type: FieldType
  placeholder?: string
  required?: boolean
  defaultValue?: string | number | boolean
  options?: SelectOption[]
  helper?: string
}

interface ProviderConfig {
  id: string
  name: string
  type: ProviderType
  description: string
  /** Filename in /public/icons (omit for type fallback). */
  icon?: string
  fields: ProviderField[]
  /** Build the SDK call from the form values. */
  submit: (
    projectSdk: ReturnType<typeof sdk.forProject>,
    providerId: string,
    values: Record<string, unknown>,
  ) => Promise<{ $id: string }>
}

const COMMON_NAME_FIELD: ProviderField = {
  key: 'name',
  label: 'Name',
  type: 'text',
  required: true,
  placeholder: 'Production sender',
}

const COMMON_FROM_EMAIL_FIELDS: ProviderField[] = [
  {
    key: 'fromName',
    label: 'Sender name',
    type: 'text',
    placeholder: 'Acme Inc.',
  },
  {
    key: 'fromEmail',
    label: 'Sender email',
    type: 'email',
    placeholder: 'no-reply@example.com',
  },
]

const PHONE_FROM_FIELD: ProviderField = {
  key: 'from',
  label: 'Sender phone',
  type: 'text',
  placeholder: '+15551234567',
  helper: 'Include the leading + and country code.',
}

const PROVIDERS: ProviderConfig[] = [
  // Email
  {
    id: 'smtp',
    name: 'SMTP',
    type: 'email',
    description: 'Connect any SMTP server.',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'host', label: 'Host', type: 'text', required: true, placeholder: 'smtp.example.com' },
      { key: 'port', label: 'Port', type: 'number', defaultValue: 587 },
      {
        key: 'encryption',
        label: 'Encryption',
        type: 'select',
        defaultValue: SmtpEncryption.Tls,
        options: [
          { value: SmtpEncryption.None, label: 'None' },
          { value: SmtpEncryption.Ssl, label: 'SSL' },
          { value: SmtpEncryption.Tls, label: 'TLS' },
        ],
      },
      { key: 'username', label: 'Username', type: 'text' },
      { key: 'password', label: 'Password', type: 'password' },
      ...COMMON_FROM_EMAIL_FIELDS,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createSMTPProvider({
        providerId,
        name: String(v.name).trim(),
        host: String(v.host).trim(),
        port: parseInt(String(v.port), 10) || 587,
        username: optString(v.username),
        password: optString(v.password),
        encryption:
          (v.encryption as (typeof SmtpEncryption)[keyof typeof SmtpEncryption]) ??
          SmtpEncryption.Tls,
        autoTLS: true,
        fromName: optString(v.fromName),
        fromEmail: optString(v.fromEmail),
        enabled: true,
      }),
  },
  {
    id: 'resend',
    name: 'Resend',
    type: 'email',
    description: 'Send transactional email through Resend.',
    icon: 'resend.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'apiKey', label: 'API key', type: 'password', required: true },
      ...COMMON_FROM_EMAIL_FIELDS,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createResendProvider({
        providerId,
        name: String(v.name).trim(),
        apiKey: optString(v.apiKey),
        fromName: optString(v.fromName),
        fromEmail: optString(v.fromEmail),
        enabled: true,
      }),
  },
  {
    id: 'sendgrid',
    name: 'SendGrid',
    type: 'email',
    description: 'Send transactional email through SendGrid.',
    icon: 'sendgrid.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'apiKey', label: 'API key', type: 'password', required: true },
      ...COMMON_FROM_EMAIL_FIELDS,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createSendgridProvider({
        providerId,
        name: String(v.name).trim(),
        apiKey: optString(v.apiKey),
        fromName: optString(v.fromName),
        fromEmail: optString(v.fromEmail),
        enabled: true,
      }),
  },
  {
    id: 'mailgun',
    name: 'Mailgun',
    type: 'email',
    description: 'Send transactional email through Mailgun.',
    icon: 'mailgun.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'apiKey', label: 'API key', type: 'password', required: true },
      { key: 'domain', label: 'Domain', type: 'text', required: true, placeholder: 'mg.example.com' },
      {
        key: 'isEuRegion',
        label: 'EU region',
        type: 'switch',
        defaultValue: false,
        helper: 'Enable when your Mailgun account is hosted in the EU.',
      },
      ...COMMON_FROM_EMAIL_FIELDS,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createMailgunProvider({
        providerId,
        name: String(v.name).trim(),
        apiKey: optString(v.apiKey),
        domain: optString(v.domain),
        isEuRegion: Boolean(v.isEuRegion),
        fromName: optString(v.fromName),
        fromEmail: optString(v.fromEmail),
        enabled: true,
      }),
  },
  // SMS
  {
    id: 'twilio',
    name: 'Twilio',
    type: 'sms',
    description: 'Send SMS through Twilio.',
    icon: 'twilio.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'accountSid', label: 'Account SID', type: 'text', required: true },
      { key: 'authToken', label: 'Auth token', type: 'password', required: true },
      PHONE_FROM_FIELD,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createTwilioProvider({
        providerId,
        name: String(v.name).trim(),
        accountSid: optString(v.accountSid),
        authToken: optString(v.authToken),
        from: optString(v.from),
        enabled: true,
      }),
  },
  {
    id: 'vonage',
    name: 'Vonage',
    type: 'sms',
    description: 'Send SMS through Vonage.',
    icon: 'vonage.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'apiKey', label: 'API key', type: 'text', required: true },
      { key: 'apiSecret', label: 'API secret', type: 'password', required: true },
      PHONE_FROM_FIELD,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createVonageProvider({
        providerId,
        name: String(v.name).trim(),
        apiKey: optString(v.apiKey),
        apiSecret: optString(v.apiSecret),
        from: optString(v.from),
        enabled: true,
      }),
  },
  {
    id: 'msg91',
    name: 'MSG91',
    type: 'sms',
    description: 'Send SMS through MSG91.',
    icon: 'msg91.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'authKey', label: 'Auth key', type: 'password', required: true },
      { key: 'senderId', label: 'Sender ID', type: 'text' },
      { key: 'templateId', label: 'Template ID', type: 'text' },
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createMsg91Provider({
        providerId,
        name: String(v.name).trim(),
        authKey: optString(v.authKey),
        senderId: optString(v.senderId),
        templateId: optString(v.templateId),
        enabled: true,
      }),
  },
  {
    id: 'telesign',
    name: 'Telesign',
    type: 'sms',
    description: 'Send SMS through Telesign.',
    icon: 'telesign.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'customerId', label: 'Customer ID', type: 'text', required: true },
      { key: 'apiKey', label: 'API key', type: 'password', required: true },
      PHONE_FROM_FIELD,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createTelesignProvider({
        providerId,
        name: String(v.name).trim(),
        customerId: optString(v.customerId),
        apiKey: optString(v.apiKey),
        from: optString(v.from),
        enabled: true,
      }),
  },
  {
    id: 'textmagic',
    name: 'Textmagic',
    type: 'sms',
    description: 'Send SMS through Textmagic.',
    icon: 'textmagic.svg',
    fields: [
      COMMON_NAME_FIELD,
      { key: 'username', label: 'Username', type: 'text', required: true },
      { key: 'apiKey', label: 'API key', type: 'password', required: true },
      PHONE_FROM_FIELD,
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createTextmagicProvider({
        providerId,
        name: String(v.name).trim(),
        username: optString(v.username),
        apiKey: optString(v.apiKey),
        from: optString(v.from),
        enabled: true,
      }),
  },
  // Push
  {
    id: 'fcm',
    name: 'Firebase Cloud Messaging',
    type: 'push',
    description: 'Send push notifications via FCM (Android, iOS, web).',
    icon: 'firebase.svg',
    fields: [
      COMMON_NAME_FIELD,
      {
        key: 'serviceAccountJSON',
        label: 'Service account JSON',
        type: 'json',
        required: true,
        helper:
          'Paste the contents of the FCM service account JSON file from the Firebase console.',
      },
    ],
    submit: (projectSdk, providerId, v) => {
      const parsed = parseJson(v.serviceAccountJSON, 'Service account JSON')
      return projectSdk.messaging.createFCMProvider({
        providerId,
        name: String(v.name).trim(),
        serviceAccountJSON: parsed as object,
        enabled: true,
      })
    },
  },
  {
    id: 'apns',
    name: 'Apple Push Notifications',
    type: 'push',
    description: 'Send push notifications via APNS (iOS).',
    icon: 'apple.svg',
    fields: [
      COMMON_NAME_FIELD,
      {
        key: 'authKey',
        label: 'Auth key',
        type: 'textarea',
        required: true,
        placeholder: '-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----',
      },
      { key: 'authKeyId', label: 'Auth key ID', type: 'text', required: true },
      { key: 'teamId', label: 'Team ID', type: 'text', required: true },
      {
        key: 'bundleId',
        label: 'Bundle ID',
        type: 'text',
        required: true,
        placeholder: 'com.example.app',
      },
      {
        key: 'sandbox',
        label: 'Use sandbox environment',
        type: 'switch',
        defaultValue: false,
        helper: 'Enable for development builds, disable for production.',
      },
    ],
    submit: (projectSdk, providerId, v) =>
      projectSdk.messaging.createAPNSProvider({
        providerId,
        name: String(v.name).trim(),
        authKey: optString(v.authKey),
        authKeyId: optString(v.authKeyId),
        teamId: optString(v.teamId),
        bundleId: optString(v.bundleId),
        sandbox: Boolean(v.sandbox),
        enabled: true,
      }),
  },
]

const TYPE_LABEL: Record<ProviderType, string> = {
  email: 'Email',
  sms: 'SMS',
  push: 'Push',
}

const TYPE_ICON: Record<ProviderType, typeof Mail> = {
  email: Mail,
  sms: Phone,
  push: Bell,
}

function optString(v: unknown): string | undefined {
  if (typeof v !== 'string') return undefined
  const trimmed = v.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

function parseJson(value: unknown, label: string): unknown {
  if (typeof value !== 'string' || !value.trim()) {
    throw new Error(`${label} is required`)
  }
  try {
    return JSON.parse(value)
  } catch {
    throw new Error(`${label} must be valid JSON`)
  }
}

function defaultsForProvider(p: ProviderConfig): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const f of p.fields) {
    out[f.key] =
      f.defaultValue ?? (f.type === 'switch' ? false : f.type === 'number' ? '' : '')
  }
  return out
}

function isFieldFilled(field: ProviderField, value: unknown): boolean {
  if (!field.required) return true
  if (field.type === 'switch') return true
  if (typeof value === 'string') return value.trim().length > 0
  if (typeof value === 'number') return Number.isFinite(value)
  return value != null
}

export function CreateProviderWizardView() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pid = projectId as string

  const [step, setStep] = useState<'pick' | 'configure'>('pick')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [values, setValues] = useState<Record<string, unknown>>({})

  const selected = useMemo(
    () => PROVIDERS.find((p) => p.id === selectedId) ?? null,
    [selectedId],
  )

  const grouped = useMemo(() => {
    const out: Record<ProviderType, ProviderConfig[]> = {
      email: [],
      sms: [],
      push: [],
    }
    for (const p of PROVIDERS) out[p.type].push(p)
    return out
  }, [])

  // Keep step / selected state consistent if state goes out of sync
  useEffect(() => {
    if (step === 'configure' && !selected) {
      setStep('pick')
    }
  }, [step, selected])

  const mutation = useMutation({
    mutationFn: async () => {
      if (!selected) throw new Error('No provider selected')
      const projectSdk = sdk.forProject(pid)
      return selected.submit(projectSdk, ID.unique(), values)
    },
    onSuccess: async (provider) => {
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', pid],
      })
      toast.success(`${t('Provider')} ${selected?.name} ${t('created successfully')}`)
      navigate({
        to: '/projects/$projectId/messaging/providers/$providerId',
        params: { projectId: pid, providerId: provider.$id },
      })
    },
    onError: (e: Error) =>
      toast.error(getErrorMessage(e) || t('Could not create provider')),
  })

  const handlePickProvider = (p: ProviderConfig) => {
    setSelectedId(p.id)
    setValues(defaultsForProvider(p))
    setStep('configure')
  }

  const handleBackToPick = () => {
    setStep('pick')
  }

  const fallbackPath = `/projects/${pid}/messaging/providers`

  const canSubmit =
    selected != null &&
    !mutation.isPending &&
    selected.fields.every((f) => isFieldFilled(f, values[f.key]))

  const title = selected && step === 'configure'
    ? `${t('Configure')} ${selected.name}`
    : t('Add provider')

  const footer =
    step === 'configure' && selected ? (
      <div className="flex w-full justify-end">
        <Button
          type="button"
          disabled={!canSubmit}
          onClick={() => mutation.mutate()}
        >
          {t('Create provider')}
        </Button>
      </div>
    ) : undefined

  return (
    <WizardLayout
      title={title}
      fullscreen
      useSidebar={false}
      maxWidth={step === 'configure' ? 'max-w-2xl' : 'max-w-4xl'}
      fallbackPath={fallbackPath}
      showBackButton={step === 'configure'}
      onBack={handleBackToPick}
      footer={footer}
      footerAlign="right"
    >
      {step === 'pick' && (
        <ProviderPicker grouped={grouped} onPick={handlePickProvider} />
      )}
      {step === 'configure' && selected && (
        <ProviderForm
          provider={selected}
          values={values}
          onChange={(key, value) =>
            setValues((prev) => ({ ...prev, [key]: value }))
          }
        />
      )}
    </WizardLayout>
  )
}

function ProviderPicker({
  grouped,
  onPick,
}: {
  grouped: Record<ProviderType, ProviderConfig[]>
  onPick: (p: ProviderConfig) => void
}) {
  const t = useT()
  return (
    <div className="space-y-10">
      {(['email', 'sms', 'push'] as ProviderType[]).map((type) => {
        const TypeIcon = TYPE_ICON[type]
        return (
          <section key={type}>
            <div className="mb-4 flex items-center gap-2">
              <TypeIcon className="h-4 w-4 text-muted-foreground" />
              <h2 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t(TYPE_LABEL[type])}
              </h2>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {grouped[type].map((p) => (
                <ProviderCard key={p.id} provider={p} onPick={onPick} />
              ))}
            </div>
          </section>
        )
      })}
    </div>
  )
}

function ProviderCard({
  provider,
  onPick,
}: {
  provider: ProviderConfig
  onPick: (p: ProviderConfig) => void
}) {
  const t = useT()
  const TypeIcon = TYPE_ICON[provider.type]
  return (
    <button
      type="button"
      onClick={() => onPick(provider)}
      className={cn(
        'group flex w-full cursor-pointer items-center gap-3 rounded-xl border border-border bg-card/50 p-4 text-start transition-all',
        'hover:border-border/80 hover:bg-card/60',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      )}
    >
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
        {provider.icon ? (
          <img
            src={`/icons/${provider.icon}`}
            alt={provider.name}
            className={cn('h-5 w-5', PUBLIC_ICON_MUTED_CLASSES)}
          />
        ) : (
          <TypeIcon className="h-5 w-5" />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[14px] font-medium text-foreground truncate">
          {provider.name}
        </p>
        <p className="text-[12px] text-muted-foreground truncate">
          {t(provider.description)}
        </p>
      </div>
      <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 group-hover:text-foreground" />
    </button>
  )
}

function ProviderForm({
  provider,
  values,
  onChange,
}: {
  provider: ProviderConfig
  values: Record<string, unknown>
  onChange: (key: string, value: unknown) => void
}) {
  return (
    <div className="w-full space-y-4">
      {provider.fields.map((field) => (
        <FieldRenderer
          key={field.key}
          field={field}
          value={values[field.key]}
          onChange={(v) => onChange(field.key, v)}
        />
      ))}
    </div>
  )
}

function FieldRenderer({
  field,
  value,
  onChange,
}: {
  field: ProviderField
  value: unknown
  onChange: (value: unknown) => void
}) {
  const t = useT()
  const id = `provider-field-${field.key}`

  if (field.type === 'switch') {
    return (
      <div className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3">
        <div className="min-w-0 space-y-0.5">
          <Label htmlFor={id}>{t(field.label)}</Label>
          {field.helper && (
            <p className="text-[12px] text-muted-foreground">{t(field.helper)}</p>
          )}
        </div>
        <Switch
          id={id}
          checked={Boolean(value)}
          onCheckedChange={(c) => onChange(c)}
        />
      </div>
    )
  }

  if (field.type === 'select') {
    const stringValue =
      typeof value === 'string' ? value : String(field.defaultValue ?? '')
    return (
      <div className="space-y-2">
        <Label htmlFor={id}>
          {t(field.label)}
          {field.required && <RequiredMark />}
        </Label>
        <Select value={stringValue} onValueChange={(v) => onChange(v)}>
          <SelectTrigger id={id}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(field.options ?? []).map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {t(o.label)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {field.helper && (
          <p className="text-[12px] text-muted-foreground">{t(field.helper)}</p>
        )}
      </div>
    )
  }

  if (field.type === 'textarea' || field.type === 'json') {
    return (
      <div className="space-y-2">
        <Label htmlFor={id}>
          {t(field.label)}
          {field.required && <RequiredMark />}
        </Label>
        <Textarea
          id={id}
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder={field.placeholder}
          className="min-h-[140px] font-mono text-[12px]"
        />
        {field.helper && (
          <p className="text-[12px] text-muted-foreground">{t(field.helper)}</p>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>
        {t(field.label)}
        {field.required && <RequiredMark />}
      </Label>
      <Input
        id={id}
        type={
          field.type === 'password'
            ? 'password'
            : field.type === 'email'
              ? 'email'
              : field.type === 'number'
                ? 'number'
                : 'text'
        }
        value={typeof value === 'string' || typeof value === 'number' ? value : ''}
        onChange={(e) => onChange(e.target.value)}
        placeholder={field.placeholder}
      />
      {field.helper && (
        <p className="text-[12px] text-muted-foreground">{t(field.helper)}</p>
      )}
    </div>
  )
}

function RequiredMark() {
  return <span className="ms-0.5 text-destructive">*</span>
}
