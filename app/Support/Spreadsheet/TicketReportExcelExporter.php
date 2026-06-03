<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketReportExcelExporter
{
    /**
     * @param  array<int, array{0: string, 1: string}>  $meta
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    public static function download(
        string $filename,
        string $title,
        array $meta,
        array $headers,
        array $rows
    ): BinaryFileResponse {
        $path = self::writeToTempFile($title, $meta, $headers, $rows);

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
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    public static function writeToTempFile(
        string $title,
        array $meta,
        array $headers,
        array $rows
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Tiket');

        $colCount = max(1, count($headers));
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        $row = 1;
        $sheet->setCellValue("A{$row}", $title);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '4338CA']],
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
                ->getStartColor()->setRGB('EEF2FF');
            $row++;
        }

        if ($meta !== []) {
            $row++;
        }

        $headerRow = $row;
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}{$headerRow}", $header);
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4F46E5'],
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
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $priorityColIndex = array_search('Prioritas', $headers, true);
        $statusColIndex = array_search('Status', $headers, true);

        $dataStartRow = $headerRow + 1;
        $row = $dataStartRow;
        foreach ($rows as $dataRow) {
            foreach ($headers as $index => $_header) {
                $col = Coordinate::stringFromColumnIndex($index + 1);
                $cellRef = "{$col}{$row}";
                $value = (string) ($dataRow[$index] ?? '');

                if ($index === $priorityColIndex) {
                    self::applyBadgeCell($sheet, $cellRef, TicketBadgeStyles::priority($value));
                } elseif ($index === $statusColIndex) {
                    self::applyBadgeCell($sheet, $cellRef, TicketBadgeStyles::status($value));
                } else {
                    $sheet->setCellValue($cellRef, $value);
                }
            }
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);

        if ($lastRow > $headerRow) {
            $dataBorderStyle = [
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ];

            for ($colIndex = 0; $colIndex < $colCount; $colIndex++) {
                if ($colIndex === $priorityColIndex || $colIndex === $statusColIndex) {
                    continue;
                }
                $col = Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastRow}")->applyFromArray($dataBorderStyle);
            }

            if ($priorityColIndex !== false) {
                $col = Coordinate::stringFromColumnIndex($priorityColIndex + 1);
                $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                    ],
                ]);
            }
            if ($statusColIndex !== false) {
                $col = Coordinate::stringFromColumnIndex($statusColIndex + 1);
                $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                    ],
                ]);
            }

            $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$lastRow}");
            $sheet->freezePane("A{$dataStartRow}");
        }

        $widths = [22, 22, 14, 24, 36, 48, 14, 16, 24];
        foreach ($headers as $index => $_header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($col)->setWidth($widths[$index] ?? 16);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ticket_report_');
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
