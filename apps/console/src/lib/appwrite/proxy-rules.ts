/**
 * The per-region results of a proxy rule's DNS checks: the pinned Console SDK's
 * `Models.ProxyRuleRecord` has no `regions` yet. Delete this for the SDK model
 * once the pin carries it.
 */

import type { Models } from '@appwrite.io/console'

/**
 * What one checking region saw for a record. The record is verified only
 * when every region sees it.
 */
export interface ProxyRuleRecordRegion {
  /** Region ID, e.g. `fra` */
  id: string
  /** Display name, e.g. `Frankfurt` */
  name: string
  status: 'verified' | 'missing' | 'mismatch' | 'failed'
  /** The values this region's resolver returned */
  current: string[]
}

export type ProxyRuleRecord = Models.ProxyRuleRecord & {
  /** Per-region results. Older servers do not send them. */
  regions?: ProxyRuleRecordRegion[]
}
