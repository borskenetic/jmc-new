<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsListExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected Collection $students
    ) {}

    public function collection()
    {
        return $this->students->map(fn ($s) => [
            $s->student_id ?? '',
            $s->lastname ?? '',
            $this->formatFirstNameMi($s->firstname, $s->midname),
            $s->year ?? '',
            $this->nullableDisplay($s->lrn),
            $this->formatBirthDate($s->birth_date),
            $s->emergency_person ?? '',
            $s->emergency_number ?? '',
            $s->address ?: ($s->emergency_address ?? ''),
            $s->rfid ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'ID NUMBER',
            'LAST NAME',
            'FIRST NAME & MI',
            'GRADE LEVEL',
            'LRN',
            'DATE OF BIRTH',
            'CONTACT PERSON',
            'NUMBER',
            'ADDRESS',
            'RFID',
        ];
    }

    private function formatFirstNameMi(?string $firstname, ?string $midname): string
    {
        $first = trim((string) $firstname);
        $mi = trim((string) $midname);

        if ($mi === '') {
            return $first;
        }

        if (preg_match('/^[A-Za-z]$/', $mi)) {
            $mi .= '.';
        }

        return trim($first.' '.$mi);
    }

    private function formatBirthDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return strtoupper(Carbon::parse($value)->format('F j, Y'));
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function nullableDisplay(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : 'N/A';
    }
}
