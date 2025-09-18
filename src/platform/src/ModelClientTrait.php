<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform;

use Symfony\AI\Platform\Attribute\AsModel;

trait ModelClientTrait
{
    private function hasModelAttribute(Model $model, string $platform): bool
    {
        $reflection = new \ReflectionObject($model);
        $attributes = array_filter(
            $reflection->getAttributes(AsModel::class),
            static fn(\ReflectionAttribute $attribute) =>
                ($attribute->getArguments()['platform'] ?? null) === $platform
                || ($attribute->getArguments()[0] ?? null) === $platform
        );
        return $attributes !== [];
    }
}
