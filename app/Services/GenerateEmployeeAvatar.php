<?php

namespace App\Services;

use Composer\InstalledVersions;
use DiceBear\Avatar;
use DiceBear\Style;
use Illuminate\Support\Facades\File;

class GenerateEmployeeAvatar
{
    public function generate(string $employeeId): string
    {
        $stylesPath = InstalledVersions::getInstallPath('dicebear/styles');
        $style = Style::fromJson(File::get($stylesPath.'/src/lorelei.json'));

        return (new Avatar($style, [
            'seed' => strtolower($employeeId),
            'size' => 128,
            'backgroundColor' => ['e0e7ff', 'dbeafe', 'dcfce7', 'fef3c7', 'fce7f3'],
        ]))->toDataUri();
    }
}
