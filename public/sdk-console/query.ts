type QueryTypesSingle = string | number | boolean;
export type QueryTypesList = string[] | number[] | boolean[];
export type QueryTypes = QueryTypesSingle | QueryTypesList;

export class Query {
  static equal = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "equal", value);

  static notEqual = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "notEqual", value);

  static lessThan = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "lessThan", value);

  static lessThanEqual = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "lessThanEqual", value);

  static greaterThan = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "greaterThan", value);

  static greaterThanEqual = (attribute: string, value: QueryTypes): string =>
    Query.addQuery(attribute, "greaterThanEqual", value);

  static search = (attribute: string, value: string): string =>
    Query.addQuery(attribute, "search", value);

  static orderDesc = (attribute: string): string =>
    `orderDesc("${attribute}")`;

  static orderAsc = (attribute: string): string =>
    `orderAsc("${attribute}")`;

  static cursorAfter = (documentId: string): string =>
    `cursorAfter("${documentId}")`;

  static cursorBefore = (documentId: string): string =>
    `cursorBefore("${documentId}")`;

  static limit = (limit: number): string =>
    `limit(${limit})`;

  static offset = (offset: number): string =>
    `offset(${offset})`;

  private static addQuery = (attribute: string, method: string, value: QueryTypes): string =>
    value instanceof Array
      ? `${method}("${attribute}", [${value
          .map((v: QueryTypesSingle) => Query.parseValues(v))
          .join(",")}])`
      : `${method}("${attribute}", [${Query.parseValues(value)}])`;

  private static parseValues = (value: QueryTypes): string =>
    typeof value === "string" || value instanceof String
      ? `"${value}"`
      : `${value}`;
}