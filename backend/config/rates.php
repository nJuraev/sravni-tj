<?php

declare(strict_types=1);

return [
    /*
     * Время старта крона парсера курсов (parser/cmd/rates, railway.rates.json:
     * cronSchedule "0 3-13/2 * * *" = 03:00 UTC = 08:00 Asia/Dushanbe).
     * До этого времени сегодняшнего курса ещё нет — витрина показывает вчерашний.
     */
    'parser_start_time' => env('RATES_PARSER_START_TIME', '08:00'),

    'timezone' => env('RATES_TIMEZONE', 'Asia/Dushanbe'),
];
