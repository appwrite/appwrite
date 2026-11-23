import { useCallback, useEffect, useRef, useState } from 'react'
import type { CoverTemplateCategoryFilter } from '@/lib/cover-generator/template-categories'
import { createDefaultCoverData } from '@/lib/cover-generator/parse-params'
import { resolveCoverEditorThemeId } from '@/lib/cover-generator/themes'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverImageFormat, CoverTemplateId } from '@/lib/cover-generator/constants'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import {
  clearCoverImageFieldsForTemplate,
  loadCoverImageFieldUrlsForTemplate,
  migrateLegacyCoverImageStorageKeys,
  persistCoverImageDataUrl,
  persistCoverImageUpload,
  removeCoverImageField,
  revokeCoverImageObjectUrl,
  revokeCoverImageObjectUrls,
} from '@/lib/cover-generator/editor-image-fields'
import type { CoverGeneratorEditorState } from '@/lib/cover-generator/editor-storage'

const PERSIST_DEBOUNCE_MS = 400

type UseCoverGeneratorStateOptions = {
  generationId?: string | null
  onDocumentPersist?: (data: CoverRenderData) => void
}

function applyThemeToCoverData(
  data: CoverRenderData,
  theme: CoverThemeId,
): CoverRenderData {
  return { ...data, theme: resolveCoverEditorThemeId(theme) }
}

function withTemplateDataEntry(
  state: CoverGeneratorEditorState,
  data: CoverRenderData,
): CoverGeneratorEditorState {
  return {
    ...state,
    data,
    templateData: {
      ...state.templateData,
      [data.template]: data,
    },
  }
}

function createEmptyEditorState(
  data: CoverRenderData = createDefaultCoverData(),
): CoverGeneratorEditorState {
  return {
    data,
    templateData: { [data.template]: data },
    imageFields: {},
    typeFilter: 'all',
  }
}

