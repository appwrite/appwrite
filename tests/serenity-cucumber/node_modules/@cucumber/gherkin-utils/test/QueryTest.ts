import { GherkinStreams } from '@cucumber/gherkin-streams'
import * as messages from '@cucumber/messages'
import { pipeline, Readable, Writable } from 'stream'
import assert from 'assert'
import Query from '../src/Query'
import { promisify } from 'util'

const pipelinePromise = promisify(pipeline)

describe('Query', () => {
  let gherkinQuery: Query
  let envelopes: messages.Envelope[]
  beforeEach(() => {
    envelopes = []
    gherkinQuery = new Query()
  })

  describe('#getLocation(astNodeId)', () => {
    it('looks up a scenario line number', async () => {
      await parse(
        `Feature: hello
  Scenario: hi
    Given a passed step
`
      )
      const pickle = envelopes.find((e) => e.pickle).pickle
      const gherkinScenarioId = pickle.astNodeIds[0]
      const location = gherkinQuery.getLocation(gherkinScenarioId)
      assert.deepStrictEqual(location.line, 2)
    })

    it('looks up a step line number', async () => {
      await parse(
        `Feature: hello
  Scenario: hi
    Given a passed step
`
      )
      const pickleStep = envelopes.find((e) => e.pickle).pickle.steps[0]
      const gherkinStepId = pickleStep.astNodeIds[0]
      const location = gherkinQuery.getLocation(gherkinStepId)
      assert.deepStrictEqual(location.line, 3)
    })
  })

  describe('#getPickleIds(uri, astNodeId)', () => {
    it('looks up pickle IDs for a scenario', async () => {
      await parse(
        `Feature: hello
  Background:
    Given a background step

  Scenario: hi
    Given a passed step
`
      )

      const gherkinDocument = envelopes.find((envelope) => envelope.gherkinDocument).gherkinDocument
      const scenario = gherkinDocument.feature.children.find((child) => child.scenario).scenario

      const pickleId = envelopes.find((e) => e.pickle).pickle.id
      const pickleIds = gherkinQuery.getPickleIds('test.feature', scenario.id)
      assert.deepStrictEqual(pickleIds, [pickleId])
    })

    it('looks up pickle IDs for a whole document', async () => {
      await parse(
        `Feature: hello
  Scenario:
    Given a failed step

  Scenario: hi
    Given a passed step
`
      )
      const expectedPickleIds = envelopes.filter((e) => e.pickle).map((e) => e.pickle.id)
      const pickleIds = gherkinQuery.getPickleIds('test.feature')
      assert.deepStrictEqual(pickleIds, expectedPickleIds)
    })

    it.skip('fails to look up pickle IDs for a step', async () => {
      await parse(
        `Feature: hello
  Background:
    Given a background step

  Scenario: hi
    Given a passed step
`
      )

      assert.throws(() => gherkinQuery.getPickleIds('test.feature', 'some-non-existing-id'), {
        message: 'No values found for key 6. Keys: [some-non-existing-id]',
      })
    })

    it('avoids dupes and ignores empty scenarios', async () => {
      await parse(
        `Feature: Examples and empty scenario

  Scenario: minimalistic
    Given the <what>

    Examples:
      | what |
      | foo  |

    Examples:
      | what |
      | bar  |

  Scenario: ha ok
`
      )

      const pickleIds = gherkinQuery.getPickleIds('test.feature')
      // One for each table, and one for the empty scenario
      // https://github.com/cucumber/cucumber/issues/249
      assert.strictEqual(pickleIds.length, 3, pickleIds.join(','))
    })
  })

  describe('#getPickleStepIds(astNodeId', () => {
    it('returns an empty list when the ID is unknown', async () => {
      await parse('Feature: An empty feature')

      assert.deepEqual(gherkinQuery.getPickleStepIds('whatever-id'), [])
    })

    it('returns the pickle step IDs corresponding the a scenario step', async () => {
      await parse(
        `Feature: hello
  Scenario:
    Given a failed step
`
      )

      const pickleStepIds = envelopes
        .find((envelope) => envelope.pickle)
        .pickle.steps.map((pickleStep) => pickleStep.id)

      const stepId = envelopes.find((envelope) => envelope.gherkinDocument).gherkinDocument.feature
        .children[0].scenario.steps[0].id

      assert.deepEqual(gherkinQuery.getPickleStepIds(stepId), pickleStepIds)
    })

    context('when a step has multiple pickle step', () => {
      it('returns all pickleStepIds linked to a background step', async () => {
        await parse(
          `Feature: hello
  Background:
    Given a step that will have 2 pickle steps

  Scenario:
    Given a step that will only have 1 pickle step

    Scenario:
    Given a step that will only have 1 pickle step
  `
        )

        const backgroundStepId = envelopes.find((envelope) => envelope.gherkinDocument)
          .gherkinDocument.feature.children[0].background.steps[0].id

        const pickleStepIds = envelopes
          .filter((envelope) => envelope.pickle)
          .map((envelope) => envelope.pickle.steps[0].id)

        assert.deepEqual(gherkinQuery.getPickleStepIds(backgroundStepId), pickleStepIds)
      })

      it('return all pickleStepIds linked to a step in a scenario with examples', async () => {
        await parse(
          `Feature: hello
  Scenario:
    Given a passed step
    And a <status> step

    Examples:
      | status |
      | passed |
      | failed |
`
        )

        const scenarioStepId = envelopes.find((envelope) => envelope.gherkinDocument)
          .gherkinDocument.feature.children[0].scenario.steps[1].id

        const pickleStepIds = envelopes
          .filter((envelope) => envelope.pickle)
          .map((envelope) => envelope.pickle.steps[1].id)

        assert.deepEqual(gherkinQuery.getPickleStepIds(scenarioStepId), pickleStepIds)
      })
    })
  })

  function parse(gherkinSource: string): Promise<void> {
    const writable = new Writable({
      objectMode: true,
      write(
        envelope: messages.Envelope,
        encoding: string,
        callback: (error?: Error | null) => void
      ): void {
        envelopes.push(envelope)
        try {
          gherkinQuery.update(envelope)
          callback()
        } catch (err) {
          callback(err)
        }
      },
    })
    return pipelinePromise(gherkinMessages(gherkinSource, 'test.feature'), writable)
  }

  function gherkinMessages(gherkinSource: string, uri: string): Readable {
    const source: messages.Envelope = {
      source: {
        uri,
        data: gherkinSource,
        mediaType: messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN,
      },
    }

    const newId = messages.IdGenerator.incrementing()
    return GherkinStreams.fromSources([source], { newId })
  }
})
