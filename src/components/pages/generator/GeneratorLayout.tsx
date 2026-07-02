import { Outlet, useLocation } from '@tanstack/react-router'
import { Terminal } from 'lucide-react'
import { useMemo } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { GeneratorApiDocsDrawer } from '@/components/pages/generator/_components/GeneratorApiDocsDrawer'
import { GeneratorCanvasPanelToggles } from '@/components/pages/generator/_components/GeneratorCanvasPanelToggles'
import { GeneratorDocumentMenu } from '@/components/pages/generator/_components/GeneratorDocumentMenu'
import { GeneratorEditorTitle } from '@/components/pages/generator/_components/GeneratorEditorTitle'
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
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'

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
    documentChrome,
    editorTitle,
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
      <div
        dir="ltr"
        className={cn(
          'flex h-full min-h-0 flex-1 flex-col overflow-hidden',
          FORCE_LTR_CLASS,
        )}
      >
        <ServiceHeader
          title={
            editorTitle ? (
              <GeneratorEditorTitle
                name={editorTitle.name}
                maxLength={editorTitle.maxLength}
                isSaving={editorTitle.isSaving}
                onChange={editorTitle.onChange}
              />
            ) : (
              'Generator'
            )
          }
          tabs={tabs}
          activeTab={activeTab}
          titleRightContent={
            <div className="flex items-center gap-2">
              <GeneratorDocumentMenu />
              {documentChrome?.phase === 'editor' ? (
                <GeneratorCanvasPanelToggles
                  leftOpen={leftPanelOpen}
                  rightOpen={rightPanelOpen}
                  onToggleLeft={toggleLeftPanel}
                  onToggleRight={toggleRightPanel}
                  leftLabel={documentChrome.leftPanelLabel}
                  rightLabel="Toggle properties panel"
                  showLeftToggle={documentChrome.showLeftPanelToggle}
                />
              ) : null}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 px-2 text-[12px]"
                onClick={() => setApiDocsOpen(true)}
              >
                <Terminal className="me-1 size-3" />
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
