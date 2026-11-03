<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Everything the application writes during an in-process request: echo and print, buffers it flushes or leaves open,
 * and dump() (VarDumper writes to php://stdout in CLI, past every output buffer, so its handler is swapped). Nothing
 * reaches the output of the calling process: a JSON report on stdout or the MCP stdio channel stays intact. What a
 * StreamedResponse sends is its body; everything else is counted as stray output.
 *
 * stop() leaves the output buffer stack as found and puts the caller's dump handler back.
 *
 * @internal
 */
final class OutputCapture
{
    private readonly int $level;

    private int $stray = 0;

    /** The body while a streamed response is sent, null otherwise. */
    private ?string $body = null;

    /** @var callable|null */
    private $previousDumper;

    public function __construct()
    {
        $this->level = ob_get_level();
        // Chunk size 1: every write reaches write() at once, also what the application flushes with ob_flush()
        ob_start($this->write(...), 1);
        $this->previousDumper = VarDumper::setHandler(function (mixed $value): void {
            $this->stray += strlen((string) (new CliDumper)->dump((new VarCloner)->cloneVar($value), true));
        });
    }

    /** What $send writes (the content of a streamed response), including buffers it leaves open. */
    public function body(callable $send): string
    {
        self::endBuffersAbove($this->level + 1);
        $this->body = '';

        try {
            $send();
            self::endBuffersAbove($this->level + 1);

            return $this->body;
        } finally {
            $this->body = null;
        }
    }

    /** Ends the capture and returns the number of stray bytes (output outside the body, dump() output). */
    public function stop(): int
    {
        try {
            self::endBuffersAbove($this->level);
        } finally {
            VarDumper::setHandler($this->previousDumper);
        }

        return $this->stray;
    }

    /** Ends every removable output buffer above $level; each passes its content down, the capture buffer to write(). */
    private static function endBuffersAbove(int $level): void
    {
        while (ob_get_level() > $level && (ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
            if (! ob_end_flush()) {
                break;
            }
        }
    }

    private function write(string $buffer): string
    {
        if ($this->body === null) {
            $this->stray += strlen($buffer);
        } else {
            $this->body .= $buffer;
        }

        return '';
    }
}
