import { Outlet, useLocation } from '@tanstack/react-router'
import { Terminal } from 'lucide-react'
import { useMemo } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { GeneratorApiDocsDrawer } from '@/components/pages/generator/_components/GeneratorApiDocsDrawer'
import { GeneratorCanvasPanelToggles } from '@/components/pages/generator/_components/GeneratorCanvasPanelToggles'
import {
  GeneratorLayoutProvider,
  useGeneratorLayout,
  type GeneratorApiTab,
} from '@/components/pages/generator/GeneratorLayoutContext'
import {
  ServiceHeader,
  type Tab,
} from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { Button } from '@/components/ui/button'

const GENERATOR_TABS: Tab[] = [
  { id: 'covers', label: 'Covers', to: '/generator' },
  { id: 'diagrams', label: 'Diagrams', to: '/generator/diagrams' },
]

function GeneratorLayoutContent() {
  const location = useLocation()
  const {
    apiDocsOpen,
    setApiDocsOpen,
    coverExportData,
    diagramDocument,
    leftPanelOpen,
    rightPanelOpen,
    toggleLeftPanel,
    toggleRightPanel,
  } = useGeneratorLayout()
  const activeTab: GeneratorApiTab = location.pathname.startsWith('/generator/diagrams')
    ? 'diagrams'
    : 'covers'

  const tabs = useMemo(() => GENERATOR_TABS, [])

  return (
    <>
      <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
        <ServiceHeader
          title="Generator"
          tabs={tabs}
          activeTab={activeTab}
          titleRightContent={
            <div className="flex items-center gap-2">
              <GeneratorCanvasPanelToggles
                leftOpen={leftPanelOpen}
                rightOpen={rightPanelOpen}
                onToggleLeft={toggleLeftPanel}
                onToggleRight={toggleRightPanel}
                leftLabel="Toggle templates panel"
                rightLabel="Toggle properties panel"
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 px-2 text-[12px]"
                onClick={() => setApiDocsOpen(true)}
              >
                <Terminal className="mr-1 size-3" />
                API
              </Button>
            </div>
          }
          fullWidthBorder
          fullWidth
        />
        <div className="relative z-0 min-h-0 flex-1 overflow-hidden">
          <Outlet />
        </div>
      </div>

      <GeneratorApiDocsDrawer
        open={apiDocsOpen}
        onOpenChange={setApiDocsOpen}
        activeTab={activeTab}
        coverData={coverExportData}
        diagramDocument={diagramDocument}
      />
    </>
  )
}

export function GeneratorLayout() {
  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout fixedLayout hideHeader showFooter={false}>
        <GeneratorLayoutProvider>
          <GeneratorLayoutContent />
        </GeneratorLayoutProvider>
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
