import { useCallback, useMemo, useRef, useState } from 'react'
import {
  cloneDiagramDocument,
  diagramDocumentsEqual,
  DIAGRAM_HISTORY_LIMIT,
} from '@/lib/diagram-generator/document-history'
import type { DiagramDocument } from '@/lib/diagram-generator/types'

export function useDiagramDocumentHistory(document: DiagramDocument) {
  const documentRef = useRef(document)
  documentRef.current = document

  const undoStackRef = useRef<DiagramDocument[]>([])
  const redoStackRef = useRef<DiagramDocument[]>([])
  const groupSnapshotRef = useRef<DiagramDocument | null>(null)
  const isApplyingHistoryRef = useRef(false)
  const coalescedCommitTimerRef = useRef<number | null>(null)
  const [historyTick, setHistoryTick] = useState(0)

  const bumpHistory = useCallback(() => {
    setHistoryTick((tick) => tick + 1)
  }, [])

  const pushUndo = useCallback(
    (snapshot: DiagramDocument) => {
      undoStackRef.current = [
        ...undoStackRef.current.slice(-(DIAGRAM_HISTORY_LIMIT - 1)),
        cloneDiagramDocument(snapshot),
      ]
      redoStackRef.current = []
      bumpHistory()
    },
    [bumpHistory],
  )

  const recordUndoPoint = useCallback(() => {
    if (isApplyingHistoryRef.current) return
    pushUndo(documentRef.current)
  }, [pushUndo])

  const beginHistoryGroup = useCallback(() => {
    if (groupSnapshotRef.current || isApplyingHistoryRef.current) return
    groupSnapshotRef.current = cloneDiagramDocument(documentRef.current)
  }, [])

  const commitHistoryGroup = useCallback(() => {
    const snapshot = groupSnapshotRef.current
    groupSnapshotRef.current = null
    if (!snapshot || isApplyingHistoryRef.current) return
    if (diagramDocumentsEqual(snapshot, documentRef.current)) return
    pushUndo(snapshot)
  }, [pushUndo])

  const cancelHistoryGroup = useCallback(() => {
    groupSnapshotRef.current = null
  }, [])

  const scheduleCoalescedCommit = useCallback(
    (delayMs = 500) => {
      if (coalescedCommitTimerRef.current) {
        window.clearTimeout(coalescedCommitTimerRef.current)
      }

      coalescedCommitTimerRef.current = window.setTimeout(() => {
        coalescedCommitTimerRef.current = null
        commitHistoryGroup()
      }, delayMs)
    },
    [commitHistoryGroup],
  )

  const cancelCoalescedCommit = useCallback(() => {
    if (coalescedCommitTimerRef.current) {
      window.clearTimeout(coalescedCommitTimerRef.current)
      coalescedCommitTimerRef.current = null
    }
    cancelHistoryGroup()
  }, [cancelHistoryGroup])

  const undo = useCallback((): DiagramDocument | null => {
    const stack = undoStackRef.current
    if (stack.length === 0) return null

    const previous = cloneDiagramDocument(stack[stack.length - 1]!)
    undoStackRef.current = stack.slice(0, -1)
    redoStackRef.current = [
      ...redoStackRef.current,
      cloneDiagramDocument(documentRef.current),
    ]
    isApplyingHistoryRef.current = true
    bumpHistory()
    return previous
  }, [bumpHistory])

  const redo = useCallback((): DiagramDocument | null => {
    const stack = redoStackRef.current
    if (stack.length === 0) return null

    const next = cloneDiagramDocument(stack[stack.length - 1]!)
    redoStackRef.current = stack.slice(0, -1)
    undoStackRef.current = [
      ...undoStackRef.current,
      cloneDiagramDocument(documentRef.current),
    ]
    isApplyingHistoryRef.current = true
    bumpHistory()
    return next
  }, [bumpHistory])

  const clearHistory = useCallback(() => {
    undoStackRef.current = []
    redoStackRef.current = []
    groupSnapshotRef.current = null
    if (coalescedCommitTimerRef.current) {
      window.clearTimeout(coalescedCommitTimerRef.current)
      coalescedCommitTimerRef.current = null
    }
    bumpHistory()
  }, [bumpHistory])

  const finishApplyingHistory = useCallback(() => {
    isApplyingHistoryRef.current = false
  }, [])

  const canUndo = useMemo(
    () => undoStackRef.current.length > 0,
    [historyTick],
  )
  const canRedo = useMemo(
    () => redoStackRef.current.length > 0,
    [historyTick],
  )

  return {
    canUndo,
    canRedo,
    recordUndoPoint,
    beginHistoryGroup,
    commitHistoryGroup,
    cancelHistoryGroup,
    scheduleCoalescedCommit,
    cancelCoalescedCommit,
    undo,
    redo,
    clearHistory,
    finishApplyingHistory,
  }
}
