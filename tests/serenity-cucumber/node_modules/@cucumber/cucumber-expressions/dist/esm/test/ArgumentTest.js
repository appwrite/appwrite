import * as assert from 'assert';
import Argument from '../src/Argument.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
import TreeRegexp from '../src/TreeRegexp.js';
describe('Argument', () => {
    it('exposes getParameterTypeName()', () => {
        const treeRegexp = new TreeRegexp('three (.*) mice');
        const parameterTypeRegistry = new ParameterTypeRegistry();
        const group = treeRegexp.match('three blind mice');
        const args = Argument.build(group, [parameterTypeRegistry.lookupByTypeName('string')]);
        const argument = args[0];
        assert.strictEqual(argument.getParameterType().name, 'string');
    });
});
//# sourceMappingURL=ArgumentTest.js.map