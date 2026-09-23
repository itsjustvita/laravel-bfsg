<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use Laravel\Mcp\Server\Registrar;

class McpServerCommand extends Command
{
    public const HANDLE = 'bfsg';

    protected $signature = 'bfsg:mcp-server';

    protected $description = 'Start the BFSG accessibility MCP server (stdio)';

    /**
     * Starts the server through laravel/mcp's own local-server registry, so the
     * stdio transport is built the way the installed laravel/mcp version expects
     * (0.6.x and 1.x construct it differently). An app that registered its own
     * server under the "bfsg" handle with Mcp::local() keeps it.
     */
    public function handle(Registrar $registrar): int
    {
        if ($registrar->getLocalServer(self::HANDLE) === null) {
            $registrar->local(self::HANDLE, BfsgMcpServer::class);
        }

        $server = $registrar->getLocalServer(self::HANDLE);

        if ($server === null) {
            $this->components->error('The BFSG MCP server could not be registered.');

            return self::FAILURE;
        }

        $server();

        return self::SUCCESS;
    }
}
