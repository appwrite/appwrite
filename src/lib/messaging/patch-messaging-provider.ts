import { Messaging, SmtpEncryption } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'

function shallowMerge(
  ...objects: Array<Record<string, unknown> | undefined>
): Record<string, unknown> {
  return Object.assign({}, ...objects.filter(Boolean))
}

function pickDefined<T extends Record<string, unknown>>(obj: T): T {
  const out = {} as T
  for (const [k, v] of Object.entries(obj)) {
    if (v !== undefined) (out as Record<string, unknown>)[k] = v
  }
  return out
}

function optStr(v: unknown): string | undefined {
  if (v == null || v === '') return undefined
  return String(v)
}

function optBool(v: unknown): boolean | undefined {
  if (v === undefined) return undefined
  if (typeof v === 'boolean') return v
  return Boolean(v)
}

function optNum(v: unknown): number | undefined {
  if (v == null || v === '') return undefined
  const n = Number(v)
  return Number.isNaN(n) ? undefined : n
}

function parseServiceAccountJson(raw: unknown): object | undefined {
  if (raw == null || raw === '') return undefined
  if (typeof raw === 'object' && !Array.isArray(raw)) return raw as object
  if (typeof raw === 'string') {
    try {
      return JSON.parse(raw) as object
    } catch {
      return undefined
    }
  }
  return undefined
}

function asSmtpEncryption(value: unknown): SmtpEncryption | undefined {
  if (value === SmtpEncryption.None || value === SmtpEncryption.Ssl || value === SmtpEncryption.Tls) {
    return value
  }
  if (value === 'none') return SmtpEncryption.None
  if (value === 'ssl') return SmtpEncryption.Ssl
  if (value === 'tls') return SmtpEncryption.Tls
  return undefined
}

export type PatchMessagingProviderInput = {
  providerId: string
  name?: string
  enabled?: boolean
  credentials?: Record<string, unknown>
  options?: Record<string, unknown>
}

/**
 * Updates a messaging provider using the correct Console SDK method per
 * `provider.provider` (smtp, mailgun, twilio, fcm, …). There is no generic
 * `messaging.updateProvider` on the project SDK.
 */
export function patchMessagingProvider(
  messaging: Messaging,
  provider: Models.Provider,
  input: PatchMessagingProviderInput,
): Promise<Models.Provider> {
  const { providerId, name, enabled } = input
  const identity = pickDefined({ providerId, name, enabled })

  const hasConfig =
    input.credentials !== undefined || input.options !== undefined

  if (!hasConfig) {
    return dispatchIdentityOnly(messaging, provider, identity)
  }

  const merged = shallowMerge(
    provider.credentials as Record<string, unknown>,
    (provider.options ?? {}) as Record<string, unknown>,
    input.credentials,
    input.options,
  )

  return dispatchWithMerged(messaging, provider, {
    ...identity,
    merged,
  })
}

function dispatchIdentityOnly(
  messaging: Messaging,
  provider: Models.Provider,
  identity: { providerId: string; name?: string; enabled?: boolean },
): Promise<Models.Provider> {
  const key = provider.provider.toLowerCase()

  switch (key) {
    case 'smtp':
      return messaging.updateSMTPProvider(identity)
    case 'mailgun':
      return messaging.updateMailgunProvider(identity)
    case 'sendgrid':
      return messaging.updateSendgridProvider(identity)
    case 'resend':
      return messaging.updateResendProvider(identity)
    case 'twilio':
      return messaging.updateTwilioProvider(identity)
    case 'vonage':
      return messaging.updateVonageProvider(identity)
    case 'msg91':
      return messaging.updateMsg91Provider(identity)
    case 'telesign':
      return messaging.updateTelesignProvider(identity)
    case 'textmagic':
      return messaging.updateTextmagicProvider(identity)
    case 'fcm':
      return messaging.updateFCMProvider(identity)
    case 'apns':
      return messaging.updateAPNSProvider(identity)
    default:
      throw new Error(
        `Unsupported messaging provider for update: ${provider.provider}`,
      )
  }
}

