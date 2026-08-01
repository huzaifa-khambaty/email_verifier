<?php

declare(strict_types=1);

/**
 * Minimal file logger. Not PSR-3, just enough for a CLI worker.
 */
class Logger
{
    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    private string $path;
    private int $minLevel;

    public function __construct(string $path, string $minLevel = 'INFO')
    {
        $this->path = $path;
        $this->minLevel = self::LEVELS[$minLevel] ?? self::LEVELS['INFO'];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function debug(string $message): void
    {
        $this->write('DEBUG', $message);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $line = sprintf(
            '[%s] %-7s %s%s',
            date('Y-m-d H:i:s'),
            $level,
            $message,
            PHP_EOL
        );

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
