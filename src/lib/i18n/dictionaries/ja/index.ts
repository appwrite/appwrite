import { jaDatabasesDictionary } from './databases'
import { jaSitesDictionary } from './sites'
import { jaFunctionsDictionary } from './functions'
import { jaAuthStorageDictionary } from './auth-storage'
import { jaProjectMiscDictionary } from './project-misc'
import { jaOrganizationsDictionary } from './organizations'
import { jaAccountGlobalDictionary } from './account-global'
import { jaSharedUiDictionary } from './shared-ui'
import { jaMarketingDictionary } from './marketing'
import { jaProductPagesDictionary } from './product-pages'
import { jaPricingDictionary } from './pricing'

/**
 * Merged Japanese dictionary keyed by English source strings.
 * Later entries override earlier ones on key collisions.
 */
export const jaDictionary: Record<string, string> = {
  ...jaMarketingDictionary,
  ...jaProductPagesDictionary,
  ...jaPricingDictionary,
  ...jaSharedUiDictionary,
  ...jaAccountGlobalDictionary,
  ...jaOrganizationsDictionary,
  ...jaProjectMiscDictionary,
  ...jaAuthStorageDictionary,
  ...jaFunctionsDictionary,
  ...jaSitesDictionary,
  ...jaDatabasesDictionary,
}