function dispatchWithMerged(
  messaging: Messaging,
  provider: Models.Provider,
  args: {
    providerId: string
    name?: string
    enabled?: boolean
    merged: Record<string, unknown>
  },
): Promise<Models.Provider> {
  const { providerId, name, enabled, merged } = args
  const key = provider.provider.toLowerCase()

  switch (key) {
    case 'smtp': {
      const enc = asSmtpEncryption(merged.encryption)
      return messaging.updateSMTPProvider({
        providerId,
        name,
        enabled,
        host: optStr(merged.host),
        port: optNum(merged.port),
        username: optStr(merged.username),
        password: optStr(merged.password),
        encryption: enc,
        autoTLS: optBool(merged.autoTLS),
        mailer: optStr(merged.mailer),
        fromName: optStr(merged.fromName),
        fromEmail: optStr(merged.fromEmail),
        replyToName: optStr(merged.replyToName),
        replyToEmail: optStr(merged.replyToEmail),
      })
    }
    case 'mailgun':
      return messaging.updateMailgunProvider({
        providerId,
        name,
        enabled,
        apiKey: optStr(merged.apiKey),
        domain: optStr(merged.domain),
        isEuRegion: optBool(merged.isEuRegion),
        fromName: optStr(merged.fromName),
        fromEmail: optStr(merged.fromEmail),
        replyToName: optStr(merged.replyToName),
        replyToEmail: optStr(merged.replyToEmail),
      })
    case 'sendgrid':
      return messaging.updateSendgridProvider({
        providerId,
        name,
        enabled,
        apiKey: optStr(merged.apiKey),
        fromName: optStr(merged.fromName),
        fromEmail: optStr(merged.fromEmail),
        replyToName: optStr(merged.replyToName),
        replyToEmail: optStr(merged.replyToEmail),
      })
    case 'resend':
      return messaging.updateResendProvider({
        providerId,
        name,
        enabled,
        apiKey: optStr(merged.apiKey),
        fromName: optStr(merged.fromName),
        fromEmail: optStr(merged.fromEmail),
        replyToName: optStr(merged.replyToName),
        replyToEmail: optStr(merged.replyToEmail),
      })
    case 'twilio':
      return messaging.updateTwilioProvider({
        providerId,
        name,
        enabled,
        accountSid: optStr(merged.accountSid),
        authToken: optStr(merged.authToken),
        from: optStr(merged.from),
      })
    case 'vonage':
      return messaging.updateVonageProvider({
        providerId,
        name,
        enabled,
        apiKey: optStr(merged.apiKey),
        apiSecret: optStr(merged.apiSecret),
        from: optStr(merged.from),
      })
    case 'msg91':
      return messaging.updateMsg91Provider({
        providerId,
        name,
        enabled,
        templateId: optStr(merged.templateId),
        senderId: optStr(merged.senderId),
        authKey: optStr(merged.authKey),
      })
    case 'telesign':
      return messaging.updateTelesignProvider({
        providerId,
        name,
        enabled,
        customerId: optStr(merged.customerId),
        apiKey: optStr(merged.apiKey),
        from: optStr(merged.from),
      })
    case 'textmagic':
      return messaging.updateTextmagicProvider({
        providerId,
        name,
        enabled,
        username: optStr(merged.username),
        apiKey: optStr(merged.apiKey),
        from: optStr(merged.from),
      })
    case 'fcm': {
      const parsed = parseServiceAccountJson(merged.serviceAccountJSON)
      return messaging.updateFCMProvider({
        providerId,
        name,
        enabled,
        serviceAccountJSON: parsed,
      })
    }
    case 'apns':
      return messaging.updateAPNSProvider({
        providerId,
        name,
        enabled,
        authKey: optStr(merged.authKey),
        authKeyId: optStr(merged.authKeyId),
        teamId: optStr(merged.teamId),
        bundleId: optStr(merged.bundleId),
        sandbox: optBool(merged.sandbox),
      })
    default:
      throw new Error(
        `Unsupported messaging provider for update: ${provider.provider}`,
      )
  }
}
