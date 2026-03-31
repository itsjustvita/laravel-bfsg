<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use Laravel\Mcp\Server\Transport\StdioTransport;

class McpServerCommand extends Command
{
    protected $signature = 'bfsg:mcp-server';

    protected $description = 'Start the BFSG accessibility MCP server (stdio)';

    public function handle(): int
    {
        $transport = new StdioTransport(Str::uuid()->toString());

        $server = app()->make(BfsgMcpServer::class, [
            'transport' => $transport,
        ]);

        $server->start();
        $transport->run();

        return Command::SUCCESS;
    }
}
