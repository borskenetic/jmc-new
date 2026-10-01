<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CollegeStudentsImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'ID NUMBER',
            'LAST NAME',
            'FIRST NAME & MI',
            'COURSE',
            'YEAR LEVEL',
            'EDUCATIONAL LEVEL',
            'DATE OF BIRTH',
            'MOBILE NUMBER',
            'CONTACT PERSON',
            'NUMBER',
            'ADDRESS',
            'PROFILE PICTURE',
            'RFID',
        ];
    }

    public function array(): array
    {
        return [
            [
                '2024-COL-001',
                'Dela Cruz',
                'Juan M.',
                'BSCS',
                '3rd Year',
                'college',
                'MARCH 15, 2002',
                '09171234501',
                'Maria Dela Cruz',
                '09181234567',
                'Bajada, Davao City',
                '2024-COL-001.jpg',
                '3026958322',
            ],
        ];
    }
}
