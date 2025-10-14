<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\GptResponses;

use Symfony\AI\Platform\Bridge\OpenAi\Contract\DocumentNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @author Guillermo Lengemann <guillermo.lengemann@gmail.com>
 */
final readonly class GptResponsesContract extends Contract
{
    public static function create(NormalizerInterface ...$normalizer): Contract
    {
        return parent::create(
            new MessageBagNormalizer(),
            new DocumentNormalizer(),
            ...$normalizer
        );
    }
}
