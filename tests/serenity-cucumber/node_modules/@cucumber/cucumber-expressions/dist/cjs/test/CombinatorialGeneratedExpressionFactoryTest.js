"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var CombinatorialGeneratedExpressionFactory_js_1 = __importDefault(require("../src/CombinatorialGeneratedExpressionFactory.js"));
var ParameterType_js_1 = __importDefault(require("../src/ParameterType.js"));
describe('CucumberExpressionGenerator', function () {
    it('generates multiple expressions', function () {
        var parameterTypeCombinations = [
            [
                new ParameterType_js_1.default('color', /red|blue|yellow/, null, function (s) { return s; }, false, true),
                new ParameterType_js_1.default('csscolor', /red|blue|yellow/, null, function (s) { return s; }, false, true),
            ],
            [
                new ParameterType_js_1.default('date', /\d{4}-\d{2}-\d{2}/, null, function (s) { return s; }, false, true),
                new ParameterType_js_1.default('datetime', /\d{4}-\d{2}-\d{2}/, null, function (s) { return s; }, false, true),
                new ParameterType_js_1.default('timestamp', /\d{4}-\d{2}-\d{2}/, null, function (s) { return s; }, false, true),
            ],
        ];
        var factory = new CombinatorialGeneratedExpressionFactory_js_1.default('I bought a {{0}} ball on {{1}}', parameterTypeCombinations);
        var expressions = factory.generateExpressions().map(function (ge) { return ge.source; });
        assert_1.default.deepStrictEqual(expressions, [
            'I bought a {color} ball on {date}',
            'I bought a {color} ball on {datetime}',
            'I bought a {color} ball on {timestamp}',
            'I bought a {csscolor} ball on {date}',
            'I bought a {csscolor} ball on {datetime}',
            'I bought a {csscolor} ball on {timestamp}',
        ]);
    });
});
//# sourceMappingURL=CombinatorialGeneratedExpressionFactoryTest.js.map