var FUNCTIONS = {};

/**
 * Create a function wrapper that specifies the argument length.
 *
 * @param  {number}   arity
 * @param  {Function} fn
 * @return {Function}
 */
module.exports = function (arity, fn) {
  if (!FUNCTIONS[arity]) {
    var params = [];

    if (typeof arity !== 'number') {
      throw new TypeError('Expected arity to be a number, got ' + arity);
    }

    for (var i = 0; i < arity; i++) {
      params.push('_' + i);
    }

    FUNCTIONS[arity] = new Function(
      'fn',
      'return function arity' + arity + ' (' + params.join(', ') + ') { return fn.apply(this, arguments); }'
    );
  }

  return FUNCTIONS[arity](fn);
};
