<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

interface MaterializedViews
{
    /**
     * Emit a `CREATE MATERIALIZED VIEW` DDL.
     *
     * Accepts either a {@see Builder} (whose `build()` SQL is inlined and
     * whose bindings ride along on the returned Statement) or a raw SQL
     * string for bodies that do not yet round-trip through the builder.
     *
     * `$targetTable` is the ClickHouse-specific destination for the
     * materialised aggregate (`CREATE MATERIALIZED VIEW … TO target AS …`).
     * Dialects whose materialised views own their own storage (e.g.
     * PostgreSQL) ignore the hint.
     *
     * @security When `$body` is a string, its contents are inlined verbatim
     *           into the DDL with no escaping or validation. Pass only SQL
     *           that the caller fully controls — never a value derived from
     *           an untrusted source. Prefer the {@see Builder} overload when
     *           any part of the body is parameterised.
     */
    public function createMaterializedView(string $name, Builder|string $body, ?string $targetTable = null, bool $ifNotExists = true): Statement;

    public function dropMaterializedView(string $name, bool $ifExists = true): Statement;
}
