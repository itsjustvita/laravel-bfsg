<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Model;

/** Base of the package models: the connection comes from `bfsg.reporting.database.connection` (null = default). */
abstract class BfsgModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: parent::getConnectionName();
    }
}
