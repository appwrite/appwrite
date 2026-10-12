import { useMemo } from 'react'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { useT, type Translator } from '@/lib/i18n/translate'

/**
 * User-facing copy aligned with Appwrite Console SDK service APIs:
 * - {@link import('@appwrite.io/console').TablesDB} - listTables, tableId, listRows, listColumns
 * - {@link import('@appwrite.io/console').DocumentsDB} - listCollections, collectionId, listDocuments, listIndexes
 * - {@link import('@appwrite.io/console').VectorsDB} - listCollections, collectionId, listDocuments, listIndexes
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
  /** Table/collection selector trigger when nothing is selected. */
  selectContainerPlaceholder: string
  /** Shown when the database has no containers (e.g. empty state beside create). */
  emptyContainersShortLabel: string
  /** Shown in the selector popover when search returns nothing. */
  noContainerSearchResultsLabel: string
  sortContainersMenu: string
  sortContainersAriaLabel: string

  emptyContainersTitle: string
  emptyContainersDescription: string
  selectContainerHint: string

  /** Empty grid when no records exist or filters match nothing. */
  noRecordsYetTitle: string
  noRecordsFilteredTitle: string
  addFirstRecordHint: string
  /** Document/row preview pane when nothing is selected. */
  noRecordSelectedTitle: string
  noRecordSelectedHint: string

  /** Center empty state when the spreadsheet grid has no schema or rows. */
  emptyGridNoSchemaTitle: string
  emptyGridNoRecordsTitle: string
  emptyGridNoSchemaHint: string
  emptyGridHasSchemaHint: string
  emptyGridHasSchemaWithSampleHint: string
  suggestSchemaCardTitle: string
  suggestSchemaCardHint: string
  createRecordCardHint: string
  createSchemaCardHint: string
  createSchemaDisabledHint: string
  loadingRecordsLabel: string
  failedToLoadRecordsTitle: string
  loadingContainersLabel: string
  addSchemaForSampleDataHint: string
  oneRecordSelectedLabel: string
  recordsSelectedSuffix: string
  recordUpdatedSuccess: string
  recordCreatedSuccess: string
  recordDuplicatedSuccess: string
  failedToUpdateRecord: string
  failedToCreateRecord: string
  failedToDuplicateRecord: string
  failedToDeleteRecords: string
  updateRecord: string
  recordIdLabel: string
  deleteRecordsTitle: string
  containerNotFoundTitle: string
  failedToCreateContainer: string
  failedToDeleteContainers: string
  recordsRefreshedSuccess: string
  failedToRefreshRecords: string
  deleteDatabaseContainersDescription: string
  deleteDatabaseConfirmSuffix: string
  addFirstColumnHint: string
  noIndexesYetTitle: string
  addFirstIndexHint: string

  /** Database overview first tab (list of containers). */
  databaseOverviewTabLabel: string

  disabledContainerTitle: string
  disabledContainerBodyPrefix: string

  /** `Pagination` itemLabel (lowercase, plural). */
  paginationItemLabel: string
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
  searchContainersPlaceholder: 'Search...',
  selectContainerPlaceholder: 'Select table',
  emptyContainersShortLabel: 'No tables',
  noContainerSearchResultsLabel: 'No tables found',
  sortContainersMenu: 'Sort tables',
  sortContainersAriaLabel: 'Sort tables',
  emptyContainersTitle: 'No tables yet',
  emptyContainersDescription: 'Create your first table to get started',
  selectContainerHint: 'Select a table from the sidebar',
  noRecordsYetTitle: 'No rows yet',
  noRecordsFilteredTitle: 'No rows match your filters',
  addFirstRecordHint:
    'Use Create row in the header to add your first row.',
  noRecordSelectedTitle: 'No row selected',
  noRecordSelectedHint:
    'Select a row in the table to view and edit, or use Create row in the header.',
  emptyGridNoSchemaTitle: 'No columns yet',
  emptyGridNoRecordsTitle: 'No rows found',
  emptyGridNoSchemaHint:
    'This table has no columns yet. Create your first column to get started.',
  emptyGridHasSchemaHint:
    'This table is empty. Get started by creating a row or adding columns.',
  emptyGridHasSchemaWithSampleHint:
    'This table is empty. Get started by creating a row, adding columns, or generating sample data.',
  suggestSchemaCardTitle: 'Suggest columns',
  suggestSchemaCardHint: 'Use AI to generate columns',
  createRecordCardHint: 'Add a new row to this table',
  createSchemaCardHint: 'Create columns manually',
  createSchemaDisabledHint: '',
  loadingRecordsLabel: 'Loading rows…',
  failedToLoadRecordsTitle: 'Failed to load rows',
  loadingContainersLabel: 'Loading tables…',
  addSchemaForSampleDataHint: 'Add at least one column to generate sample data.',
  oneRecordSelectedLabel: '1 row selected',
  recordsSelectedSuffix: 'rows selected',
  recordUpdatedSuccess: 'Row updated successfully',
  recordCreatedSuccess: 'Row created successfully',
  recordDuplicatedSuccess: 'Row duplicated',
  failedToUpdateRecord: 'Failed to update row',
  failedToCreateRecord: 'Failed to create row',
  failedToDuplicateRecord: 'Failed to duplicate row',
  failedToDeleteRecords: 'Failed to delete rows',
  updateRecord: 'Update row',
  recordIdLabel: 'Row ID',
  deleteRecordsTitle: 'Delete rows',
  containerNotFoundTitle: 'Table not found',
  failedToCreateContainer: 'Failed to create table',
  failedToDeleteContainers: 'Failed to delete tables',
  recordsRefreshedSuccess: 'Rows refreshed successfully',
  failedToRefreshRecords: 'Failed to refresh rows',
  deleteDatabaseContainersDescription:
    'Permanently delete this database and all its tables. This action cannot be undone.',
  deleteDatabaseConfirmSuffix:
    'and all its tables and data? This action cannot be undone.',
  addFirstColumnHint:
    'Use Create column in the header to add your first column.',
  noIndexesYetTitle: 'No indexes yet',
  addFirstIndexHint:
    'Use Create index in the header to add your first index.',
  databaseOverviewTabLabel: 'Tables',
  disabledContainerTitle: 'Table is disabled',
  disabledContainerBodyPrefix: 'This table is currently disabled.',
  paginationItemLabel: 'tables',
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
  searchContainersPlaceholder: 'Search...',
  selectContainerPlaceholder: 'Select collection',
  emptyContainersShortLabel: 'No collections',
  noContainerSearchResultsLabel: 'No collections found',
  sortContainersMenu: 'Sort collections',
  sortContainersAriaLabel: 'Sort collections',
  emptyContainersTitle: 'No collections yet',
  emptyContainersDescription: 'Create your first collection to get started',
  selectContainerHint: 'Select a collection from the sidebar',
  noRecordsYetTitle: 'No documents yet',
  noRecordsFilteredTitle: 'No documents match your filters',
  addFirstRecordHint:
    'Use Create document in the header to add your first document.',
  noRecordSelectedTitle: 'No document selected',
  noRecordSelectedHint:
    'Select a row in the table to view and edit, or use Create document in the header.',
  emptyGridNoSchemaTitle: 'No attributes yet',
  emptyGridNoRecordsTitle: 'No documents found',
  emptyGridNoSchemaHint:
    'This collection has no attributes yet. Create your first attribute to get started.',
  emptyGridHasSchemaHint:
    'This collection is empty. Get started by creating a document or adding attributes.',
  emptyGridHasSchemaWithSampleHint:
    'This collection is empty. Get started by creating a document, adding attributes, or generating sample data.',
  suggestSchemaCardTitle: '',
  suggestSchemaCardHint: '',
  createRecordCardHint: 'Add a new document to this collection',
  createSchemaCardHint: '',
  createSchemaDisabledHint: 'Add attributes with the Documents API or SDK',
  loadingRecordsLabel: 'Loading documents…',
  failedToLoadRecordsTitle: 'Failed to load documents',
  loadingContainersLabel: 'Loading collections…',
  addSchemaForSampleDataHint:
    'Add at least one attribute to generate sample data.',
  oneRecordSelectedLabel: '1 document selected',
  recordsSelectedSuffix: 'documents selected',
  recordUpdatedSuccess: 'Document updated successfully',
  recordCreatedSuccess: 'Document created successfully',
  recordDuplicatedSuccess: 'Document duplicated',
  failedToUpdateRecord: 'Failed to update document',
  failedToCreateRecord: 'Failed to create document',
  failedToDuplicateRecord: 'Failed to duplicate document',
  failedToDeleteRecords: 'Failed to delete documents',
  updateRecord: 'Update document',
  recordIdLabel: 'Document ID',
  deleteRecordsTitle: 'Delete documents',
  containerNotFoundTitle: 'Collection not found',
  failedToCreateContainer: 'Failed to create collection',
  failedToDeleteContainers: 'Failed to delete collections',
  recordsRefreshedSuccess: 'Documents refreshed successfully',
  failedToRefreshRecords: 'Failed to refresh documents',
  deleteDatabaseContainersDescription:
    'Permanently delete this database and all its collections. This action cannot be undone.',
  deleteDatabaseConfirmSuffix:
    'and all its collections and data? This action cannot be undone.',
  addFirstColumnHint: '',
  noIndexesYetTitle: 'No indexes yet',
  addFirstIndexHint:
    'Use Create index in the header to add your first index.',
  databaseOverviewTabLabel: 'Collections',
  disabledContainerTitle: 'Collection is disabled',
  disabledContainerBodyPrefix: 'This collection is currently disabled.',
  paginationItemLabel: 'collections',
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
  searchContainersPlaceholder: 'Search...',
  selectContainerPlaceholder: 'Select collection',
  emptyContainersShortLabel: 'No collections',
  noContainerSearchResultsLabel: 'No collections found',
  sortContainersMenu: 'Sort collections',
  sortContainersAriaLabel: 'Sort collections',
  emptyContainersTitle: 'No collections yet',
  emptyContainersDescription: 'Create your first collection to get started',
  selectContainerHint: 'Select a collection from the sidebar',
  noRecordsYetTitle: 'No documents yet',
  noRecordsFilteredTitle: 'No documents match your filters',
  addFirstRecordHint:
    'Use Create document in the header to add your first document.',
  noRecordSelectedTitle: 'No document selected',
  noRecordSelectedHint:
    'Select a row in the table to view and edit, or use Create document in the header.',
  emptyGridNoSchemaTitle: 'No attributes yet',
  emptyGridNoRecordsTitle: 'No documents found',
  emptyGridNoSchemaHint:
    'This collection has no attributes yet. Create your first attribute to get started.',
  emptyGridHasSchemaHint:
    'This collection is empty. Get started by creating a document or adding attributes.',
  emptyGridHasSchemaWithSampleHint:
    'This collection is empty. Get started by creating a document, adding attributes, or generating sample data.',
  suggestSchemaCardTitle: '',
  suggestSchemaCardHint: '',
  createRecordCardHint: 'Add a new document to this collection',
  createSchemaCardHint: '',
  createSchemaDisabledHint: 'Add attributes with the Vectors API or SDK',
  loadingRecordsLabel: 'Loading documents…',
  failedToLoadRecordsTitle: 'Failed to load documents',
  loadingContainersLabel: 'Loading collections…',
  addSchemaForSampleDataHint:
    'Add at least one attribute to generate sample data.',
  oneRecordSelectedLabel: '1 document selected',
  recordsSelectedSuffix: 'documents selected',
  recordUpdatedSuccess: 'Document updated successfully',
  recordCreatedSuccess: 'Document created successfully',
  recordDuplicatedSuccess: 'Document duplicated',
  failedToUpdateRecord: 'Failed to update document',
  failedToCreateRecord: 'Failed to create document',
  failedToDuplicateRecord: 'Failed to duplicate document',
  failedToDeleteRecords: 'Failed to delete documents',
  updateRecord: 'Update document',
  recordIdLabel: 'Document ID',
  deleteRecordsTitle: 'Delete documents',
  containerNotFoundTitle: 'Collection not found',
  failedToCreateContainer: 'Failed to create collection',
  failedToDeleteContainers: 'Failed to delete collections',
  recordsRefreshedSuccess: 'Documents refreshed successfully',
  failedToRefreshRecords: 'Failed to refresh documents',
  deleteDatabaseContainersDescription:
    'Permanently delete this database and all its collections. This action cannot be undone.',
  deleteDatabaseConfirmSuffix:
    'and all its collections and data? This action cannot be undone.',
  addFirstColumnHint: '',
  noIndexesYetTitle: 'No indexes yet',
  addFirstIndexHint:
    'Use Create index in the header to add your first index.',
  databaseOverviewTabLabel: 'Collections',
  disabledContainerTitle: 'Collection is disabled',
  disabledContainerBodyPrefix: 'This collection is currently disabled.',
  paginationItemLabel: 'collections',
}

