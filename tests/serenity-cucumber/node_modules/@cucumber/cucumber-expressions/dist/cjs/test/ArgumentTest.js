"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert = __importStar(require("assert"));
var Argument_js_1 = __importDefault(require("../src/Argument.js"));
var ParameterTypeRegistry_js_1 = __importDefault(require("../src/ParameterTypeRegistry.js"));
var TreeRegexp_js_1 = __importDefault(require("../src/TreeRegexp.js"));
describe('Argument', function () {
    it('exposes getParameterTypeName()', function () {
        var treeRegexp = new TreeRegexp_js_1.default('three (.*) mice');
        var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
        var group = treeRegexp.match('three blind mice');
        var args = Argument_js_1.default.build(group, [parameterTypeRegistry.lookupByTypeName('string')]);
        var argument = args[0];
        assert.strictEqual(argument.getParameterType().name, 'string');
    });
});
//# sourceMappingURL=ArgumentTest.js.map