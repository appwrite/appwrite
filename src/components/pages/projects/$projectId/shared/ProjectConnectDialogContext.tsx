import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { ConnectProject, type ConnectProjectTab } from './ConnectProject'

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
  const [open, setOpen] = useState(false)
  const [initialConnectTab, setInitialConnectTab] =
    useState<ConnectProjectTab>('app')

  const openConnect = useCallback((tab?: ConnectProjectTab) => {
    setInitialConnectTab(tab ?? 'app')
    setOpen(true)
  }, [])

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
