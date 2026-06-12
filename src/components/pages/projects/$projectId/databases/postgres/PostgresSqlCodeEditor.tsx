import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
} from 'react'
import type { editor, IDisposable } from 'monaco-editor'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import {
  usePostgresColumns,
  usePostgresSchemas,
  usePostgresTables,
} from '@/lib/react-query/hooks'
import {
  registerPostgresSqlCompletionProvider,
  type PostgresSqlCompletionCatalog,
} from '@/lib/postgres-sql-completion'
import { cn } from '@/lib/utils'
import { POSTGRES_SQL_EDITOR_SURFACE_CLASS } from './_components/postgres-chrome'

type PostgresSqlCodeEditorProps = {
  projectId: string
  databaseId: string
  tabId: string
  sql: string
  onSqlChange: (value: string) => void
  onRun?: () => void
  canRun?: boolean
  onUndoRedoStateChange?: (state: {
    canUndo: boolean
    canRedo: boolean
  }) => void
}

export type PostgresSqlCodeEditorRef = {
  undo: () => void
  redo: () => void
}

let completionDisposable: IDisposable | null = null
let getCompletionCatalog: () => PostgresSqlCompletionCatalog = () => ({
  schemas: [],
  tables: [],
  columns: [],
})

function focusEditorInstance(editorInstance: editor.IStandaloneCodeEditor) {
  requestAnimationFrame(() => {
    const model = editorInstance.getModel()
    if (model) {
      editorInstance.setPosition(model.getFullModelRange().getEndPosition())
    }
    editorInstance.focus()
  })
}

export const PostgresSqlCodeEditor = forwardRef<
  PostgresSqlCodeEditorRef,
  PostgresSqlCodeEditorProps
