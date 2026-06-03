<?php

namespace App\Support\Hris;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HrisPermissionGrouper
{
  public static function groupFromName(string $name): string
  {
    if (preg_match('/([a-z_]+::[a-z_]+)$/i', $name, $matches)) {
      return str_replace('::', '_', $matches[1]);
    }

    if (preg_match('/_([a-z_]+)$/i', $name, $matches)) {
      return $matches[1];
    }

    return 'general';
  }

  public static function labelForGroup(string $group): string
  {
    $labels = config('managementpro.permission_group_labels', []);

    if (isset($labels[$group])) {
      return $labels[$group];
    }

    return Str::title(str_replace(['_', '::'], ' ', $group));
  }

  public static function humanName(string $name): string
  {
    $action = $name;
    $resource = '';

    if (preg_match('/^(.+)_([a-z_]+::[a-z_]+)$/i', $name, $matches)) {
      $action = $matches[1];
      $resource = str_replace('::', ' ', $matches[2]);
    } elseif (preg_match('/^(.+)_(.+)$/i', $name, $matches)) {
      $action = $matches[1];
      $resource = str_replace('_', ' ', $matches[2]);
    }

    $actionLabel = Str::title(str_replace('_', ' ', $action));

    return $resource ? "{$actionLabel} — {$resource}" : $actionLabel;
  }

  public static function groupCollection(Collection $permissions): Collection
  {
    return $permissions
      ->groupBy(fn ($p) => self::groupFromName($p->name))
      ->map(fn ($items, $group) => [
        'group' => $group,
        'label' => self::labelForGroup($group),
        'permissions' => $items->sortBy('name')->values(),
      ])
      ->sortBy('label')
      ->values();
  }
}
