<?php

declare(strict_types=1);

arch('source declares strict types')
    ->expect('Publicala\Ragno')
    ->toUseStrictTypes();

arch('namespaced test support declares strict types')
    ->expect('Publicala\Ragno\Tests')
    ->toUseStrictTypes();
