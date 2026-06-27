import { useEffect, useRef, useState } from 'react'
import {
  getInitialCoverGeneratorEditorState,
  migrateLegacyCoverGeneratorImageFields,
  writeCoverGeneratorEditorState,
  type CoverGeneratorEditorState,
} from '@/lib/cover-generator/editor-storage'
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
import type { CoverTemplateCategoryFilter } from '@/lib/cover-generator/template-categories'
import { createDefaultCoverData } from '@/lib/cover-generator/parse-params'
import { resolveCoverEditorThemeId } from '@/lib/cover-generator/themes'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverImageFormat, CoverTemplateId } from '@/lib/cover-generator/constants'
import { isCoverTemplateId } from '@/lib/cover-generator/constants'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

const PERSIST_DEBOUNCE_MS = 400

function applyThemeToCoverData(
  data: CoverRenderData,
  theme: CoverThemeId,
): CoverRenderData {
  return { ...data, theme: resolveCoverEditorThemeId(theme) }
}

function applyThemeToTemplateData(
  templateData: Partial<Record<CoverTemplateId, CoverRenderData>>,
  theme: CoverThemeId,
): Partial<Record<CoverTemplateId, CoverRenderData>> {
  const resolvedTheme = resolveCoverEditorThemeId(theme)
  const next: Partial<Record<CoverTemplateId, CoverRenderData>> = {}

  for (const [templateId, entry] of Object.entries(templateData)) {
    if (!entry || !isCoverTemplateId(templateId)) continue
    next[templateId] = { ...entry, theme: resolvedTheme }
  }

  return next
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

export function useCoverGeneratorState() {
  const [state, setState] = useState<CoverGeneratorEditorState>(
    getInitialCoverGeneratorEditorState,
  )
  const [imagesHydrated, setImagesHydrated] = useState(false)
  const imageFieldsRef = useRef(state.imageFields)
  const dataRef = useRef(state.data)
  const previousTemplateRef = useRef(state.data.template)

  useEffect(() => {
    imageFieldsRef.current = state.imageFields
  }, [state.imageFields])

  useEffect(() => {
    dataRef.current = state.data
  }, [state.data])

  useEffect(() => {
    let cancelled = false
    const templateId = state.data.template

    void (async () => {
      await migrateLegacyCoverGeneratorImageFields(templateId)
      await migrateLegacyCoverImageStorageKeys(templateId)
      if (cancelled) return

      const imageFields = await loadCoverImageFieldUrlsForTemplate(templateId)
      if (cancelled) return

      setState((current) => ({
        ...current,
        imageFields,
      }))
      setImagesHydrated(true)
    })()

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (!imagesHydrated) return
    if (previousTemplateRef.current === state.data.template) return

    previousTemplateRef.current = state.data.template

    let cancelled = false
    const templateId = state.data.template

    void loadCoverImageFieldUrlsForTemplate(templateId).then((imageFields) => {
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

    const timeoutId = window.setTimeout(() => {
      writeCoverGeneratorEditorState({
        data: state.data,
        templateData: state.templateData,
        typeFilter: state.typeFilter,
      })
    }, PERSIST_DEBOUNCE_MS)

    return () => window.clearTimeout(timeoutId)
  }, [state.data, state.templateData, state.typeFilter, imagesHydrated])

  const setData = (next: CoverRenderData) => {
    setState((current) => {
      const resolvedTheme = resolveCoverEditorThemeId(next.theme)
      const resolvedCurrentTheme = resolveCoverEditorThemeId(current.data.theme)
      const nextData = applyThemeToCoverData(next, resolvedTheme)

      if (resolvedTheme !== resolvedCurrentTheme) {
        return {
          ...current,
          data: nextData,
          templateData: {
            ...applyThemeToTemplateData(current.templateData, resolvedTheme),
            [nextData.template]: nextData,
          },
        }
      }

      return withTemplateDataEntry(current, nextData)
    })
  }

  const setTheme = (theme: CoverThemeId) => {
    setState((current) => {
      const resolvedTheme = resolveCoverEditorThemeId(theme)
      const nextData = applyThemeToCoverData(current.data, resolvedTheme)

      return {
        ...current,
        data: nextData,
        templateData: {
          ...applyThemeToTemplateData(current.templateData, resolvedTheme),
          [nextData.template]: nextData,
        },
      }
    })
  }

  const setImageField = (key: string, value: string | undefined) => {
    void (async () => {
      const templateId = dataRef.current.template
      const previousUrl = imageFieldsRef.current[key]

      if (!value) {
        revokeCoverImageObjectUrl(previousUrl)
        await removeCoverImageField(templateId, key)
        setState((current) => {
          const nextFields = { ...current.imageFields }
          delete nextFields[key]
          return { ...current, imageFields: nextFields }
        })
        return
      }

      if (value.startsWith('data:')) {
        const objectUrl = await persistCoverImageDataUrl(templateId, key, value)
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
      await removeCoverImageField(templateId, key)
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
      const previousUrl = imageFieldsRef.current[key]
      const objectUrl = await persistCoverImageUpload(templateId, key, file)
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

  const selectTemplate = (template: CoverTemplateId) => {
    setState((current) => {
      if (template === current.data.template) return current

      const templateData = {
        ...current.templateData,
        [current.data.template]: current.data,
      }

      const cached = templateData[template]
      const sharedTheme = resolveCoverEditorThemeId(current.data.theme)
      const nextData = cached
        ? applyThemeToCoverData(cached, sharedTheme)
        : createDefaultCoverData(template, sharedTheme, {
            width: current.data.width,
            height: current.data.height,
            format: current.data.format,
          })

      revokeCoverImageObjectUrls(current.imageFields)

      return {
        ...current,
        templateData,
        data: nextData,
        imageFields: {},
      }
    })
  }

  const setFormat = (format: CoverImageFormat) => {
    setState((current) =>
      withTemplateDataEntry(current, { ...current.data, format }),
    )
  }

  const resetCurrentTemplate = () => {
    void (async () => {
      const current = dataRef.current
      const templateId = current.template
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
      await clearCoverImageFieldsForTemplate(templateId)

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
    /** @deprecated Use setCategoryFilter */
    setTypeFilter: setCategoryFilter,
    selectTemplate,
    setFormat,
    setTheme,
    resetCurrentTemplate,
  }
}
