/**
 * Cursor for the next page of a cursor-paginated list: the last item's id
 * when the page came back full, otherwise `undefined` (no further page).
 */
export function nextCursorAfter<T extends { $id: string }>(
  items: T[],
  limit: number,
): string | undefined {
  return items.length >= limit ? items[items.length - 1]?.$id : undefined
}
