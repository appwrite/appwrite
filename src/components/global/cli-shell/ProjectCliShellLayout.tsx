import type { ReactNode } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { KeyboardShortcutsProvider } from '@/components/global/providers/KeyboardShortcuts'
import { ProjectConnectDialogProvider } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { CsvExportBox, CsvImportBox } from '@/components/global/csv-migrations'
import { GlobalUploadProgress } from '@/components/global/shared/GlobalUploadProgress'
import { canShowProjectTerminal } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { CliShellProvider } from './CliShellProvider'
import { ProjectCliShell } from './ProjectCliShell'

type ProjectCliShellLayoutProps = {
  projectId: string
  sidebar: {
    projectId: string
    activeSection: string
    mobileOpen: boolean
    onMobileClose: () => void
    onMenuClick: () => void
  }
  headerBanner?: ReactNode
  fixedLayout?: boolean
  children: ReactNode
}

export function ProjectCliShellLayout({
  projectId,
  sidebar,
  headerBanner,
  fixedLayout,
  children,
}: ProjectCliShellLayoutProps) {
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showTerminal = canShowProjectTerminal(access, features)

  // Connect provider wraps KeyboardShortcuts so Command Center can open Connect → MCP.
  const layout = (
    <ProjectConnectDialogProvider projectId={projectId}>
      <KeyboardShortcutsProvider projectId={projectId}>
        <ConsoleLayout
          sidebar={sidebar}
          header={{ projectId }}
          headerBanner={headerBanner}
          showFooter={false}
          fixedLayout={fixedLayout}
          bottomPanel={showTerminal ? <ProjectCliShell /> : undefined}
        >
          {children}
        </ConsoleLayout>
        <div className="fixed bottom-4 end-4 z-50 flex flex-col gap-2 max-w-sm w-full">
          <GlobalUploadProgress embedded />
          <CsvImportBox projectId={projectId} />
          <CsvExportBox projectId={projectId} />
        </div>
      </KeyboardShortcutsProvider>
    </ProjectConnectDialogProvider>
  )

  if (!showTerminal) {
    return layout
  }

  return <CliShellProvider projectId={projectId}>{layout}</CliShellProvider>
}
