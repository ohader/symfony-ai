<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\GptResponses\Normalizer\Content;

use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ImageUrlNormalizer implements NormalizerInterface
{
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ImageUrl;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            ImageUrl::class => true,
        ];
    }

    /**
     * @param ImageUrl $data
     *
     * @return array{type: 'image_url', image_url: array{url: string}}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'type' => 'input_image',
            'image_url' => $data->getUrl(),
        ];
    }
}
