import { heDatabasesDictionary } from './databases'
import { heSitesDictionary } from './sites'
import { heFunctionsDictionary } from './functions'
import { heAuthStorageDictionary } from './auth-storage'
import { heProjectMiscDictionary } from './project-misc'
import { heOrganizationsDictionary } from './organizations'
import { heAccountGlobalDictionary } from './account-global'
import { heSharedUiDictionary } from './shared-ui'
import { heMarketingDictionary } from './marketing'
import { heProductPagesDictionary } from './product-pages'
import { hePricingDictionary } from './pricing'
import { heVideosDictionary } from './videos'
import { heAlternativesDictionary } from './alternatives'
import { heEnterpriseDictionary } from './enterprise'
import { heCampaignsDictionary } from './campaigns'

/**
 * Merged Hebrew dictionary keyed by English source strings.
 * Later entries override earlier ones on key collisions.
 */
export const heDictionary: Record<string, string> = {
  ...heCampaignsDictionary,
  ...heEnterpriseDictionary,
  ...heAlternativesDictionary,
  ...heMarketingDictionary,
  ...heProductPagesDictionary,
  ...hePricingDictionary,
  ...heSharedUiDictionary,
  ...heAccountGlobalDictionary,
  ...heOrganizationsDictionary,
  ...heProjectMiscDictionary,
  ...heAuthStorageDictionary,
  ...heFunctionsDictionary,
  ...heSitesDictionary,
  ...heDatabasesDictionary,
  ...heVideosDictionary,
}
