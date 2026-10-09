<?php

namespace App\Controllers;

class Monitoring extends BaseController
{
    public function index(): string
    {
        $data['machines'] = [
            [
                'id' => 1,
                'slot' => 'SLOT 1',
                'machine_name' => 'MESIN A',
                'part_name' => 'Part B',
                'production_actual' => 750,
                'production_target' => 1000,
                'status' => 'Running',
                'operator' => 'Operator A',
                'shift' => 'Shift 1',
                'start_time' => '08:00',
                'tools' => [
                    [
                        'name' => 'Drill Ø10',
                        'position' => 'T01',
                        'actual_lifetime' => 800,
                        'max_lifetime' => 1000,
                    ],
                    [
                        'name' => 'Endmill Ø8',
                        'position' => 'T02',
                        'actual_lifetime' => 450,
                        'max_lifetime' => 1000,
                    ],
                    [
                        'name' => 'Reamer Ø6',
                        'position' => 'T03',
                        'actual_lifetime' => 980,
                        'max_lifetime' => 1000,
                    ],
                ],
            ],

            [
                'id' => 2,
                'slot' => 'SLOT 2',
                'machine_name' => 'MESIN B',
                'part_name' => 'Part C',
                'production_actual' => 420,
                'production_target' => 500,
                'status' => 'Running',
                'operator' => 'Operator B',
                'shift' => 'Shift 1',
                'start_time' => '08:15',
                'tools' => [
                    [
                        'name' => 'Drill Ø5',
                        'position' => 'T01',
                        'actual_lifetime' => 400,
                        'max_lifetime' => 800,
                    ],
                    [
                        'name' => 'Endmill Ø12',
                        'position' => 'T04',
                        'actual_lifetime' => 700,
                        'max_lifetime' => 1000,
                    ],
                ],
            ],

            [
                'id' => 3,
                'slot' => 'SLOT 3',
                'machine_name' => 'MESIN C',
                'part_name' => 'Part A',
                'production_actual' => 150,
                'production_target' => 600,
                'status' => 'Running',
                'operator' => 'Operator C',
                'shift' => 'Shift 1',
                'start_time' => '07:45',
                'tools' => [
                    [
                        'name' => 'Drill Ø6',
                        'position' => 'T02',
                        'actual_lifetime' => 250,
                        'max_lifetime' => 1000,
                    ],
                ],
            ],

            [
                'id' => 4,
                'slot' => 'SLOT 4',
                'machine_name' => 'MESIN D',
                'part_name' => 'Part D',
                'production_actual' => 950,
                'production_target' => 1000,
                'status' => 'Running',
                'operator' => 'Operator D',
                'shift' => 'Shift 1',
                'start_time' => '08:05',
                'tools' => [
                    [
                        'name' => 'Tap M8',
                        'position' => 'T05',
                        'actual_lifetime' => 990,
                        'max_lifetime' => 1000,
                    ],
                    [
                        'name' => 'Drill Ø7',
                        'position' => 'T06',
                        'actual_lifetime' => 650,
                        'max_lifetime' => 1000,
                    ],
                ],
            ],

            [
                'id' => 5,
                'slot' => 'SLOT 5',
                'machine_name' => 'MESIN E',
                'part_name' => 'Part E',
                'production_actual' => 300,
                'production_target' => 800,
                'status' => 'Running',
                'operator' => 'Operator E',
                'shift' => 'Shift 1',
                'start_time' => '08:30',
                'tools' => [
                    [
                        'name' => 'Endmill Ø10',
                        'position' => 'T01',
                        'actual_lifetime' => 320,
                        'max_lifetime' => 900,
                    ],
                    [
                        'name' => 'Chamfer',
                        'position' => 'T07',
                        'actual_lifetime' => 500,
                        'max_lifetime' => 1200,
                    ],
                    [
                        'name' => 'Drill Ø4',
                        'position' => 'T09',
                        'actual_lifetime' => 600,
                        'max_lifetime' => 1000,
                    ],
                ],
            ],
        ];
        return view('monitoring/index', $data);
    }
}
