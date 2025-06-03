export default interface Dialect {
  readonly name: string
  readonly native: string
  readonly feature: readonly string[]
  readonly background: readonly string[]
  readonly rule: readonly string[]
  readonly scenario: readonly string[]
  readonly scenarioOutline: readonly string[]
  readonly examples: readonly string[]
  readonly given: readonly string[]
  readonly when: readonly string[]
  readonly then: readonly string[]
  readonly and: readonly string[]
  readonly but: readonly string[]
}
