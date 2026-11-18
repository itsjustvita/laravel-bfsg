<?php

namespace ItsJustVita\LaravelBfsg\Commands\Concerns;

use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unknown options and stray or missing arguments are operational errors (exit 2), never a findings-style exit code.
 * The using command declares OPERATIONAL_ERROR and a static escape(string): string.
 *
 * @internal shared by the package's own commands, not part of the public API
 */
trait ExitsWithOperationalErrors
{
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stderr->writeln('<error>'.self::escape($e->getMessage()).'</error>');

            return self::OPERATIONAL_ERROR;
        }
    }
}
