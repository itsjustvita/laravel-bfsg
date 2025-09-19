<?php

namespace ItsJustVita\LaravelBfsg\Facades;

use Illuminate\Support\Facades\Facade;

class Bfsg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'bfsg';
    }
}