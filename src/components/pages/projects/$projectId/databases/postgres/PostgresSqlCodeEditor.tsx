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
  sql: string
  onSqlChange: (value: string) => void
  onRun?: () => void
  canRun?: boolean
}

export function PostgresSqlCodeEditor({
  projectId,
  databaseId,
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
  const catalogRef = useRef<PostgresSqlCompletionCatalog>({
    schemas: [],
    tables: [],
    columns: [],
  })
  const keyDownDisposeRef = useRef<IDisposable | null>(null)
  const completionDisposeRef = useRef<IDisposable | null>(null)

  useEffect(() => {
    onRunRef.current = onRun
    canRunRef.current = canRun
  }, [onRun, canRun])

  useEffect(() => {
    catalogRef.current = { schemas, tables, columns }
  }, [schemas, tables, columns])

  useEffect(() => {
    return () => {
      keyDownDisposeRef.current?.dispose()
      keyDownDisposeRef.current = null
      completionDisposeRef.current?.dispose()
      completionDisposeRef.current = null
    }
  }, [])

  const handleEditorMount = useCallback(
    (
      editorInstance: editor.IStandaloneCodeEditor,
      monaco: typeof import('monaco-editor'),
    ) => {
      completionDisposeRef.current?.dispose()
      completionDisposeRef.current = registerPostgresSqlCompletionProvider(
        monaco,
        () => catalogRef.current,
      )

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

      if (!onRun) return

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
    },
    [databaseId, onRun, projectId],
  )

  return (
    <div className="relative min-h-0 flex-1" data-postgres-sql-editor>
      <div className="absolute inset-0 overflow-hidden">
        <CodeEditor
          value={sql}
          onChange={onSqlChange}
          language="sql"
          height="100%"
          modelPath={`postgres-sql/${projectId}/${databaseId}`}
          className="h-full rounded-none border-0"
          onEditorMount={handleEditorMount}
        />
      </div>
    </div>
  )
}
