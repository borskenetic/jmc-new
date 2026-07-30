<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsImportTemplateExport implements FromArray, WithHeadings
{
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
            'PROFILE PICTURE',
            'RFID',
        ];
    }

    public function array(): array
    {
        return [
            [
                '30168',
                'Agahan',
                'Steve Rion D.',
                'Kinder 1',
                'N/A',
                'DECEMBER 21, 2021',
                'Desiree de la Serna',
                '0943-068-9307',
                'Laverna Hills, Davao City',
                '30168.jpg',
                '3026958322',
            ],
        ];
    }
}
