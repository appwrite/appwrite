/**
 * Project-specific organization roles.
 *
 * Cloud stores per-project access on the *organization* membership, encoding it
 * into the role string itself: `project-{projectId}-{roleName}`. There is no
 * separate per-project membership resource — a member with project-specific
 * access simply has one encoded role per project they can reach.
 *
 * The backend unwraps these in `organizations.getScopes`, and its behaviour is
 * the reason every project-context caller must pass a project id:
 *
 * - **with** `projectId` — `project-{projectId}-{role}` resolves to `{role}`,
 *   and roles belonging to other projects are dropped.
 * - **without** `projectId` — *every* `project-*` role collapses to `analyst`,
 *   i.e. read-only. This is deliberate (it is what the org-wide view should
 *   show), but it means a caller that forgets the project id silently sees a
 *   member as read-only on a project where they are meant to be a developer.
 *
 * `billing` is intentionally absent from {@link PROJECT_ROLE_VALUES}: billing is
 * an organization-level concern, so it cannot be granted per project.
 */

/** Roles that can be granted on a single project. */
export const PROJECT_ROLE_VALUES = [
  'owner',
  'developer',
  'editor',
  'analyst',
] as const

export type ProjectRoleName = (typeof PROJECT_ROLE_VALUES)[number]

/** One row of "member X has role Y on project Z". */
export type ProjectAccessEntry = {
  projectId: string
  roleName: ProjectRoleName
}

const PROJECT_ROLE_PREFIX = 'project-'

const PROJECT_ROLE_NAMES = new Set<string>(PROJECT_ROLE_VALUES)

/**
 * Whether a membership role string encodes access to a single project.
 *
 * Parsing splits on the *last* dash because project ids may themselves contain
 * dashes; only the trailing segment is the role name.
 */
export function isProjectSpecificRole(role: string): boolean {
  if (!role.startsWith(PROJECT_ROLE_PREFIX)) return false
  const lastDash = role.lastIndexOf('-')
  // Strictly greater: equal would mean an empty project id, which parses to
  // nothing and must not be reported as a valid project role.
  if (lastDash <= PROJECT_ROLE_PREFIX.length) return false
  return PROJECT_ROLE_NAMES.has(role.slice(lastDash + 1))
}

/** Split `project-{projectId}-{roleName}` back into its parts. */
export function parseProjectRole(
  role: string,
): { projectId: string; roleName: ProjectRoleName } | null {
  if (!isProjectSpecificRole(role)) return null
  const withoutPrefix = role.slice(PROJECT_ROLE_PREFIX.length)
  const lastDash = withoutPrefix.lastIndexOf('-')
  if (lastDash === -1) return null
  const projectId = withoutPrefix.slice(0, lastDash)
  if (!projectId) return null
  return {
    projectId,
    roleName: withoutPrefix.slice(lastDash + 1) as ProjectRoleName,
  }
}

/** Encode access to a single project as a membership role. */
export function buildProjectRole(
  projectId: string,
  roleName: ProjectRoleName | string,
): string {
  return `${PROJECT_ROLE_PREFIX}${projectId}-${roleName}`
}

/** Whether this membership grants access per project rather than org-wide. */
export function hasProjectSpecificRoles(
  roles: string[] | null | undefined,
): boolean {
  return (roles ?? []).some(isProjectSpecificRole)
}

/** Every project this membership grants access to, in stored order. */
export function parseProjectAccess(
  roles: string[] | null | undefined,
): ProjectAccessEntry[] {
  return (roles ?? [])
    .map(parseProjectRole)
    .filter((entry): entry is ProjectAccessEntry => entry !== null)
}

/** Project ids a membership can reach, for scoping project lists. */
export function projectIdsFromRoles(
  roles: string[] | null | undefined,
): string[] {
  return parseProjectAccess(roles).map((entry) => entry.projectId)
}
