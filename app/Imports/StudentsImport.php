<?php

namespace App\Imports;

use App\Console\Commands\NormalizeStudentNames;
use App\Enums\EducationalLevel;
use App\Models\Student;
use App\Support\PatronOptions;
use App\Support\ProfilePicture;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentsImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public function collection(Collection $rows): void
    {
        $seenIds = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $row = $row->toArray();
            $studentId = $this->value($row, ['student_id', 'id_number', 'id']);

            if ($studentId === '') {
                continue;
            }

            if (isset($seenIds[$studentId])) {
                $this->skipped++;
                $this->errors[] = "Row {$line}: duplicate ID \"{$studentId}\" in file (first at row {$seenIds[$studentId]}).";

                continue;
            }

            $seenIds[$studentId] = $line;

            [$firstname, $midname] = $this->parseName($row);
            $lastname = $this->value($row, ['lastname', 'last_name']);

            $student = Student::where('student_id', $studentId)->first();
            $mapped = $this->mapRowAttributes($row, $studentId, $firstname, $midname, $lastname);

            if ($student === null) {
                if ($firstname === '' || $lastname === '') {
                    $this->skipped++;
                    $this->errors[] = "Row {$line}: first and last name are required for new student \"{$studentId}\".";

                    continue;
                }

                if (! $this->rfidAvailable($mapped['rfid'] ?? null, null)) {
                    $this->skipped++;
                    $this->errors[] = "Row {$line}: RFID \"{$mapped['rfid']}\" is already assigned to another student.";

                    continue;
                }

                if (! isset($mapped['educational_level'])) {
                    $mapped['educational_level'] = EducationalLevel::College->value;
                }

                $mapped['qrcode'] = $this->nextStudentQrCode();
                $mapped['normalized_name'] = NormalizeStudentNames::normalizeFullName($firstname.' '.$lastname);

                Student::create($mapped);
                $this->created++;

                continue;
            }

            $updates = $this->fillOnlyEmpty($student, $mapped);

            if (isset($updates['rfid']) && ! $this->rfidAvailable($updates['rfid'], $student->id)) {
                unset($updates['rfid']);
                $this->errors[] = "Row {$line}: RFID already assigned to another student; other fields were still applied if any.";
            }

            if ($updates === []) {
                $this->skipped++;

                continue;
            }

            if (isset($updates['firstname']) || isset($updates['lastname'])) {
                $updates['normalized_name'] = NormalizeStudentNames::normalizeFullName(
                    ($updates['firstname'] ?? $student->firstname).' '.($updates['lastname'] ?? $student->lastname),
                );
            }

            $student->update($updates);
            $this->updated++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRowAttributes(
        array $row,
        string $studentId,
        string $firstname,
        string $midname,
        string $lastname,
    ): array {
        $gradeLevel = PatronOptions::normalizeYearLabel(
            $this->value($row, ['year', 'grade_level'])
        ) ?? '';
        $educationalLevel = $this->value($row, ['educational_level']);
        if ($educationalLevel === '' && $gradeLevel !== '') {
            $educationalLevel = PatronOptions::educationalLevelForYear($gradeLevel) ?? '';
        }

        if ($educationalLevel !== '' && ! in_array($educationalLevel, EducationalLevel::values(), true)) {
            $educationalLevel = '';
        }

        $lrn = $this->nullableText($this->value($row, ['lrn']));
        $address = $this->nullableText($this->value($row, ['address', 'home_address']));
        $emergencyAddress = $this->nullableText($this->value($row, ['emergency_address']));

        $data = [
            'student_id' => $studentId,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'midname' => $midname !== '' ? $midname : null,
            'lrn' => $lrn,
            'year' => $gradeLevel !== '' ? $gradeLevel : null,
            'educational_level' => $educationalLevel !== '' ? $educationalLevel : null,
            'course' => $this->value($row, ['course']) ?: null,
            'mobile_number' => $this->value($row, ['mobile_number']) ?: null,
            'birth_date' => $this->parseDate($row['birth_date'] ?? $row['date_of_birth'] ?? null),
            'emergency_person' => $this->value($row, ['emergency_person', 'contact_person']) ?: null,
            'emergency_number' => $this->value($row, ['emergency_number', 'number']) ?: null,
            'address' => $address,
            'emergency_address' => $emergencyAddress ?? $address,
            'profile_picture' => $this->normalizeProfilePicture($row),
            'rfid' => $this->value($row, ['rfid']) ?: null,
            'qrcode' => $this->value($row, ['qrcode']) ?: null,
        ];

        return array_filter(
            $data,
            fn (mixed $value, string $key) => $key === 'student_id' || ($value !== null && $value !== ''),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: string}
     */
    private function parseName(array $row): array
    {
        $firstname = $this->value($row, ['firstname', 'first_name']);
        $midname = $this->value($row, ['midname', 'middle_initial', 'mi']);

        if ($firstname !== '') {
            return [$firstname, $midname];
        }

        $combined = $this->value($row, [
            'first_name_mi',
            'first_name_m_i',
            'first_name_and_mi',
            'firstname_mi',
        ]);

        if ($combined === '') {
            return ['', $midname];
        }

        $parts = preg_split('/\s+/', $combined) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], $midname];
        }

        $last = array_pop($parts);
        if (preg_match('/^[A-Za-z]\.?$/', $last)) {
            return [implode(' ', $parts), rtrim($last, '.')];
        }

        $parts[] = $last;

        return [implode(' ', $parts), $midname];
    }

    /**
     * @param  array<string, mixed>  $candidates
     * @return array<string, mixed>
     */
    private function fillOnlyEmpty(Student $student, array $candidates): array
    {
        unset($candidates['student_id'], $candidates['qrcode']);

        $updates = [];

        foreach ($candidates as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $student->{$field};

            if ($current === null || $current === '') {
                $updates[$field] = $value;
            }
        }

        return $updates;
    }

    private function rfidAvailable(?string $rfid, ?int $exceptStudentId): bool
    {
        if ($rfid === null || $rfid === '') {
            return true;
        }

        $query = Student::where('rfid', $rfid);

        if ($exceptStudentId !== null) {
            $query->where('id', '!=', $exceptStudentId);
        }

        return ! $query->exists();
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'N/A') === 0) {
            return null;
        }

        return $value;
    }

    private function nextStudentQrCode(): string
    {
        $last = Student::whereNotNull('qrcode')
            ->where('qrcode', 'like', 'S-%')
            ->orderByDesc('id')
            ->value('qrcode');

        $nextNumber = 1;
        if ($last && preg_match('/S-(\d+)/', $last, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return 'S-'.str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizeProfilePicture(array $row): ?string
    {
        $value = $this->value($row, [
            'profile_picture',
            'profile_photo',
            'photo',
            'picture',
        ]);

        return $value !== '' ? ProfilePicture::relativePath($value) : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
