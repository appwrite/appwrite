import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useQueryClient } from '@tanstack/react-query'
import {
  postgresDatabaseCredentialsQueryOptions,
  postgresDatabasePoolerQueryOptions,
  postgresDatabaseQueryOptions,
} from '@/lib/react-query/hooks'
import { PostgresConnectDialog } from './PostgresConnectDialog'

type PostgresConnectDialogContextValue = {
  openConnect: () => void
}

const PostgresConnectDialogContext =
  createContext<PostgresConnectDialogContextValue | null>(null)

export function usePostgresConnectDialog() {
  return useContext(PostgresConnectDialogContext)
}

export function PostgresConnectDialogProvider({
  projectId,
  databaseId,
  children,
}: {
  projectId: string
  databaseId: string
  children: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()

  const openConnect = useCallback(() => {
    setOpen(true)
    void Promise.all([
      queryClient.ensureQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresDatabaseCredentialsQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresDatabasePoolerQueryOptions(projectId, databaseId),
      ),
    ])
  }, [databaseId, projectId, queryClient])

  const value = useMemo(
    () => ({
      openConnect,
    }),
    [openConnect],
  )

  return (
    <PostgresConnectDialogContext.Provider value={value}>
      {children}
      <PostgresConnectDialog
        open={open}
        onOpenChange={setOpen}
        projectId={projectId}
        databaseId={databaseId}
      />
    </PostgresConnectDialogContext.Provider>
  )
}
