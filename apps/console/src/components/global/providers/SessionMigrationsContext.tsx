/**
 * Tracks CSV export/import migration IDs started in the current session (per project).
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

interface SessionMigrationsContextValue {
  exportIdsByProject: SessionIdsState
  importIdsByProject: SessionIdsState
  addExportId: (projectId: string, migrationId: string) => void
  addImportId: (projectId: string, migrationId: string) => void
  dismissExport: (projectId: string, migrationId: string) => void
  dismissImport: (projectId: string, migrationId: string) => void
  getExportIds: (projectId: string) => string[]
  getImportIds: (projectId: string) => string[]
  getDismissedExportIds: (projectId: string) => string[]
  getDismissedImportIds: (projectId: string) => string[]
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
  const dismissedExportRef = useRef<SessionIdsState>({})
  const dismissedImportRef = useRef<SessionIdsState>({})
  dismissedExportRef.current = dismissedExportByProject
  dismissedImportRef.current = dismissedImportByProject

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
    },
    [],
  )

  const value = useMemo<SessionMigrationsContextValue>(
    () => ({
      exportIdsByProject,
      importIdsByProject,
      addExportId: (p, id) => addExportId(p, id),
      addImportId: (p, id) => addImportId(p, id),
      dismissExport,
      dismissImport,
      getExportIds,
      getImportIds,
      getDismissedExportIds,
      getDismissedImportIds,
      addMigrationFromRealtime,
    }),
    [
      exportIdsByProject,
      importIdsByProject,
      addExportId,
      addImportId,
      dismissExport,
      dismissImport,
      getExportIds,
      getImportIds,
      getDismissedExportIds,
      getDismissedImportIds,
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
      dismissedExportIds: [] as string[],
      dismissedImportIds: [] as string[],
      addExportId: () => {},
      addImportId: () => {},
      dismissExport: () => {},
      dismissImport: () => {},
      addMigrationFromRealtime: () => {},
    }
  }
  return {
    sessionExportIds: projectId ? ctx.getExportIds(projectId) : [],
    sessionImportIds: projectId ? ctx.getImportIds(projectId) : [],
    dismissedExportIds: projectId ? ctx.getDismissedExportIds(projectId) : [],
    dismissedImportIds: projectId ? ctx.getDismissedImportIds(projectId) : [],
    addExportId: ctx.addExportId,
    addImportId: ctx.addImportId,
    dismissExport: ctx.dismissExport,
    dismissImport: ctx.dismissImport,
    addMigrationFromRealtime: ctx.addMigrationFromRealtime,
  }
}
