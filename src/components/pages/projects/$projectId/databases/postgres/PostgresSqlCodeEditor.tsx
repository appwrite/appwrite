import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
} from 'react'
import type { editor, IDisposable } from 'monaco-editor'
import { useQueryClient } from '@tanstack/react-query'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import {
  fetchPostgresSchemasPage,
  fetchPostgresTableAutocompleteColumns,
  fetchPostgresTablesPage,
  postgresTableAutocompleteColumnsQueryOptions,
} from '@/lib/react-query/hooks'
import {
  registerPostgresSqlCompletionProvider,
  type PostgresSqlCompletionResolvers,
} from '@/lib/postgres-sql-completion'
import { POSTGRES_SIDEBAR_LIST_PAGE_SIZE } from '@/lib/postgres-sql'
import { cn } from '@/lib/utils'
import { POSTGRES_SQL_EDITOR_SURFACE_CLASS } from './_components/postgres-chrome'
import { getPostgresSqlEditorActions } from '@/lib/postgres-sql-editor-actions'

type PostgresSqlCodeEditorProps = {
  projectId: string
  databaseId: string
  tabId: string
  sql: string
  onSqlChange: (value: string) => void
  onUndoRedoStateChange?: (state: {
    canUndo: boolean
    canRedo: boolean
  }) => void
}

export type PostgresSqlCodeEditorRef = {
  undo: () => void
  redo: () => void
}

const AUTOCOMPLETE_RESULT_LIMIT = POSTGRES_SIDEBAR_LIST_PAGE_SIZE

let completionDisposable: IDisposable | null = null
let getCompletionResolvers: () => PostgresSqlCompletionResolvers = () => ({
  searchSchemas: async () => [],
  searchTables: async () => [],
  resolveTableColumns: async () => [],
  resolveTableRef: async () => null,
})

function focusEditorInstance(editorInstance: editor.IStandaloneCodeEditor) {
  requestAnimationFrame(() => {
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
    onUndoRedoStateChange,
  },
  ref,
) {
  const queryClient = useQueryClient()
  const resolversRef = useRef<PostgresSqlCompletionResolvers>({
    searchSchemas: async () => [],
    searchTables: async () => [],
    resolveTableColumns: async () => [],
    resolveTableRef: async () => null,
  })

  const editorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
  const undoRedoDisposeRef = useRef<(() => void) | null>(null)
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
    resolversRef.current = {
      searchSchemas: async (search) => {
        const page = await fetchPostgresSchemasPage(projectId, databaseId, {
          search: search.trim() || undefined,
          page: 0,
          limit: AUTOCOMPLETE_RESULT_LIMIT,
        })
        return page.schemas
      },
      searchTables: async (schema, search) => {
        const page = await fetchPostgresTablesPage(projectId, databaseId, {
          schema: schema?.trim() || undefined,
          search: search.trim() || undefined,
          page: 0,
          limit: AUTOCOMPLETE_RESULT_LIMIT,
        })
        return page.tables
      },
      resolveTableColumns: async (tables) => {
        const uniqueTables = Array.from(
          new Map(
            tables.map((table) => [
              `${table.schema}.${table.table}`,
              table,
            ]),
          ).values(),
        )

        const columnGroups = await Promise.all(
          uniqueTables.map((table) =>
            queryClient.fetchQuery({
              ...postgresTableAutocompleteColumnsQueryOptions(
                projectId,
                databaseId,
                table.schema,
                table.table,
              ),
              queryFn: () =>
                fetchPostgresTableAutocompleteColumns(
                  projectId,
                  databaseId,
                  table.schema,
                  table.table,
                ),
            }),
          ),
        )

        return columnGroups.flat()
      },
      resolveTableRef: async (ref) => {
        const parts = ref.split('.').map((part) =>
          part.replace(/^["']|["']$/g, '').replace(/""/g, '"'),
        )
        if (parts.length === 2) {
          return { schema: parts[0], table: parts[1] }
        }
        if (parts.length !== 1) return null

        const page = await fetchPostgresTablesPage(projectId, databaseId, {
          search: parts[0],
          page: 0,
          limit: 8,
        })
        const matches = page.tables.filter(
          (row) => row.table_name.toLowerCase() === parts[0].toLowerCase(),
        )
        if (matches.length === 0) {
          return page.tables[0]
            ? {
                schema: page.tables[0].table_schema,
                table: page.tables[0].table_name,
              }
            : null
        }
        const publicMatch = matches.find(
          (row) => row.table_schema === 'public',
        )
        const chosen = publicMatch ?? matches[0]
        return {
          schema: chosen.table_schema,
          table: chosen.table_name,
        }
      },
    }
    getCompletionResolvers = () => resolversRef.current
  }, [databaseId, projectId, queryClient])

  useEffect(() => {
    return () => {
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
          () => getCompletionResolvers(),
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

      editorInstance.addAction({
        id: `postgres-format-sql-${projectId}-${databaseId}`,
        label: 'Format SQL',
        keybindings: [
          monaco.KeyMod.Shift | monaco.KeyMod.Alt | monaco.KeyCode.KeyF,
        ],
        run: () => {
          const actions = getPostgresSqlEditorActions()
          if (actions?.canFormat) {
            actions.format()
          }
        },
      })

      focusEditorInstance(editorInstance)
    },
    [attachUndoRedoListeners, databaseId, projectId],
  )

  const handleEditorAreaMouseDown = useCallback(() => {
    requestAnimationFrame(() => {
      editorRef.current?.focus()
    })
  }, [])

  return (
    <div
      className="relative h-full min-h-0 flex-1 overflow-hidden"
      data-postgres-sql-editor
      onMouseDown={handleEditorAreaMouseDown}
    >
      <div className="absolute inset-0 overflow-hidden">
        <CodeEditor
          value={sql}
          onChange={onSqlChange}
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
