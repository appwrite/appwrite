"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
var OPERAND = 'operand';
var OPERATOR = 'operator';
var PREC = {
    '(': -2,
    ')': -1,
    or: 0,
    and: 1,
    not: 2,
};
var ASSOC = {
    or: 'left',
    and: 'left',
    not: 'right',
};
/**
 * Parses infix boolean expression (using Dijkstra's Shunting Yard algorithm)
 * and builds a tree of expressions. The root node of the expression is returned.
 *
 * This expression can be evaluated by passing in an array of literals that resolve to true
 */
function parse(infix) {
    var tokens = tokenize(infix);
    if (tokens.length === 0) {
        return new True();
    }
    var expressions = [];
    var operators = [];
    var expectedTokenType = OPERAND;
    tokens.forEach(function (token) {
        if (isUnary(token)) {
            check(expectedTokenType, OPERAND);
            operators.push(token);
            expectedTokenType = OPERAND;
        }
        else if (isBinary(token)) {
            check(expectedTokenType, OPERATOR);
            while (operators.length > 0 &&
                isOp(peek(operators)) &&
                ((ASSOC[token] === 'left' && PREC[token] <= PREC[peek(operators)]) ||
                    (ASSOC[token] === 'right' && PREC[token] < PREC[peek(operators)]))) {
                pushExpr(pop(operators), expressions);
            }
            operators.push(token);
            expectedTokenType = OPERAND;
        }
        else if ('(' === token) {
            check(expectedTokenType, OPERAND);
            operators.push(token);
            expectedTokenType = OPERAND;
        }
        else if (')' === token) {
            check(expectedTokenType, OPERATOR);
            while (operators.length > 0 && peek(operators) !== '(') {
                pushExpr(pop(operators), expressions);
            }
            if (operators.length === 0) {
                throw new Error("Tag expression \"".concat(infix, "\" could not be parsed because of syntax error: Unmatched )."));
            }
            if (peek(operators) === '(') {
                pop(operators);
            }
            expectedTokenType = OPERATOR;
        }
        else {
            check(expectedTokenType, OPERAND);
            pushExpr(token, expressions);
            expectedTokenType = OPERATOR;
        }
    });
    while (operators.length > 0) {
        if (peek(operators) === '(') {
            throw new Error("Tag expression \"".concat(infix, "\" could not be parsed because of syntax error: Unmatched (."));
        }
        pushExpr(pop(operators), expressions);
    }
    return pop(expressions);
    function check(expectedTokenType, tokenType) {
        if (expectedTokenType !== tokenType) {
            throw new Error("Tag expression \"".concat(infix, "\" could not be parsed because of syntax error: Expected ").concat(expectedTokenType, "."));
        }
    }
}
exports.default = parse;
function tokenize(expr) {
    var tokens = [];
    var isEscaped = false;
    var token = [];
    for (var i = 0; i < expr.length; i++) {
        var c = expr.charAt(i);
        if (isEscaped) {
            if (c === '(' || c === ')' || c === '\\' || /\s/.test(c)) {
                token.push(c);
                isEscaped = false;
            }
            else {
                throw new Error("Tag expression \"".concat(expr, "\" could not be parsed because of syntax error: Illegal escape before \"").concat(c, "\"."));
            }
        }
        else if (c === '\\') {
            isEscaped = true;
        }
        else if (c === '(' || c === ')' || /\s/.test(c)) {
            if (token.length > 0) {
                tokens.push(token.join(''));
                token = [];
            }
            if (!/\s/.test(c)) {
                tokens.push(c);
            }
        }
        else {
            token.push(c);
        }
    }
    if (token.length > 0) {
        tokens.push(token.join(''));
    }
    return tokens;
}
function isUnary(token) {
    return 'not' === token;
}
function isBinary(token) {
    return 'or' === token || 'and' === token;
}
function isOp(token) {
    return ASSOC[token] !== undefined;
}
function peek(stack) {
    return stack[stack.length - 1];
}
function pop(stack) {
    if (stack.length === 0) {
        throw new Error('empty stack');
    }
    return stack.pop();
}
function pushExpr(token, stack) {
    if (token === 'and') {
        var rightAndExpr = pop(stack);
        stack.push(new And(pop(stack), rightAndExpr));
    }
    else if (token === 'or') {
        var rightOrExpr = pop(stack);
        stack.push(new Or(pop(stack), rightOrExpr));
    }
    else if (token === 'not') {
        stack.push(new Not(pop(stack)));
    }
    else {
        stack.push(new Literal(token));
    }
}
var Literal = /** @class */ (function () {
    function Literal(value) {
        this.value = value;
    }
    Literal.prototype.evaluate = function (variables) {
        return variables.indexOf(this.value) !== -1;
    };
    Literal.prototype.toString = function () {
        return this.value
            .replace(/\\/g, '\\\\')
            .replace(/\(/g, '\\(')
            .replace(/\)/g, '\\)')
            .replace(/\s/g, '\\ ');
    };
    return Literal;
}());
var Or = /** @class */ (function () {
    function Or(leftExpr, rightExpr) {
        this.leftExpr = leftExpr;
        this.rightExpr = rightExpr;
    }
    Or.prototype.evaluate = function (variables) {
        return this.leftExpr.evaluate(variables) || this.rightExpr.evaluate(variables);
    };
    Or.prototype.toString = function () {
        return '( ' + this.leftExpr.toString() + ' or ' + this.rightExpr.toString() + ' )';
    };
    return Or;
}());
var And = /** @class */ (function () {
    function And(leftExpr, rightExpr) {
        this.leftExpr = leftExpr;
        this.rightExpr = rightExpr;
    }
    And.prototype.evaluate = function (variables) {
        return this.leftExpr.evaluate(variables) && this.rightExpr.evaluate(variables);
    };
    And.prototype.toString = function () {
        return '( ' + this.leftExpr.toString() + ' and ' + this.rightExpr.toString() + ' )';
    };
    return And;
}());
var Not = /** @class */ (function () {
    function Not(expr) {
        this.expr = expr;
    }
    Not.prototype.evaluate = function (variables) {
        return !this.expr.evaluate(variables);
    };
    Not.prototype.toString = function () {
        return 'not ( ' + this.expr.toString() + ' )';
    };
    return Not;
}());
var True = /** @class */ (function () {
    function True() {
    }
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    True.prototype.evaluate = function (variables) {
        return true;
    };
    True.prototype.toString = function () {
        return 'true';
    };
    return True;
}());
//# sourceMappingURL=index.js.map