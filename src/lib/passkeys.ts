import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import { parsePasskeysFlag } from '@/lib/user-prefs-keys'

/** On Cloud, passkey settings show only to console users whose prefs carry the rollout flag. */
export function canUsePasskeys(
  features: ConsoleProfileFeatures,
  accountPrefs: Record<string, unknown> | null | undefined,
): boolean {
  return !features.passkeysFlag || parsePasskeysFlag(accountPrefs)
}
