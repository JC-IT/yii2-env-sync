<?php

declare(strict_types=1);

namespace JCIT\envSync;

use Closure;
use Gitonomy\Git\Repository;
use JCIT\envSync\commands\ExportController;
use JCIT\envSync\commands\ImportController;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use Spatie\DbDumper\Databases\MariaDb;
use Symfony\Component\Process\Process;
use yii\base\BootstrapInterface;
use yii\base\Exception;
use yii\base\InvalidCallException;
use yii\base\Module;
use yii\console\Application;
use yii\db\Connection;
use yii\di\Instance;
use yii\helpers\FileHelper;

class EnvSync extends Module implements BootstrapInterface
{
    /**
     * The branch used in the path to store externally.
     * Set to null to detect the branch from git repository.
     */
    public string|bool|null $branch = false;

    /**
     * This variable indicates whether the sync works.
     * On production environments it is not recommended to use for example.
     * Default implementation can be 'canSync' => YII_ENV_DEV
     */
    public bool $canSync = false;
    public array $dbList = [];
    public array $fileSystems = [];
    public Filesystem|Closure|array|string $syncFilesystem;
    public string $tempBackupPath = '@runtime/db-dumps/';

    /**
     * The user used in the path to store externally
     */
    public Closure|string|bool $user = false;

    private function backupDb(string $name, Connection $db): void
    {
        $path = \Yii::getAlias($this->tempBackupPath) . $name . '.sql';
        if (!is_dir(dirname($path))) {
            FileHelper::createDirectory(dirname($path));
        }

        MariaDb::create()
            ->setDbName($this->dsnAttribute('dbname', $db->dsn))
            ->setUserName($db->username)
            ->setPassword($db->password)
            ->setHost($this->dsnAttribute('host', $db->dsn))
            ->setSkipSsl()
            ->dumpToFile($path);

        $targetPath = $this->getSyncStorageBasePath() . 'backup/' . basename($path);
        $this->syncFilesystem->delete($targetPath);
        $this->syncFilesystem->writeStream($targetPath, fopen($path, 'r'));

        FileHelper::unlink($path);
    }

    private function backupDbImport(string $name, Connection $db): void
    {
        $path = \Yii::getAlias($this->tempBackupPath) . $name . '.sql';
        $backupPath = $this->getSyncStorageBasePath() . 'backup/' . basename($path);

        if (!$this->syncFilesystem->has($backupPath)) {
            return;
        }

        if (!is_dir(dirname($path))) {
            FileHelper::createDirectory(dirname($path));
        }
        fwrite(fopen($path, 'w'), $this->syncFilesystem->read($backupPath));

        $arguments = [
            'mysql',
            '--host=' . $this->dsnAttribute('host', $db->dsn),
            '--port=' . ($this->dsnAttribute('port', $db->dsn) ?? 3306),
            '--user=' . $db->username,
            '--password=' . $db->password,
            $this->dsnAttribute('dbname', $db->dsn),
            '<',
            $path
        ];

        $process = Process::fromShellCommandline(implode(' ', $arguments));
        $process->run();

        FileHelper::unlink($path);

        if (!$process->isSuccessful()) {
            throw new \Exception('Import failed with output: ' . $process->getOutput());
        }
    }

    public function bootstrap($app): void
    {
        if ($app instanceof Application) {
            $this->controllerNamespace = 'JCIT\envSync\commands';
            $this->controllerMap['export'] = ExportController::class;
            $this->controllerMap['import'] = ImportController::class;
        }
    }

    private function checkCanSync(): void
    {
        if (!$this->canSync) {
            throw new InvalidCallException('Can sync is disabled.');
        }
    }

    private function cleanBranch(string $branch): string
    {
        return str_replace('/', '-', $branch);
    }

    private function dsnAttribute(string $name, string $dsn): ?string
    {
        if (preg_match('/' . $name . '=([^;]*)/', $dsn, $match)) {
            return $match[1];
        } else {
            return null;
        }
    }

    public function export(): void
    {
        $this->checkCanSync();

        /** @var Connection $db */
        foreach ($this->dbList as $name => $db) {
            $this->backupDb($name, $db);
        }

        foreach ($this->fileSystems as $targetPath => $fileSystem) {
            $this->syncDirectory($fileSystem, '/', $this->syncFilesystem, $this->getSyncStorageBasePath() . ltrim($targetPath, '/'));
        }
    }

    private function getCurrentBranch(): string
    {
        $repository = new Repository(\Yii::getAlias('@root'));
        $branches = explode(PHP_EOL, $repository->run('branch', ['-a', '--no-color']));
        foreach ($branches as $branch) {
            if (str_starts_with($branch, '* ')) {
                return substr($branch, 2);
            }
        }

        throw new Exception('Failed finding branch.');
    }

    private function getSyncStorageBasePath(): string
    {
        return '/' .
            implode('/', array_filter([
                isset($this->user) && $this->user instanceof Closure ? ($this->user)() : $this->user,
                is_null($this->branch) ? $this->cleanBranch($this->getCurrentBranch()) : $this->cleanBranch($this->branch),
            ]))
            . '/';
    }

    public function import(): void
    {
        $this->checkCanSync();

        foreach ($this->dbList as $name => $db) {
            $this->backupDbImport($name, $db);
        }

        foreach ($this->fileSystems as $targetPath => $fileSystem) {
            $this->syncDirectory($this->syncFilesystem, $this->getSyncStorageBasePath() . ltrim($targetPath, '/'), $fileSystem, '/');
        }
    }

    public function init()
    {
        parent::init();

        foreach ($this->dbList as $name => $db) {
            $this->dbList[$name] = Instance::ensure($db, Connection::class);
        }

        foreach ($this->fileSystems as $targetPath => $fileSystem) {
            $this->fileSystems[$targetPath] = Instance::ensure($fileSystem, Filesystem::class);
        }

        $this->syncFilesystem = Instance::ensure($this->syncFilesystem, Filesystem::class);
    }

    private function syncDirectory(FileSystem $source, string $sourcePath, Filesystem $target, string $targetPath): void
    {
        $sourcePath = $sourcePath != '/' ? trim($sourcePath, '/') : $sourcePath;
        $targetPath = $targetPath != '/' ? trim($targetPath, '/') : $targetPath;

        $target->deleteDirectory($targetPath);

        foreach ($source->listContents($sourcePath, Filesystem::LIST_DEEP) as $elem) {
            if ($elem instanceof DirectoryAttributes) {
                continue;
            } elseif ($elem instanceof FileAttributes) {
                $targetElemPath = ltrim($elem->path(), '/');
                if ($sourcePath != '/') {
                    $targetElemPath = str_replace($sourcePath, '', $targetElemPath);
                }

                $target->writeStream($targetPath . '/' . $targetElemPath, $source->readStream($elem->path()));
            }
        }
    }
}
