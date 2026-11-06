/**
 * Tracks CSV export/import and environment copy migration IDs started in the current session (per project).
 * Progress boxes only fetch and show these migrations, so we don't show old exports on reload.
 * When realtime reports an in-progress migration we add it to the session (so the alert shows)
 * unless the user has already dismissed that migration.
 */

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'

type SessionIdsState = Record<string, string[]>

const IN_PROGRESS_EXPORT_STATUSES = ['pending', 'processing']
const IN_PROGRESS_IMPORT_STATUSES = ['pending', 'uploading', 'processing']
export const IN_PROGRESS_COPY_STATUSES = ['pending', 'processing']

/** An environment's copy of its project is an Appwrite to Appwrite migration of the environment */
export function isEnvironmentCopyMigration(migration: {
  source?: string
  destination?: string
}): boolean {
  return migration.source === 'Appwrite' && migration.destination === 'Appwrite'
}

interface SessionMigrationsContextValue {
  exportIdsByProject: SessionIdsState
  importIdsByProject: SessionIdsState
  copyIdsByProject: SessionIdsState
  addExportId: (projectId: string, migrationId: string) => void
  addImportId: (projectId: string, migrationId: string) => void
  addCopyId: (projectId: string, migrationId: string) => void
  dismissExport: (projectId: string, migrationId: string) => void
  dismissImport: (projectId: string, migrationId: string) => void
  dismissCopy: (projectId: string, migrationId: string) => void
  getExportIds: (projectId: string) => string[]
  getImportIds: (projectId: string) => string[]
  getCopyIds: (projectId: string) => string[]
  getDismissedExportIds: (projectId: string) => string[]
  getDismissedImportIds: (projectId: string) => string[]
  getDismissedCopyIds: (projectId: string) => string[]
  addMigrationFromRealtime: (projectId: string, payload: unknown) => void
}

const SessionMigrationsContext =
  createContext<SessionMigrationsContextValue | null>(null)

