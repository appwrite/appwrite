import { describe, expect, test } from 'bun:test'
import type {
  DistributionApp,
  UpdateDistributionAppParams,
} from '@/lib/appwrite/distribution'
import {
  isRepositorySaved,
  unsavedRepositoryFields,
} from '@/lib/distribution/repository'

const APP: DistributionApp = {
  $id: 'shop',
  $createdAt: '2026-11-20T12:00:00.000+00:00',
  $updatedAt: '2026-11-20T12:00:00.000+00:00',
  name: 'Acme Shop',
  enabled: true,
  framework: 'flutter',
  installationId: '',
  providerRepositoryId: '',
  repositoryId: '',
  providerBranch: '',
  providerRootDirectory: '',
  providerBranches: ['main', 'release/*'],
  providerPaths: [],
  providerTags: [],
  providerSilentMode: false,
  providerComments: true,
  providerDeployments: true,
  providerStatuses: true,
  latestBuildId: '',
  latestBuildStatus: '',
  latestBuildCreatedAt: '',
}

describe('unsavedRepositoryFields', () => {
  test('lists the repository fields the returned app does not hold', () => {
    expect(
      unsavedRepositoryFields(
        {
          installationId: 'installation0001',
          providerRepositoryId: '1001',
          providerBranch: 'main',
          providerRootDirectory: './',
          providerSilentMode: false,
        },
        APP,
      ),
    ).toEqual([
      'installationId',
      'providerRepositoryId',
      'providerBranch',
      'providerRootDirectory',
    ])
  })

  test('ignores fields that were not sent and fields outside the repository', () => {
    const sent: UpdateDistributionAppParams = {
      appId: APP.$id,
      name: 'Renamed',
      providerDeployments: true,
    }
    expect(unsavedRepositoryFields(sent, APP)).toEqual([])
  })

  test('compares trigger patterns regardless of order', () => {
    expect(
      isRepositorySaved({ providerBranches: ['release/*', 'main'] }, APP),
    ).toBe(true)
    expect(isRepositorySaved({ providerTags: ['v*'] }, APP)).toBe(false)
  })

  test('detects a flag or repository the server left unchanged', () => {
    expect(isRepositorySaved({ providerStatuses: false }, APP)).toBe(false)
    expect(
      isRepositorySaved(
        {
          installationId: '',
          providerRepositoryId: '',
          providerBranch: '',
          providerRootDirectory: '',
        },
        APP,
      ),
    ).toBe(true)
  })
})
