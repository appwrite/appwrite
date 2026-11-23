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
  deleteResendCredential,
  isResendUnauthorizedError,
  listResendDomains,
} from './resend-api'
import {
  BIRD_CREDENTIAL_NAME_MAX_LENGTH,
  BIRD_DOMAINS_URL,
  BIRD_OAUTH_SCOPES,
  BIRD_SENDERS_URL,
  BIRD_SMTP_PORT,
  BIRD_SMTP_SECURE,
  BIRD_SMTP_USERNAME,
  birdSmtpHost,
} from './bird'
import {
  createBirdCredential,
  createBirdSmsCredential,
  deleteBirdCredential,
  isBirdUnauthorizedError,
  listBirdDomains,
  listBirdSmsSenders,
} from './bird-api'
import type { QuickSetupDomain } from './quick-setup'

export type SmtpQuickSetupProviderId = 'resend' | 'bird' | 'mailgun'

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
  /** Best-effort cleanup when a minted credential could not be saved. */
  deleteCredential(accessToken: string, credentialId: string): Promise<void>
  /** True when the provider rejected the access token (triggers one refresh + retry). */
  isUnauthorizedError(error: unknown): boolean
}

/**
 * SMS calls for the messaging wizard's SMS one-click setup, on providers that
 * also send SMS. Senders reuse the domain shape so the same picker lists them.
 */
export interface SmsQuickSetupApi {
  listSenders(accessToken: string): Promise<QuickSetupDomain[]>
  createCredential(
    accessToken: string,
    input: { name: string },
  ): Promise<SmtpQuickSetupCredential>
  /** Where the user manages SMS senders at the provider. */
  sendersUrl: string
}

export interface SmtpQuickSetupProvider {
  id: SmtpQuickSetupProviderId
  name: string
  /** Asset under `/public/icons`. */
  iconPath: string
  /** One line shown next to the provider name. */
  tagline: string
  /**
   * Connect button label and wizard titles. Whole sentences per provider
   * rather than fragments joined with the brand, so languages that order
   * words differently still read correctly.
   */
  connectLabel: string
  setupTitle: string
  disconnectTitle: string
  /** What the minted credential can do, shown next to the domain picker. */
  credentialDescription: string
  /** Relay settings, resolved for the selected sending domain. */
  smtp: {
    /** Bird runs one relay per region, and each domain lives in one. */
    host: (domain: QuickSetupDomain) => string
    port: number
    secure: 'tls' | 'ssl'
    /** Mailgun uses `postmaster@<domain>`. */
    username: (domain: QuickSetupDomain) => string
  }
  /** Where the user manages sending domains at the provider. */
  domainsUrl: string
  credentialNameMaxLength: number
  analyticsAction: AnalyticsActionId
  /** Console OAuth2 provider that issues an access token for this provider. */
  oauth?: { provider: OAuthProvider; scopes: string[] }
  api?: SmtpQuickSetupApi
  /** Bird also sends SMS, with a key minted by the same authorization. */
  sms?: SmsQuickSetupApi
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
    setupTitle: 'Set up SMTP with Resend',
    disconnectTitle: 'Disconnect Resend',
    credentialDescription:
      'The credential is restricted to this domain and can only send email.',
    smtp: {
      host: () => RESEND_SMTP_HOST,
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
      deleteCredential: deleteResendCredential,
      isUnauthorizedError: isResendUnauthorizedError,
    },
  },
  {
    id: 'bird',
    name: 'Bird',
    iconPath: '/icons/bird.svg',
    tagline: 'Creates an API key that can only send email.',
    connectLabel: 'Connect with Bird',
    setupTitle: 'Set up SMTP with Bird',
    disconnectTitle: 'Disconnect Bird',
    credentialDescription:
      'The credential can only send email. It is not tied to a domain, so pick the one your sender address uses.',
    smtp: {
      // Domains are listed with the access token, whose prefix names the region.
      host: (domain) => (domain.region ? birdSmtpHost(domain.region) : ''),
      port: BIRD_SMTP_PORT,
      secure: BIRD_SMTP_SECURE,
      username: () => BIRD_SMTP_USERNAME,
    },
    domainsUrl: BIRD_DOMAINS_URL,
    credentialNameMaxLength: BIRD_CREDENTIAL_NAME_MAX_LENGTH,
    analyticsAction: 'smtp-quick-setup-bird',
    oauth: { provider: OAuthProvider.Bird, scopes: BIRD_OAUTH_SCOPES },
    api: {
      listDomains: listBirdDomains,
      createCredential: createBirdCredential,
      deleteCredential: deleteBirdCredential,
      isUnauthorizedError: isBirdUnauthorizedError,
    },
    sms: {
      listSenders: listBirdSmsSenders,
      createCredential: createBirdSmsCredential,
      sendersUrl: BIRD_SENDERS_URL,
    },
  },
  {
    id: 'mailgun',
    name: 'Mailgun',
    iconPath: '/icons/mailgun.svg',
    tagline: 'Creates a domain sending key for a verified domain.',
    connectLabel: 'Connect with Mailgun',
    setupTitle: 'Set up SMTP with Mailgun',
    disconnectTitle: 'Disconnect Mailgun',
    credentialDescription:
      'The credential is restricted to this domain and can only send email.',
    smtp: {
      host: () => 'smtp.mailgun.org',
      port: 587,
      secure: 'tls',
      username: (domain) => `postmaster@${domain.name}`,
    },
    domainsUrl: 'https://app.mailgun.com/mg/sending/domains',
    credentialNameMaxLength: 64,
    analyticsAction: 'smtp-quick-setup-mailgun',
    // No console OAuth2 provider for Mailgun yet, so this stays "Coming soon".
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
