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
  buildListSearchParams,
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
export { databasesFilterColumns } from './filter-configs/databases'
export { functionsFilterColumns } from './filter-configs/functions'
export { sitesFilterColumns } from './filter-configs/sites'
export { domainsFilterColumns } from './filter-configs/domains'
export { dnsRecordsFilterColumns } from './filter-configs/dns-records'
export { deploymentsFilterColumns } from './filter-configs/deployments'
export { executionsFilterColumns } from './filter-configs/executions'
export { proxyRulesFilterColumns } from './filter-configs/proxy-rules'
export { tableColumnsFilterColumns } from './filter-configs/table-columns'
export { tableIndexesFilterColumns } from './filter-configs/table-indexes'
export {
  rowsFilterColumnsFromAttributes,
  type TableIndexForFilters,
} from './filter-configs/rows'

export { SIZE_FILTER_UNITS, sizeFilterToBytes } from './size-filter'
export {
  recordMatchesCompactKey,
  filterRecordsByCompactMap,
} from './client-side-filter'
