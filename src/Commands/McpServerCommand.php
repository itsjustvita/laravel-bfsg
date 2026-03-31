<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;

class McpServerCommand extends Command
{
    protected $signature = 'bfsg:mcp-server';

    protected $description = 'Start the BFSG accessibility MCP server (stdio)';

    public function handle(): int
    {
        $mcpServer = new BfsgMcpServer();
        $server = $mcpServer->create();
        $server->runStdio();

        return Command::SUCCESS;
    }
}
