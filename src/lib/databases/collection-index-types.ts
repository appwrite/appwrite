export type CollectionIndexType = 'key' | 'unique' | 'fulltext' | 'spatial'

export const COLLECTION_INDEX_TYPES: {
  id: CollectionIndexType
  label: string
  description: string
}[] = [
  {
    id: 'key',
    label: 'Key',
    description: 'Speed up filters and sorting on indexed fields.',
  },
  {
    id: 'unique',
    label: 'Unique',
    description: 'Reject duplicate values across indexed fields.',
  },
  {
    id: 'fulltext',
    label: 'Fulltext',
    description: 'Enable text search on string fields.',
  },
  {
    id: 'spatial',
    label: 'Spatial',
    description: 'Accelerate location queries on spatial fields.',
  },
]

export function getCollectionIndexTypeDefinition(
  type: CollectionIndexType,
): (typeof COLLECTION_INDEX_TYPES)[number] {
  return (
    COLLECTION_INDEX_TYPES.find((entry) => entry.id === type) ??
    COLLECTION_INDEX_TYPES[0]
  )
}

export function getCollectionIndexTypeSearchValue(entry: {
  label: string
  description: string
}): string {
  return `${entry.label} ${entry.description}`
}
