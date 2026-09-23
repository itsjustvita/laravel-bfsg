<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

use LogicException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** A console output with separate in-memory stdout and stderr, for Artisan::call() stdout-purity tests. */
final class CapturedOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'w+'), decorated: false);
        $this->stderr = new StreamOutput(fopen('php://memory', 'w+'), decorated: false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('Sections are not supported.');
    }

    public function stdout(): string
    {
        return self::read($this);
    }

    public function stderr(): string
    {
        return $this->stderr instanceof StreamOutput ? self::read($this->stderr) : '';
    }

    private static function read(StreamOutput $output): string
    {
        rewind($output->getStream());

        return (string) stream_get_contents($output->getStream());
    }
}
