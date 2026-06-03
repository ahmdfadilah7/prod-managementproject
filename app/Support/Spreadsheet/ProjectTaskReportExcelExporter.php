<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectTaskReportExcelExporter
{
    /** @var array<int, string> */
    public const TASK_HEADERS = [
        'No. Task',
        'Subjek',
        'Deskripsi',
        'Tanggal mulai',
        'Tanggal selesai',
        'Prioritas',
        'Status',
        'Pelapor',
        'Ditugaskan',
        'Dibuat',
    ];

    /**
     * @param  array<int, array{0: string, 1: string}>  $meta
     * @param  array<int, array{
     *   label: string,
     *   details?: array<int, array{0: string, 1: string}>,
     *   tasks: array<int, array<int, string>>
     * }>  $groups
     */
    public static function download(
        string $filename,
        string $title,
        array $meta,
        array $groups
    ): BinaryFileResponse {
        $path = self::writeToTempFile($title, $meta, $groups);

        return response()->download(
            $path,
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        )->deleteFileAfterSend(true);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $meta
     * @param  array<int, array{
     *   label: string,
     *   details?: array<int, array{0: string, 1: string}>,
     *   tasks: array<int, array<int, string>>
     * }>  $groups
     */
    public static function writeToTempFile(string $title, array $meta, array $groups): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Task Project');

        $colCount = count(self::TASK_HEADERS);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        $row = 1;
        $sheet->setCellValue("A{$row}", $title);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '047857']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(28);
        $row += 2;

        foreach ($meta as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:B{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('ECFDF5');
            $row++;
        }

        if ($meta !== []) {
            $row++;
        }

        $priorityColIndex = 4;
        $statusColIndex = 5;
        $mergeRanges = [];

        $projectBlockStyle = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D1FAE5'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '10B981'],
                ],
            ],
        ];

        foreach ($groups as $group) {
            $blockStartRow = $row;

            $sheet->setCellValue("A{$row}", $group['label']);
            $mergeRanges[] = "A{$row}:{$lastCol}{$row}";
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray(array_merge($projectBlockStyle, [
                'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '065F46']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]));
            $sheet->getRowDimension($row)->setRowHeight(self::rowHeightForText($group['label'], 22));
            $row++;

            foreach ($group['details'] ?? [] as $detail) {
                $sheet->setCellValue("A{$row}", $detail[0]);
                $sheet->setCellValue("B{$row}", $detail[1]);
                $mergeRanges[] = "B{$row}:{$lastCol}{$row}";
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '047857']],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D1FAE5'],
                    ],
                    'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                ]);
                $sheet->getStyle("B{$row}:{$lastCol}{$row}")->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '064E3B']],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D1FAE5'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_TOP,
                        'wrapText' => true,
                    ],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(
                    self::rowHeightForText($detail[0].': '.$detail[1], 18)
                );
                $row++;
            }

            $blockEndRow = $row - 1;
            if ($blockEndRow >= $blockStartRow) {
                $sheet->getStyle("A{$blockStartRow}:{$lastCol}{$blockEndRow}")->applyFromArray([
                    'borders' => [
                        'outline' => [
                            'borderStyle' => Border::BORDER_MEDIUM,
                            'color' => ['rgb' => '10B981'],
                        ],
                    ],
                ]);
            }

            $row++;

            foreach (self::TASK_HEADERS as $index => $header) {
                $col = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue("{$col}{$row}", $header);
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '059669'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            foreach ($group['tasks'] as $taskRow) {
                foreach (self::TASK_HEADERS as $index => $_header) {
                    $col = Coordinate::stringFromColumnIndex($index + 1);
                    $cellRef = "{$col}{$row}";
                    $value = (string) ($taskRow[$index] ?? '');

                    if ($index === $priorityColIndex) {
                        self::applyBadgeCell($sheet, $cellRef, TicketBadgeStyles::priority($value));
                    } elseif ($index === $statusColIndex) {
                        self::applyBadgeCell($sheet, $cellRef, KanbanBadgeStyles::status($value));
                    } else {
                        $sheet->setCellValue($cellRef, $value);
                        $sheet->getStyle($cellRef)->applyFromArray([
                            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                            'borders' => [
                                'allBorders' => [
                                    'borderStyle' => Border::BORDER_THIN,
                                    'color' => ['rgb' => 'E5E7EB'],
                                ],
                            ],
                        ]);
                    }
                }
                $row++;
            }

            $row++;
        }

        if ($mergeRanges !== []) {
            foreach ($mergeRanges as $range) {
                $sheet->mergeCells($range);
            }
        }

        $widths = [14, 32, 44, 14, 12, 14, 22, 22, 18];
        foreach ($widths as $index => $width) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'project_task_report_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temporary file for export.');
        }

        $path = $tmp.'.xlsx';
        @unlink($tmp);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array{label: string, font: string, background: string, border: string}  $badge
     */
    protected static function rowHeightForText(string $text, int $minHeight = 18): float
    {
        $lines = max(1, substr_count($text, "\n") + 1);
        $charsPerLine = 70;
        $wrappedLines = (int) ceil(mb_strlen($text) / $charsPerLine);

        return (float) max($minHeight, ($lines + $wrappedLines - 1) * 15 + 6);
    }

    protected static function applyBadgeCell(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $cellRef,
        array $badge
    ): void {
        $sheet->setCellValue($cellRef, $badge['label']);
        $sheet->getStyle($cellRef)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => $badge['font']],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $badge['background']],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => $badge['border']],
                ],
            ],
        ]);
    }
}