export function useCoverGeneratorState(options: UseCoverGeneratorStateOptions = {}) {
  const generationIdRef = useRef(options.generationId ?? null)
  generationIdRef.current = options.generationId ?? null
  const onDocumentPersistRef = useRef(options.onDocumentPersist)
  onDocumentPersistRef.current = options.onDocumentPersist

  const [state, setState] = useState<CoverGeneratorEditorState>(createEmptyEditorState)
  const [imagesHydrated, setImagesHydrated] = useState(false)
  const imageFieldsRef = useRef(state.imageFields)
  const dataRef = useRef(state.data)
  const previousTemplateRef = useRef(state.data.template)
  const persistTimerRef = useRef<number | null>(null)
  /** Skip the persist scheduled by loadCover / initial hydrate. */
  const suppressNextPersistRef = useRef(false)
  const hasUserEditsRef = useRef(false)

  useEffect(() => {
    imageFieldsRef.current = state.imageFields
  }, [state.imageFields])

  useEffect(() => {
    dataRef.current = state.data
  }, [state.data])

  const hydrateImages = async (templateId: CoverTemplateId, generationId: string | null) => {
    await migrateLegacyCoverImageStorageKeys(templateId)
    return loadCoverImageFieldUrlsForTemplate(templateId, generationId)
  }

  const loadCover = (data: CoverRenderData) => {
    revokeCoverImageObjectUrls(imageFieldsRef.current)
    const nextData = applyThemeToCoverData(data, data.theme)
    suppressNextPersistRef.current = true
    hasUserEditsRef.current = false
    if (persistTimerRef.current != null) {
      window.clearTimeout(persistTimerRef.current)
      persistTimerRef.current = null
    }
    setState(createEmptyEditorState(nextData))
    setImagesHydrated(false)
    previousTemplateRef.current = nextData.template

    void (async () => {
      const imageFields = await hydrateImages(
        nextData.template,
        generationIdRef.current,
      )
      suppressNextPersistRef.current = true
      setState(() => ({
        ...createEmptyEditorState(nextData),
        imageFields,
      }))
      setImagesHydrated(true)
    })()
  }

  useEffect(() => {
    if (!imagesHydrated) return
    if (previousTemplateRef.current === state.data.template) return

    previousTemplateRef.current = state.data.template

    let cancelled = false
    const templateId = state.data.template

    void hydrateImages(templateId, generationIdRef.current).then((imageFields) => {
      if (cancelled) return

      setState((current) => {
        if (current.data.template !== templateId) return current

        revokeCoverImageObjectUrls(current.imageFields)
        return { ...current, imageFields }
      })
    })

    return () => {
      cancelled = true
    }
  }, [state.data.template, imagesHydrated])

  useEffect(() => {
    return () => {
      revokeCoverImageObjectUrls(imageFieldsRef.current)
    }
  }, [])

  useEffect(() => {
    if (!imagesHydrated) return

    if (suppressNextPersistRef.current) {
      suppressNextPersistRef.current = false
      return
    }

    hasUserEditsRef.current = true
    const timeoutId = window.setTimeout(() => {
      onDocumentPersistRef.current?.(state.data)
    }, PERSIST_DEBOUNCE_MS)
    persistTimerRef.current = timeoutId

    return () => {
      window.clearTimeout(timeoutId)
      if (persistTimerRef.current === timeoutId) {
        persistTimerRef.current = null
      }
    }
  }, [state.data, imagesHydrated])

  const cancelPersistDebounce = useCallback(() => {
    if (persistTimerRef.current == null) return
    window.clearTimeout(persistTimerRef.current)
    persistTimerRef.current = null
  }, [])

  const flushPersist = useCallback(() => {
    if (!hasUserEditsRef.current) {
      cancelPersistDebounce()
      return
    }
    cancelPersistDebounce()
    onDocumentPersistRef.current?.(dataRef.current)
  }, [cancelPersistDebounce])

  const isDirty = useCallback(() => hasUserEditsRef.current, [])

  const setData = (
    next: CoverRenderData | ((current: CoverRenderData) => CoverRenderData),
  ) => {
    setState((current) => {
      const resolved = typeof next === 'function' ? next(current.data) : next
      return withTemplateDataEntry(current, applyThemeToCoverData(resolved, resolved.theme))
    })
  }

  const setTheme = (theme: CoverThemeId) => {
    setState((current) => {
      const resolvedTheme = resolveCoverEditorThemeId(theme)
      const nextData = applyThemeToCoverData(current.data, resolvedTheme)
      return withTemplateDataEntry(current, nextData)
    })
  }

  const setImageField = (key: string, value: string | undefined) => {
    void (async () => {
      const templateId = dataRef.current.template
      const generationId = generationIdRef.current
      const previousUrl = imageFieldsRef.current[key]

      if (!value) {
        revokeCoverImageObjectUrl(previousUrl)
        await removeCoverImageField(templateId, key, generationId)
        setState((current) => {
          const nextFields = { ...current.imageFields }
          delete nextFields[key]
          return { ...current, imageFields: nextFields }
        })
        return
      }

      if (value.startsWith('data:')) {
        const objectUrl = await persistCoverImageDataUrl(
          templateId,
          key,
          value,
          generationId,
        )
        revokeCoverImageObjectUrl(previousUrl)
        setState((current) => ({
          ...current,
          imageFields: { ...current.imageFields, [key]: objectUrl },
        }))
        return
      }

      if (value.startsWith('blob:')) {
        setState((current) => ({
          ...current,
          imageFields: { ...current.imageFields, [key]: value },
        }))
        return
      }

      revokeCoverImageObjectUrl(previousUrl)
      await removeCoverImageField(templateId, key, generationId)
      setState((current) => {
        const nextFields = { ...current.imageFields }
        delete nextFields[key]
        return { ...current, imageFields: nextFields }
      })
    })()
  }

  const setImageFile = (key: string, file: File) => {
    void (async () => {
      const templateId = dataRef.current.template
      const generationId = generationIdRef.current
      const previousUrl = imageFieldsRef.current[key]
      const objectUrl = await persistCoverImageUpload(
        templateId,
        key,
        file,
        generationId,
      )
      revokeCoverImageObjectUrl(previousUrl)
      setState((current) => ({
        ...current,
        imageFields: { ...current.imageFields, [key]: objectUrl },
      }))
    })()
  }

  const setCategoryFilter = (categoryFilter: CoverTemplateCategoryFilter) => {
    setState((current) => ({ ...current, typeFilter: categoryFilter }))
  }

  const setFormat = (format: CoverImageFormat) => {
    setState((current) => withTemplateDataEntry(current, { ...current.data, format }))
  }

  const resetCurrentTemplate = () => {
    void (async () => {
      const current = dataRef.current
      const templateId = current.template
      const generationId = generationIdRef.current
      const nextData = createDefaultCoverData(
        templateId,
        resolveCoverEditorThemeId(current.theme),
        {
          width: current.width,
          height: current.height,
          format: current.format,
        },
      )

      revokeCoverImageObjectUrls(imageFieldsRef.current)
      await clearCoverImageFieldsForTemplate(templateId, generationId)

      setState((currentState) =>
        withTemplateDataEntry(
          {
            ...currentState,
            imageFields: {},
          },
          nextData,
        ),
      )
    })()
  }

  return {
    data: state.data,
    imageFields: state.imageFields,
    typeFilter: state.typeFilter,
    imagesHydrated,
    setData,
    setImageField,
    setImageFile,
    setCategoryFilter,
    setTypeFilter: setCategoryFilter,
    setFormat,
    setTheme,
    resetCurrentTemplate,
    loadCover,
    flushPersist,
    cancelPersistDebounce,
    isDirty,
  }
}
