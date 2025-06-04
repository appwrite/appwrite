// This is a demo of using knuth-shuffle-seeded with Node.js.

'use strict'

var shuffle = require('./')
  , assert = require('assert')
  , a = [ 2, 11, 37, 42 ]

// shuffle() modifies the original array as well.

// Calling a.slice(0) creates a copy, which is then assigned to b
var copy = a.slice(0)
var b = shuffle(copy)
console.log(copy, b)

// Seed the following two functions the same way. The output should be the
// same.
var c = shuffle(a.slice(0), 2)
var d = shuffle(a.slice(0), 2)
console.log(c, d)

// The seed can be a string too:
var e = shuffle(a.slice(0), 'str')
var f = shuffle(a.slice(0), 'str')
console.log(e, f)

var g = shuffle(a.slice(0), '\ns\0t\rr\uD834')
console.log(g)

// It can even be an object or array, although it is not recommended to do so:
var h = shuffle(a.slice(0), { obj: true })
var i = shuffle(a.slice(0), new Date(0))
var j = shuffle(a.slice(0), a)
console.log(h, i, j)

// But it can't be undefined or null. If it is, then the "seed" is discarded
// and a random one will be used.
