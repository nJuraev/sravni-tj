#!/bin/sh
# Railway cron entrypoint для отдельного сервиса parser-rates: только курсы
# валют. Крон чаще и в дневном окне (см. railway.rates.json), в отличие от
# discover+parser (run.sh, раз в сутки).
set -x

./rates
