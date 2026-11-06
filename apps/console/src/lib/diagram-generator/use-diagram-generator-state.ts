import { useCallback, useEffect, useRef, useState } from 'react'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import type {
  DiagramAnchorSide,
  DiagramConnectDraft,
  DiagramDocument,
  DiagramEdge,
  DiagramNode,
  DiagramNodeKind,
  DiagramSelection,
  DiagramTemplateId,
} from '@/lib/diagram-generator/types'
import {
  createDiagramEdge,
  createDiagramFromTemplate,
  createDiagramNode,
  createDefaultDiagramDocument,
  getDiagramNodeCenterPlacement,
  snapDiagramValue,
} from '@/lib/diagram-generator/templates'
import {
  normalizeDiagramDocument,
} from '@/lib/diagram-generator/storage'
import { DIAGRAM_NODE_DEFAULTS } from '@/lib/diagram-generator/constants'
import { clampDiagramNodeSize } from '@/lib/diagram-generator/node-size'
import { normalizeDiagramNode } from '@/lib/diagram-generator/node-normalize'
import { reorderDiagramNodesInDisplayOrder } from '@/lib/diagram-generator/node-layers'
import { normalizeDiagramEdge } from '@/lib/diagram-generator/edge-appearance'
import { selectDiagramNode, sanitizeDiagramSelection, selectDiagramNodes } from '@/lib/diagram-generator/selection'
import {
  buildDiagramClipboardPayload,
  pasteDiagramClipboardPayload,
  type DiagramClipboardPayload,
} from '@/lib/diagram-generator/diagram-clipboard'
import { useDiagramDocumentHistory } from '@/lib/diagram-generator/use-diagram-document-history'

const PERSIST_DEBOUNCE_MS = 400

type UseDiagramGeneratorStateOptions = {
  onDocumentPersist?: (document: DiagramDocument) => void
}

