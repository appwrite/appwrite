import * as assert from 'assert';
import ParameterType from '../src/ParameterType.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
describe('ParameterType', () => {
    it('does not allow ignore flag on regexp', () => {
        assert.throws(() => new ParameterType('case-insensitive', /[a-z]+/i, String, (s) => s, true, true), { message: "ParameterType Regexps can't use flag 'i'" });
    });
    it('has a type name for {int}', () => {
        const r = new ParameterTypeRegistry();
        const t = r.lookupByTypeName('int');
        // @ts-ignore
        assert.strictEqual(t.type.name, 'Number');
    });
    it('has a type name for {bigint}', () => {
        const r = new ParameterTypeRegistry();
        const t = r.lookupByTypeName('biginteger');
        // @ts-ignore
        assert.strictEqual(t.type.name, 'BigInt');
    });
    it('has a type name for {word}', () => {
        const r = new ParameterTypeRegistry();
        const t = r.lookupByTypeName('word');
        // @ts-ignore
        assert.strictEqual(t.type.name, 'String');
    });
});
//# sourceMappingURL=ParameterTypeTest.js.map