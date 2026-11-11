<?php

namespace Database\Seeders;

use App\Services\GenerateEmployeeAvatar;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    public const EMPLOYEES = [
        '00000000-0000-4000-8000-000000000201' => 'Кузнецова Алина',
        '00000000-0000-4000-8000-000000000202' => 'Волков Тимур',
        '00000000-0000-4000-8000-000000000203' => 'Морозова Виктория',
        '00000000-0000-4000-8000-000000000204' => 'Соколов Артём',
        '00000000-0000-4000-8000-000000000205' => 'Лебедева Полина',
        '00000000-0000-4000-8000-000000000206' => 'Новиков Кирилл',
        '00000000-0000-4000-8000-000000000207' => 'Ерохина Дарья',
        '00000000-0000-4000-8000-000000000208' => 'Гаврилов Егор',
    ];

    /**
     * Run the database seeds.
     */
    public function run(GenerateEmployeeAvatar $avatars): void
    {
        $timestamp = now('UTC')->toIso8601String();
        foreach (self::EMPLOYEES as $id => $name) {
            $avatar = $avatars->generate($id);
            DB::table('employees')->insertOrIgnore([
                'id' => $id,
                'full_name' => $name,
                'position_title' => 'Агент',
                'avatar_url' => $avatar,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('employees')->where('id', $id)
                ->where(fn (Builder $query): Builder => $query->whereNull('avatar_url')->orWhereRaw("btrim(avatar_url) = ''"))
                ->update(['avatar_url' => $avatar, 'updated_at' => $timestamp]);
        }
    }
}