export function getDatabaseConsoleLabels(
  kind: DatabaseRouteKind,
): DatabaseConsoleLabels {
  if (kind === 'documentsdb') return DOCUMENTS
  if (kind === 'vectorsdb') return VECTORS
  return TABLES
}

const TRANSLATABLE_DATABASE_LABEL_KEYS = [
  'containerSingular',
  'containerPlural',
  'recordSingular',
  'recordPlural',
  'schemaPlural',
  'containerSingularTitle',
  'containerPluralTitle',
  'recordSingularTitle',
  'recordPluralTitle',
  'gridDataTabLabel',
  'jsonDocumentsTabLabel',
  'schemaSingularTitle',
  'schemaPluralTitle',
  'createContainer',
  'createRecord',
  'createSchema',
  'createIndex',
  'searchContainersPlaceholder',
  'selectContainerPlaceholder',
  'emptyContainersShortLabel',
  'noContainerSearchResultsLabel',
  'sortContainersMenu',
  'sortContainersAriaLabel',
  'emptyContainersTitle',
  'emptyContainersDescription',
  'selectContainerHint',
  'noRecordsYetTitle',
  'noRecordsFilteredTitle',
  'addFirstRecordHint',
  'noRecordSelectedTitle',
  'noRecordSelectedHint',
  'emptyGridNoSchemaTitle',
  'emptyGridNoRecordsTitle',
  'emptyGridNoSchemaHint',
  'emptyGridHasSchemaHint',
  'emptyGridHasSchemaWithSampleHint',
  'suggestSchemaCardTitle',
  'suggestSchemaCardHint',
  'createRecordCardHint',
  'createSchemaCardHint',
  'createSchemaDisabledHint',
  'loadingRecordsLabel',
  'failedToLoadRecordsTitle',
  'loadingContainersLabel',
  'addSchemaForSampleDataHint',
  'oneRecordSelectedLabel',
  'recordsSelectedSuffix',
  'recordUpdatedSuccess',
  'recordCreatedSuccess',
  'recordDuplicatedSuccess',
  'failedToUpdateRecord',
  'failedToCreateRecord',
  'failedToDuplicateRecord',
  'failedToDeleteRecords',
  'updateRecord',
  'recordIdLabel',
  'deleteRecordsTitle',
  'containerNotFoundTitle',
  'failedToCreateContainer',
  'failedToDeleteContainers',
  'recordsRefreshedSuccess',
  'failedToRefreshRecords',
  'deleteDatabaseContainersDescription',
  'deleteDatabaseConfirmSuffix',
  'addFirstColumnHint',
  'noIndexesYetTitle',
  'addFirstIndexHint',
  'databaseOverviewTabLabel',
  'disabledContainerTitle',
  'disabledContainerBodyPrefix',
  'paginationItemLabel',
] as const satisfies readonly (keyof DatabaseConsoleLabels)[]

/** Returns user-facing database labels translated for the active language. */
export function getLocalizedDatabaseConsoleLabels(
  t: Translator,
  kind: DatabaseRouteKind,
): DatabaseConsoleLabels {
  const base = getDatabaseConsoleLabels(kind)
  const localized = { ...base }
  for (const key of TRANSLATABLE_DATABASE_LABEL_KEYS) {
    const value = base[key]
    if (typeof value === 'string' && value.length > 0) {
      localized[key] = t(value)
    }
  }
  return localized
}

export function useDatabaseConsoleLabels(
  kind: DatabaseRouteKind,
): DatabaseConsoleLabels {
  const t = useT()
  return useMemo(
    () => getLocalizedDatabaseConsoleLabels(t, kind),
    [t, kind],
  )
}
