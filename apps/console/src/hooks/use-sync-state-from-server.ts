import { useEffect, useState, type Dispatch, type SetStateAction } from 'react'

export function serverValueKey(value: unknown): string {
  if (value == null) return String(value)
  if (typeof value !== 'object') return `${typeof value}:${String(value)}`
  return JSON.stringify(value)
}

/**
 * Local form state that follows the saved server value, but does not reset
 * when the parent resource object is replaced (realtime / refetch) with the
 * same field value.
 */
export function useSyncStateFromServer<T>(
  serverValue: T,
): [T, Dispatch<SetStateAction<T>>] {
  const [value, setValue] = useState(serverValue)
  const key = serverValueKey(serverValue)

  useEffect(() => {
    setValue(serverValue)
    // Only re-sync when the saved value itself changes, not when a new object
    // with the same contents arrives from cache/realtime.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` is the change signal
  }, [key])

  return [value, setValue]
}
