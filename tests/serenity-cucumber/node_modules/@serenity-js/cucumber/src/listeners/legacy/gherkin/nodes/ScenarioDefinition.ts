import type { ASTNode } from './ASTNode';
import type { Step } from './Step';
import type { Tag } from './Tag';

/**
 * @private
 */
export interface ScenarioDefinition extends ASTNode {
    type: 'Background' | 'Scenario' | 'ScenarioOutline';
    tags: Tag[];
    keyword: string;
    name: string;
    description: string;
    steps: Step[];
}
