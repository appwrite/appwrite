import { describe, expect, test } from 'bun:test'
import {
  projectEnvironmentDotClass,
  type ProjectEnvironmentOption,
} from '@/lib/project-environments'

const PROJECT: ProjectEnvironmentOption = {
  id: 'proj0000000000000000001',
  name: 'Acme',
  isDefault: true,
}

describe('projectEnvironmentDotClass', () => {
  const PROJECT_DOT = projectEnvironmentDotClass(PROJECT)
  const environmentDots = [
    'staging',
    'preview',
    'eu',
    'a',
    '0',
    'z'.repeat(36),
  ].map((id) => projectEnvironmentDotClass({ id, name: id, isDefault: false }))

  test('gives the project the same color whatever its id', () => {
    expect(
      projectEnvironmentDotClass({ ...PROJECT, id: 'anything-else' }),
    ).toBe(PROJECT_DOT)
  })

  test('spreads environments across the palette instead of collapsing them', () => {
    // The color is derived from the id precisely so two environments do not
    // look alike; a constant or broken hash collapses these to one class.
    expect(new Set(environmentDots).size).toBeGreaterThan(1)
  })

  test('reserves the project color for the project', () => {
    expect(environmentDots).not.toContain(PROJECT_DOT)
  })
})
