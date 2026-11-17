/**
 * The stored DNS checks of a proxy rule (`rule.records`): the pinned Console
 * SDK has no `Models.RuleRecord` yet. Delete this for the SDK model once the
 * pin carries it.
 */

export interface ProxyRuleRecord {
  /** `CNAME`, `A`, `AAAA` or `CAA` */
  type: string
  /** The hostname that was looked up */
  name: string
  /** The value Appwrite expects */
  value: string
  /** The values DNS returned for this type */
  found: string[]
  status: 'verified' | 'missing' | 'mismatch' | 'failed'
  /** Why the check did not pass, empty when verified */
  message: string
  /** CAA must pass; CNAME, A and AAAA are alternatives, any one is enough */
  required: boolean
  /** When DNS was checked, missing on rows stored before it was recorded */
  checkedAt?: string
}
