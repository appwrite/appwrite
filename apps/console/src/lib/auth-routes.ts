/** True for `/projects/:id/auth/users/:userId` and user sub-routes. */
export function isAuthUserDetailPath(pathname: string): boolean {
  const pathParts = pathname.split('/').filter(Boolean)
  const authIndex = pathParts.findIndex((part) => part === 'auth')
  return (
    authIndex >= 0 &&
    pathParts[authIndex + 1] === 'users' &&
    Boolean(pathParts[authIndex + 2])
  )
}

/** True for `/projects/:id/auth/teams/:teamId` and team sub-routes. */
export function isAuthTeamDetailPath(pathname: string): boolean {
  const pathParts = pathname.split('/').filter(Boolean)
  const authIndex = pathParts.findIndex((part) => part === 'auth')
  return (
    authIndex >= 0 &&
    pathParts[authIndex + 1] === 'teams' &&
    Boolean(pathParts[authIndex + 2])
  )
}

/** True for `/projects/:id/auth` (users list index). */
export function isAuthUsersIndexPath(
  pathname: string,
  projectId: string | undefined,
): boolean {
  if (!projectId) return false
  return pathname.replace(/\/$/, '') === `/projects/${projectId}/auth`
}
