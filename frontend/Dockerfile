# Vue frontend — Node 22 для dev-сервера Vite.
FROM node:22-alpine

WORKDIR /app

# package.json копируется отдельно для кэша слоёв; при наличии — ставим зависимости.
# При первом запуске зависимости установит агент-скаффолдер или:
#   docker compose run --rm frontend npm install

EXPOSE 5173
