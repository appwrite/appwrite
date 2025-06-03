var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import assert from 'assert';
import CucumberExpression from '../src/CucumberExpression.js';
import ParameterType from '../src/ParameterType.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
import RegularExpression from '../src/RegularExpression.js';
class Color {
    constructor(name) {
        this.name = name;
    }
}
class CssColor {
    constructor(name) {
        this.name = name;
    }
}
describe('Custom parameter type', () => {
    let parameterTypeRegistry;
    beforeEach(() => {
        parameterTypeRegistry = new ParameterTypeRegistry();
        parameterTypeRegistry.defineParameterType(new ParameterType('color', /red|blue|yellow/, Color, (s) => new Color(s), false, true));
    });
    describe('CucumberExpression', () => {
        it('throws exception for illegal character in parameter name', () => {
            assert.throws(() => new ParameterType('[string]', /.*/, String, (s) => s, false, true), {
                message: "Illegal character in parameter name {[string]}. Parameter names may not contain '{', '}', '(', ')', '\\' or '/'",
            });
        });
        it('matches parameters with custom parameter type', () => {
            var _a;
            const expression = new CucumberExpression('I have a {color} ball', parameterTypeRegistry);
            const value = (_a = expression.match('I have a red ball')) === null || _a === void 0 ? void 0 : _a[0].getValue(null);
            assert.strictEqual(value === null || value === void 0 ? void 0 : value.name, 'red');
        });
        it('matches parameters with multiple capture groups', () => {
            class Coordinate {
                constructor(x, y, z) {
                    this.x = x;
                    this.y = y;
                    this.z = z;
                }
            }
            parameterTypeRegistry.defineParameterType(new ParameterType('coordinate', /(\d+),\s*(\d+),\s*(\d+)/, Coordinate, (x, y, z) => new Coordinate(Number(x), Number(y), Number(z)), true, true));
            const expression = new CucumberExpression('A {int} thick line from {coordinate} to {coordinate}', parameterTypeRegistry);
            const args = expression.match('A 5 thick line from 10,20,30 to 40,50,60');
            const thick = args === null || args === void 0 ? void 0 : args[0].getValue(null);
            assert.strictEqual(thick, 5);
            const from = args === null || args === void 0 ? void 0 : args[1].getValue(null);
            assert.strictEqual(from === null || from === void 0 ? void 0 : from.x, 10);
            assert.strictEqual(from === null || from === void 0 ? void 0 : from.y, 20);
            assert.strictEqual(from === null || from === void 0 ? void 0 : from.z, 30);
            const to = args === null || args === void 0 ? void 0 : args[2].getValue(null);
            assert.strictEqual(to === null || to === void 0 ? void 0 : to.x, 40);
            assert.strictEqual(to === null || to === void 0 ? void 0 : to.y, 50);
            assert.strictEqual(to === null || to === void 0 ? void 0 : to.z, 60);
        });
        it('matches parameters with custom parameter type using optional capture group', () => {
            var _a;
            parameterTypeRegistry = new ParameterTypeRegistry();
            parameterTypeRegistry.defineParameterType(new ParameterType('color', [/red|blue|yellow/, /(?:dark|light) (?:red|blue|yellow)/], Color, (s) => new Color(s), false, true));
            const expression = new CucumberExpression('I have a {color} ball', parameterTypeRegistry);
            const value = (_a = expression.match('I have a dark red ball')) === null || _a === void 0 ? void 0 : _a[0].getValue(null);
            assert.strictEqual(value === null || value === void 0 ? void 0 : value.name, 'dark red');
        });
        it('defers transformation until queried from argument', () => {
            parameterTypeRegistry.defineParameterType(new ParameterType('throwing', /bad/, null, (s) => {
                throw new Error(`Can't transform [${s}]`);
            }, false, true));
            const expression = new CucumberExpression('I have a {throwing} parameter', parameterTypeRegistry);
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            const args = expression.match('I have a bad parameter');
            assert.throws(() => args[0].getValue(null), {
                message: "Can't transform [bad]",
            });
        });
        describe('conflicting parameter type', () => {
            it('is detected for type name', () => {
                assert.throws(() => parameterTypeRegistry.defineParameterType(new ParameterType('color', /.*/, CssColor, (s) => new CssColor(s), false, true)), { message: 'There is already a parameter type with name color' });
            });
            it('is not detected for type', () => {
                parameterTypeRegistry.defineParameterType(new ParameterType('whatever', /.*/, Color, (s) => new Color(s), false, false));
            });
            it('is not detected for regexp', () => {
                var _a, _b, _c, _d, _e, _f, _g, _h;
                parameterTypeRegistry.defineParameterType(new ParameterType('css-color', /red|blue|yellow/, CssColor, (s) => new CssColor(s), true, false));
                assert.strictEqual((_b = (_a = new CucumberExpression('I have a {css-color} ball', parameterTypeRegistry)
                    .match('I have a blue ball')) === null || _a === void 0 ? void 0 : _a[0].getValue(null)) === null || _b === void 0 ? void 0 : _b.constructor, CssColor);
                assert.strictEqual((_d = (_c = new CucumberExpression('I have a {css-color} ball', parameterTypeRegistry)
                    .match('I have a blue ball')) === null || _c === void 0 ? void 0 : _c[0].getValue(null)) === null || _d === void 0 ? void 0 : _d.name, 'blue');
                assert.strictEqual((_f = (_e = new CucumberExpression('I have a {color} ball', parameterTypeRegistry)
                    .match('I have a blue ball')) === null || _e === void 0 ? void 0 : _e[0].getValue(null)) === null || _f === void 0 ? void 0 : _f.constructor, Color);
                assert.strictEqual((_h = (_g = new CucumberExpression('I have a {color} ball', parameterTypeRegistry)
                    .match('I have a blue ball')) === null || _g === void 0 ? void 0 : _g[0].getValue(null)) === null || _h === void 0 ? void 0 : _h.name, 'blue');
            });
        });
        // JavaScript-specific
        it('creates arguments using async transform', () => __awaiter(void 0, void 0, void 0, function* () {
            parameterTypeRegistry = new ParameterTypeRegistry();
            parameterTypeRegistry.defineParameterType(new ParameterType('asyncColor', /red|blue|yellow/, Color, (s) => __awaiter(void 0, void 0, void 0, function* () { return new Color(s); }), false, true));
            const expression = new CucumberExpression('I have a {asyncColor} ball', parameterTypeRegistry);
            const args = expression.match('I have a red ball');
            const value = yield (args === null || args === void 0 ? void 0 : args[0].getValue(null));
            assert.strictEqual(value === null || value === void 0 ? void 0 : value.name, 'red');
        }));
    });
    describe('RegularExpression', () => {
        it('matches arguments with custom parameter type', () => {
            var _a;
            const expression = new RegularExpression(/I have a (red|blue|yellow) ball/, parameterTypeRegistry);
            const value = (_a = expression.match('I have a red ball')) === null || _a === void 0 ? void 0 : _a[0].getValue(null);
            assert.strictEqual(value === null || value === void 0 ? void 0 : value.constructor, Color);
            assert.strictEqual(value === null || value === void 0 ? void 0 : value.name, 'red');
        });
    });
});
//# sourceMappingURL=CustomParameterTypeTest.js.map