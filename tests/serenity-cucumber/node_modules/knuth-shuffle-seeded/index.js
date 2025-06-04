/*
 * Copyright 2013 AJ O'Neal
 * Copyright 2015 Tiancheng "Timothy" Gu
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

'use strict'

/**
 * @file
 *
 * Implementation of the Fisher-Yates shuffle algorithm in JavaScript, with
 * the possibility of using a seed to ensure reproducibility.
 *
 * @module knuth-shuffle-seeded
 */

var randGen = require('seed-random')

/**
 * Shuffle an array using the Fisher-Yates shuffle algorithm, aka Knuth
 * shuffle.
 *
 * Note that this function overwrites the initial array. As a result if you
 * would like to keep the original array intact, you have to copy the initial
 * array to a new array.
 *
 * Implementation derived from http://stackoverflow.com/questions/2450954/.
 *
 * @param {Array} array An array that is to be shuffled.
 * @param [seed=Math.random()] Seed for the shuffling operation. If
 *                             unspecified then a random value is used.
 * @return {Array} The resulting array.
 */
module.exports = function shuffle(array, seed) {
  var currentIndex
    , temporaryValue
    , randomIndex
    , rand
  if (seed == null) rand = randGen()
  else              rand = randGen(seed)

  if (array.constructor !== Array) throw new Error('Input is not an array')
  currentIndex = array.length

  // While there remain elements to shuffle...
  while (0 !== currentIndex) {
    // Pick a remaining element...
    randomIndex = Math.floor(rand() * (currentIndex --))

    // And swap it with the current element.
    temporaryValue = array[currentIndex]
    array[currentIndex] = array[randomIndex]
    array[randomIndex] = temporaryValue
  }

  return array
}
