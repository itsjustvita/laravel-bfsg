<?php

namespace Laravel\Boost;

// Stand-in for laravel/boost (not a dependency) so the provider's Boost integration can be tested.
if (! class_exists(BoostServiceProvider::class)) {
    class BoostServiceProvider {}
}