export function useDiagramGeneratorState(options: UseDiagramGeneratorStateOptions = {}) {
  const onDocumentPersistRef = useRef(options.onDocumentPersist)
  onDocumentPersistRef.current = options.onDocumentPersist

  const [document, setDocumentState] = useState<DiagramDocument>(() =>
    createDefaultDiagramDocument(),
  )
  const [selection, setSelection] = useState<DiagramSelection>({ type: 'none' })
  const [connectDraft, setConnectDraft] = useState<DiagramConnectDraft | null>(null)
  const persistTimerRef = useRef<number | null>(null)
  /** Skip the persist scheduled by initial mount / loadDocument. */
  const suppressNextPersistRef = useRef(true)
  const hasUserEditsRef = useRef(false)
  const clipboardRef = useRef<DiagramClipboardPayload | null>(null)
  const pasteGenerationRef = useRef(0)

  const {
    canUndo,
    canRedo,
    recordUndoPoint,
    beginHistoryGroup,
    commitHistoryGroup,
    scheduleCoalescedCommit,
    cancelCoalescedCommit,
    undo: undoDocument,
    redo: redoDocument,
    clearHistory,
    finishApplyingHistory,
  } = useDiagramDocumentHistory(document)

  useEffect(() => {
    if (!onDocumentPersistRef.current) return

    if (suppressNextPersistRef.current) {
      suppressNextPersistRef.current = false
      return
    }

    hasUserEditsRef.current = true

    if (persistTimerRef.current) {
      window.clearTimeout(persistTimerRef.current)
    }

    persistTimerRef.current = window.setTimeout(() => {
      onDocumentPersistRef.current?.(document)
    }, PERSIST_DEBOUNCE_MS)

    return () => {
      if (persistTimerRef.current) {
        window.clearTimeout(persistTimerRef.current)
      }
    }
  }, [document])

  const updateDocument = useCallback(
    (patch: Partial<DiagramDocument>) => {
      beginHistoryGroup()
      setDocumentState((current) =>
        normalizeDiagramDocument({ ...current, ...patch }),
      )
      scheduleCoalescedCommit()
    },
    [beginHistoryGroup, scheduleCoalescedCommit],
  )

  const applyDocument = useCallback((next: DiagramDocument) => {
    setDocumentState(normalizeDiagramDocument(next))
    finishApplyingHistory()
  }, [finishApplyingHistory])

  const undo = useCallback(() => {
    cancelCoalescedCommit()
    const previous = undoDocument()
    if (!previous) return
    applyDocument(previous)
    setSelection((current) => sanitizeDiagramSelection(current, previous))
    setConnectDraft(null)
  }, [applyDocument, cancelCoalescedCommit, undoDocument])

  const redo = useCallback(() => {
    cancelCoalescedCommit()
    const next = redoDocument()
    if (!next) return
    applyDocument(next)
    setSelection((current) => sanitizeDiagramSelection(current, next))
    setConnectDraft(null)
  }, [applyDocument, cancelCoalescedCommit, redoDocument])

  const cancelConnectDraft = useCallback(() => {
    setConnectDraft(null)
  }, [])

  const applyTemplate = useCallback((templateId: DiagramTemplateId) => {
    recordUndoPoint()
    setDocumentState((current) =>
      normalizeDiagramDocument({
        ...createDiagramFromTemplate(templateId),
        theme: current.theme,
      }),
    )
    setSelection({ type: 'none' })
    setConnectDraft(null)
    clipboardRef.current = null
    pasteGenerationRef.current = 0
    clearHistory()
  }, [clearHistory, recordUndoPoint])

  const addNode = useCallback(
    (
      kind: DiagramNodeKind,
      overrides?: Partial<Pick<DiagramNode, 'label' | 'subtitle' | 'iconSrc'>>,
    ) => {
      recordUndoPoint()
      setDocumentState((current) => {
        const defaults = DIAGRAM_NODE_DEFAULTS[kind]
        const position = getDiagramNodeCenterPlacement(
          current,
          defaults,
          current.nodes.length,
        )
        const node = createDiagramNode(kind, position, overrides)

        return {
          ...current,
          nodes: [...current.nodes, node],
        }
      })
    },
    [recordUndoPoint],
  )

  const updateNode = useCallback((nodeId: string, patch: Partial<DiagramNode>) => {
    beginHistoryGroup()
    setDocumentState((current) => ({
      ...current,
      nodes: current.nodes.map((node) => {
        if (node.id !== nodeId) return node
        const next = normalizeDiagramNode({ ...node, ...patch, id: node.id, kind: node.kind })
        if (patch.width !== undefined || patch.height !== undefined) {
          const size = clampDiagramNodeSize(next.kind, next.width, next.height)
          return { ...next, ...size }
        }
        return next
      }),
    }))
    scheduleCoalescedCommit()
  }, [beginHistoryGroup, scheduleCoalescedCommit])

  const moveNode = useCallback((nodeId: string, x: number, y: number) => {
    setDocumentState((current) => ({
      ...current,
      nodes: current.nodes.map((node) =>
        node.id === nodeId
          ? {
              ...node,
              x: snapDiagramValue(x),
              y: snapDiagramValue(y),
            }
          : node,
      ),
    }))
  }, [])

  const moveNodes = useCallback(
    (updates: Array<{ id: string; x: number; y: number }>) => {
      if (updates.length === 0) return

      const updateMap = new Map(updates.map((update) => [update.id, update]))

      setDocumentState((current) => ({
        ...current,
        nodes: current.nodes.map((node) => {
          const update = updateMap.get(node.id)
          if (!update) return node

          return {
            ...node,
            x: snapDiagramValue(update.x),
            y: snapDiagramValue(update.y),
          }
        }),
      }))
    },
    [],
  )

  const resizeNode = useCallback((nodeId: string, width: number, height: number) => {
    setDocumentState((current) => ({
      ...current,
      nodes: current.nodes.map((node) => {
        if (node.id !== nodeId) return node
        const size = clampDiagramNodeSize(node.kind, width, height)
        return { ...node, ...size }
      }),
    }))
  }, [])

  const removeNode = useCallback((nodeId: string) => {
    recordUndoPoint()
    setDocumentState((current) => ({
      ...current,
      nodes: current.nodes.filter((node) => node.id !== nodeId),
      edges: current.edges.filter(
        (edge) => edge.fromNodeId !== nodeId && edge.toNodeId !== nodeId,
      ),
    }))
    setSelection((current) => {
      if (current.type === 'node' && current.id === nodeId) {
        return { type: 'none' }
      }
      if (current.type === 'nodes') {
        const ids = current.ids.filter((id) => id !== nodeId)
        if (ids.length === 0) return { type: 'none' }
        if (ids.length === 1) return { type: 'node', id: ids[0] }
        return { type: 'nodes', ids }
      }
      return current
    })
    setConnectDraft((current) => (current?.nodeId === nodeId ? null : current))
  }, [recordUndoPoint])

  const removeNodes = useCallback((nodeIds: string[]) => {
    if (nodeIds.length === 0) return

    recordUndoPoint()
    const removeSet = new Set(nodeIds)
    setDocumentState((current) => ({
      ...current,
      nodes: current.nodes.filter((node) => !removeSet.has(node.id)),
      edges: current.edges.filter(
        (edge) => !removeSet.has(edge.fromNodeId) && !removeSet.has(edge.toNodeId),
      ),
    }))
    setSelection({ type: 'none' })
    setConnectDraft((current) =>
      current && removeSet.has(current.nodeId) ? null : current,
    )
  }, [recordUndoPoint])

  const addEdgeBetweenPorts = useCallback(
    (
      fromNodeId: string,
      fromSide: DiagramAnchorSide,
      toNodeId: string,
      toSide: DiagramAnchorSide,
    ) => {
      if (fromNodeId === toNodeId) return null

      recordUndoPoint()
      const createdEdgeRef: { current: DiagramEdge | null } = { current: null }

      setDocumentState((current) => {
        const fromNode = current.nodes.find((node) => node.id === fromNodeId)
        const toNode = current.nodes.find((node) => node.id === toNodeId)
        if (!fromNode || !toNode) return current

        const exists = current.edges.some(
          (edge) =>
            edge.fromNodeId === fromNodeId &&
            edge.toNodeId === toNodeId &&
            edge.fromSide === fromSide &&
            edge.toSide === toSide,
        )
        if (exists) return current

        const edge = createDiagramEdge(fromNode, toNode, { fromSide, toSide })
        createdEdgeRef.current = edge
        return {
          ...current,
          edges: [...current.edges, edge],
        }
      })

      return createdEdgeRef.current
    },
    [recordUndoPoint],
  )

  const updateEdge = useCallback((edgeId: string, patch: Partial<DiagramEdge>) => {
    beginHistoryGroup()
    setDocumentState((current) => ({
      ...current,
      edges: current.edges.map((edge) =>
        edge.id === edgeId
          ? normalizeDiagramEdge({ ...edge, ...patch, id: edge.id })
          : edge,
      ),
    }))
    scheduleCoalescedCommit()
  }, [beginHistoryGroup, scheduleCoalescedCommit])

  const removeEdge = useCallback((edgeId: string) => {
    recordUndoPoint()
    setDocumentState((current) => ({
      ...current,
      edges: current.edges.filter((edge) => edge.id !== edgeId),
    }))
    setSelection((current) =>
      current.type === 'edge' && current.id === edgeId ? { type: 'none' } : current,
    )
  }, [recordUndoPoint])

  const copySelection = useCallback(() => {
    const payload = buildDiagramClipboardPayload(document, selection)
    if (!payload) return false

    clipboardRef.current = payload
    pasteGenerationRef.current = 0
    return true
  }, [document, selection])

  const pasteClipboard = useCallback(() => {
    const payload = clipboardRef.current
    if (!payload || payload.nodes.length === 0) return false

    pasteGenerationRef.current += 1
    const pasted = pasteDiagramClipboardPayload(payload, pasteGenerationRef.current)

    recordUndoPoint()
    setDocumentState((current) => ({
      ...current,
      nodes: [...current.nodes, ...pasted.nodes],
      edges: [...current.edges, ...pasted.edges],
    }))
    setSelection(selectDiagramNodes(pasted.selectedNodeIds))
    setConnectDraft(null)
    return true
  }, [recordUndoPoint])

  const cutSelection = useCallback(() => {
    const nodeIds = buildDiagramClipboardPayload(document, selection)?.nodes.map(
      (node) => node.id,
    )
    if (!nodeIds?.length) return false
    if (!copySelection()) return false

    removeNodes(nodeIds)
    return true
  }, [copySelection, document, removeNodes, selection])

  const handleNodeClick = useCallback(
    (
      nodeId: string,
      modifiers?: { shiftKey?: boolean; metaKey?: boolean; ctrlKey?: boolean },
    ) => {
      if (modifiers?.shiftKey || modifiers?.metaKey || modifiers?.ctrlKey) {
        setSelection((current) =>
          selectDiagramNode(current, nodeId, {
            additive: modifiers.shiftKey,
            toggle: modifiers.metaKey || modifiers.ctrlKey,
          }),
        )
        return
      }

      setSelection({ type: 'node', id: nodeId })
    },
    [],
  )

  const handlePortClick = useCallback(
    (nodeId: string, side: DiagramAnchorSide) => {
      if (!connectDraft) {
        setConnectDraft({ nodeId, side })
        setSelection({ type: 'node', id: nodeId })
        return
      }

      if (connectDraft.nodeId === nodeId && connectDraft.side === side) {
        setConnectDraft(null)
        return
      }

      const edge = addEdgeBetweenPorts(
        connectDraft.nodeId,
        connectDraft.side,
        nodeId,
        side,
      )
      setConnectDraft(null)
      if (edge) {
        setSelection({ type: 'edge', id: edge.id })
      } else {
        setSelection({ type: 'node', id: nodeId })
      }
    },
    [addEdgeBetweenPorts, connectDraft],
  )

  const completePortConnect = useCallback(
    (
      fromNodeId: string,
      fromSide: DiagramAnchorSide,
      toNodeId: string,
      toSide: DiagramAnchorSide,
    ) => {
      if (fromNodeId === toNodeId && fromSide === toSide) {
        setConnectDraft(null)
        return
      }

      const edge = addEdgeBetweenPorts(fromNodeId, fromSide, toNodeId, toSide)
      setConnectDraft(null)
      if (edge) {
        setSelection({ type: 'edge', id: edge.id })
      } else {
        setSelection({ type: 'node', id: toNodeId })
      }
    },
    [addEdgeBetweenPorts],
  )

  const reorderNodes = useCallback((activeId: string, overId: string) => {
    recordUndoPoint()
    setDocumentState((current) => ({
      ...current,
      nodes: reorderDiagramNodesInDisplayOrder(
        current.nodes,
        activeId,
        overId,
      ),
    }))
  }, [recordUndoPoint])

  const loadDocument = useCallback((next: DiagramDocument) => {
    cancelCoalescedCommit()
    if (persistTimerRef.current) {
      window.clearTimeout(persistTimerRef.current)
      persistTimerRef.current = null
    }
    suppressNextPersistRef.current = true
    hasUserEditsRef.current = false
    setDocumentState(normalizeDiagramDocument(next))
    setSelection({ type: 'none' })
    setConnectDraft(null)
    clipboardRef.current = null
    pasteGenerationRef.current = 0
    clearHistory()
    finishApplyingHistory()
  }, [cancelCoalescedCommit, clearHistory, finishApplyingHistory])

  const flushPersist = useCallback(() => {
    if (!hasUserEditsRef.current) {
      if (persistTimerRef.current) {
        window.clearTimeout(persistTimerRef.current)
        persistTimerRef.current = null
      }
      return
    }
    if (persistTimerRef.current) {
      window.clearTimeout(persistTimerRef.current)
      persistTimerRef.current = null
    }
    onDocumentPersistRef.current?.(document)
  }, [document])

  const isDirty = useCallback(() => hasUserEditsRef.current, [])

  const resetDocument = useCallback(() => {
    recordUndoPoint()
    setDocumentState(createDefaultDiagramDocument())
    setSelection({ type: 'none' })
    setConnectDraft(null)
    clipboardRef.current = null
    pasteGenerationRef.current = 0
    clearHistory()
  }, [clearHistory, recordUndoPoint])

  const setTheme = useCallback((theme: CoverEditorThemeId) => {
    updateDocument({ theme })
  }, [updateDocument])

  const setFormat = useCallback((format: CoverImageFormat) => {
    setDocumentState((current) => ({ ...current, format }))
  }, [])

  const setCanvasSize = useCallback((width: number, height: number) => {
    updateDocument({ width, height })
  }, [updateDocument])

  return {
    document,
    selection,
    connectDraft,
    canUndo,
    canRedo,
    undo,
    redo,
    loadDocument,
    flushPersist,
    isDirty,
    setDocument: updateDocument,
    setSelection,
    cancelConnectDraft,
    beginDocumentGesture: beginHistoryGroup,
    commitDocumentGesture: commitHistoryGroup,
    applyTemplate,
    addNode,
    updateNode,
    moveNode,
    moveNodes,
    resizeNode,
    removeNode,
    removeNodes,
    reorderNodes,
    updateEdge,
    removeEdge,
    copySelection,
    pasteClipboard,
    cutSelection,
    handleNodeClick,
    handlePortClick,
    completePortConnect,
    resetDocument,
    setTheme,
    setFormat,
    setCanvasSize,
  }
}
