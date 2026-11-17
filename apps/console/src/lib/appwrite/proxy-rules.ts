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
}
