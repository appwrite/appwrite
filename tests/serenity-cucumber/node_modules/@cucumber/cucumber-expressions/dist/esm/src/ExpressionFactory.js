import CucumberExpression from './CucumberExpression.js';
import RegularExpression from './RegularExpression.js';
export default class ExpressionFactory {
    constructor(parameterTypeRegistry) {
        this.parameterTypeRegistry = parameterTypeRegistry;
    }
    createExpression(expression) {
        return typeof expression === 'string'
            ? new CucumberExpression(expression, this.parameterTypeRegistry)
            : new RegularExpression(expression, this.parameterTypeRegistry);
    }
}
//# sourceMappingURL=ExpressionFactory.js.map