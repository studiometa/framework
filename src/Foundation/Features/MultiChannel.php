<?php

declare(strict_types=1);

/**
 * Contains the MultiChannel class.
 *
 * @copyright   Copyright (c) 2023 Vanilo UG
 * @author      Attila Fulop
 * @license     MIT
 * @since       2023-09-07
 *
 */

namespace Vanilo\Foundation\Features;

use Vanilo\Foundation\Contracts\Feature;

class MultiChannel implements Feature
{
    public function isEnabled(): bool
    {
        return (bool) config('vanilo.foundation.features.multi_channel.is_enabled', false);
    }

    public function isDisabled(): bool
    {
        return !$this->isEnabled();
    }

    public function configuration(): array
    {
        return config('vanilo.foundation.features.multi_channel', []);
    }
}
