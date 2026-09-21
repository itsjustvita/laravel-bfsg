<?php

namespace ItsJustVita\LaravelBfsg\Facades;

use Illuminate\Support\Facades\Facade;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;

/**
 * @method static \ItsJustVita\LaravelBfsg\Bfsg register(string $key, \ItsJustVita\LaravelBfsg\Contracts\Analyzer|string $analyzer)
 * @method static \ItsJustVita\LaravelBfsg\Bfsg forget(string $key)
 * @method static \ItsJustVita\LaravelBfsg\Bfsg only(array $keys)
 * @method static \ItsJustVita\LaravelBfsg\Bfsg except(array $keys)
 * @method static array analyzers()
 * @method static AnalysisResult analyze(string $html, array $options = [])
 * @method static AnalysisResult analyzeDocument(HtmlDocument $document, array $options = [])
 * @method static bool isAccessible(string $html)
 *
 * @see \ItsJustVita\LaravelBfsg\Bfsg
 */
class Bfsg extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ItsJustVita\LaravelBfsg\Bfsg::class;
    }
}
