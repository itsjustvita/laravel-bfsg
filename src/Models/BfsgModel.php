<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BfsgModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: parent::getConnectionName();
    }
}