>(function PostgresSqlCodeEditor(
  {
    projectId,
    databaseId,
    tabId,
    sql,
    onSqlChange,
    onRun,
    canRun = false,
    onUndoRedoStateChange,
  },
  ref,
) {
  const { schemas } = usePostgresSchemas(projectId, databaseId)
  const { tables } = usePostgresTables(projectId, databaseId)
  const { columns } = usePostgresColumns(projectId, databaseId)

  const onRunRef = useRef(onRun)
  const canRunRef = useRef(canRun)
  const sqlRef = useRef(sql)
  const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
  const keyDownDisposeRef = useRef<IDisposable | null>(null)
  const undoRedoDisposeRef = useRef<IDisposable | null>(null)
  const isApplyingExternalSqlRef = useRef(false)
  const onUndoRedoStateChangeRef = useRef(onUndoRedoStateChange)

  useEffect(() => {
    onUndoRedoStateChangeRef.current = onUndoRedoStateChange
  }, [onUndoRedoStateChange])

  useImperativeHandle(ref, () => ({
    undo: () => {
      const model = editorRef.current?.getModel()
      if (!model?.canUndo()) return
      void model.undo()
    },
    redo: () => {
      const model = editorRef.current?.getModel()
      if (!model?.canRedo()) return
      void model.redo()
    },
  }))

  useEffect(() => {
    onRunRef.current = onRun
    canRunRef.current = canRun
  }, [onRun, canRun])

  useEffect(() => {
    sqlRef.current = sql
  }, [sql])

  useEffect(() => {
    getCompletionCatalog = () => ({ schemas, tables, columns })
  }, [schemas, tables, columns])

  useEffect(() => {
    const editorInstance = editorRef.current
    const model = editorInstance?.getModel()
    if (!editorInstance || !model || model.getValue() === sql) return

    isApplyingExternalSqlRef.current = true
    editorInstance.pushUndoStop()
    editorInstance.executeEdits('table-switch', [
      {
        range: model.getFullModelRange(),
        text: sql,
        forceMoveMarkers: true,
      },
    ])
    editorInstance.pushUndoStop()
    isApplyingExternalSqlRef.current = false
  }, [sql])

  useEffect(() => {
    return () => {
      keyDownDisposeRef.current?.dispose()
      keyDownDisposeRef.current = null
      undoRedoDisposeRef.current?.()
      undoRedoDisposeRef.current = null
      editorRef.current = null
    }
  }, [])

  const attachUndoRedoListeners = useCallback(
    (editorInstance: editor.IStandaloneCodeEditor) => {
      undoRedoDisposeRef.current?.()
      undoRedoDisposeRef.current = null

      const refreshUndoRedoState = () => {
        queueMicrotask(() => {
          const model = editorInstance.getModel()
          onUndoRedoStateChangeRef.current?.({
            canUndo: model?.canUndo() ?? false,
            canRedo: model?.canRedo() ?? false,
          })
        })
      }

      refreshUndoRedoState()
      const contentDispose = editorInstance.onDidChangeModelContent(refreshUndoRedoState)
      const modelDispose = editorInstance.onDidChangeModel(refreshUndoRedoState)

      undoRedoDisposeRef.current = () => {
        contentDispose.dispose()
        modelDispose.dispose()
        undoRedoDisposeRef.current = null
      }
    },
    [],
  )

  useEffect(() => {
    const editorInstance = editorRef.current
    if (!editorInstance) return

    const frame = requestAnimationFrame(() => {
      attachUndoRedoListeners(editorInstance)
      focusEditorInstance(editorInstance)
    })
    return () => cancelAnimationFrame(frame)
  }, [attachUndoRedoListeners, tabId])

  const handleEditorMount = useCallback(
    (
      editorInstance: editor.IStandaloneCodeEditor,
      monaco: typeof import('monaco-editor'),
    ) => {
      editorRef.current = editorInstance
      attachUndoRedoListeners(editorInstance)

      if (!completionDisposable) {
        completionDisposable = registerPostgresSqlCompletionProvider(
          monaco,
          () => getCompletionCatalog(),
        )
      }

      editorInstance.updateOptions({
        quickSuggestions: {
          other: true,
          comments: false,
          strings: false,
        },
        suggestOnTriggerCharacters: true,
        wordBasedSuggestions: 'off',
      })

      keyDownDisposeRef.current?.dispose()
      keyDownDisposeRef.current = null

      if (!onRunRef.current) return

      editorInstance.addAction({
        id: `postgres-run-query-${projectId}-${databaseId}`,
        label: 'Run query',
        keybindings: [monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter],
        run: () => {
          if (canRunRef.current) {
            onRunRef.current?.()
          }
        },
      })

      keyDownDisposeRef.current = editorInstance.onKeyDown((e) => {
        const isRunKey =
          e.keyCode === monaco.KeyCode.Enter &&
          (e.ctrlKey || e.metaKey) &&
          !e.shiftKey &&
          !e.altKey

        if (!isRunKey || !canRunRef.current) return

        e.preventDefault()
        e.stopPropagation()
        onRunRef.current?.()
      })

      const model = editorInstance.getModel()
      if (model && model.getValue() !== sqlRef.current) {
        isApplyingExternalSqlRef.current = true
        editorInstance.pushUndoStop()
        editorInstance.executeEdits('initial-sql', [
          {
            range: model.getFullModelRange(),
            text: sqlRef.current,
            forceMoveMarkers: true,
          },
        ])
        editorInstance.pushUndoStop()
        isApplyingExternalSqlRef.current = false
      }

      focusEditorInstance(editorInstance)
    },
    [attachUndoRedoListeners, databaseId, projectId],
  )

  const handleSqlChange = useCallback(
    (value: string) => {
      if (isApplyingExternalSqlRef.current) return
      onSqlChange(value)
    },
    [onSqlChange],
  )

  return (
    <div
      className="relative h-full min-h-0 flex-1 overflow-hidden"
      data-postgres-sql-editor
    >
      <div className="absolute inset-0 overflow-hidden">
        <CodeEditor
          value={sql}
          onChange={handleSqlChange}
          language="sql"
          height="100%"
          modelPath={`postgres-sql/${projectId}/${databaseId}/${tabId}`}
          className={cn(
            'h-full rounded-none border-0',
            POSTGRES_SQL_EDITOR_SURFACE_CLASS,
          )}
          onEditorMount={handleEditorMount}
        />
      </div>
    </div>
  )
})
