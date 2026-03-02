import { useState, useEffect, useCallback } from 'react'
import {
  type EventBuilderSelection,
  buildEventString,
  parseEventString,
} from './events-model'

export function useEventBuilder(initialValue?: string) {
  const [selection, setSelection] = useState<EventBuilderSelection>({
    service: null,
    resource: null,
    action: null,
    attribute: null,
  })
  const [customMode, setCustomMode] = useState(false)
  const [customInput, setCustomInput] = useState('')

  const eventString = customMode ? customInput : buildEventString(selection)
  const isValid =
    eventString.trim().length > 0 &&
    (!customMode ||
      parseEventString(customInput.trim()) !== null ||
      /^[\w.*-]+(\.[\w*]+)+$/.test(customInput.trim()))

  const reset = useCallback(() => {
    setSelection({
      service: null,
      resource: null,
      action: null,
      attribute: null,
      databaseId: undefined,
      tableId: undefined,
      bucketId: undefined,
      functionId: undefined,
      teamId: undefined,
      userId: undefined,
      topicId: undefined,
      providerId: undefined,
      fileId: undefined,
      rowId: undefined,
      columnId: undefined,
      indexId: undefined,
    })
    setCustomMode(false)
    setCustomInput('')
  }, [])

  const initFromString = useCallback((str: string) => {
    const parsed = parseEventString(str)
    if (parsed) {
      setSelection(parsed)
      setCustomMode(false)
      setCustomInput(str)
    } else {
      setCustomMode(true)
      setCustomInput(str)
    }
  }, [])

  useEffect(() => {
    if (initialValue) {
      initFromString(initialValue)
    } else {
      reset()
    }
  }, [initialValue, initFromString, reset])

  const setService = useCallback((v: string | null) => {
    setSelection(() => ({
      service: v,
      resource: null,
      action: null,
      attribute: null,
      databaseId: undefined,
      tableId: undefined,
      bucketId: undefined,
      functionId: undefined,
      teamId: undefined,
      userId: undefined,
      topicId: undefined,
      providerId: undefined,
      fileId: undefined,
      rowId: undefined,
      columnId: undefined,
      indexId: undefined,
    }))
  }, [])

  const setResource = useCallback((v: string | null) => {
    setSelection((s) => ({
      ...s,
      resource: v,
      action: null,
      attribute: null,
      tableId: undefined,
      fileId: undefined,
      rowId: undefined,
      columnId: undefined,
      indexId: undefined,
    }))
  }, [])

  const setAction = useCallback((v: string | null) => {
    setSelection((s) => ({ ...s, action: v, attribute: null }))
  }, [])

  const setAttribute = useCallback((v: string | null) => {
    setSelection((s) => ({ ...s, attribute: v }))
  }, [])

  const setDatabaseId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({
      ...s,
      databaseId: v ?? undefined,
      tableId: undefined,
      rowId: undefined,
      columnId: undefined,
      indexId: undefined,
    }))
  }, [])

  const setTableId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({
      ...s,
      tableId: v ?? undefined,
      rowId: undefined,
      columnId: undefined,
      indexId: undefined,
    }))
  }, [])

  const setBucketId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, bucketId: v ?? undefined, fileId: undefined }))
  }, [])

  const setFunctionId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, functionId: v ?? undefined }))
  }, [])

  const setTeamId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, teamId: v ?? undefined }))
  }, [])

  const setUserId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, userId: v ?? undefined }))
  }, [])

  const setTopicId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, topicId: v ?? undefined }))
  }, [])

  const setProviderId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, providerId: v ?? undefined }))
  }, [])

  const setFileId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, fileId: v ?? undefined }))
  }, [])

  const setRowId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, rowId: v ?? undefined }))
  }, [])

  const setColumnId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, columnId: v ?? undefined }))
  }, [])

  const setIndexId = useCallback((v: string | '*' | undefined) => {
    setSelection((s) => ({ ...s, indexId: v ?? undefined }))
  }, [])

  const applyCustomAndExit = useCallback(() => {
    const parsed = parseEventString(customInput.trim())
    if (parsed) {
      setSelection(parsed)
      setCustomMode(false)
    }
  }, [customInput])

  const cancelCustom = useCallback(() => {
    setCustomMode(false)
    setCustomInput(buildEventString(selection))
  }, [selection])

  const enterCustomMode = useCallback(() => {
    setCustomInput(buildEventString(selection))
    setCustomMode(true)
  }, [selection])

  return {
    selection,
    setService,
    setResource,
    setAction,
    setAttribute,
    setDatabaseId,
    setTableId,
    setBucketId,
    setFunctionId,
    setTeamId,
    setUserId,
    setTopicId,
    setProviderId,
    setFileId,
    setRowId,
    setColumnId,
    setIndexId,
    customMode,
    setCustomMode,
    customInput,
    setCustomInput,
    eventString,
    isValid,
    reset,
    initFromString,
    applyCustomAndExit,
    cancelCustom,
    enterCustomMode,
  }
}
