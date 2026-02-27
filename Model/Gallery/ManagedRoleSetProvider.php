<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Gallery;

class ManagedRoleSetProvider
{
    /**
     * @return array<int,string>
     */
    public function getManagedRoles(): array
    {
        return ['image', 'small_image', 'thumbnail', 'swatch_image'];
    }
}
