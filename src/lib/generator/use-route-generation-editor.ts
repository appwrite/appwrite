import { useNavigate } from '@tanstack/react-router'
import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'

export type GeneratorEditorPhase = 'start' | 'editor'

type UseRouteGenerationEditorOptions<TGeneration> = {
  routeGenerationId?: string
  startTo: '/generator' | '/generator/diagrams'
  editorTo: '/generator/$generationId' | '/generator/diagrams/$generationId'
  generations: TGeneration[]
  getGenerationId: (generation: TGeneration) => string
  loadGeneration: (generation: TGeneration) => void
  migrateLegacyIfNeeded: () => Promise<TGeneration[] | unknown>
  onEnterEditor?: () => void
  onLeaveEditor?: () => void
  notFoundMessage: string
}

export function useRouteGenerationEditor<TGeneration>({
  routeGenerationId,
  startTo,
  editorTo,
  generations,
  getGenerationId,
  loadGeneration,
  migrateLegacyIfNeeded,
  onEnterEditor,
  onLeaveEditor,
  notFoundMessage,
}: UseRouteGenerationEditorOptions<TGeneration>) {
  const navigate = useNavigate()
  const [activeGenerationId, setActiveGenerationId] = useState<string | null>(null)
  const activeGenerationIdRef = useRef<string | null>(null)
  activeGenerationIdRef.current = activeGenerationId

  const loadedRouteIdRef = useRef<string | null>(null)
  const previousRouteIdRef = useRef<string | undefined>(routeGenerationId)
  const [isRouteSyncing, setIsRouteSyncing] = useState(Boolean(routeGenerationId))

  const generationsRef = useRef(generations)
  const getGenerationIdRef = useRef(getGenerationId)
  const loadGenerationRef = useRef(loadGeneration)
  const migrateLegacyIfNeededRef = useRef(migrateLegacyIfNeeded)
  const onEnterEditorRef = useRef(onEnterEditor)
  const onLeaveEditorRef = useRef(onLeaveEditor)
  generationsRef.current = generations
  getGenerationIdRef.current = getGenerationId
  loadGenerationRef.current = loadGeneration
  migrateLegacyIfNeededRef.current = migrateLegacyIfNeeded
  onEnterEditorRef.current = onEnterEditor
  onLeaveEditorRef.current = onLeaveEditor

  const phase: GeneratorEditorPhase = routeGenerationId ? 'editor' : 'start'

  const routeGenerationReady = routeGenerationId
    ? generations.some((item) => getGenerationId(item) === routeGenerationId)
    : false

  useEffect(() => {
    const previousRouteId = previousRouteIdRef.current
    previousRouteIdRef.current = routeGenerationId

    if (!routeGenerationId) {
      loadedRouteIdRef.current = null
      if (activeGenerationIdRef.current !== null) {
        setActiveGenerationId(null)
      }
      setIsRouteSyncing(false)
      if (previousRouteId) {
        onLeaveEditorRef.current?.()
      }
      return
    }

    if (loadedRouteIdRef.current === routeGenerationId) {
      return
    }

    let cancelled = false
    setIsRouteSyncing(true)

    void (async () => {
      let list = generationsRef.current
      try {
        const migrated = await migrateLegacyIfNeededRef.current()
        if (Array.isArray(migrated)) {
          list = migrated
        }
      } catch {
        /* ignore migration errors */
      }

      if (cancelled) return

      const generation = list.find(
        (item) => getGenerationIdRef.current(item) === routeGenerationId,
      )
      if (!generation) {
        if (loadedRouteIdRef.current === routeGenerationId) {
          return
        }
        setIsRouteSyncing(false)
        toast.error(notFoundMessage)
        void navigate({ to: startTo, replace: true })
        return
      }

      loadGenerationRef.current(generation)
      loadedRouteIdRef.current = routeGenerationId
      setActiveGenerationId(routeGenerationId)
      setIsRouteSyncing(false)
      onEnterEditorRef.current?.()
    })()

    return () => {
      cancelled = true
    }
  }, [navigate, notFoundMessage, routeGenerationId, routeGenerationReady, startTo])

  const openEditorRoute = useCallback(
    (generationId: string) => {
      void navigate({ to: editorTo, params: { generationId } })
    },
    [editorTo, navigate],
  )

  const backToStart = useCallback(
    (beforeNavigate?: () => void) => {
      beforeNavigate?.()
      void navigate({ to: startTo })
    },
    [navigate, startTo],
  )

  return {
    phase,
    activeGenerationId,
    activeGenerationIdRef,
    isRouteSyncing,
    openEditorRoute,
    backToStart,
  }
}
