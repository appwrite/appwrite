const CONSOLE_SUFFIX = 'Appwrite'
const PAGE_TITLE_SEPARATOR = ' · '
const LEGACY_CONTEXT_PREFIXES = ['Project ID: ', 'Organization ID: ']

/** Max length for resource names in document titles and headers before ellipsis. */
export const PAGE_TITLE_MAX_LENGTH = 80

/**
 * Trims whitespace and shortens text for tab titles and headers when it exceeds
 * {@link PAGE_TITLE_MAX_LENGTH} characters.
 */
export function trimForPageTitle(
  text: string,
  maxLength: number = PAGE_TITLE_MAX_LENGTH,
): string {
  const t = text.trim()
  if (t.length <= maxLength) return t
  const sliceEnd = Math.max(1, maxLength - 1)
  return `${t.slice(0, sliceEnd)}…`
}

/**
 * Builds a consistent document title for console pages.
 * Pattern: [Leading resource name] · [Service name] · Appwrite.
 * - Inside a resource (user, database, bucket, etc.): resource name first, then service (Auth, Databases, Storage, etc.).
 * - List pages: service only → "Databases · Appwrite".
 * Do not include tab names (Rows, Settings, etc.) - only the leading resource and service.
 *
 * @param parts - [resourceName?, serviceName] - optional resource name, then service (Databases, Auth, Storage, etc.)
 * @returns Full title string for use in route head meta
 */
export function pageTitle(...parts: string[]): string {
  const filtered = parts.filter(Boolean)
  if (filtered.length === 0) return CONSOLE_SUFFIX
  return [...filtered, CONSOLE_SUFFIX].join(PAGE_TITLE_SEPARATOR)
}

type PageTitleNameContext = {
  projectName?: string
  organizationName?: string
  previousContextPart?: string
}

function splitPageTitle(title: string): string[] {
  return title
    .split(PAGE_TITLE_SEPARATOR)
    .map((part) => part.trim())
    .filter(
      (part) =>
        part &&
        !LEGACY_CONTEXT_PREFIXES.some((prefix) => part.startsWith(prefix)),
    )
}

/**
 * Adds the active project or organization name to a document title without
 * fetching. Callers should pass names that are already present in local state.
 */
export function withPageTitleNameContext(
  title: string,
  context: PageTitleNameContext,
): string {
  const parts = splitPageTitle(title)
  const suffixIndex = parts.lastIndexOf(CONSOLE_SUFFIX)
  const appwriteConsoleIndex = parts.lastIndexOf('Appwrite Console')
  const titleSuffixIndex =
    suffixIndex >= 0
      ? suffixIndex
      : appwriteConsoleIndex >= 0
        ? appwriteConsoleIndex
        : -1
  const suffix = titleSuffixIndex >= 0 ? parts[titleSuffixIndex] : undefined

  if (context.previousContextPart && titleSuffixIndex > 0) {
    const previousIndex = titleSuffixIndex - 1
    if (parts[previousIndex] === context.previousContextPart) {
      parts.splice(previousIndex, 1)
    }
  }

  if (context.projectName) {
    const projectName = trimForPageTitle(context.projectName)
    if (titleSuffixIndex >= 0) {
      const insertIndex = parts.lastIndexOf(suffix!)
      if (parts[insertIndex - 1] !== projectName) {
        parts.splice(insertIndex, 0, projectName)
      }
      return parts.join(PAGE_TITLE_SEPARATOR)
    }

    if (parts.at(-1) !== projectName) {
      parts.push(projectName)
    }
    return parts.join(PAGE_TITLE_SEPARATOR)
  }

  if (context.organizationName) {
    const organizationName = trimForPageTitle(context.organizationName)
    const organizationIndex = parts.indexOf('Organization')
    if (organizationIndex >= 0) {
      parts[organizationIndex] = organizationName
      return parts.join(PAGE_TITLE_SEPARATOR)
    }

    if (titleSuffixIndex >= 0) {
      const insertIndex = parts.lastIndexOf(suffix!)
      if (parts[insertIndex - 1] !== organizationName) {
        parts.splice(insertIndex, 0, organizationName)
      }
      return parts.join(PAGE_TITLE_SEPARATOR)
    }

    if (parts.at(-1) !== organizationName) {
      parts.push(organizationName)
    }
  }

  return parts.join(PAGE_TITLE_SEPARATOR)
}

export function getConsoleRouteIds(pathname: string): {
  projectId?: string
  orgId?: string
} {
  const parts = pathname.split('/').filter(Boolean)
  try {
    if (parts[0] === 'projects' && parts[1]) {
      return { projectId: decodeURIComponent(parts[1]) }
    }
    if (parts[0] === 'organizations' && parts[1]) {
      return { orgId: decodeURIComponent(parts[1]) }
    }
  } catch {
    return {}
  }
  return {}
}
