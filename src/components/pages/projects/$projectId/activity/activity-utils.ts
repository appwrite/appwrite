/**
 * Shared helpers for the project Activity view and detail drawer.
 */

export function isRegularUserType(userType: string): boolean {
  const normalized = userType.toLowerCase()
  /** `user` is the supported actor; `users` may appear on older audit rows. */
  return normalized === 'user' || normalized === 'users'
}

/**
 * Whether this actor has a real human email worth surfacing as the secondary
 * line under their name. End-users do (their auth email); admins do (their
 * console account email). API keys and system actors don't — their `userEmail`
 * is often a synthetic service address, so we fall back to the actor id for those.
 */
export function hasHumanEmail(userType: string): boolean {
  return isRegularUserType(userType) || userType.toLowerCase() === 'admin'
}

/**
 * Label + color used in the dedicated "Type" column so each row's actor type
 * (end-user, admin, API key, system) is identifiable at a glance.
 */
export function userTypeBadge(userType: string): { label: string; tone: string } {
  const normalized = userType.toLowerCase()

  if (isRegularUserType(userType)) {
    return {
      label: 'User',
      tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    }
  }
  if (normalized === 'admin') {
    return {
      label: 'Admin',
      tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    }
  }
  if (normalized === 'guest') {
    return {
      label: 'Guest',
      tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    }
  }
  if (normalized === 'keyproject') {
    return {
      label: 'Project key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyaccount') {
    return {
      label: 'Account key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyorganization') {
    return {
      label: 'Org key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized.startsWith('key')) {
    return {
      label: 'API key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  return {
    label: 'System',
    tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  }
}
