import {
  useState,
  useCallback,
  useMemo,
  useEffect,
  useLayoutEffect,
  useRef,
} from 'react'
import type { ImperativePanelHandle } from 'react-resizable-panels'
import { useParams, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { useT } from '@/lib/i18n/translate'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import { monacoSyntaxHighlightRules } from '@/lib/code-syntax-theme'
import Editor from '@monaco-editor/react'
import {
  ArrowLeft,
  Download,
  Copy,
  FileCode,
  File,
  FileJson,
  FileText,
  Folder,
  ChevronRight,
  ChevronDown,
  ListCollapse,
  Plus,
  Trash2,
  X,
  Circle,
  Search,
  Replace,
  PanelLeftClose,
  PanelLeft,
  ChevronUp,
  ChevronDown as ChevronDownIcon,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  ResizablePanelGroup,
  ResizablePanel,
  ResizableHandle,
} from '@/components/ui/resizable'
import { ScrollArea } from '@/components/ui/scroll-area'
import { toast } from 'sonner'
import { verticalPanelResizeHandleClass } from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'
import {
  computeTwoPanelHorizontalLayout,
  FUNCTIONS_EDITOR_EXPLORER_DEFAULT_WIDTH_PX,
  FUNCTIONS_EDITOR_EXPLORER_MAX_WIDTH_PX,
  FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX,
  FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX,
  syncPanelGroupFirstPanePx,
} from '@/lib/resizable-layout'
import {
  DEFAULT_FILES,
  EDITOR_TEMPLATES,
  type EditorTemplate,
} from './getting-started-template'

type Language = 'javascript' | 'typescript' | 'json'

/** Tree node for explorer: folder (with children) or file (leaf). */
type FileTreeNode =
  | {
      type: 'folder'
      name: string
      pathPrefix: string
      children: FileTreeNode[]
    }
  | { type: 'file'; name: string; path: string }

function buildFileTree(paths: string[]): FileTreeNode[] {
  type FolderNode = {
    type: 'folder'
    name: string
    pathPrefix: string
    children: FileTreeNode[]
  }
  const root: FileTreeNode[] = []

  function ensureFolder(
    parent: FolderNode,
    name: string,
    pathPrefix: string,
  ): FolderNode {
    let f = parent.children.find(
      (c): c is FolderNode => c.type === 'folder' && c.name === name,
    )
    if (!f) {
      f = { type: 'folder', name, pathPrefix, children: [] }
      parent.children.push(f)
    }
    return f
  }

  function addPath(segments: string[], path: string) {
    if (segments.length === 1) {
      root.push({ type: 'file', name: segments[0], path })
      return
    }
    const [first, ...rest] = segments
    let folder = root.find(
      (n): n is FolderNode => n.type === 'folder' && n.name === first,
    )
    if (!folder) {
      folder = { type: 'folder', name: first, pathPrefix: first, children: [] }
      root.push(folder)
    }
    if (rest.length === 1) {
      folder.children.push({ type: 'file', name: rest[0], path })
      return
    }
    let current = folder
    for (let i = 0; i < rest.length - 1; i++) {
      const seg = rest[i]
      const prefix = [first, ...rest.slice(0, i + 1)].join('/')
      current = ensureFolder(current, seg, prefix)
    }
    current.children.push({ type: 'file', name: rest[rest.length - 1], path })
  }

  for (const path of paths) {
    const parts = path.split('/').filter(Boolean)
    if (parts.length) addPath(parts, path)
  }

  const sortNodes = (nodes: FileTreeNode[]): FileTreeNode[] =>
    [...nodes].sort((a, b) => {
      const aFolder = a.type === 'folder' ? 1 : 0
      const bFolder = b.type === 'folder' ? 1 : 0
      if (aFolder !== bFolder) return bFolder - aFolder
      const aName = a.type === 'folder' ? a.name : a.name
      const bName = b.type === 'folder' ? b.name : b.name
      return aName.localeCompare(bName, undefined, { sensitivity: 'base' })
    })

  function sortTree(node: FileTreeNode): FileTreeNode {
    if (node.type === 'folder') {
      return {
        ...node,
        children: sortNodes(node.children.map(sortTree)),
      }
    }
    return node
  }
  return sortNodes(root.map(sortTree))
}

/** Filter file tree by search query (path or name, case-insensitive). Keeps folders that match or contain matches. */
function filterFileTree(nodes: FileTreeNode[], query: string): FileTreeNode[] {
  const q = query.trim().toLowerCase()
  if (!q) return nodes
  const result: FileTreeNode[] = []
  for (const node of nodes) {
    if (node.type === 'file') {
      const match =
        node.path.toLowerCase().includes(q) ||
        node.name.toLowerCase().includes(q)
      if (match) result.push(node)
    } else {
      const filteredChildren = filterFileTree(node.children, query)
      const match =
        node.name.toLowerCase().includes(q) || filteredChildren.length > 0
      if (match) result.push({ ...node, children: filteredChildren })
    }
  }
  return result
}

/** Collect all folder pathPrefixes in a tree (for auto-expand when searching). */
function getAllPathPrefixes(nodes: FileTreeNode[]): string[] {
  const out: string[] = []
  for (const node of nodes) {
    if (node.type === 'folder') {
      out.push(node.pathPrefix)
      out.push(...getAllPathPrefixes(node.children))
    }
  }
  return out
}

function getFileIcon(path: string) {
  if (path.endsWith('.json')) return FileJson
  if (path.endsWith('.ts') || path.endsWith('.js')) return FileText
  return File
}

const EXPLORER_INDENT = 14

function FileTreeNodes({
  nodes,
  activeFile,
  expandedFolders,
  onToggleFolder,
  onSelectFile,
  onDeleteFile,
  canDelete,
  depth,
}: {
  nodes: FileTreeNode[]
  activeFile: string
  expandedFolders: Set<string>
  onToggleFolder: (pathPrefix: string) => void
  onSelectFile: (path: string) => void
  onDeleteFile: (path: string) => void
  canDelete: boolean
  depth: number
}) {
  const t = useT()
  return (
    <>
      {nodes.map((node) => {
        if (node.type === 'folder') {
          const isExpanded = expandedFolders.has(node.pathPrefix)
          return (
            <div key={node.pathPrefix} className="select-none">
              <button
                type="button"
                className="flex w-full items-center gap-1.5 py-1 pe-2 text-start text-[12px] text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-inset"
                style={{
                  paddingInlineStart: depth * EXPLORER_INDENT + 6,
                  backgroundColor: 'transparent',
                }}
                onClick={() => onToggleFolder(node.pathPrefix)}
                aria-expanded={isExpanded}
              >
                <span className="flex shrink-0 w-4 justify-center">
                  {isExpanded ? (
                    <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
                  ) : (
                    <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
                  )}
                </span>
                <Folder className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <span className="min-w-0 truncate font-normal">
                  {node.name}
                </span>
              </button>
              {isExpanded && (
                <FileTreeNodes
                  nodes={node.children}
                  activeFile={activeFile}
                  expandedFolders={expandedFolders}
                  onToggleFolder={onToggleFolder}
                  onSelectFile={onSelectFile}
                  onDeleteFile={onDeleteFile}
                  canDelete={canDelete}
                  depth={depth + 1}
                />
              )}
            </div>
          )
        }
        const isActive = activeFile === node.path
        const Icon = getFileIcon(node.path)
        return (
          <div
            key={node.path}
            role="button"
            tabIndex={0}
            className={cn(
              'group relative flex items-center gap-2 py-1 pe-1.5 text-[12px] cursor-pointer border-s-2 border-transparent focus:outline-none focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-inset',
              isActive
                ? 'border-s-primary bg-muted/40 text-foreground'
                : 'text-muted-foreground hover:bg-muted/30 hover:text-foreground',
            )}
            style={{ paddingInlineStart: depth * EXPLORER_INDENT + 6 + 18 }}
            onClick={() => onSelectFile(node.path)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                onSelectFile(node.path)
              }
            }}
          >
            <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span
              className="min-w-0 truncate flex-1 font-normal"
              title={node.path}
            >
              {node.name}
            </span>
            {canDelete && (
              <button
                type="button"
                className="shrink-0 rounded p-0.5 opacity-0 group-hover:opacity-100 hover:bg-muted text-muted-foreground hover:text-foreground focus:opacity-100 focus:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                onClick={(e) => {
                  e.stopPropagation()
                  onDeleteFile(node.path)
                }}
                aria-label={`${t('Remove')} ${node.name}`}
              >
                <Trash2 className="h-3 w-3" />
              </button>
            )}
          </div>
        )
      })}
    </>
  )
}

