const CONSOLE_SUFFIX = 'Appwrite'

/**
 * Builds a consistent document title for console pages.
 * Pattern: [Leading resource name] | [Service name] | Appwrite.
 * - Inside a resource (user, database, bucket, etc.): resource name first, then service (Auth, Databases, Storage, etc.).
 * - List pages: service only → "Databases | Appwrite".
 * Do not include tab names (Rows, Settings, etc.) - only the leading resource and service.
 *
 * @param parts - [resourceName?, serviceName] - optional resource name, then service (Databases, Auth, Storage, etc.)
 * @returns Full title string for use in route head meta
 */
export function pageTitle(...parts: string[]): string {
  const filtered = parts.filter(Boolean)
  if (filtered.length === 0) return CONSOLE_SUFFIX
  return [...filtered, CONSOLE_SUFFIX].join(' | ')
}
