<?php

namespace App\Console\Commands;

use App\Models\Food;
use App\Models\Poster;
use App\Services\Media\CloudinaryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Move any image still pointing at an external URL onto Cloudinary.
 *
 * Seeding before credentials exist leaves rows holding their source URL. This
 * pushes those across afterwards, so adding CLOUDINARY_URL and running one
 * command is the entire migration path - no reseed, no manual re-upload, and
 * no data loss for rows edited since.
 */
class SyncMediaToCloudinary extends Command
{
    protected $signature = 'media:sync-cloudinary
                            {--dry-run : List what would be uploaded without uploading it}';

    protected $description = 'Upload every food and poster image that is not yet hosted on Cloudinary';

    public function handle(CloudinaryService $cloudinary): int
    {
        $this->line('  cloud_name : '.($cloudinary->credentials()['cloud_name'] ?: '(empty)'));
        $this->newLine();

        if (! $cloudinary->isConfigured()) {
            $this->error($cloudinary->unavailableReason());

            return self::FAILURE;
        }

        $moved = 0;
        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ([[Food::class, 'thumbnail', 'food'], [Poster::class, 'image', 'posters']] as [$model, $column, $folder]) {
            $rows = $model::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->where($column, 'NOT LIKE', '%res.cloudinary.com%')
                ->get();

            $this->line(class_basename($model).': '.$rows->count().' image(s) to move.');

            foreach ($rows as $row) {
                $source = $row->{$column};

                if ($dryRun) {
                    $this->line('  would upload '.$source);

                    continue;
                }

                try {
                    $row->{$column} = $cloudinary->upload($source, $folder);
                    $row->save();
                    $moved++;
                    $this->line('  moved '.$this->label($row));
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn('  failed '.$this->label($row).' - '.$e->getMessage());
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete. Nothing was uploaded.');

            return self::SUCCESS;
        }

        $this->info("Moved {$moved} image(s) to Cloudinary.".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function label(Model $row): string
    {
        return '#'.$row->getKey().' '.($row->food_name ?? $row->poster_name ?? '');
    }
}
