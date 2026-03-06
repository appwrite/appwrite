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
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  PARAM_SEARCH,
  PARAM_QUERY,
  PARAM_PAGE,
  PARAM_LIMIT,
} from './url'
export type { ListSearchParams } from './url'

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

export { SIZE_FILTER_UNITS, sizeFilterToBytes } from './size-filter'
