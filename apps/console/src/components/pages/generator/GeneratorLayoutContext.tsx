import {
  createContext,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import type { DiagramDocument } from '@/lib/diagram-generator/types'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import {
  useGeneratorPanelVisibility,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'

export type GeneratorApiTab = 'covers' | 'diagrams'

export type GeneratorDocumentChrome = {
  phase: 'start' | 'editor'
  resource: GeneratorApiTab
  showLeftPanelToggle: boolean
  leftPanelLabel: string
  onNewDocument: () => void
  onBrowseDocuments: () => void
  newDocumentLabel: string
  browseDocumentsLabel: string
}

export type GeneratorEditorTitleState = {
  name: string
  maxLength: number
  isSaving?: boolean
  onChange: (name: string) => void | Promise<void>
}

type GeneratorLayoutContextValue = {
  coverExportData: CoverRenderData | null
  setCoverExportData: (data: CoverRenderData | null) => void
  diagramDocument: DiagramDocument | null
  setDiagramDocument: (document: DiagramDocument | null) => void
  apiDocsOpen: boolean
  setApiDocsOpen: (open: boolean) => void
  documentChrome: GeneratorDocumentChrome | null
  setDocumentChrome: (chrome: GeneratorDocumentChrome | null) => void
  editorTitle: GeneratorEditorTitleState | null
  setEditorTitle: (title: GeneratorEditorTitleState | null) => void
  leftPanelOpen: boolean
  rightPanelOpen: boolean
  setLeftPanelOpen: (open: boolean | ((prev: boolean) => boolean)) => void
  setRightPanelOpen: (open: boolean | ((prev: boolean) => boolean)) => void
  toggleLeftPanel: () => void
  toggleRightPanel: () => void
}

const GeneratorLayoutContext = createContext<GeneratorLayoutContextValue | null>(
  null,
)

export function GeneratorLayoutProvider({ children }: { children: ReactNode }) {
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const {
    leftOpen,
    rightOpen,
    setLeftOpen,
    setRightOpen,
    toggleLeft,
    toggleRight,
  } = useGeneratorPanelVisibility(consoleAccount)

  const [coverExportData, setCoverExportData] = useState<CoverRenderData | null>(null)
  const [diagramDocument, setDiagramDocument] = useState<DiagramDocument | null>(null)
  const [apiDocsOpen, setApiDocsOpen] = useState(false)
  const [documentChrome, setDocumentChrome] = useState<GeneratorDocumentChrome | null>(
    null,
  )
  const [editorTitle, setEditorTitle] = useState<GeneratorEditorTitleState | null>(
    null,
  )

  const value = useMemo(
    () => ({
      coverExportData,
      setCoverExportData,
      diagramDocument,
      setDiagramDocument,
      apiDocsOpen,
      setApiDocsOpen,
      documentChrome,
      setDocumentChrome,
      editorTitle,
      setEditorTitle,
      leftPanelOpen: leftOpen,
      rightPanelOpen: rightOpen,
      setLeftPanelOpen: setLeftOpen,
      setRightPanelOpen: setRightOpen,
      toggleLeftPanel: toggleLeft,
      toggleRightPanel: toggleRight,
    }),
    [
      apiDocsOpen,
      coverExportData,
      diagramDocument,
      documentChrome,
      editorTitle,
      leftOpen,
      rightOpen,
      setLeftOpen,
      setRightOpen,
      toggleLeft,
      toggleRight,
    ],
  )

  return (
    <GeneratorLayoutContext.Provider value={value}>
      {children}
    </GeneratorLayoutContext.Provider>
  )
}

export function useGeneratorLayout() {
  const context = useContext(GeneratorLayoutContext)
  if (!context) {
    throw new Error('useGeneratorLayout must be used within GeneratorLayoutProvider')
  }
  return context
}
