/**
 * Registry of email providers offered by the SMTP quick setup card.
 *
 * Adding a provider is meant to be one entry here plus an API adapter:
 * fill in the SMTP relay settings, point at the console OAuth2 provider that
 * issues an access token for it, and implement {@link SmtpQuickSetupApi}.
 * Entries without `oauth` + `api` render as "Coming soon".
 */

import { OAuthProvider } from '@appwrite.io/console'
import type { AnalyticsActionId } from '@/lib/analytics-actions'
import {
  RESEND_CREDENTIAL_NAME_MAX_LENGTH,
  RESEND_DOMAINS_URL,
  RESEND_OAUTH_SCOPES,
  RESEND_SMTP_HOST,
  RESEND_SMTP_PORT,
  RESEND_SMTP_SECURE,
  RESEND_SMTP_USERNAME,
} from './resend'
import {
  createResendCredential,
  isResendUnauthorizedError,
  listResendDomains,
} from './resend-api'
import type { QuickSetupDomain } from './quick-setup'

export type SmtpQuickSetupProviderId = 'resend' | 'mailgun' | 'sendgrid'

/** A sending credential minted at the provider; `secret` becomes the SMTP password. */
export interface SmtpQuickSetupCredential {
  id: string
  secret: string
}

/** Provider REST calls the setup dialog needs, each taking a provider access token. */
export interface SmtpQuickSetupApi {
  listDomains(accessToken: string): Promise<QuickSetupDomain[]>
  createCredential(
    accessToken: string,
    input: { name: string; domainId?: string },
  ): Promise<SmtpQuickSetupCredential>
  /** True when the provider rejected the access token (triggers one refresh + retry). */
  isUnauthorizedError(error: unknown): boolean
}

export interface SmtpQuickSetupProvider {
  id: SmtpQuickSetupProviderId
  name: string
  /** Asset under `/public/icons`. */
  iconPath: string
  /** One line shown next to the provider name. */
  tagline: string
  /**
   * Label of the connect button. A whole sentence per provider, so languages
   * that put the brand first still read correctly.
   */
  connectLabel: string
  /** Full sentence shown when the layout has room for it. */
  description: string
  smtp: {
    host: string
    port: number
    secure: 'tls' | 'ssl'
    /** Mailgun uses `postmaster@<domain>`, so this resolves per selected domain. */
    username: (domainName: string) => string
  }
  /** Where the user manages sending domains at the provider. */
  domainsUrl: string
  credentialNameMaxLength: number
  analyticsAction: AnalyticsActionId
  /** Console OAuth2 provider that issues an access token for this provider. */
  oauth?: { provider: OAuthProvider; scopes: string[] }
  api?: SmtpQuickSetupApi
}

/** Provider entry that the flow can actually run end to end. */
export type AvailableSmtpQuickSetupProvider = SmtpQuickSetupProvider &
  Required<Pick<SmtpQuickSetupProvider, 'oauth' | 'api'>>

export const SMTP_QUICK_SETUP_PROVIDERS: readonly SmtpQuickSetupProvider[] = [
  {
    id: 'resend',
    name: 'Resend',
    iconPath: '/icons/resend.svg',
    tagline: 'Creates a sending-only API key for a verified domain.',
    connectLabel: 'Connect with Resend',
    description:
      'Connect your Resend account and Appwrite generates a sending-only API key, then fills in the SMTP settings for you. You need a verified domain in Resend.',
    smtp: {
      host: RESEND_SMTP_HOST,
      port: RESEND_SMTP_PORT,
      secure: RESEND_SMTP_SECURE,
      username: () => RESEND_SMTP_USERNAME,
    },
    domainsUrl: RESEND_DOMAINS_URL,
    credentialNameMaxLength: RESEND_CREDENTIAL_NAME_MAX_LENGTH,
    analyticsAction: 'smtp-quick-setup-resend',
    oauth: { provider: OAuthProvider.Resend, scopes: RESEND_OAUTH_SCOPES },
    api: {
      listDomains: listResendDomains,
      createCredential: createResendCredential,
      isUnauthorizedError: isResendUnauthorizedError,
    },
  },
  {
    id: 'mailgun',
    name: 'Mailgun',
    iconPath: '/icons/mailgun.svg',
    tagline: 'Creates a domain sending key for a verified domain.',
    connectLabel: 'Connect with Mailgun',
    description:
      'Connect your Mailgun account and Appwrite generates a domain sending key, then fills in the SMTP settings for you.',
    smtp: {
      host: 'smtp.mailgun.org',
      port: 587,
      secure: 'tls',
      username: (domainName) => `postmaster@${domainName}`,
    },
    domainsUrl: 'https://app.mailgun.com/mg/sending/domains',
    credentialNameMaxLength: 64,
    analyticsAction: 'smtp-quick-setup-mailgun',
    // No console OAuth2 provider for Mailgun yet, so this stays "Coming soon".
  },
  {
    id: 'sendgrid',
    name: 'SendGrid',
    iconPath: '/icons/sendgrid.svg',
    tagline: 'Creates a restricted API key with mail send access.',
    connectLabel: 'Connect with SendGrid',
    description:
      'Connect your SendGrid account and Appwrite generates a restricted API key with mail send access, then fills in the SMTP settings for you.',
    smtp: {
      host: 'smtp.sendgrid.net',
      port: 587,
      secure: 'tls',
      username: () => 'apikey',
    },
    domainsUrl: 'https://app.sendgrid.com/settings/sender_auth',
    credentialNameMaxLength: 100,
    analyticsAction: 'smtp-quick-setup-sendgrid',
    // No console OAuth2 provider for SendGrid yet, so this stays "Coming soon".
  },
]

/** True when the provider has both an OAuth2 provider and an API adapter wired. */
export function isProviderAvailable(
  provider: SmtpQuickSetupProvider,
): provider is AvailableSmtpQuickSetupProvider {
  return Boolean(provider.oauth && provider.api)
}

export function getSmtpQuickSetupProvider(
  providerId: string | null | undefined,
): SmtpQuickSetupProvider | undefined {
  if (!providerId) return undefined
  return SMTP_QUICK_SETUP_PROVIDERS.find(
    (provider) => provider.id === providerId,
  )
}

/** Provider ready to run the flow, or `undefined` (unknown id or coming soon). */
export function getAvailableSmtpQuickSetupProvider(
  providerId: string | null | undefined,
): AvailableSmtpQuickSetupProvider | undefined {
  const provider = getSmtpQuickSetupProvider(providerId)
  return provider && isProviderAvailable(provider) ? provider : undefined
}
