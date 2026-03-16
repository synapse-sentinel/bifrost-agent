<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadSourceFile implements Tool
{
    public function description(): Stringable|string
    {
        return 'Read a source file from the deployed Bifrost application or list all files in app/. Use this to inspect actual deployed code before diagnosing a bug or filing an issue.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = trim($request['path'] ?? '');

        if ($path === '' || $path === '/') {
            return $this->listFiles();
        }

        return $this->readFile($path);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Relative path within app/ (e.g. "Listeners/RespondToSlack.php"). Omit or pass "/" to list all files.'),
        ];
    }

    protected function appPath(): string
    {
        return config('services.bifrost.app_path', '/opt/bifrost/app');
    }

    private function listFiles(): string
    {
        $base = $this->appPath();

        if (! is_dir($base)) {
            return "Bifrost app path not found: {$base}";
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace($base.DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        sort($files);

        return '**Files in app/ ('.count($files)." total):**\n".implode("\n", $files);
    }

    private function readFile(string $path): string
    {
        $path = ltrim(str_replace(['..', '//'], '', $path), '/');
        $full = $this->appPath().DIRECTORY_SEPARATOR.$path;

        if (! file_exists($full)) {
            return "File not found: app/{$path}";
        }

        $contents = (string) file_get_contents($full);
        $lines = count(explode("\n", $contents));

        return "**app/{$path}** ({$lines} lines):\n\n```php\n{$contents}\n```";
    }
}
