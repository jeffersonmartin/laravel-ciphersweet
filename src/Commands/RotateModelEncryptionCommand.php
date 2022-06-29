<?php

namespace Spatie\LaravelCipherSweet\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use ParagonIE\CipherSweet\CipherSweet as CipherSweetEngine;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Exception\InvalidCiphertextException;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\CipherSweet\KeyRotation\RowRotator;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class RotateModelEncryptionCommand extends Command
{
    protected $signature = 'ciphersweet:rotate-model-encryption {model} {newKey}';

    protected $description = 'Rotate the encryption keys for a model';

    public function handle(): int
    {
        /** @var class-string<\Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted> $modelClass */
        $modelClass = $this->argument('model');

        if (! class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist");

            return self::INVALID;
        }

        $newClass = (new $modelClass());

        if (! $newClass instanceof CipherSweetEncrypted) {
            $this->error("Model {$modelClass} does not implement CipherSweetEncrypted");

            return self::INVALID;
        }

        $updatedRows = 0;

        $this->getOutput()->progressStart(DB::table($newClass->getTable())->count());

        DB::table($newClass->getTable())->orderBy((new $modelClass())->getKeyName())->each(function (object $model) use ($modelClass, $newClass, &$updatedRows) {
            $model = (array) $model;

            $oldRow = new EncryptedRow(app(CipherSweetEngine::class), $newClass->getTable());
            $modelClass::configureCipherSweet($oldRow);

            $newRow = new EncryptedRow(
                new CipherSweetEngine(new StringProvider($this->argument('newKey')), $oldRow->getBackend()),
                $newClass->getTable(),
            );
            $modelClass::configureCipherSweet($newRow);

            $rotator = new RowRotator($oldRow, $newRow);
            if ($rotator->needsReEncrypt($model)) {
                try {
                    [$ciphertext, $indices] = $rotator->prepareForUpdate($model);
                } catch (InvalidCiphertextException $e) {
                    [$ciphertext, $indices] = $newRow->prepareRowForStorage($model);
                }

                DB::table($newClass->getTable())
                    ->where($newClass->getKeyName(), $model[$newClass->getKeyName()])
                    ->update(Arr::only($ciphertext, $newRow->listEncryptedFields()));

                foreach ($indices as $name => $value) {
                    DB::table('blind_indexes')->updateOrInsert([
                        'indexable_type' => $newClass->getMorphClass(),
                        'indexable_id' => $model[$newClass->getKeyName()],
                        'name' => $name,
                    ], [
                        'value' => $value,
                    ]);
                }

                $updatedRows++;
            }

            $this->getOutput()->progressAdvance();
        });

        $this->getOutput()->progressFinish();

        $this->info("Updated {$updatedRows} rows.");
        $this->info("You can now set your config key to the new key.");

        return self::SUCCESS;
    }
}
