import { describe, expect, test } from 'bun:test'
import {
  isFirewallPromoPath,
  isFirewallSpiderPromoPath,
} from '@/lib/console-banners/firewall-promo-path'

describe('isFirewallPromoPath', () => {
  test('hides the promo on every Firewall page', () => {
    for (const pathname of [
      '/projects/project_1/firewall/create',
      '/projects/project_1/settings/firewall',
      '/projects/project_1/functions/function_1/firewall',
      '/projects/project_1/sites/site_1/firewall',
    ]) {
      expect(isFirewallPromoPath(pathname)).toBe(false)
      expect(isFirewallSpiderPromoPath(pathname)).toBe(false)
    }
  })

  test('keeps the promo on other tabs of a function, site or settings', () => {
    for (const pathname of [
      '/projects/project_1/settings/domains',
      '/projects/project_1/functions/firewall',
      '/projects/project_1/functions/function_1/domains',
      '/projects/project_1/sites/site_1',
    ]) {
      expect(isFirewallSpiderPromoPath(pathname)).toBe(true)
    }
  })
})
