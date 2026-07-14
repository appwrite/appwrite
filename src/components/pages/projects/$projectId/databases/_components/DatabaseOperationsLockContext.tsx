import {
  createContext,
  useContext,
  useMemo,
  type ReactNode,
} from 'react'
import { useParams } from '@tanstack/react-router'
import {
  canCreateDatabase,
  canCreateRow,
  canShowTableSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getDedicatedDatabaseOperationsLock,
  getDedicatedDatabaseOperationsLockTooltipKey,
  type DedicatedDatabaseOperationsLockState,
} from '@/lib/databases/dedicated-database-write-lock'
import { useProjectDedicatedDatabases } from '@/lib/react-query/hooks'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useT } from '@/lib/i18n/translate'

type DatabaseOperationsLockContextValue = {
  operationsLock: DedicatedDatabaseOperationsLockState
  isOperationsLocked: boolean
  operationsLockTooltip: string | undefined
  /** @deprecated Use {@link isOperationsLocked}. */
  isWriteLocked: boolean
  /** @deprecated Use {@link operationsLockTooltip}. */
  writeLockTooltip: string | undefined
  /** @deprecated Use {@link operationsLock}. */
  writeLock: DedicatedDatabaseOperationsLockState
}

const DatabaseOperationsLockContext =
  createContext<DatabaseOperationsLockContextValue | null>(null)

const unlockedValue = {
  operationsLock: { locked: false, reason: null },
  isOperationsLocked: false,
  operationsLockTooltip: undefined,
  isWriteLocked: false,
  writeLockTooltip: undefined,
  writeLock: { locked: false, reason: null },
} satisfies DatabaseOperationsLockContextValue

export function DatabaseOperationsLockProvider({
  projectId: projectIdProp,
  databaseId: databaseIdProp,
  status: statusProp,
  children,
}: {
  projectId?: string | null
  databaseId?: string | null
  status?: string | null
  children: ReactNode
}) {
  const t = useT()
  const params = useParams({ strict: false }) as {
    projectId?: string
    databaseId?: string
  }
  const projectId = projectIdProp ?? params.projectId ?? null
  const databaseId = databaseIdProp ?? params.databaseId ?? null
  // When status is omitted (product `$dbKind` layout), resolve from the dedicated
  // list only. Do not call `usePostgresDatabase`: that fires `postgresql.get` for
  // tablesdb/documentsdb/vectorsdb IDs. Postgres shell always passes `status`.
  const resolveStatus = statusProp == null

  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    resolveStatus ? projectId : null,
  )

  const resolvedStatus = useMemo(() => {
    if (statusProp != null) return statusProp
    if (!databaseId) return undefined
    return dedicatedDatabases.find((db) => db.$id === databaseId)?.status
  }, [databaseId, dedicatedDatabases, statusProp])

  const value = useMemo(() => {
    const operationsLock = getDedicatedDatabaseOperationsLock(resolvedStatus)
    const operationsLockTooltip = operationsLock.reason
      ? t(getDedicatedDatabaseOperationsLockTooltipKey(operationsLock.reason))
      : undefined

    return {
      operationsLock,
      isOperationsLocked: operationsLock.locked,
      operationsLockTooltip,
      writeLock: operationsLock,
      isWriteLocked: operationsLock.locked,
      writeLockTooltip: operationsLockTooltip,
    }
  }, [resolvedStatus, t])

  return (
    <DatabaseOperationsLockContext.Provider value={value}>
      {children}
    </DatabaseOperationsLockContext.Provider>
  )
}

export function useDatabaseOperationsLock() {
  const context = useContext(DatabaseOperationsLockContext)
  if (!context) return unlockedValue
  return context
}

type DatabaseOperationsAccessOptions = {
  permissionCanWrite: boolean
  permissionDeniedTooltip: string
  isPending?: boolean
}

export function useDatabaseOperationsAccess({
  permissionCanWrite,
  permissionDeniedTooltip,
  isPending = false,
}: DatabaseOperationsAccessOptions) {
  const { isOperationsLocked, operationsLockTooltip } =
    useDatabaseOperationsLock()
  const canWrite = permissionCanWrite && !isOperationsLocked
  const writeDisabled = !canWrite || isPending
  const writeTooltip = !permissionCanWrite
    ? permissionDeniedTooltip
    : isOperationsLocked
      ? operationsLockTooltip
      : undefined

  return {
    canWrite,
    writeDisabled,
    writeTooltip,
    isOperationsLocked,
    isWriteLocked: isOperationsLocked,
  }
}

export function useDatabaseTableOperationsAccess(
  options?: {
    isPending?: boolean
    permissionDeniedTooltip?: string
  },
) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId?: string }
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  return useDatabaseOperationsAccess({
    permissionCanWrite: canShowTableSecuritySettings(access, features),
    permissionDeniedTooltip:
      options?.permissionDeniedTooltip ??
      t("You don't have permission to modify table structure."),
    isPending: options?.isPending,
  })
}

export function useDatabaseAdminOperationsAccess(
  options?: {
    isPending?: boolean
    permissionDeniedTooltip?: string
  },
) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId?: string }
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  return useDatabaseOperationsAccess({
    permissionCanWrite: canCreateDatabase(access, features),
    permissionDeniedTooltip:
      options?.permissionDeniedTooltip ??
      t("You don't have permission to change database settings."),
    isPending: options?.isPending,
  })
}

export function useDatabaseRowOperationsAccess(
  options?: {
    isPending?: boolean
    permissionDeniedTooltip?: string
  },
) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId?: string }
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  return useDatabaseOperationsAccess({
    permissionCanWrite: canCreateRow(access, features),
    permissionDeniedTooltip:
      options?.permissionDeniedTooltip ??
      t("You don't have permission to perform this action."),
    isPending: options?.isPending,
  })
}
