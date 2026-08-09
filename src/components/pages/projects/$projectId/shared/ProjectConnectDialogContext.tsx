import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConnectProjectTab } from '@/lib/react-query/hooks'
import {
  ConnectProject,
  DEFAULT_CONNECT_PROJECT_TAB,
  type ConnectProjectTab,
} from './ConnectProject'

type ProjectConnectDialogContextValue = {
  openConnect: (tab?: ConnectProjectTab) => void
}

const ProjectConnectDialogContext =
  createContext<ProjectConnectDialogContextValue | null>(null)

export function useProjectConnectDialog() {
  return useContext(ProjectConnectDialogContext)
}

export function ProjectConnectDialogProvider({
  projectId,
  children,
}: {
  projectId: string
  children: ReactNode
}) {
  const { account } = useAuth()
  const { tab: savedConnectTab, setTab: persistConnectTab } =
    useConnectProjectTab(account)
  const [open, setOpen] = useState(false)
  const [initialConnectTab, setInitialConnectTab] =
    useState<ConnectProjectTab>(DEFAULT_CONNECT_PROJECT_TAB)

  const openConnect = useCallback(
    (tab?: ConnectProjectTab) => {
      const nextTab =
        tab ??
        (savedConnectTab as ConnectProjectTab) ??
        DEFAULT_CONNECT_PROJECT_TAB
      setInitialConnectTab(nextTab)
      persistConnectTab(nextTab)
      setOpen(true)
    },
    [persistConnectTab, savedConnectTab],
  )

  const value = useMemo(
    () => ({
      openConnect,
    }),
    [openConnect],
  )

  return (
    <ProjectConnectDialogContext.Provider value={value}>
      {children}
      <ConnectProject
        open={open}
        onOpenChange={setOpen}
        projectId={projectId}
        initialConnectTab={initialConnectTab}
      />
    </ProjectConnectDialogContext.Provider>
  )
}
