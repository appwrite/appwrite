const assert = require('assert')
const t = require('.')

CamelCase: {
  ;[
    ['hi  there', 'hiThere'],
    ['hi-there', 'hiThere'],
    ['hi_there_1', 'hiThere1'],
    ['  hi_there  ', 'hiThere'],
    ['1ApplePlease', '1ApplePlease'],
    ['CON_STAT', 'conStat'],
    ['CaseStatus', 'caseStatus'],
  ].forEach(([input, expected]) => {
    assert.strictEqual(
      t.camelCase(input),
      expected,
      `${t.camelCase(input)} !== ${expected}`,
    )
  })
}

PascalCase: {
  ;[
    ['hi  there', 'HiThere'],
    ['hi-there', 'HiThere'],
    ['hi_there_1', 'HiThere1'],
    ['  hi_there  ', 'HiThere'],
    ['1ApplePlease', '1ApplePlease'],
  ].forEach(([input, expected]) => {
    assert.strictEqual(
      t.pascalCase(input),
      expected,
      `${t.pascalCase(input)} !== ${expected}`,
    )
  })
}

SnakeCase: {
  ;[
    ['hi  there', 'hi_there'],
    ['hi-there', 'hi_there'],
    ['hi_there_1', 'hi_there_1'],
    ['  hi_there  ', 'hi_there'],
    ['1ApplePlease', '1_apple_please'],
  ].forEach(([input, expected]) => {
    assert.strictEqual(
      t.snakeCase(input),
      expected,
      `${t.snakeCase(input)} !== ${expected}`,
    )
  })
}

SentenceCase: {
  ;[
    ['hi  there', 'Hi there'],
    ['hi-There', 'Hi there'],
    ['hi_there_1', 'Hi there 1'],
    ['  hi_there  ', 'Hi there'],
  ].forEach(([input, expected]) => {
    assert.strictEqual(
      t.sentenceCase(input),
      expected,
      `${t.sentenceCase(input)} !== ${expected}`,
    )
  })
}

TitleCase: {
  ;[
    ['hi  there', 'Hi There'],
    ['hi-There', 'Hi There'],
    ['hi_there_1', 'Hi There 1'],
    ['  hi_there  ', 'Hi There'],
  ].forEach(([input, expected]) => {
    assert.strictEqual(
      t.titleCase(input),
      expected,
      `${t.titleCase(input)} !== ${expected}`,
    )
  })
}
