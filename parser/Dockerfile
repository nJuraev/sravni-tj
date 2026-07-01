# Go-парсер — образ для разработки (исходники монтируются как volume).
FROM golang:1.23-alpine

RUN apk add --no-cache git ca-certificates

WORKDIR /app

# Зависимости кэшируются, если go.mod/go.sum уже существуют.
# При первом запуске инициализируйте модуль:
#   docker compose run --rm parser go mod init sravni/parser
