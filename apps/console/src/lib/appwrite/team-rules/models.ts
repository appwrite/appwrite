import type { Models } from '@appwrite.io/console'

/**
 * A proxy rule with the `team` protocol: an email domain an organization claimed.
 * People who sign up with a verified email on a verified domain join the
 * organization with `roles`.
 */
export type TeamRule = Omit<Models.ProxyRule, 'status'> & {
  /** `unverified` until the TXT record is found, then `verified`. */
  status: 'unverified' | 'verified'
  roles: string[]
}

export type TeamRuleList = {
  total: number
  rules: TeamRule[]
}
