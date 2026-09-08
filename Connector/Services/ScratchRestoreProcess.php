<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Contracts\RestoresBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\BackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\ScratchRestoreResult;
use App\Domains\PeopleConnector\Connector\Exceptions\BackupRestoreRehearsalException;
use Symfony\Component\Process\Process;
use Throwable;

final class ScratchRestoreProcess implements RestoresBackupRestorePackage
{
    public function __construct(private readonly ScratchRestoreEnvironment $environment) {}

    public function restore(BackupRestorePackage $package, int $operatorId): ScratchRestoreResult
    {
        $databaseUrl = trim((string) config('people-connector.backup_rehearsal.database_url'));
        if ($databaseUrl === '') {
            throw BackupRestoreRehearsalException::scratchNotConfigured();
        }

        $handoff = tempnam(sys_get_temp_dir(), 'connector-backup-rehearsal-');
        if ($handoff === false) {
            throw BackupRestoreRehearsalException::scratchFailed('Could not create the private handoff file.');
        }

        try {
            chmod($handoff, 0600);
            $written = file_put_contents($handoff, json_encode([
                'tenant_id' => $package->tenantId,
                'tables' => $package->tables,
                'package_path' => $package->protectedPackagePath,
                'offer' => $package->offerJson,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($written === false) {
                throw BackupRestoreRehearsalException::scratchFailed('Could not write the private handoff file.');
            }

            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'connector:backup:rehearse-restore',
                '--tenant='.$package->tenantId,
                '--as='.$operatorId,
                '--json',
            ], base_path(), $this->environment->forDatabase($databaseUrl, $handoff), null, 300);
            $process->run();

            if (! $process->isSuccessful()) {
                throw BackupRestoreRehearsalException::scratchFailed(trim($process->getErrorOutput()."\n".$process->getOutput()));
            }

            $payload = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! is_array($payload['before'] ?? null) || ! is_array($payload['after'] ?? null)) {
                throw BackupRestoreRehearsalException::scratchFailed('The scratch process returned an invalid report.');
            }

            return new ScratchRestoreResult($this->counts($payload['before']), $this->counts($payload['after']));
        } catch (BackupRestoreRehearsalException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw BackupRestoreRehearsalException::scratchFailed($exception->getMessage());
        } finally {
            @unlink($handoff);
        }
    }

    /** @return array<string, int> */
    private function counts(array $values): array
    {
        $counts = [];
        foreach ($values as $table => $count) {
            if (! is_string($table) || ! is_int($count) || $count < 0) {
                throw BackupRestoreRehearsalException::scratchFailed('The scratch process returned invalid row counts.');
            }
            $counts[$table] = $count;
        }

        return $counts;
    }
}
