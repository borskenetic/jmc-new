<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LinkStudentProfilePictures extends Command
{
    protected $signature = 'students:link-profile-pictures
                            {--dir= : Folder with {student_id}.jpg files (default: images/profile_pictures)}
                            {--dry-run : Show matches without updating the database}
                            {--overwrite : Replace students that already have a profile_picture}';

    protected $description = 'Set students.profile_picture from uploaded images named {student_id}.jpg';

    public function handle(): int
    {
        $dir = $this->resolveDirectory();
        if ($dir === null) {
            $this->error('Profile pictures folder not found. Pass --dir= with the absolute path.');

            return self::FAILURE;
        }

        $this->info('Scanning: '.$dir);

        $files = collect(File::files($dir))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png'], true))
            ->values();

        if ($files->isEmpty()) {
            $this->warn('No .jpg/.jpeg/.png files found in that folder.');

            return self::SUCCESS;
        }

        $linked = 0;
        $skippedHasPhoto = 0;
        $missingStudent = 0;
        $unchanged = 0;

        foreach ($files as $file) {
            $studentId = $file->getFilenameWithoutExtension();
            $relative = 'images/profile_pictures/'.$file->getFilename();

            $student = Student::query()->where('student_id', $studentId)->first();
            if (! $student) {
                $missingStudent++;
                $this->line("  skip (no student_id={$studentId}): {$file->getFilename()}");

                continue;
            }

            $current = $student->getRawOriginal('profile_picture');
            if ($current && ! $this->option('overwrite')) {
                $skippedHasPhoto++;

                continue;
            }

            if ($current === $relative) {
                $unchanged++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would link {$studentId} → {$relative}");
                $linked++;

                continue;
            }

            $student->forceFill(['profile_picture' => $relative])->saveQuietly();
            $linked++;
            $this->line("  linked {$studentId} → {$relative}");
        }

        $this->newLine();
        $this->info(($this->option('dry-run') ? 'Dry run — ' : '')."Linked: {$linked}");
        $this->line("Already had photo (skipped): {$skippedHasPhoto}");
        $this->line("Already correct path: {$unchanged}");
        $this->line("No matching student_id: {$missingStudent}");

        return self::SUCCESS;
    }

    private function resolveDirectory(): ?string
    {
        if ($custom = $this->option('dir')) {
            $custom = rtrim($custom, '/\\');

            return is_dir($custom) ? $custom : null;
        }

        foreach ([
            base_path('images/profile_pictures'),
            public_path('images/profile_pictures'),
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
