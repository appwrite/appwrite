/**
 * Resolve which IDs a row editor hands to its save mutation.
 *
 * The mutation branches on `rowId` alone: a non-null value means "update this
 * existing row" and issues a PATCH, which 404s for a row that does not exist
 * yet. So create mode always sends `rowId: null` and forwards a user-supplied
 * ID through the separate `customId` argument, which only the create call reads.
 */
export function resolveRowSaveTarget(input: {
  isCreateMode: boolean
  existingRowId?: string | null
  customRowId?: string | null
}): { rowId: string | null; customId: string | undefined } {
  if (input.isCreateMode) {
    return { rowId: null, customId: input.customRowId || undefined }
  }
  return { rowId: input.existingRowId || null, customId: undefined }
}
