import { useCallback, useEffect, useRef } from 'react'
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

type PostgresSqlCodeEditorProps = {
  projectId: string
  databaseId: string
  tabId: string
  sql: string
  onSqlChange: (value: string) => void
  onRun?: () => void
  canRun?: boolean
}

let completionDisposable: IDisposable | null = null
let getCompletionCatalog: () => PostgresSqlCompletionCatalog = () => ({
  schemas: [],
  tables: [],
  columns: [],
})

export function PostgresSqlCodeEditor({
  projectId,
  databaseId,
  tabId,
  sql,
  onSqlChange,
  onRun,
  canRun = false,
}: PostgresSqlCodeEditorProps) {
  const { schemas } = usePostgresSchemas(projectId, databaseId)
  const { tables } = usePostgresTables(projectId, databaseId)
  const { columns } = usePostgresColumns(projectId, databaseId)

  const onRunRef = useRef(onRun)
  const canRunRef = useRef(canRun)
  const sqlRef = useRef(sql)
  const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
  const keyDownDisposeRef = useRef<IDisposable | null>(null)
  const isApplyingExternalSqlRef = useRef(false)

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
      editorRef.current = null
    }
  }, [])

  const handleEditorMount = useCallback(
    (
      editorInstance: editor.IStandaloneCodeEditor,
      monaco: typeof import('monaco-editor'),
    ) => {
      editorRef.current = editorInstance

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
    },
    [databaseId, projectId],
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
          key={`postgres-sql-${projectId}-${databaseId}-${tabId}`}
          value={sql}
          onChange={handleSqlChange}
          language="sql"
          height="100%"
          modelPath={`postgres-sql/${projectId}/${databaseId}/${tabId}`}
          className="h-full rounded-none border-0"
          onEditorMount={handleEditorMount}
        />
      </div>
    </div>
  )
}
