<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Gallery;

class RoleMapper
{
    /**
     * @var array<string,string>
     */
    private array $map = [
        'base' => 'image',
        'image' => 'image',
        'small' => 'small_image',
        'small_image' => 'small_image',
        'thumbnail' => 'thumbnail',
        'swatch' => 'swatch_image',
        'swatch_image' => 'swatch_image',
    ];

    /**
     * @param array<int,string> $roles
     * @return array<int,string>
     */
    public function mapMany(array $roles): array
    {
        $out = [];
        $seen = [];

        foreach ($roles as $role) {
            $normalized = strtolower(trim((string)$role));
            if ($normalized === '') {
                continue;
            }
            $mapped = $this->map[$normalized] ?? null;
            if ($mapped === null || isset($seen[$mapped])) {
                continue;
            }
            $seen[$mapped] = true;
            $out[] = $mapped;
        }

        return $out;
    }
}
