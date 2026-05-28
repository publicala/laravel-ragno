<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

arch('source classes are final')
    ->expect('Publicala\Ragno')
    ->classes()
    ->toBeFinal();

arch('no debug helpers in source')
    ->expect('Publicala\Ragno')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'ds']);

arch('no Log::debug in source')
    ->expect('Publicala\Ragno')
    ->not->toUse(Log::class.'::debug');

arch('exceptions are throwable')
    ->expect('Publicala\Ragno\Exceptions')
    ->toImplement(Throwable::class);
