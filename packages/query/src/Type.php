<?php

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
