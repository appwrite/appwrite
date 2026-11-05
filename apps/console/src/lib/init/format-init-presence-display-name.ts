const EMAIL_LIKE_NAME_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function capitalizeFirst(value: string): string {
  if (!value) return value
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function firstTokenFromHandle(value: string): string {
  return value.split(/[\s._-]+/).find(Boolean) ?? value
}

/** Presence list names: keep full display names; never show raw emails. */
export function formatInitPresenceDisplayName(name: string): string {
  const trimmed = name.trim()
  if (!trimmed) return trimmed

  if (EMAIL_LIKE_NAME_PATTERN.test(trimmed)) {
    const localPart = trimmed.split('@')[0] ?? trimmed
    return capitalizeFirst(firstTokenFromHandle(localPart))
  }

  return trimmed
}
