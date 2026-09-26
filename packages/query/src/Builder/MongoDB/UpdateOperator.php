<?php

namespace Utopia\Query\Builder\MongoDB;

enum UpdateOperator: string
{
    case Set = '$set';
    case Push = '$push';
    case Pull = '$pull';
    case PullAll = '$pullAll';
    case AddToSet = '$addToSet';
    case Increment = '$inc';
    case Multiply = '$mul';
    case Unset = '$unset';
    case Rename = '$rename';
    case Pop = '$pop';
    case Min = '$min';
    case Max = '$max';
    case CurrentDate = '$currentDate';
}
