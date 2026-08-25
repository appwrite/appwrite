/**
 * Table filters and search – unified URL contract, filter state, and column contract.
 *
 * See TABLE_FILTERS_AND_SEARCH.md for the full rebuild guide and migration steps.
 */

export type {
  FilterTagValue,
  CompactFilterKey,
  FilterMap,
  FilterColumn,
  FilterColumnType,
  FilterOperatorDef,
} from './types'

export {
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  parseSort,
  encodeSort,
  queryParamToMap,
  mapToQueryParam,
  compactFilterKeysEqual,
  findCompactFilterKeyInMap,
  buildListSearchParams,
  parseListSearch,
  urlFromRouterLocation,
  searchParamsFromRouterLocation,
  MIN_SEARCH_LENGTH,
  PARAM_SEARCH,
  PARAM_QUERY,
  PARAM_PAGE,
  PARAM_LIMIT,
  PARAM_SORT,
} from './url'
export type { ListSearchParams, ListSortParams } from './url'

export {
  FILTER_OPERATORS,
  getOperatorsForType,
  getOperatorsForColumn,
  buildFilterQueryString,
  buildFilterTag,
  buildFilterTagFromCompactKey,
} from './operators'

export { listSearchSchema } from './search-schema'
export type { ListSearch } from './search-schema'

export { usersFilterColumns } from './filter-configs/users'
export { teamsFilterColumns } from './filter-configs/teams'
export { bucketsFilterColumns } from './filter-configs/buckets'
export { filesFilterColumns } from './filter-configs/files'
export {
  databasesFilterColumns,
  getDatabasesFilterColumns,
  getDatabaseTypeFilterOptions,
  getSelectedDatabaseTypesFromFilterMap,
  omitDatabaseTypeFilters,
  setSelectedDatabaseTypesInFilterMap,
  DATABASE_TYPE_FILTER_COLUMN_ID,
} from './filter-configs/databases'
export { functionsFilterColumns } from './filter-configs/functions'
export { sitesFilterColumns } from './filter-configs/sites'
export { domainsFilterColumns } from './filter-configs/domains'
export { dnsRecordsFilterColumns } from './filter-configs/dns-records'
export { deploymentsFilterColumns } from './filter-configs/deployments'
export { executionsFilterColumns } from './filter-configs/executions'
export {
  getActivitiesFilterColumns,
  activitiesFilterColumns,
} from './filter-configs/activities'
export {
  getActivityFilterQueryParts,
  maxIso,
  minIso,
} from './activity-filters'
export { proxyRulesFilterColumns } from './filter-configs/proxy-rules'
export { tableColumnsFilterColumns } from './filter-configs/table-columns'
export { tableIndexesFilterColumns } from './filter-configs/table-indexes'
export {
  rowsFilterColumnsFromAttributes,
  appendDocumentsDbCustomAttributeFilter,
  DOCUMENTS_DB_CUSTOM_ATTRIBUTE_FILTER_COLUMN_ID,
  type TableIndexForFilters,
} from './filter-configs/rows'
export { postgresRowsFilterColumns } from './filter-configs/postgres-rows'
export { mysqlRowsFilterColumns } from './filter-configs/mysql-rows'
export {
  buildPostgresFilterSqlCondition,
  buildPostgresFilterWhereClause,
  buildPostgresTextSearchWhereClause,
  combinePostgresWhereClauses,
} from './sql/postgres'
export {
  buildMysqlFilterSqlCondition,
  buildMysqlFilterWhereClause,
  buildMysqlTextSearchWhereClause,
  combineMysqlWhereClauses,
} from './sql/mysql'

export {
  SIZE_FILTER_UNITS,
  sizeFilterToBytes,
  bytesToSizeFilterInput,
} from './size-filter'
export {
  recordMatchesCompactKey,
  filterRecordsByCompactMap,
} from './client-side-filter'
