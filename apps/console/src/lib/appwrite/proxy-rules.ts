/**
 * The stored DNS checks of a proxy rule (`rule.records`): the pinned Console
 * SDK has no `Models.RuleRecord` yet. Delete this for the SDK model once the
 * pin carries it.
 */

export interface ProxyRuleRecord {
  $id: string
  /** When this record type was first checked for the rule */
  $createdAt: string
  /** When DNS was last checked for this record */
  $updatedAt: string
  /** `CNAME`, `A`, `AAAA` or `CAA` */
  type: string
  /** The hostname that was looked up */
  name: string
  /** The value Appwrite expects */
  expected: string
  /** The values DNS returned for this type */
  current: string[]
  status: 'verified' | 'missing' | 'mismatch' | 'failed'
  /** Why the check did not pass, empty when verified */
  message: string
  /** CAA must pass; CNAME, A and AAAA are alternatives, any one is enough */
  required: boolean
}
