export declare enum KeywordType {
    Precondition = "precondition",
    Event = "event",
    Outcome = "outcome"
}
export interface IGetStepKeywordTypeOptions {
    keyword: string;
    language: string;
    previousKeywordType?: KeywordType;
}
export declare function getStepKeywordType({ keyword, language, previousKeywordType, }: IGetStepKeywordTypeOptions): KeywordType;
