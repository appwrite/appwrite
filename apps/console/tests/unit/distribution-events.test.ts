import { describe, expect, test } from 'bun:test'
import { distributionEvents } from '../../e2e/fixtures/distribution-events'

describe('distributionEvents', () => {
  test('matches the server events for one level', () => {
    expect(
      distributionEvents([['distributionConnections', 'c1']], 'update'),
    ).toEqual([
      'distributionConnections.c1.update',
      'distributionConnections.*.update',
      'distributionConnections.c1',
      'distributionConnections.*',
    ])
  })

  test('matches the server events for two levels', () => {
    expect(
      distributionEvents(
        [
          ['distributionApps', 'a1'],
          ['builds', 'b1'],
        ],
        'update',
      ),
    ).toEqual([
      'distributionApps.a1.builds.b1.update',
      'distributionApps.*.builds.*.update',
      'distributionApps.a1.builds.*.update',
      'distributionApps.*.builds.b1.update',
      'distributionApps.a1.builds.b1',
      'distributionApps.*.builds.*',
      'distributionApps.a1.builds.*',
      'distributionApps.*.builds.b1',
      'distributionApps.a1',
      'distributionApps.*',
    ])
  })

  test('matches the server events for three levels', () => {
    expect(
      distributionEvents(
        [
          ['distributionApps', 'a1'],
          ['builds', 'b1'],
          ['logs', 'l1'],
        ],
        'create',
      ),
    ).toEqual([
      'distributionApps.a1.builds.b1.logs.l1.create',
      'distributionApps.*.builds.*.logs.*.create',
      'distributionApps.a1.builds.*.logs.l1.create',
      'distributionApps.*.builds.*.logs.l1.create',
      'distributionApps.*.builds.b1.logs.l1.create',
      'distributionApps.a1.builds.b1.logs.*.create',
      'distributionApps.*.builds.b1.logs.*.create',
      'distributionApps.a1.builds.*.logs.*.create',
      'distributionApps.a1.builds.b1.logs.l1',
      'distributionApps.*.builds.*.logs.*',
      'distributionApps.a1.builds.*.logs.l1',
      'distributionApps.*.builds.*.logs.l1',
      'distributionApps.*.builds.b1.logs.l1',
      'distributionApps.a1.builds.b1.logs.*',
      'distributionApps.*.builds.b1.logs.*',
      'distributionApps.a1.builds.*.logs.*',
      'distributionApps.a1.builds.b1',
      'distributionApps.*.builds.*',
      'distributionApps.a1.builds.*',
      'distributionApps.*.builds.b1',
      'distributionApps.a1',
      'distributionApps.*',
    ])
  })

  test('rejects anything but one to three levels', () => {
    expect(() => distributionEvents([], 'create')).toThrow()
    expect(() =>
      distributionEvents(
        [
          ['a', '1'],
          ['b', '2'],
          ['c', '3'],
          ['d', '4'],
        ],
        'create',
      ),
    ).toThrow()
  })
})