function getLanguageFromPath(path: string): Language {
  if (path.endsWith('.ts')) return 'typescript'
  if (path.endsWith('.json')) return 'json'
  return 'javascript'
}

/**
 * Compress a string to gzip and return as Uint8Array.
 */
async function gzipString(str: string): Promise<Uint8Array> {
  const bytes = new TextEncoder().encode(str)
  const stream = new Blob([bytes])
    .stream()
    .pipeThrough(new CompressionStream('gzip'))
  const blob = await new Response(stream).blob()
  return new Uint8Array(await blob.arrayBuffer())
}

function uint8ArrayToBase64(bytes: Uint8Array): string {
  let binary = ''
  const len = bytes.byteLength
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  return typeof btoa !== 'undefined' ? btoa(binary) : ''
}

const ENTRY_FILE = 'src/main.js'

/** Monaco theme matching app light/dark (background, foreground, borders, line numbers). */
const MONACO_THEME_LIGHT = 'app-light'
const MONACO_THEME_DARK = 'app-dark'

/**
 * Resolve a CSS color value (e.g. oklch, var(--x)) to hex for Monaco.
 * Uses a temporary element so the browser computes the final rgb.
 */
function resolveCssColorToHex(cssValue: string, fallback: string): string {
  if (typeof document === 'undefined' || !cssValue?.trim()) return fallback
  const trimmed = cssValue.trim()
  if (/^#[0-9A-Fa-f]{6}$/.test(trimmed)) return trimmed
  const el = document.createElement('div')
  el.style.setProperty('background', trimmed)
  el.style.setProperty('color', 'transparent')
  el.style.setProperty('position', 'absolute')
  el.style.setProperty('visibility', 'hidden')
  el.style.setProperty('pointer-events', 'none')
  document.body.appendChild(el)
  const computed = getComputedStyle(el).backgroundColor
  document.body.removeChild(el)
  const m = computed.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
  if (m) {
    const [, r, g, b] = m
    return (
      '#' +
      [r, g, b].map((x) => Number(x).toString(16).padStart(2, '0')).join('')
    )
  }
  return fallback
}

function defineAppThemes(monaco: typeof import('monaco-editor')) {
  monaco.editor.defineTheme(MONACO_THEME_LIGHT, {
    base: 'vs',
    inherit: true,
    rules: monacoSyntaxHighlightRules(false),
    colors: {
      'editor.background': '#ffffff',
      'editor.foreground': '#1a1a1a',
      'editorLineNumber.foreground': '#6b7280',
      'editorCursor.foreground': '#1a1a1a',
      'editor.selectionBackground': '#e5e7eb',
    },
  })
  // Dark: use --editor-bg (#09090b) so Monaco --vscode-editor-background matches.
  const root = typeof document !== 'undefined' ? document.documentElement : null
  const isDark = root ? isHtmlDarkChrome() : false
  const editorBgVar = root
    ? getComputedStyle(root).getPropertyValue('--editor-bg').trim()
    : ''
  const DARK_BG =
    editorBgVar && editorBgVar.startsWith('#') ? editorBgVar : '#09090b'
  const darkFgVar = root
    ? getComputedStyle(root).getPropertyValue('--foreground').trim()
    : ''
  const darkFg =
    isDark && darkFgVar ? resolveCssColorToHex(darkFgVar, '#fafafa') : '#fafafa'
  const darkMutedVar = root
    ? getComputedStyle(root).getPropertyValue('--muted-foreground').trim()
    : ''
  const darkMuted =
    isDark && darkMutedVar
      ? resolveCssColorToHex(darkMutedVar, '#71717a')
      : '#71717a'
  monaco.editor.defineTheme(MONACO_THEME_DARK, {
    base: 'vs-dark',
    inherit: true,
    rules: monacoSyntaxHighlightRules(true),
    colors: {
      'editor.background': DARK_BG,
      'editorGutter.background': DARK_BG,
      'editor.foreground': darkFg,
      'editor.lineHighlightBackground': DARK_BG,
      'editorLineNumber.foreground': darkMuted,
      'editorCursor.foreground': darkFg,
      'editor.selectionBackground': '#2d2d30',
      'editorWidget.background': DARK_BG,
      'editorSuggestWidget.background': DARK_BG,
      'minimap.background': DARK_BG,
      'minimapGutter.background': DARK_BG,
    },
  })
}

export function View() {
  const t = useT()
  const { isMac } = usePlatform()
  const { projectId } = useParams({ strict: false })
  const { resolvedTheme } = useTheme()
  const isDark = isResolvedThemeDarkChrome(resolvedTheme)
  const [files, setFiles] = useState<Record<string, string>>(DEFAULT_FILES)
  const [activeFile, setActiveFile] = useState<string>(ENTRY_FILE)
  const [isCompressing, setIsCompressing] = useState(false)
  const [gzipSize, setGzipSize] = useState<number | null>(null)
  const [addFileOpen, setAddFileOpen] = useState(false)
  const [newFilePath, setNewFilePath] = useState('')
  const [deleteConfirmPath, setDeleteConfirmPath] = useState<string | null>(
    null,
  )
  const [expandedFolders, setExpandedFolders] = useState<Set<string>>(
    () => new Set(['src']),
  )
  const [fileSearchQuery, setFileSearchQuery] = useState('')
  const [explorerOpen, setExplorerOpen] = useState(true)
  const [openFiles, setOpenFiles] = useState<string[]>([
    ENTRY_FILE,
    'package.json',
  ])
  const [findPanelMode, setFindPanelMode] = useState<
    false | 'find' | 'replace'
  >(false)
  const [findInFileQuery, setFindInFileQuery] = useState('')
  const [replaceValue, setReplaceValue] = useState('')
  const [findMatchIndex, setFindMatchIndex] = useState(0)
  const [findMatches, setFindMatches] = useState<
    {
      lineNumber: number
      column: number
      endLineNumber: number
      endColumn: number
    }[]
  >([])
  const editorRef = useRef<
    import('monaco-editor').editor.IStandaloneCodeEditor | null
  >(null)
  const findDecorationIdsRef = useRef<string[]>([])
  const findInputRef = useRef<HTMLInputElement | null>(null)
  const [currentTemplateLabel, setCurrentTemplateLabel] = useState<
    string | null
  >('Getting started')
  const [dirtyFiles, setDirtyFiles] = useState<Set<string>>(new Set())
  const [cursorPosition, setCursorPosition] = useState<{
    lineNumber: number
    column: number
  } | null>(null)
  const editorSplitContainerRef = useRef<HTMLDivElement>(null)
  const explorerPanelRef = useRef<ImperativePanelHandle>(null)
  const prevEditorSplitWidthRef = useRef(0)
  const [editorSplitWidth, setEditorSplitWidth] = useState(0)

  useLayoutEffect(() => {
    const el = editorSplitContainerRef.current
    if (!el) return
    const ro = new ResizeObserver((entries) => {
      setEditorSplitWidth(entries[0]?.contentRect.width ?? 0)
    })
    ro.observe(el)
    setEditorSplitWidth(el.getBoundingClientRect().width)
    return () => ro.disconnect()
  }, [explorerOpen])

  const explorerPanelLayout = useMemo(() => {
    const layout = computeTwoPanelHorizontalLayout({
      containerWidth: editorSplitWidth,
      firstPx: FUNCTIONS_EDITOR_EXPLORER_DEFAULT_WIDTH_PX,
      firstMinPx: FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX,
      firstMaxPx: FUNCTIONS_EDITOR_EXPLORER_MAX_WIDTH_PX,
      secondMinPx: FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX,
    })
    return {
      explorerDefault: layout.firstPercent,
      explorerMin: layout.firstMinPercent,
      explorerMax: layout.firstMaxPercent,
      mainDefault: layout.secondPercent,
      mainMin: layout.secondMinPercent,
    }
  }, [editorSplitWidth])

  useLayoutEffect(() => {
    if (!explorerOpen || editorSplitWidth <= 0) return

    const prevWidth = prevEditorSplitWidthRef.current
    prevEditorSplitWidthRef.current = editorSplitWidth
    if (prevWidth <= 0 || prevWidth === editorSplitWidth) return

    syncPanelGroupFirstPanePx(
      explorerPanelRef.current,
      editorSplitWidth,
      FUNCTIONS_EDITOR_EXPLORER_DEFAULT_WIDTH_PX,
      {
        firstMinPx: FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX,
        firstMaxPx: FUNCTIONS_EDITOR_EXPLORER_MAX_WIDTH_PX,
        secondMinPx: FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX,
      },
    )
  }, [editorSplitWidth, explorerOpen])

  const shortcutFind = isMac ? '⌘F' : 'Ctrl+F'
  const shortcutReplace = isMac ? '⌘H' : 'Ctrl+H'

  const filePaths = useMemo(
    () => Object.keys(files).sort((a, b) => a.localeCompare(b)),
    [files],
  )

  const fileTree = useMemo(() => buildFileTree(filePaths), [filePaths])
  const filteredFileTree = useMemo(
    () => filterFileTree(fileTree, fileSearchQuery),
    [fileTree, fileSearchQuery],
  )
  const expandedForView = useMemo(() => {
    if (!fileSearchQuery.trim()) return expandedFolders
    const allInFiltered = getAllPathPrefixes(filteredFileTree)
    return new Set([...expandedFolders, ...allInFiltered])
  }, [expandedFolders, fileSearchQuery, filteredFileTree])

  const toggleFolder = useCallback((pathPrefix: string) => {
    setExpandedFolders((prev) => {
      const next = new Set(prev)
      if (next.has(pathPrefix)) next.delete(pathPrefix)
      else next.add(pathPrefix)
      return next
    })
  }, [])

  const openFile = useCallback((path: string) => {
    setActiveFile(path)
    setOpenFiles((prev) => (prev.includes(path) ? prev : [...prev, path]))
  }, [])

  const closeTab = useCallback(
    (path: string, e: React.MouseEvent) => {
      e.stopPropagation()
      if (openFiles.length <= 1) return
      setOpenFiles((prev) => prev.filter((p) => p !== path))
      if (activeFile === path) {
        const remaining = openFiles.filter((p) => p !== path)
        setActiveFile(remaining[remaining.length - 1] ?? '')
      }
    },
    [activeFile, openFiles],
  )

  const reorderOpenFiles = useCallback(
    (fromIndex: number, toIndex: number) => {
      if (
        fromIndex < 0 ||
        toIndex < 0 ||
        fromIndex >= openFiles.length ||
        toIndex >= openFiles.length ||
        fromIndex === toIndex
      )
        return
      const next = [...openFiles]
      const [removed] = next.splice(fromIndex, 1)
      next.splice(toIndex, 0, removed)
      setOpenFiles(next)
    },
    [openFiles],
  )

  const applyTemplate = useCallback((template: EditorTemplate) => {
    const paths = Object.keys(template.files).sort((a, b) => a.localeCompare(b))
    const firstPath = paths[0] ?? 'src/main.js'
    setFiles(template.files)
    setActiveFile(firstPath)
    setOpenFiles(paths)
    setDirtyFiles(new Set())
    setGzipSize(null)
    setCurrentTemplateLabel(template.label)
    setFindInFileQuery('')
    setFindMatches([])
    setFindMatchIndex(0)
    const editor = editorRef.current
    if (editor) {
      editor.deltaDecorations(findDecorationIdsRef.current, [])
      findDecorationIdsRef.current = []
    }
    setExpandedFolders((prev) => {
      const next = new Set(prev)
      if (paths.some((p) => p.startsWith('src/'))) next.add('src')
      return next
    })
    toast.success(`${t('Loaded template:')} ${t(template.label)}`)
  }, [t])

  const activeContent = files[activeFile] ?? ''
  const activeLang = getLanguageFromPath(activeFile)
  const lineCount = useMemo(
    () => (activeContent ? activeContent.split('\n').length : 0),
    [activeContent],
  )

  useEffect(() => {
    setCursorPosition(null)
  }, [activeFile])

  const handleEditorMount = useCallback(
    (
      editor: import('monaco-editor').editor.IStandaloneCodeEditor,
      monaco: typeof import('monaco-editor'),
    ) => {
      editorRef.current = editor
      // Re-define and apply theme so we use current DOM (--editor-bg, .dark) and theme actually applies
      defineAppThemes(monaco)
      monaco.editor.setTheme(isDark ? MONACO_THEME_DARK : MONACO_THEME_LIGHT)
      const pos = editor.getPosition()
      setCursorPosition(
        pos ? { lineNumber: pos.lineNumber, column: pos.column } : null,
      )
      const disposable = editor.onDidChangeCursorPosition((e) => {
        setCursorPosition({
          lineNumber: e.position.lineNumber,
          column: e.position.column,
        })
      })
      return () => {
        editorRef.current = null
        disposable.dispose()
      }
    },
    [isDark],
  )

  /** Find all matches in current model for find-in-file; update decorations and selection. */
  const runFindInFile = useCallback(() => {
    const editor = editorRef.current
    const model = editor?.getModel()
    const q = findInFileQuery.trim()
    if (!editor || !model || !q) {
      setFindMatches([])
      setFindMatchIndex(0)
      if (editor) {
        editor.deltaDecorations(findDecorationIdsRef.current, [])
        findDecorationIdsRef.current = []
      }
      return
    }
    const findMatchesResult = model.findMatches(
      q,
      true,
      false,
      false,
      null,
      false,
    )
    const matches = findMatchesResult.map((m) => ({
      lineNumber: m.range.startLineNumber,
      column: m.range.startColumn,
      endLineNumber: m.range.endLineNumber,
      endColumn: m.range.endColumn,
    }))
    setFindMatches(matches)
    setFindMatchIndex(0)
    const newDecorations: import('monaco-editor').editor.IModelDeltaDecoration[] =
      matches.map((m) => ({
        range: {
          startLineNumber: m.lineNumber,
          startColumn: m.column,
          endLineNumber: m.endLineNumber,
          endColumn: m.endColumn,
        },
        options: {
          className: 'find-in-file-highlight',
          stickiness: 1,
        },
      }))
    findDecorationIdsRef.current = editor.deltaDecorations(
      findDecorationIdsRef.current,
      newDecorations,
    )
    if (matches.length > 0) {
      const current = matches[0]
      editor.setSelection({
        startLineNumber: current.lineNumber,
        startColumn: current.column,
        endLineNumber: current.endLineNumber,
        endColumn: current.endColumn,
      })
      editor.revealLineInCenter(current.lineNumber)
    }
  }, [findInFileQuery])

  useEffect(() => {
    runFindInFile()
  }, [runFindInFile, activeFile, activeContent])

  const goToFindMatch = useCallback(
    (delta: number) => {
      if (findMatches.length === 0) return
      const next =
        (findMatchIndex + delta + findMatches.length) % findMatches.length
      setFindMatchIndex(next)
      const m = findMatches[next]
      const editor = editorRef.current
      if (editor) {
        editor.setSelection({
          startLineNumber: m.lineNumber,
          startColumn: m.column,
          endLineNumber: m.endLineNumber,
          endColumn: m.endColumn,
        })
        editor.revealLineInCenter(m.lineNumber)
      }
    },
    [findMatches, findMatchIndex],
  )

  const replaceCurrent = useCallback(() => {
    const editor = editorRef.current
    const model = editor?.getModel()
    if (!editor || !model || findMatches.length === 0) return
    const m = findMatches[findMatchIndex]
    const range = {
      startLineNumber: m.lineNumber,
      startColumn: m.column,
      endLineNumber: m.endLineNumber,
      endColumn: m.endColumn,
    }
    editor.executeEdits('find-replace', [{ range, text: replaceValue }])
    const newValue = model.getValue()
    setFiles((prev) => ({ ...prev, [activeFile]: newValue }))
    setDirtyFiles((prev) => new Set(prev).add(activeFile))
    setGzipSize(null)
    runFindInFile()
    toast.success(t('Replaced'))
  }, [findMatches, findMatchIndex, replaceValue, activeFile, runFindInFile, t])

  const replaceAll = useCallback(() => {
    const editor = editorRef.current
    const model = editor?.getModel()
    if (!editor || !model || findMatches.length === 0) return
    const edits = findMatches
      .slice()
      .reverse()
      .map((m) => ({
        range: {
          startLineNumber: m.lineNumber,
          startColumn: m.column,
          endLineNumber: m.endLineNumber,
          endColumn: m.endColumn,
        },
        text: replaceValue,
      }))
    editor.executeEdits('find-replace-all', edits)
    const newValue = model.getValue()
    setFiles((prev) => ({ ...prev, [activeFile]: newValue }))
    setDirtyFiles((prev) => new Set(prev).add(activeFile))
    setGzipSize(null)
    runFindInFile()
    toast.success(
      `${t('Replaced')} ${findMatches.length} ${findMatches.length === 1 ? t('occurrence') : t('occurrences')}`,
    )
  }, [findMatches, replaceValue, activeFile, runFindInFile, t])

  const handleEditorChange = useCallback(
    (value: string | undefined) => {
      setCurrentTemplateLabel(null)
      setFiles((prev) => ({
        ...prev,
        [activeFile]: value ?? '',
      }))
      setGzipSize(null)
      setDirtyFiles((prev) => new Set(prev).add(activeFile))
    },
    [activeFile],
  )

  const addFile = useCallback(() => {
    const path = newFilePath.trim()
    if (!path) return
    if (files[path]) {
      toast.error(t('A file with this path already exists'))
      return
    }
    setCurrentTemplateLabel(null)
    setFiles((prev) => ({ ...prev, [path]: '' }))
    setActiveFile(path)
    setOpenFiles((prev) => (prev.includes(path) ? prev : [...prev, path]))
    setNewFilePath('')
    setAddFileOpen(false)
    toast.success(t('File added'))
  }, [newFilePath, files, t])

  const deleteFile = useCallback(
    (path: string) => {
      if (filePaths.length <= 1) {
        toast.error(t('Keep at least one file'))
        return
      }
      setCurrentTemplateLabel(null)
      setFiles((prev) => {
        const next = { ...prev }
        delete next[path]
        return next
      })
      if (activeFile === path) {
        const remaining = filePaths.filter((p) => p !== path)
        setActiveFile(remaining[0] ?? '')
      }
      setOpenFiles((prev) => prev.filter((p) => p !== path))
      setDirtyFiles((prev) => {
        const next = new Set(prev)
        next.delete(path)
        return next
      })
      setDeleteConfirmPath(null)
      toast.success(t('File removed'))
    },
    [filePaths, activeFile, t],
  )

  const bundleForExport = useCallback(() => {
    return JSON.stringify({ files })
  }, [files])

  const compressAndPrepare = useCallback(async () => {
    if (typeof CompressionStream === 'undefined') {
      toast.error(t('Gzip compression is not supported in this browser'))
      return
    }
    setIsCompressing(true)
    try {
      const payload = bundleForExport()
      const gzipBytes = await gzipString(payload)
      setGzipSize(gzipBytes.byteLength)
      toast.success(`${t('Compressed to')} ${gzipBytes.byteLength} ${t('bytes')}`)
    } catch (e) {
      toast.error(t('Failed to compress code'))
      console.error(e)
    } finally {
      setIsCompressing(false)
    }
  }, [bundleForExport, t])

  const downloadGzip = useCallback(async () => {
    if (typeof CompressionStream === 'undefined') {
      toast.error(t('Gzip compression is not supported in this browser'))
      return
    }
    setIsCompressing(true)
    try {
      const payload = bundleForExport()
      const gzipBytes = await gzipString(payload)
      const blob = new Blob([gzipBytes], { type: 'application/gzip' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'function.tar.gz'
      a.click()
      URL.revokeObjectURL(url)
      setGzipSize(gzipBytes.byteLength)
      toast.success(t('Downloaded function.tar.gz'))
    } catch (e) {
      toast.error(t('Failed to create gzip file'))
      console.error(e)
    } finally {
      setIsCompressing(false)
    }
  }, [bundleForExport, t])

  const handleEditorAreaKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Escape' && findPanelMode !== false) {
        e.preventDefault()
        e.stopPropagation()
        setFindPanelMode(false)
        setFindInFileQuery('')
        setReplaceValue('')
        setFindMatches([])
        setFindMatchIndex(0)
        const editor = editorRef.current
        if (editor) {
          editor.deltaDecorations(findDecorationIdsRef.current, [])
          findDecorationIdsRef.current = []
        }
      } else if (e.key === 'f' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault()
        e.stopPropagation()
        setFindPanelMode('find')
        setTimeout(() => findInputRef.current?.focus(), 0)
      } else if (e.key === 'h' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault()
        e.stopPropagation()
        setFindPanelMode('replace')
        setTimeout(() => findInputRef.current?.focus(), 0)
      }
    },
    [findPanelMode],
  )

  const copyGzipBase64 = useCallback(async () => {
    if (typeof CompressionStream === 'undefined') {
      toast.error(t('Gzip compression is not supported in this browser'))
      return
    }
    setIsCompressing(true)
    try {
      const payload = bundleForExport()
      const gzipBytes = await gzipString(payload)
      const base64 = uint8ArrayToBase64(gzipBytes)
      await navigator.clipboard.writeText(base64)
      setGzipSize(gzipBytes.byteLength)
      toast.success(t('Gzip (base64) copied to clipboard'))
    } catch (e) {
      toast.error(t('Failed to copy'))
      console.error(e)
    } finally {
      setIsCompressing(false)
    }
  }, [bundleForExport, t])

  return (
    <div className="flex flex-1 min-h-0 flex-col bg-background">
      {/* Top bar */}
      <div className="flex shrink-0 items-center justify-between gap-4 border-b border-border bg-background px-3 py-2 sm:px-4">
        <div className="flex items-center gap-2 min-w-0">
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                {projectId ? (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    asChild
                    aria-label={t('Back to functions')}
                  >
                    <Link
                      to="/projects/$projectId/functions"
                      params={{ projectId }}
                    >
                      <ArrowLeft className="h-4 w-4" />
                    </Link>
                  </Button>
                ) : (
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    aria-label={t('Back to functions')}
                    onClick={() => window.history.back()}
                  >
                    <ArrowLeft className="h-4 w-4" />
                  </Button>
                )}
              </TooltipTrigger>
              <TooltipContent side="bottom">
                <p>{t('Back to functions')}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
          <span className="text-[13px] font-medium text-foreground truncate">
            {currentTemplateLabel
              ? `${t('Function editor')} – ${t(currentTemplateLabel)}`
              : t('Function editor')}
          </span>
        </div>
        <div className="flex items-center gap-1.5 shrink-0">
          <TooltipProvider delayDuration={0}>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              onClick={compressAndPrepare}
              disabled={isCompressing}
            >
              <Download className="h-4 w-4" />
              {t('Prepare for deployment')}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              onClick={downloadGzip}
              disabled={isCompressing}
            >
              <Download className="h-4 w-4" />
              {t('Download gzip')}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              onClick={copyGzipBase64}
              disabled={isCompressing}
            >
              <Copy className="h-4 w-4" />
              {t('Copy gzip (base64)')}
            </Button>
          </TooltipProvider>
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 gap-1.5 text-[13px]"
                  onClick={() => {
                    setFindPanelMode('find')
                    setTimeout(() => findInputRef.current?.focus(), 0)
                  }}
                >
                  <Search className="h-4 w-4" />
                  {t('Find')}
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                <p>
                  {t('Find in file')} ({shortcutFind})
                </p>
              </TooltipContent>
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 gap-1.5 text-[13px]"
                  onClick={() => {
                    setFindPanelMode('replace')
                    setTimeout(() => findInputRef.current?.focus(), 0)
                  }}
                >
                  <Replace className="h-4 w-4" />
                  {t('Replace')}
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                <p>
                  {t('Find and replace')} ({shortcutReplace})
                </p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className="h-9 gap-1.5 text-[13px]"
              >
                <FileCode className="h-4 w-4" />
                {t('Templates')}
                <ChevronDownIcon className="h-3.5 w-3.5 opacity-70" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-[180px]">
              {EDITOR_TEMPLATES.map((template) => (
                <DropdownMenuItem
                  key={template.id}
                  onClick={() => applyTemplate(template)}
                >
                  {t(template.label)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {gzipSize !== null && (
        <div className="shrink-0 border-b border-border bg-muted/20 px-3 py-1.5 text-[12px] text-muted-foreground sm:px-4">
          {t('Compressed:')}{' '}
          <span className="font-medium text-foreground tabular-nums">
            {gzipSize}
          </span>{' '}
          {t('bytes')} ({filePaths.length}{' '}
          {filePaths.length === 1 ? t('file') : t('files')})
        </div>
      )}

      {/* File tree (left) + Editor (right), or editor only when explorer closed */}
      <div
        ref={editorSplitContainerRef}
        className="flex h-full min-h-0 min-w-0 flex-1"
      >
        {explorerOpen ? (
          <ResizablePanelGroup
            direction="horizontal"
            className="h-full min-w-0 flex-1"
          >
            <ResizablePanel
              ref={explorerPanelRef}
              defaultSize={explorerPanelLayout.explorerDefault}
              minSize={explorerPanelLayout.explorerMin}
              maxSize={explorerPanelLayout.explorerMax}
              style={{ minWidth: FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX }}
            >
              <div className="flex h-full flex-col border-e border-border bg-background">
                {/* Explorer header – no close button (moved to left of tabs) */}
                <div className="flex h-8 shrink-0 items-center justify-between border-b border-border px-2">
                  <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">
                    {t('Explorer')}
                  </span>
                  <TooltipProvider delayDuration={0}>
                    <div className="flex items-center gap-0.5">
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <button
                            type="button"
                            className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            onClick={() => setExpandedFolders(new Set())}
                            aria-label={t('Collapse all')}
                          >
                            <ListCollapse className="h-3.5 w-3.5" />
                          </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                          <p>{t('Collapse all')}</p>
                        </TooltipContent>
                      </Tooltip>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <button
                            type="button"
                            className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            onClick={() => setAddFileOpen(true)}
                            aria-label={t('New file')}
                          >
                            <Plus className="h-3.5 w-3.5" />
                          </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                          <p>{t('New file')}</p>
                        </TooltipContent>
                      </Tooltip>
                    </div>
                  </TooltipProvider>
                </div>
                <div className="flex h-[41px] shrink-0 items-center border-b border-border px-2">
                  <div className="relative w-full">
                    <Search className="absolute start-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input
                      type="search"
                      placeholder={t('Search files...')}
                      value={fileSearchQuery}
                      onChange={(e) => setFileSearchQuery(e.target.value)}
                      className="h-7 ps-7 pe-2 text-[12px] font-normal"
                      aria-label={t('Search files')}
                    />
                  </div>
                </div>
                <ScrollArea className="flex-1">
                  <nav
                    className="py-1 pe-1 ps-0.5"
                    aria-label={t('Project files')}
                  >
                    {fileTree.length === 0 ? (
                      <div className="px-3 py-4 text-center">
                        <p className="text-[12px] text-muted-foreground">
                          {t('No files yet')}
                        </p>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="mt-2 h-7 text-[12px]"
                          onClick={() => setAddFileOpen(true)}
                        >
                          <Plus className="h-3 w-3 me-1.5" />
                          {t('New file')}
                        </Button>
                      </div>
                    ) : filteredFileTree.length === 0 ? (
                      <div className="px-3 py-4 text-center">
                        <p className="text-[12px] text-muted-foreground">
                          {t('No matching files')}
                        </p>
                      </div>
                    ) : (
                      <FileTreeNodes
                        nodes={filteredFileTree}
                        activeFile={activeFile}
                        expandedFolders={expandedForView}
                        onToggleFolder={toggleFolder}
                        onSelectFile={openFile}
                        onDeleteFile={(path) => setDeleteConfirmPath(path)}
                        canDelete={filePaths.length > 1}
                        depth={0}
                      />
                    )}
                  </nav>
                </ScrollArea>
              </div>
            </ResizablePanel>
            <ResizableHandle
              className={verticalPanelResizeHandleClass(
                'z-10 before:z-10',
              )}
            />
            <ResizablePanel
              defaultSize={explorerPanelLayout.mainDefault}
              minSize={explorerPanelLayout.mainMin}
              style={{ minWidth: FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX }}
            >
              <div
                className="flex h-full flex-col min-h-0"
                onKeyDown={(e) => e.stopPropagation()}
                onKeyDownCapture={handleEditorAreaKeyDown}
                onKeyUp={(e) => e.stopPropagation()}
              >
                {/* Tab bar – explorer toggle left of tabs, then VS Code style tabs */}
                <div className="flex h-8 shrink-0 items-end border-b border-border bg-muted/50">
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <button
                          type="button"
                          className="flex h-8 w-8 shrink-0 items-center justify-center border-e border-border text-muted-foreground hover:bg-muted/60 hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-inset"
                          onClick={() => setExplorerOpen(false)}
                          aria-label={t('Close explorer')}
                        >
                          <PanelLeftClose className="h-3.5 w-3.5" />
                        </button>
                      </TooltipTrigger>
                      <TooltipContent side="bottom">
                        <p>{t('Close explorer')}</p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                  <div className="flex min-w-0 flex-1 items-end overflow-x-auto">
                    {(() => {
                      const visibleOpenFiles = openFiles.filter(
                        (path) => path in files,
                      )
                      const canCloseTab = visibleOpenFiles.length > 1
                      return visibleOpenFiles.map((path) => {
                        const tabIndex = openFiles.indexOf(path)
                        const isActive = activeFile === path
                        const isDirty = dirtyFiles.has(path)
                        const name = path.split('/').pop() ?? path
                        return (
                          <div
                            key={path}
                            role="tab"
                            aria-selected={isActive}
                            data-tab-index={tabIndex}
                            draggable
                            className={cn(
                              'group flex shrink-0 cursor-grab active:cursor-grabbing items-center gap-1.5 border-b-2 px-3 py-1.5 font-mono text-[12px] transition-colors',
                              isActive
                                ? 'border-t-2 border-t-primary border-b-background bg-background text-foreground -mb-px'
                                : 'border-t-2 border-t-transparent border-b-transparent bg-muted/40 text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                            )}
                            onClick={() => setActiveFile(path)}
                            onDragStart={(e) => {
                              e.dataTransfer.setData(
                                'application/x-tab-index',
                                String(tabIndex),
                              )
                              e.dataTransfer.effectAllowed = 'move'
                              e.dataTransfer.dropEffect = 'move'
                            }}
                            onDragOver={(e) => {
                              e.preventDefault()
                              e.stopPropagation()
                              e.dataTransfer.dropEffect = 'move'
                            }}
                            onDrop={(e) => {
                              e.preventDefault()
                              e.stopPropagation()
                              const fromIndex = parseInt(
                                e.dataTransfer.getData(
                                  'application/x-tab-index',
                                ),
                                10,
                              )
                              const toIndex = tabIndex
                              if (
                                !Number.isNaN(fromIndex) &&
                                fromIndex !== toIndex
                              )
                                reorderOpenFiles(fromIndex, toIndex)
                            }}
                          >
                            <span
                              className="min-w-0 max-w-[140px] truncate"
                              title={path}
                            >
                              {name}
                            </span>
                            {isDirty && (
                              <Circle
                                className="h-1 w-1 shrink-0 fill-current text-amber-500"
                                aria-label={t('Unsaved')}
                              />
                            )}
                            {canCloseTab && (
                              <button
                                type="button"
                                className="rounded p-0.5 opacity-0 group-hover:opacity-100 hover:bg-muted hover:text-foreground focus:opacity-100 focus:outline-none focus-visible:ring-1 focus-visible:ring-ring text-muted-foreground"
                                onClick={(e) => closeTab(path, e)}
                                onDragStart={(e) => e.stopPropagation()}
                                aria-label={`${t('Close')} ${name}`}
                              >
                                <X className="h-3 w-3" />
                              </button>
                            )}
                          </div>
                        )
                      })
                    })()}
                  </div>
                </div>
                {/* Find / Find+replace – only when opened via shortcut or top bar buttons */}
                {findPanelMode !== false && (
                  <div className="flex h-[41px] shrink-0 items-center border-b border-border bg-muted/30 px-2 gap-2">
                    <div className="flex min-w-0 flex-1 items-center gap-2 sm:min-w-[160px]">
                      <label className="shrink-0 text-[11px] font-medium text-muted-foreground">
                        {t('Find')}
                      </label>
                      <Input
                        ref={findInputRef}
                        type="search"
                        placeholder={`${t('Search in file')} (${shortcutFind})`}
                        value={findInFileQuery}
                        onChange={(e) => setFindInFileQuery(e.target.value)}
                        className="h-7 min-w-0 flex-1 max-w-[180px] text-[12px]"
                        aria-label={t('Find in file')}
                      />
                    </div>
                    {findPanelMode === 'replace' && (
                      <div className="flex min-w-0 flex-1 items-center gap-2 sm:min-w-[160px]">
                        <label className="shrink-0 text-[11px] font-medium text-muted-foreground">
                          {t('Replace')}
                        </label>
                        <Input
                          type="text"
                          placeholder={t('Replace with')}
                          value={replaceValue}
                          onChange={(e) => setReplaceValue(e.target.value)}
                          className="h-7 min-w-0 flex-1 max-w-[180px] text-[12px]"
                          aria-label={t('Replace with')}
                        />
                      </div>
                    )}
                    <div className="flex shrink-0 items-center gap-0.5">
                      {findMatches.length > 0 && (
                        <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                          {findMatchIndex + 1}/{findMatches.length}
                        </span>
                      )}
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 shrink-0"
                        onClick={() => goToFindMatch(-1)}
                        disabled={findMatches.length === 0}
                        aria-label={t('Previous match')}
                      >
                        <ChevronUp className="h-3.5 w-3.5" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 shrink-0"
                        onClick={() => goToFindMatch(1)}
                        disabled={findMatches.length === 0}
                        aria-label={t('Next match')}
                      >
                        <ChevronDown className="h-3.5 w-3.5" />
                      </Button>
                      {findPanelMode === 'replace' && (
                        <>
                          <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="h-7 shrink-0 text-[11px] px-2"
                            onClick={replaceCurrent}
                            disabled={findMatches.length === 0}
                          >
                            {t('Replace')}
                          </Button>
                          <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="h-7 shrink-0 text-[11px] px-2"
                            onClick={replaceAll}
                            disabled={findMatches.length === 0}
                          >
                            {t('All')}
                          </Button>
                        </>
                      )}
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 shrink-0"
                        onClick={() => {
                          setFindPanelMode(false)
                          setFindInFileQuery('')
                          setReplaceValue('')
                          setFindMatches([])
                          setFindMatchIndex(0)
                          const editor = editorRef.current
                          if (editor) {
                            editor.deltaDecorations(
                              findDecorationIdsRef.current,
                              [],
                            )
                            findDecorationIdsRef.current = []
                          }
                        }}
                        aria-label={t('Close find')}
                      >
                        <X className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                )}
                <div className="min-h-0 flex-1">
                  <Editor
                    key={`${activeFile}-${isDark}`}
                    height="100%"
                    defaultLanguage={activeLang}
                    language={activeLang}
                    value={activeContent}
                    onChange={handleEditorChange}
                    onMount={handleEditorMount}
                    theme={isDark ? MONACO_THEME_DARK : MONACO_THEME_LIGHT}
                    beforeMount={defineAppThemes}
                    options={{
                      minimap: { enabled: true },
                      fontSize: 13,
                      lineNumbers: 'on',
                      scrollBeyondLastLine: false,
                      wordWrap: 'on',
                      padding: { top: 16 },
                    }}
                    loading={
                      <div className="flex h-full items-center justify-center bg-background text-muted-foreground">
                        {t('Loading editor...')}
                      </div>
                    }
                    className={cn('rounded-b-lg')}
                  />
                </div>
                {/* Footer */}
                <div className="flex shrink-0 items-center justify-between gap-4 border-t border-border bg-muted/20 px-3 py-1.5 text-[11px] text-muted-foreground">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="truncate font-mono" title={activeFile}>
                      {activeFile}
                    </span>
                    <span className="shrink-0 capitalize">{activeLang}</span>
                    {dirtyFiles.has(activeFile) && (
                      <span className="shrink-0 text-amber-600 dark:text-amber-500">
                        {t('Unsaved')}
                      </span>
                    )}
                  </div>
                  <div className="flex shrink-0 items-center gap-3 tabular-nums">
                    {cursorPosition && (
                      <span>
                        {t('Ln')} {cursorPosition.lineNumber}, {t('Col')}{' '}
                        {cursorPosition.column}
                      </span>
                    )}
                    <span>
                      {lineCount} {t('lines')}
                    </span>
                    <span>
                      {activeContent.length} {t('chars')}
                    </span>
                  </div>
                </div>
              </div>
            </ResizablePanel>
          </ResizablePanelGroup>
        ) : (
          <div
            className="flex min-h-0 flex-1 flex-col min-w-0 bg-background"
            onKeyDown={(e) => e.stopPropagation()}
            onKeyDownCapture={handleEditorAreaKeyDown}
            onKeyUp={(e) => e.stopPropagation()}
          >
            {/* Tab bar – explorer toggle left of tabs */}
            <div className="flex h-8 shrink-0 items-end border-b border-border bg-muted/50">
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      className="flex h-8 w-8 shrink-0 items-center justify-center border-e border-border text-muted-foreground hover:bg-muted/60 hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-inset"
                      onClick={() => setExplorerOpen(true)}
                      aria-label={t('Open explorer')}
                    >
                      <PanelLeft className="h-3.5 w-3.5" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    <p>{t('Open explorer')}</p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
              <div className="flex min-w-0 flex-1 items-end overflow-x-auto">
                {openFiles
                  .filter((path) => path in files)
                  .map((path) => {
                    const tabIndex = openFiles.indexOf(path)
                    const isActive = activeFile === path
                    const isDirty = dirtyFiles.has(path)
                    const name = path.split('/').pop() ?? path
                    const canCloseTab =
                      openFiles.filter((p) => p in files).length > 1
                    return (
                      <div
                        key={path}
                        role="tab"
                        aria-selected={isActive}
                        data-tab-index={tabIndex}
                        draggable
                        className={cn(
                          'group flex shrink-0 cursor-grab active:cursor-grabbing items-center gap-1.5 border-b-2 px-3 py-1.5 font-mono text-[12px] transition-colors',
                          isActive
                            ? 'border-t-2 border-t-primary border-b-background bg-background text-foreground -mb-px'
                            : 'border-t-2 border-t-transparent border-b-transparent bg-muted/40 text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                        )}
                        onClick={() => setActiveFile(path)}
                        onDragStart={(e) => {
                          e.dataTransfer.setData(
                            'application/x-tab-index',
                            String(tabIndex),
                          )
                          e.dataTransfer.effectAllowed = 'move'
                          e.dataTransfer.dropEffect = 'move'
                        }}
                        onDragOver={(e) => {
                          e.preventDefault()
                          e.stopPropagation()
                          e.dataTransfer.dropEffect = 'move'
                        }}
                        onDrop={(e) => {
                          e.preventDefault()
                          e.stopPropagation()
                          const fromIndex = parseInt(
                            e.dataTransfer.getData('application/x-tab-index'),
                            10,
                          )
                          const toIndex = tabIndex
                          if (!Number.isNaN(fromIndex) && fromIndex !== toIndex)
                            reorderOpenFiles(fromIndex, toIndex)
                        }}
                      >
                        <span
                          className="min-w-0 max-w-[140px] truncate"
                          title={path}
                        >
                          {name}
                        </span>
                        {isDirty && (
                          <Circle
                            className="h-1 w-1 shrink-0 fill-current text-amber-500"
                            aria-label={t('Unsaved')}
                          />
                        )}
                        {canCloseTab && (
                          <button
                            type="button"
                            className="rounded p-0.5 opacity-0 group-hover:opacity-100 hover:bg-muted hover:text-foreground focus:opacity-100 focus:outline-none focus-visible:ring-1 focus-visible:ring-ring text-muted-foreground"
                            onClick={(e) => closeTab(path, e)}
                            onDragStart={(e) => e.stopPropagation()}
                            aria-label={`${t('Close')} ${name}`}
                          >
                            <X className="h-3 w-3" />
                          </button>
                        )}
                      </div>
                    )
                  })}
              </div>
            </div>
            {/* Find / Find+replace – only when opened via shortcut or top bar buttons */}
            {findPanelMode !== false && (
              <div className="flex h-[41px] shrink-0 items-center border-b border-border bg-muted/30 px-2 gap-2">
                <div className="flex min-w-0 flex-1 items-center gap-2 sm:min-w-[160px]">
                  <label className="shrink-0 text-[11px] font-medium text-muted-foreground">
                    {t('Find')}
                  </label>
                  <Input
                    ref={findInputRef}
                    type="search"
                    placeholder={`${t('Search in file')} (${shortcutFind})`}
                    value={findInFileQuery}
                    onChange={(e) => setFindInFileQuery(e.target.value)}
                    className="h-7 min-w-0 flex-1 max-w-[180px] text-[12px]"
                    aria-label={t('Find in file')}
                  />
                </div>
                {findPanelMode === 'replace' && (
                  <div className="flex min-w-0 flex-1 items-center gap-2 sm:min-w-[160px]">
                    <label className="shrink-0 text-[11px] font-medium text-muted-foreground">
                      {t('Replace')}
                    </label>
                    <Input
                      type="text"
                      placeholder={t('Replace with')}
                      value={replaceValue}
                      onChange={(e) => setReplaceValue(e.target.value)}
                      className="h-7 min-w-0 flex-1 max-w-[180px] text-[12px]"
                      aria-label={t('Replace with')}
                    />
                  </div>
                )}
                <div className="flex shrink-0 items-center gap-0.5">
                  {findMatches.length > 0 && (
                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                      {findMatchIndex + 1}/{findMatches.length}
                    </span>
                  )}
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 shrink-0"
                    onClick={() => goToFindMatch(-1)}
                    disabled={findMatches.length === 0}
                    aria-label={t('Previous match')}
                  >
                    <ChevronUp className="h-3.5 w-3.5" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 shrink-0"
                    onClick={() => goToFindMatch(1)}
                    disabled={findMatches.length === 0}
                    aria-label={t('Next match')}
                  >
                    <ChevronDown className="h-3.5 w-3.5" />
                  </Button>
                  {findPanelMode === 'replace' && (
                    <>
                      <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="h-7 shrink-0 text-[11px] px-2"
                        onClick={replaceCurrent}
                        disabled={findMatches.length === 0}
                      >
                        {t('Replace')}
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="h-7 shrink-0 text-[11px] px-2"
                        onClick={replaceAll}
                        disabled={findMatches.length === 0}
                      >
                        {t('All')}
                      </Button>
                    </>
                  )}
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 shrink-0"
                    onClick={() => {
                      setFindPanelMode(false)
                      setFindInFileQuery('')
                      setReplaceValue('')
                      setFindMatches([])
                      setFindMatchIndex(0)
                      const editor = editorRef.current
                      if (editor) {
                        editor.deltaDecorations(
                          findDecorationIdsRef.current,
                          [],
                        )
                        findDecorationIdsRef.current = []
                      }
                    }}
                    aria-label={t('Close find')}
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
            )}
            <div className="min-h-0 flex-1">
              <Editor
                key={`${activeFile}-${isDark}`}
                height="100%"
                defaultLanguage={activeLang}
                language={activeLang}
                value={activeContent}
                onChange={handleEditorChange}
                onMount={handleEditorMount}
                theme={isDark ? MONACO_THEME_DARK : MONACO_THEME_LIGHT}
                beforeMount={defineAppThemes}
                options={{
                  minimap: { enabled: true },
                  fontSize: 13,
                  lineNumbers: 'on',
                  scrollBeyondLastLine: false,
                  wordWrap: 'on',
                  padding: { top: 16 },
                }}
                loading={
                  <div className="flex h-full items-center justify-center bg-background text-muted-foreground">
                    {t('Loading editor...')}
                  </div>
                }
                className={cn('rounded-b-lg')}
              />
            </div>
            <div className="flex shrink-0 items-center justify-between gap-4 border-t border-border bg-muted/20 px-3 py-1.5 text-[11px] text-muted-foreground">
              <div className="flex min-w-0 items-center gap-3">
                <span className="truncate font-mono" title={activeFile}>
                  {activeFile}
                </span>
                <span className="shrink-0 capitalize">{activeLang}</span>
                {dirtyFiles.has(activeFile) && (
                  <span className="shrink-0 text-amber-600 dark:text-amber-500">
                    {t('Unsaved')}
                  </span>
                )}
              </div>
              <div className="flex shrink-0 items-center gap-3 tabular-nums">
                {cursorPosition && (
                  <span>
                    {t('Ln')} {cursorPosition.lineNumber}, {t('Col')}{' '}
                    {cursorPosition.column}
                  </span>
                )}
                <span>
                  {lineCount} {t('lines')}
                </span>
                <span>
                  {activeContent.length} {t('chars')}
                </span>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Add file dialog */}
      <Dialog open={addFileOpen} onOpenChange={setAddFileOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Add file')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Enter the file path (e.g. src/utils.js or lib/helper.ts).')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <Label htmlFor="new-file-path" className="text-[13px]">
              {t('File path')}
            </Label>
            <Input
              id="new-file-path"
              value={newFilePath}
              onChange={(e) => setNewFilePath(e.target.value)}
              placeholder="src/utils.js"
              className="mt-2 text-[13px] font-mono"
              onKeyDown={(e) => e.key === 'Enter' && addFile()}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setAddFileOpen(false)}>
              {t('Cancel')}
            </Button>
            <Button onClick={addFile} disabled={!newFilePath.trim()}>
              {t('Add file')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete file confirm */}
      <Dialog
        open={deleteConfirmPath !== null}
        onOpenChange={(open) => !open && setDeleteConfirmPath(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove file')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Remove')} &quot;{deleteConfirmPath}&quot;{' '}
              {t('from the project? This cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteConfirmPath(null)}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteConfirmPath && deleteFile(deleteConfirmPath)}
            >
              {t('Remove')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