export function SessionMigrationsProvider({
  children,
}: {
  children: ReactNode
}) {
  const [exportIdsByProject, setExportIdsByProject] = useState<SessionIdsState>(
    {},
  )
  const [importIdsByProject, setImportIdsByProject] = useState<SessionIdsState>(
    {},
  )
  const [dismissedExportByProject, setDismissedExportByProject] =
    useState<SessionIdsState>({})
  const [dismissedImportByProject, setDismissedImportByProject] =
    useState<SessionIdsState>({})
  const [copyIdsByProject, setCopyIdsByProject] = useState<SessionIdsState>({})
  const [dismissedCopyByProject, setDismissedCopyByProject] =
    useState<SessionIdsState>({})
  const dismissedExportRef = useRef<SessionIdsState>({})
  const dismissedImportRef = useRef<SessionIdsState>({})
  const dismissedCopyRef = useRef<SessionIdsState>({})
  dismissedExportRef.current = dismissedExportByProject
  dismissedImportRef.current = dismissedImportByProject
  dismissedCopyRef.current = dismissedCopyByProject

  const addExportId = useCallback((projectId: string, migrationId: string) => {
    setExportIdsByProject((prev) => {
      const list = prev[projectId] ?? []
      if (list.includes(migrationId)) return prev
      return { ...prev, [projectId]: [...list, migrationId] }
    })
  }, [])

  const addImportId = useCallback((projectId: string, migrationId: string) => {
    setImportIdsByProject((prev) => {
      const list = prev[projectId] ?? []
      if (list.includes(migrationId)) return prev
      return { ...prev, [projectId]: [...list, migrationId] }
    })
  }, [])

  const addCopyId = useCallback((projectId: string, migrationId: string) => {
    setCopyIdsByProject((prev) => {
      const list = prev[projectId] ?? []
      if (list.includes(migrationId)) return prev
      return { ...prev, [projectId]: [...list, migrationId] }
    })
  }, [])

  const dismissCopy = useCallback((projectId: string, migrationId: string) => {
    setDismissedCopyByProject((prev) => {
      const list = prev[projectId] ?? []
      if (list.includes(migrationId)) return prev
      return { ...prev, [projectId]: [...list, migrationId] }
    })
  }, [])

  const dismissExport = useCallback(
    (projectId: string, migrationId: string) => {
      setDismissedExportByProject((prev) => {
        const list = prev[projectId] ?? []
        if (list.includes(migrationId)) return prev
        return { ...prev, [projectId]: [...list, migrationId] }
      })
    },
    [],
  )

  const dismissImport = useCallback(
    (projectId: string, migrationId: string) => {
      setDismissedImportByProject((prev) => {
        const list = prev[projectId] ?? []
        if (list.includes(migrationId)) return prev
        return { ...prev, [projectId]: [...list, migrationId] }
      })
    },
    [],
  )

  const getExportIds = useCallback(
    (projectId: string) => exportIdsByProject[projectId] ?? [],
    [exportIdsByProject],
  )

  const getImportIds = useCallback(
    (projectId: string) => importIdsByProject[projectId] ?? [],
    [importIdsByProject],
  )

  const getDismissedExportIds = useCallback(
    (projectId: string) => dismissedExportByProject[projectId] ?? [],
    [dismissedExportByProject],
  )

  const getDismissedImportIds = useCallback(
    (projectId: string) => dismissedImportByProject[projectId] ?? [],
    [dismissedImportByProject],
  )

  const getCopyIds = useCallback(
    (projectId: string) => copyIdsByProject[projectId] ?? [],
    [copyIdsByProject],
  )

  const getDismissedCopyIds = useCallback(
    (projectId: string) => dismissedCopyByProject[projectId] ?? [],
    [dismissedCopyByProject],
  )

  const addMigrationFromRealtime = useCallback(
    (projectId: string, payload: unknown) => {
      const m = payload as {
        $id?: string
        destination?: string
        source?: string
        status?: string
      }
      if (!m?.$id || typeof m.status !== 'string') return
      const dismissedExport = dismissedExportRef.current[projectId] ?? []
      const dismissedImport = dismissedImportRef.current[projectId] ?? []
      if (
        m.destination === 'CSV' &&
        IN_PROGRESS_EXPORT_STATUSES.includes(m.status)
      ) {
        if (!dismissedExport.includes(m.$id)) {
          setExportIdsByProject((prev) => {
            const list = prev[projectId] ?? []
            if (list.includes(m.$id!)) return prev
            return { ...prev, [projectId]: [...list, m.$id!] }
          })
        }
      }
      if (
        m.source === 'CSV' &&
        IN_PROGRESS_IMPORT_STATUSES.includes(m.status)
      ) {
        if (!dismissedImport.includes(m.$id)) {
          setImportIdsByProject((prev) => {
            const list = prev[projectId] ?? []
            if (list.includes(m.$id!)) return prev
            return { ...prev, [projectId]: [...list, m.$id!] }
          })
        }
      }
      if (
        isEnvironmentCopyMigration(m) &&
        IN_PROGRESS_COPY_STATUSES.includes(m.status)
      ) {
        const dismissedCopy = dismissedCopyRef.current[projectId] ?? []
        if (!dismissedCopy.includes(m.$id)) {
          setCopyIdsByProject((prev) => {
            const list = prev[projectId] ?? []
            if (list.includes(m.$id!)) return prev
            return { ...prev, [projectId]: [...list, m.$id!] }
          })
        }
      }
    },
    [],
  )

  const value = useMemo<SessionMigrationsContextValue>(
    () => ({
      exportIdsByProject,
      importIdsByProject,
      copyIdsByProject,
      addExportId: (p, id) => addExportId(p, id),
      addImportId: (p, id) => addImportId(p, id),
      addCopyId,
      dismissExport,
      dismissImport,
      dismissCopy,
      getExportIds,
      getImportIds,
      getCopyIds,
      getDismissedExportIds,
      getDismissedImportIds,
      getDismissedCopyIds,
      addMigrationFromRealtime,
    }),
    [
      exportIdsByProject,
      importIdsByProject,
      copyIdsByProject,
      addExportId,
      addImportId,
      addCopyId,
      dismissExport,
      dismissImport,
      dismissCopy,
      getExportIds,
      getImportIds,
      getCopyIds,
      getDismissedExportIds,
      getDismissedImportIds,
      getDismissedCopyIds,
      addMigrationFromRealtime,
    ],
  )

  return (
    <SessionMigrationsContext.Provider value={value}>
      {children}
    </SessionMigrationsContext.Provider>
  )
}

export function useSessionMigrations(projectId: string | null | undefined) {
  const ctx = useContext(SessionMigrationsContext)
  if (!ctx) {
    return {
      sessionExportIds: [] as string[],
      sessionImportIds: [] as string[],
      sessionCopyIds: [] as string[],
      dismissedExportIds: [] as string[],
      dismissedImportIds: [] as string[],
      dismissedCopyIds: [] as string[],
      addExportId: () => {},
      addImportId: () => {},
      addCopyId: () => {},
      dismissExport: () => {},
      dismissImport: () => {},
      dismissCopy: () => {},
      addMigrationFromRealtime: () => {},
    }
  }
  return {
    sessionExportIds: projectId ? ctx.getExportIds(projectId) : [],
    sessionImportIds: projectId ? ctx.getImportIds(projectId) : [],
    sessionCopyIds: projectId ? ctx.getCopyIds(projectId) : [],
    dismissedExportIds: projectId ? ctx.getDismissedExportIds(projectId) : [],
    dismissedImportIds: projectId ? ctx.getDismissedImportIds(projectId) : [],
    dismissedCopyIds: projectId ? ctx.getDismissedCopyIds(projectId) : [],
    addExportId: ctx.addExportId,
    addImportId: ctx.addImportId,
    addCopyId: ctx.addCopyId,
    dismissExport: ctx.dismissExport,
    dismissImport: ctx.dismissImport,
    dismissCopy: ctx.dismissCopy,
    addMigrationFromRealtime: ctx.addMigrationFromRealtime,
  }
}
