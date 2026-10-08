/**
 * Predefined filter columns for bucket files list (Storage).
 */

import type { FilterColumn } from '../types'

export const filesFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'signature', title: 'Signature', type: 'string' },
  { id: 'mimeType', title: 'MIME type', type: 'string' },
  {
    id: 'sizeOriginal',
    title: 'Size',
    type: 'integer',
    format: 'size',
  },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]
