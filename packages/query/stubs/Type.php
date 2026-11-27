<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query;

enum Type: string
{
    case Read = 'read';
    case Write = 'write';
    case TransactionBegin = 'transaction_begin';
    case TransactionEnd = 'transaction_end';
    case Transaction = 'transaction';
    case Unknown = 'unknown';
}
