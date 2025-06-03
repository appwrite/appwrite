import assert from 'assert'
import GherkinDocumentWalker, { rejectAllFilters } from '../src/GherkinDocumentWalker'
import pretty from '../src/pretty'
import parse from './parse'

describe('GherkinDocumentWalker', () => {
  let walker: GherkinDocumentWalker

  beforeEach(() => {
    walker = new GherkinDocumentWalker()
  })

  function assertCopy(copy: any, source: any) {
    assert.deepEqual(copy, source)
    assert.notEqual(copy, source)
  }

  it('returns a deep copy', () => {
    const gherkinDocument = parse(`@featureTag
Feature: hello
  This feature has a description

  Background: Base Background
    This is a described background
    Given a passed step

  @scenarioTag
  Scenario: salut
    Yes, there is a description here too

  @ruleTag
  Rule: roule
    Can we describe a Rule ?

    Background: poupidou
    Scenario: pouet
`)
    const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)

    assertCopy(newGherkinDocument, gherkinDocument)
    assertCopy(newGherkinDocument.feature, gherkinDocument.feature)
    assertCopy(
      newGherkinDocument.feature.children[0].background,
      gherkinDocument.feature.children[0].background
    )
    assertCopy(
      newGherkinDocument.feature.children[1].scenario,
      gherkinDocument.feature.children[1].scenario
    )
    assertCopy(
      newGherkinDocument.feature.children[2].rule,
      gherkinDocument.feature.children[2].rule
    )
    assertCopy(
      newGherkinDocument.feature.children[2].rule.children[1],
      gherkinDocument.feature.children[2].rule.children[1]
    )
    assertCopy(
      newGherkinDocument.feature.children[0].background.steps,
      gherkinDocument.feature.children[0].background.steps
    )
  })

  context('filtering objects', () => {
    it('filters one scenario', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptScenario: (scenario) => scenario.name === 'Earth' },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Scenario: Earth
    Given is a planet with liquid water
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('keeps scenario with search hit in step', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptStep: (step) => step.text.includes('liquid') },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Scenario: Earth
    Given is a planet with liquid water
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('does not leave null object as a feature child', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptScenario: (scenario) => scenario.name === 'Earth' },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      assert.deepStrictEqual(
        newGherkinDocument.feature.children.filter((child) => child === null),
        []
      )
    })

    it('keeps a hit scenario even when no steps match', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptScenario: (scenario) => scenario.name === 'Saturn' },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    // TODO before merging https://github.com/cucumber/cucumber/pull/1419
    xit('keeps a hit background', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy
    Background: Milky Way
      Given it contains our system

  Rule: Black Hole
    Background: TON 618
      Given it exists
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{
          acceptBackground: (background) => background.name === 'Milky Way',
        },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy

    Background: Milky Way
      Given it contains our system
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('keeps a hit in background step', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy
    Background: Milky Way
      Given it contains our system

  Rule: Black Hole
    Background: TON 618
      Given it exists
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptStep: (step) => step.text.includes('space') },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy

    Background: Milky Way
      Given it contains our system

  Rule: Black Hole

    Background: TON 618
      Given it exists
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    // TODO before merging https://github.com/cucumber/cucumber/pull/1419
    xit('keeps scenario in rule', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptScenario: (scenario) => scenario.name === 'Andromeda' },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Andromeda
      Given it exists
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('keeps scenario and background in rule', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptRule: (rule) => rule.name === 'Galaxy' },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('only keeps rule and its content', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Milky Way
    Given it contains our system

  Rule: Galaxy

    Scenario: Andromeda
      Given it exists
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        ...{ acceptRule: () => true },
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Scenario: Andromeda
      Given it exists
`
      assert.strictEqual(newSource, expectedNewSource)
    })

    it('return a feature and keep scenario', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker({
        ...rejectAllFilters,
        acceptFeature: (feature) => feature.name === 'Solar System',
      })
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      const newSource = pretty(newGherkinDocument, 'gherkin')
      const expectedNewSource = `Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`
      assert.deepStrictEqual(newSource, expectedNewSource)
    })

    it('returns null when no hit found', () => {
      const gherkinDocument = parse(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`)

      const walker = new GherkinDocumentWalker(rejectAllFilters)
      const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument)
      assert.deepEqual(newGherkinDocument, null)
    })
  })

  context('handling objects', () => {
    describe('handleStep', () => {
      it('is called for each steps', () => {
        const gherkinDocument = parse(`Feature: Solar System

        Scenario: Earth
          Given it is a planet
`)

        const stepText: string[] = []
        const astWalker = new GherkinDocumentWalker(
          {},
          {
            handleStep: (step) => stepText.push(step.text),
          }
        )
        astWalker.walkGherkinDocument(gherkinDocument)

        assert.deepEqual(stepText, ['it is a planet'])
      })
    })

    describe('handleScenario', () => {
      it('is called for each scenarios', () => {
        const gherkinDocument = parse(`Feature: Solar System

        Scenario: Earth
          Given it is a planet

        Scenario: Saturn
          Given it's not a liquid planet
`)

        const scenarioName: string[] = []
        const astWalker = new GherkinDocumentWalker(
          {},
          {
            handleScenario: (scenario) => scenarioName.push(scenario.name),
          }
        )
        astWalker.walkGherkinDocument(gherkinDocument)

        assert.deepEqual(scenarioName, ['Earth', 'Saturn'])
      })
    })

    describe('handleBackground', () => {
      it('is called for each backgrounds', () => {
        const gherkinDocument = parse(`Feature: Solar System

        Background: Milky Way
          Scenario: Earth
            Given it is our galaxy
`)

        const backgroundName: string[] = []
        const astWalker = new GherkinDocumentWalker(
          {},
          {
            handleBackground: (background) => backgroundName.push(background.name),
          }
        )
        astWalker.walkGherkinDocument(gherkinDocument)

        assert.deepEqual(backgroundName, ['Milky Way'])
      })
    })

    describe('handleRule', () => {
      it('is called for each rules', () => {
        const gherkinDocument = parse(`Feature: Solar System

        Rule: On a planet
          Scenario: There is life
            Given there is water

        Rule: On an exoplanet
          Scenario: There is extraterrestrial life
            Given there is a non-humanoid form of life
`)

        const ruleName: string[] = []
        const astWalker = new GherkinDocumentWalker(
          {},
          {
            handleRule: (rule) => ruleName.push(rule.name),
          }
        )
        astWalker.walkGherkinDocument(gherkinDocument)

        assert.deepEqual(ruleName, ['On a planet', 'On an exoplanet'])
      })
    })

    describe('handleFeature', () => {
      it('is called for each features', () => {
        const gherkinDocument = parse(`Feature: Solar System

        Rule: On a planet
          Scenario: There is life
            Given there is water

        Rule: On an exoplanet
          Scenario: There is extraterrestrial life
            Given there is a non-humanoid form of life
`)

        const featureName: string[] = []
        const astWalker = new GherkinDocumentWalker(
          {},
          {
            handleFeature: (feature) => featureName.push(feature.name),
          }
        )
        astWalker.walkGherkinDocument(gherkinDocument)

        assert.deepEqual(featureName, ['Solar System'])
      })
    })
  })

  describe('regression tests', () => {
    it('does not fail with empty/commented documents', () => {
      const gherkinDocument = parse('# Feature: Solar System')
      const astWalker = new GherkinDocumentWalker()

      astWalker.walkGherkinDocument(gherkinDocument)
    })
  })
})
