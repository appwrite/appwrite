# Implementation Plan: SQL Tokenizer & AST

**Status:** Complete
**Created:** 2026-03-24
**Completed:** 2026-03-24
**Description:** Add a tokenizer, parser, AST node hierarchy, serializer, visitor pattern, and Builder integration for SQL SELECT queries. Supports per-dialect tokenization/serialization for MySQL, PostgreSQL, ClickHouse, SQLite, and MariaDB. Enables round-trip: SQL string -> tokens -> AST -> modify/validate -> SQL string, plus AST <-> Builder conversion.

## Phases

### Phase 1: Token Types & Base Tokenizer
- **Status:** [x] Complete

### Phase 2: AST Node Hierarchy
- **Status:** [x] Complete

### Phase 3: Base SQL Parser (Tokens -> AST)
- **Status:** [x] Complete

### Phase 4: Base SQL Serializer (AST -> SQL)
- **Status:** [x] Complete

### Phase 5: Dialect-Specific Tokenizers & Serializers
- **Status:** [x] Complete

### Phase 6: Visitor Pattern for AST Modification & Validation
- **Status:** [x] Complete

### Phase 7: Builder <-> AST Integration
- **Status:** [x] Complete

## Progress Log

### Phase 1 - f871339
- **Tests added:** 42 (35 initial + 7 review fixes)
- **Files:** TokenType.php, Token.php, Tokenizer.php, TokenizerTest.php
- **Review issues fixed:** C1 (block comment bug), C2 (unknown chars), W1 (backslash escapes), W2 (scientific notation), W3 (quoted identifier escapes), W5 (aggregate function casing), W6 (keyword map constant)

### Phase 2 - f871339
- **Tests added:** 27
- **Files:** Expr.php, 22 AST node classes, SelectStatement.php, NodeTest.php

### Phase 3 - 4e0f32d
- **Tests added:** 52
- **Files:** Parser.php, ParserTest.php
- **Review issues fixed:** C1 (FILTER clause stored), C2 (:: cast in parseUnary), C3 (Star schema), C4 (inColumnList reset)

### Phase 4 - b3243cf
- **Tests added:** 62
- **Files:** Serializer.php, SerializerTest.php

### Phase 5 - 5f5ae07
- **Tests added:** 16
- **Files:** 5 tokenizer subclasses, 5 serializer subclasses, 6 test files

### Phase 6 - 36641e3
- **Tests added:** 17
- **Files:** Visitor.php, Walker.php, TableRenamer.php, ColumnValidator.php, FilterInjector.php, VisitorTest.php

### Phase 7 - 188da99
- **Tests added:** 40
- **Files:** Builder.php (modified), BuilderIntegrationTest.php

### Lint/Static Analysis - 4d19662
- **Files changed:** 43 (formatting + type fixes)

## Final Summary

### Tests Added
- 256 new tests total
- 42 tokenizer tests
- 27 AST node tests
- 52 parser tests
- 62 serializer tests
- 16 dialect tokenizer/serializer tests
- 17 visitor tests
- 40 builder integration tests

### Files Changed
- 55 files created, 3 files modified

### Commits
- f871339 - Token types, tokenizer, AST nodes
- 4e0f32d - Recursive-descent parser
- b3243cf - Serializer + parser review fixes
- 5f5ae07 - Dialect tokenizers/serializers
- 36641e3 - Visitor pattern
- 188da99 - Builder <-> AST integration
- 4d19662 - Lint and static analysis fixes

### Verification
- [x] All 4045 tests pass
- [x] Lint passes (Pint)
- [x] Static analysis passes (PHPStan level max)
- [x] No TODOs remaining
- [x] Plan file complete
