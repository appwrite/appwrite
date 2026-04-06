import type { DatabaseRouteKind } from '@/lib/database-routes'

/**
 * User-facing copy aligned with Appwrite Console SDK service APIs:
 * - {@link import('@appwrite.io/console').TablesDB} — listTables, tableId, listRows, listColumns
 * - {@link import('@appwrite.io/console').DocumentsDB} — listCollections, collectionId, listDocuments, listIndexes
 * - {@link import('@appwrite.io/console').VectorsDB} — listCollections, collectionId, listDocuments, listIndexes
 */
export type DatabaseConsoleLabels = {
  /** SDK class name as in @appwrite.io/console (for subtitles / dev alignment). */
  sdkServiceName: 'TablesDB' | 'DocumentsDB' | 'VectorsDB'
  /** Primary list* API for containers in this product. */
  sdkListContainersMethod: 'listTables' | 'listCollections'
  /** Primary list* API for records in this product. */
  sdkListRecordsMethod: 'listRows' | 'listDocuments'
  /** Path-style id name used by the SDK for the container resource. */
  sdkContainerIdParam: 'tableId' | 'collectionId'

  containerSingular: string
  containerPlural: string
  containerSingularTitle: string
  containerPluralTitle: string

  recordSingular: string
  recordPlural: string
  recordSingularTitle: string
  recordPluralTitle: string

  /** First data tab: listRows (Tables DB) vs listDocuments (Documents / Vectors DB). */
  gridDataTabLabel: string
  /**
   * Second data tab on Documents DB (JSON). Avoids duplicating "Documents" when the grid tab is already Documents.
   * Empty when the JSON tab is not shown.
   */
  jsonDocumentsTabLabel: string

  schemaSingularTitle: string
  schemaPluralTitle: string
  /** Lowercase plural for filters / resource labels (columns, attributes). */
  schemaPlural: string

  createContainer: string
  createRecord: string
  createSchema: string
  createIndex: string

  searchContainersPlaceholder: string
  sortContainersMenu: string
  sortContainersAriaLabel: string

  emptyContainersTitle: string
  emptyContainersDescription: string
  selectContainerHint: string

  /** Database overview first tab (list of containers). */
  databaseOverviewTabLabel: string

  disabledContainerTitle: string
  disabledContainerBodyPrefix: string

  /** `Pagination` itemLabel (lowercase, plural). */
  paginationItemLabel: string

  debugCreateManyContainers: string
}

const TABLES: DatabaseConsoleLabels = {
  sdkServiceName: 'TablesDB',
  sdkListContainersMethod: 'listTables',
  sdkListRecordsMethod: 'listRows',
  sdkContainerIdParam: 'tableId',
  containerSingular: 'table',
  containerPlural: 'tables',
  containerSingularTitle: 'Table',
  containerPluralTitle: 'Tables',
  recordSingular: 'row',
  recordPlural: 'rows',
  recordSingularTitle: 'Row',
  recordPluralTitle: 'Rows',
  gridDataTabLabel: 'Rows',
  jsonDocumentsTabLabel: '',
  schemaSingularTitle: 'Column',
  schemaPluralTitle: 'Columns',
  schemaPlural: 'columns',
  createContainer: 'Create table',
  createRecord: 'Create row',
  createSchema: 'Create column',
  createIndex: 'Create index',
  searchContainersPlaceholder: 'Search',
  sortContainersMenu: 'Sort tables',
  sortContainersAriaLabel: 'Sort tables',
  emptyContainersTitle: 'No tables yet',
  emptyContainersDescription: 'Create your first table to get started',
  selectContainerHint: 'Select a table from the sidebar',
  databaseOverviewTabLabel: 'Tables',
  disabledContainerTitle: 'Table is disabled',
  disabledContainerBodyPrefix: 'This table is currently disabled.',
  paginationItemLabel: 'tables',
  debugCreateManyContainers: 'Debug: Create 50 tables',
}

const DOCUMENTS: DatabaseConsoleLabels = {
  sdkServiceName: 'DocumentsDB',
  sdkListContainersMethod: 'listCollections',
  sdkListRecordsMethod: 'listDocuments',
  sdkContainerIdParam: 'collectionId',
  containerSingular: 'collection',
  containerPlural: 'collections',
  containerSingularTitle: 'Collection',
  containerPluralTitle: 'Collections',
  recordSingular: 'document',
  recordPlural: 'documents',
  recordSingularTitle: 'Document',
  recordPluralTitle: 'Documents',
  gridDataTabLabel: 'Documents',
  jsonDocumentsTabLabel: 'JSON',
  schemaSingularTitle: 'Attribute',
  schemaPluralTitle: 'Attributes',
  schemaPlural: 'attributes',
  createContainer: 'Create collection',
  createRecord: 'Create document',
  createSchema: 'Create attribute',
  createIndex: 'Create index',
  searchContainersPlaceholder: 'Search',
  sortContainersMenu: 'Sort collections',
  sortContainersAriaLabel: 'Sort collections',
  emptyContainersTitle: 'No collections yet',
  emptyContainersDescription: 'Create your first collection to get started',
  selectContainerHint: 'Select a collection from the sidebar',
  databaseOverviewTabLabel: 'Collections',
  disabledContainerTitle: 'Collection is disabled',
  disabledContainerBodyPrefix: 'This collection is currently disabled.',
  paginationItemLabel: 'collections',
  debugCreateManyContainers: 'Debug: Create 50 collections',
}

const VECTORS: DatabaseConsoleLabels = {
  sdkServiceName: 'VectorsDB',
  sdkListContainersMethod: 'listCollections',
  sdkListRecordsMethod: 'listDocuments',
  sdkContainerIdParam: 'collectionId',
  containerSingular: 'collection',
  containerPlural: 'collections',
  containerSingularTitle: 'Collection',
  containerPluralTitle: 'Collections',
  recordSingular: 'document',
  recordPlural: 'documents',
  recordSingularTitle: 'Document',
  recordPluralTitle: 'Documents',
  gridDataTabLabel: 'Documents',
  jsonDocumentsTabLabel: '',
  schemaSingularTitle: 'Attribute',
  schemaPluralTitle: 'Attributes',
  schemaPlural: 'attributes',
  createContainer: 'Create collection',
  createRecord: 'Create document',
  createSchema: 'Create attribute',
  createIndex: 'Create index',
  searchContainersPlaceholder: 'Search',
  sortContainersMenu: 'Sort collections',
  sortContainersAriaLabel: 'Sort collections',
  emptyContainersTitle: 'No collections yet',
  emptyContainersDescription: 'Create your first collection to get started',
  selectContainerHint: 'Select a collection from the sidebar',
  databaseOverviewTabLabel: 'Collections',
  disabledContainerTitle: 'Collection is disabled',
  disabledContainerBodyPrefix: 'This collection is currently disabled.',
  paginationItemLabel: 'collections',
  debugCreateManyContainers: 'Debug: Create 50 collections',
}

export function getDatabaseConsoleLabels(
  kind: DatabaseRouteKind,
): DatabaseConsoleLabels {
  if (kind === 'documentsdb') return DOCUMENTS
  if (kind === 'vectorsdb') return VECTORS
  return TABLES
}
