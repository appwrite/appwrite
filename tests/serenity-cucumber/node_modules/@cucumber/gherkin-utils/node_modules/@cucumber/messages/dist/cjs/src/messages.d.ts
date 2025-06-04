import 'reflect-metadata';
export declare class Attachment {
    body: string;
    contentEncoding: AttachmentContentEncoding;
    fileName?: string;
    mediaType: string;
    source?: Source;
    testCaseStartedId?: string;
    testStepId?: string;
    url?: string;
}
export declare class Duration {
    seconds: number;
    nanos: number;
}
export declare class Envelope {
    attachment?: Attachment;
    gherkinDocument?: GherkinDocument;
    hook?: Hook;
    meta?: Meta;
    parameterType?: ParameterType;
    parseError?: ParseError;
    pickle?: Pickle;
    source?: Source;
    stepDefinition?: StepDefinition;
    testCase?: TestCase;
    testCaseFinished?: TestCaseFinished;
    testCaseStarted?: TestCaseStarted;
    testRunFinished?: TestRunFinished;
    testRunStarted?: TestRunStarted;
    testStepFinished?: TestStepFinished;
    testStepStarted?: TestStepStarted;
    undefinedParameterType?: UndefinedParameterType;
}
export declare class GherkinDocument {
    uri?: string;
    feature?: Feature;
    comments: readonly Comment[];
}
export declare class Background {
    location: Location;
    keyword: string;
    name: string;
    description: string;
    steps: readonly Step[];
    id: string;
}
export declare class Comment {
    location: Location;
    text: string;
}
export declare class DataTable {
    location: Location;
    rows: readonly TableRow[];
}
export declare class DocString {
    location: Location;
    mediaType?: string;
    content: string;
    delimiter: string;
}
export declare class Examples {
    location: Location;
    tags: readonly Tag[];
    keyword: string;
    name: string;
    description: string;
    tableHeader?: TableRow;
    tableBody: readonly TableRow[];
    id: string;
}
export declare class Feature {
    location: Location;
    tags: readonly Tag[];
    language: string;
    keyword: string;
    name: string;
    description: string;
    children: readonly FeatureChild[];
}
export declare class FeatureChild {
    rule?: Rule;
    background?: Background;
    scenario?: Scenario;
}
export declare class Rule {
    location: Location;
    tags: readonly Tag[];
    keyword: string;
    name: string;
    description: string;
    children: readonly RuleChild[];
    id: string;
}
export declare class RuleChild {
    background?: Background;
    scenario?: Scenario;
}
export declare class Scenario {
    location: Location;
    tags: readonly Tag[];
    keyword: string;
    name: string;
    description: string;
    steps: readonly Step[];
    examples: readonly Examples[];
    id: string;
}
export declare class Step {
    location: Location;
    keyword: string;
    keywordType?: StepKeywordType;
    text: string;
    docString?: DocString;
    dataTable?: DataTable;
    id: string;
}
export declare class TableCell {
    location: Location;
    value: string;
}
export declare class TableRow {
    location: Location;
    cells: readonly TableCell[];
    id: string;
}
export declare class Tag {
    location: Location;
    name: string;
    id: string;
}
export declare class Hook {
    id: string;
    name?: string;
    sourceReference: SourceReference;
    tagExpression?: string;
}
export declare class Location {
    line: number;
    column?: number;
}
export declare class Meta {
    protocolVersion: string;
    implementation: Product;
    runtime: Product;
    os: Product;
    cpu: Product;
    ci?: Ci;
}
export declare class Ci {
    name: string;
    url?: string;
    buildNumber?: string;
    git?: Git;
}
export declare class Git {
    remote: string;
    revision: string;
    branch?: string;
    tag?: string;
}
export declare class Product {
    name: string;
    version?: string;
}
export declare class ParameterType {
    name: string;
    regularExpressions: readonly string[];
    preferForRegularExpressionMatch: boolean;
    useForSnippets: boolean;
    id: string;
}
export declare class ParseError {
    source: SourceReference;
    message: string;
}
export declare class Pickle {
    id: string;
    uri: string;
    name: string;
    language: string;
    steps: readonly PickleStep[];
    tags: readonly PickleTag[];
    astNodeIds: readonly string[];
}
export declare class PickleDocString {
    mediaType?: string;
    content: string;
}
export declare class PickleStep {
    argument?: PickleStepArgument;
    astNodeIds: readonly string[];
    id: string;
    type?: PickleStepType;
    text: string;
}
export declare class PickleStepArgument {
    docString?: PickleDocString;
    dataTable?: PickleTable;
}
export declare class PickleTable {
    rows: readonly PickleTableRow[];
}
export declare class PickleTableCell {
    value: string;
}
export declare class PickleTableRow {
    cells: readonly PickleTableCell[];
}
export declare class PickleTag {
    name: string;
    astNodeId: string;
}
export declare class Source {
    uri: string;
    data: string;
    mediaType: SourceMediaType;
}
export declare class SourceReference {
    uri?: string;
    javaMethod?: JavaMethod;
    javaStackTraceElement?: JavaStackTraceElement;
    location?: Location;
}
export declare class JavaMethod {
    className: string;
    methodName: string;
    methodParameterTypes: readonly string[];
}
export declare class JavaStackTraceElement {
    className: string;
    fileName: string;
    methodName: string;
}
export declare class StepDefinition {
    id: string;
    pattern: StepDefinitionPattern;
    sourceReference: SourceReference;
}
export declare class StepDefinitionPattern {
    source: string;
    type: StepDefinitionPatternType;
}
export declare class TestCase {
    id: string;
    pickleId: string;
    testSteps: readonly TestStep[];
}
export declare class Group {
    children: readonly Group[];
    start?: number;
    value?: string;
}
export declare class StepMatchArgument {
    group: Group;
    parameterTypeName?: string;
}
export declare class StepMatchArgumentsList {
    stepMatchArguments: readonly StepMatchArgument[];
}
export declare class TestStep {
    hookId?: string;
    id: string;
    pickleStepId?: string;
    stepDefinitionIds?: readonly string[];
    stepMatchArgumentsLists?: readonly StepMatchArgumentsList[];
}
export declare class TestCaseFinished {
    testCaseStartedId: string;
    timestamp: Timestamp;
    willBeRetried: boolean;
}
export declare class TestCaseStarted {
    attempt: number;
    id: string;
    testCaseId: string;
    timestamp: Timestamp;
}
export declare class TestRunFinished {
    message?: string;
    success: boolean;
    timestamp: Timestamp;
}
export declare class TestRunStarted {
    timestamp: Timestamp;
}
export declare class TestStepFinished {
    testCaseStartedId: string;
    testStepId: string;
    testStepResult: TestStepResult;
    timestamp: Timestamp;
}
export declare class TestStepResult {
    duration: Duration;
    message?: string;
    status: TestStepResultStatus;
}
export declare class TestStepStarted {
    testCaseStartedId: string;
    testStepId: string;
    timestamp: Timestamp;
}
export declare class Timestamp {
    seconds: number;
    nanos: number;
}
export declare class UndefinedParameterType {
    expression: string;
    name: string;
}
export declare enum AttachmentContentEncoding {
    IDENTITY = "IDENTITY",
    BASE64 = "BASE64"
}
export declare enum PickleStepType {
    UNKNOWN = "Unknown",
    CONTEXT = "Context",
    ACTION = "Action",
    OUTCOME = "Outcome"
}
export declare enum SourceMediaType {
    TEXT_X_CUCUMBER_GHERKIN_PLAIN = "text/x.cucumber.gherkin+plain",
    TEXT_X_CUCUMBER_GHERKIN_MARKDOWN = "text/x.cucumber.gherkin+markdown"
}
export declare enum StepDefinitionPatternType {
    CUCUMBER_EXPRESSION = "CUCUMBER_EXPRESSION",
    REGULAR_EXPRESSION = "REGULAR_EXPRESSION"
}
export declare enum StepKeywordType {
    UNKNOWN = "Unknown",
    CONTEXT = "Context",
    ACTION = "Action",
    OUTCOME = "Outcome",
    CONJUNCTION = "Conjunction"
}
export declare enum TestStepResultStatus {
    UNKNOWN = "UNKNOWN",
    PASSED = "PASSED",
    SKIPPED = "SKIPPED",
    PENDING = "PENDING",
    UNDEFINED = "UNDEFINED",
    AMBIGUOUS = "AMBIGUOUS",
    FAILED = "FAILED"
}
//# sourceMappingURL=messages.d.ts.map