<?php

declare(strict_types=1);

namespace Publicala\Ragno\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal Eloquent model bound to the read-only `primary` (ragno) connection,
 * used to prove Eloquent hydration works unchanged through the gateway.
 *
 * @property int $id
 * @property string $name
 */
final class RagnoTestTenant extends Model
{
    public $timestamps = false;

    protected $connection = 'primary';

    protected $table = 'tenants';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['id' => 'integer'];
    }
}
