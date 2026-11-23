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
  mysqlDatabaseCredentialsQueryOptions,
  mysqlDatabasePoolerQueryOptions,
  mysqlDatabaseQueryOptions,
} from '@/lib/react-query/hooks'
import { MysqlConnectDialog } from './MysqlConnectDialog'

type MysqlConnectDialogContextValue = {
  openConnect: () => void
}

const MysqlConnectDialogContext =
  createContext<MysqlConnectDialogContextValue | null>(null)

export function useMysqlConnectDialog() {
  return useContext(MysqlConnectDialogContext)
}

export function MysqlConnectDialogProvider({
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
        mysqlDatabaseQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlDatabaseCredentialsQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlDatabasePoolerQueryOptions(projectId, databaseId),
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
    <MysqlConnectDialogContext.Provider value={value}>
      {children}
      <MysqlConnectDialog
        open={open}
        onOpenChange={setOpen}
        projectId={projectId}
        databaseId={databaseId}
      />
    </MysqlConnectDialogContext.Provider>
  )
}
