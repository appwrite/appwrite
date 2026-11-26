import {
  createContext,
  lazy,
  Suspense,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConnectProjectTab } from '@/lib/react-query/hooks/auth'
import { DEFAULT_CONNECT_PROJECT_TAB_PREF } from '@/lib/user-prefs-keys'
import type { ConnectProjectTab } from './ConnectProject'

// The dialog carries every SDK snippet and the code highlighter, so it loads
// when it first opens and then stays mounted, so closing still animates.
const ConnectProject = lazy(() =>
  import('./ConnectProject').then((m) => ({ default: m.ConnectProject })),
)

type ProjectConnectDialogContextValue = {
  openConnect: (tab?: ConnectProjectTab) => void
  isOpen: boolean
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
  const [opened, setOpened] = useState(false)
  const [initialConnectTab, setInitialConnectTab] = useState<ConnectProjectTab>(
    DEFAULT_CONNECT_PROJECT_TAB_PREF,
  )

  const openConnect = useCallback(
    (tab?: ConnectProjectTab) => {
      const nextTab =
        tab ??
        (savedConnectTab as ConnectProjectTab) ??
        DEFAULT_CONNECT_PROJECT_TAB_PREF
      setInitialConnectTab(nextTab)
      persistConnectTab(nextTab)
      setOpened(true)
      setOpen(true)
    },
    [persistConnectTab, savedConnectTab],
  )

  const value = useMemo(
    () => ({
      openConnect,
      isOpen: open,
    }),
    [openConnect, open],
  )

  return (
    <ProjectConnectDialogContext.Provider value={value}>
      {children}
      {opened ? (
        <Suspense fallback={null}>
          <ConnectProject
            open={open}
            onOpenChange={setOpen}
            projectId={projectId}
            initialConnectTab={initialConnectTab}
          />
        </Suspense>
      ) : null}
    </ProjectConnectDialogContext.Provider>
  )
}
