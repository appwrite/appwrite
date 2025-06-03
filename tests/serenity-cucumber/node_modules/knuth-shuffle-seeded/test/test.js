// Licensed under the Apache License, version 2.0

'use strict'

var shuffle = require('..')
  , assert = require('assert')
  , test = [ 2, 11, 37, 42, 'adsf', 'blah', { heeeheee: true } ]

it('changes input array', function () {
  var input = test.slice(0)
    , a = shuffle(input)
  assert.deepEqual(a, input)
})

describe('random shuffling', function () {
  it('works', function () {
    var a = shuffle(test.slice(0))
      , b = shuffle(test.slice(0))
      , c = shuffle(test.slice(0))
    // Try three times.
    // The possibility of this test being a false positive is:
    //
    // / 1  \ 3            -12
    // |----|   ≈ 7.81 × 10    ≈ 0.0000000078%
    // \ 7! /
    //
    // That's good enough IMO.
    try {
      assert.notDeepEqual(test, a)
    } catch (e) {
      if (!(e instanceof AssertionError)) throw e
      try {
        assert.notDeepEqual(test, b)
      } catch (e) {
        if (!(e instanceof AssertionError)) throw e
        assert.notDeepEqual(test, c)
      }
    }
  })
})

describe('seeding with a number', function () {
  var a, b

  it('does not crash', function () {
    a = shuffle(test.slice(0), 2)
    b = shuffle(test.slice(0), 2)
  })

  it('output is the same for the same seed', function () {
    assert.deepEqual(a, b)
    assert.deepEqual(a, [ 'blah', { heeeheee: true }, 2, 'adsf', 11, 42, 37 ])
  })
})

describe('seeding with a object', function () {
  var obj1 = { blah: 'ad', bla: 4 }
    , obj2 = new Date()
    , a
    , b

  it('does not crash', function () {
    a = shuffle(test.slice(0), obj1)
    b = shuffle(test.slice(0), obj2)
  })
})

describe('seeding with a string', function () {
  var str = 'Lorem ipsum'
    , a
    , b

  it('does not crash', function () {
    a = shuffle(test.slice(0), str)
    b = shuffle(test.slice(0), str)
  })

  it('output is the same for the same seed', function () {
    assert.deepEqual(a, b)
    assert.deepEqual(a, [ { heeeheee: true }, 2, 'blah', 11, 'adsf', 42, 37 ])
  })
})

describe('errors', function () {
  it('on String input', function () {
    assert.throws(function () {
      shuffle('adf')
    })
  })
  it('on Boolean input', function () {
    assert.throws(function () {
      shuffle(true)
    })
  })
  it('on Object input', function () {
    assert.throws(function () {
      shuffle({ a: true })
    })
  })
  it('on Number input', function () {
    assert.throws(function () {
      shuffle(40)
    })
  })
  it('on null input', function () {
    assert.throws(function () {
      shuffle(null)
    })
  })
  it('on undefined input', function () {
    assert.throws(function () {
      shuffle(undefined)
    })
  })
  it('not on empty array', function () {
    var a = shuffle([])
    assert.deepEqual(a, [])
  })
})
