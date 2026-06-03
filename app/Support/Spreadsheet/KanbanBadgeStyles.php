<?php

namespace App\Support\Spreadsheet;

/**
 * Label berwarna untuk status kanban task project (backlog, todo, …).
 */
class KanbanBadgeStyles
{
    /**
     * @return array{label: string, font: string, background: string, border: string}
     */
    public static function status(string $status): array
    {
        $key = strtolower(trim($status));

        $styles = config('managementpro.project_task_export_status_styles', []);
        $preset = $styles[$key] ?? null;

        if ($preset !== null) {
            return [
                'label' => (string) ($preset['label'] ?? $key),
                'font' => (string) ($preset['font'] ?? '374151'),
                'background' => (string) ($preset['background'] ?? 'F3F4F6'),
                'border' => (string) ($preset['border'] ?? 'E5E7EB'),
            ];
        }

        return [
            'label' => $key !== '' ? $key : '-',
            'font' => '374151',
            'background' => 'F3F4F6',
            'border' => 'E5E7EB',
        ];
    }
}
