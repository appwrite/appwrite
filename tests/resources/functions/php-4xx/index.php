<?php

return function ($context) {
    return $context->res->text('Invalid input.', 400);
};
